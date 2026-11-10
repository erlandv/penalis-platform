<?php
/**
 * Bounded user-scoped notifications and email dispatch for the editorial workflow.
 *
 * Every V2 editorial transition records a dashboard notification and sends a
 * transactional email without ever rolling back a committed editorial decision
 * when mail delivery fails. Private post content is never included, and
 * notifications are strictly scoped to the article owner and reviewers.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates editorial dashboard notifications and emails.
 */
final class Penalis_Editorial_Notifications {

	/**
	 * Counter used to keep notification IDs unique within a single request.
	 *
	 * @var int
	 */
	private static $sequence = 0;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'penalis_editorial_action', array( __CLASS__, 'handle_editorial_action' ), 10, 6 );
	}

	/**
	 * Handle a completed editorial workflow action.
	 *
	 * Mail failures are recorded on the author's account by `Penalis_Emails`
	 * and never roll back the transition that already succeeded.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $action   Workflow action.
	 * @param string $state    New editorial state.
	 * @param string $status   New WordPress post status.
	 * @param int    $actor_id Acting user ID.
	 * @param string $feedback Optional reviewer feedback.
	 * @return void
	 */
	public static function handle_editorial_action( $post_id, $action, $state = '', $status = '', $actor_id = 0, $feedback = '' ) {
		unset( $state, $status, $actor_id );

		$post   = get_post( (int) $post_id );
		$action = (string) $action;

		if ( ! $post || 'post' !== $post->post_type || ! Penalis_Editorial_Policy::is_valid_action( $action ) ) {
			return;
		}

		$author_id = (int) $post->post_author;

		if ( Penalis_Editorial_Policy::is_writer_action( $action ) ) {
			$recipients = array_merge( array( $author_id ), self::reviewer_user_ids() );
			$recipients = array_values( array_unique( array_map( 'intval', $recipients ) ) );

			foreach ( $recipients as $recipient_id ) {
				self::record_for_user( $recipient_id, $post, $action, '' );
			}

			Penalis_Emails::send_editorial_submission_notification( (int) $post->ID, $action );
			return;
		}

		if ( Penalis_Editorial_Policy::is_reviewer_action( $action ) ) {
			self::record_for_user( $author_id, $post, $action, (string) $feedback );
			Penalis_Emails::send_editorial_decision_notification( (int) $post->ID, $action, (string) $feedback );
		}
	}

	/**
	 * Record one bounded dashboard notification for a recipient.
	 *
	 * Verifies recipient eligibility through `Penalis_Editorial_Policy::can_receive_notification()`
	 * before writing so another writer or non-reviewer account can never
	 * receive a notification for a private submission.
	 *
	 * @param int         $user_id  Recipient user ID.
	 * @param WP_Post|int $post     Post or post ID.
	 * @param string      $action   Workflow action.
	 * @param string      $feedback Optional reviewer feedback.
	 * @return bool Whether the notification was stored.
	 */
	public static function record_for_user( $user_id, $post, $action, $feedback = '' ) {
		$user_id = (int) $user_id;
		$action  = (string) $action;
		$post    = $post instanceof WP_Post ? $post : get_post( (int) $post );

		if ( $user_id <= 0 || ! $post || 'post' !== $post->post_type ) {
			return false;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		$is_owner   = (int) $post->post_author === $user_id;
		$can_review = Penalis_Editorial_Workflow::can_review( $user_id );

		if ( ! Penalis_Editorial_Policy::can_receive_notification( $action, $is_owner, $can_review ) ) {
			return false;
		}

		$title          = self::safe_title( $post );
		$clean_feedback = Penalis_Editorial_Policy::is_reviewer_action( $action ) && $is_owner
			? self::safe_text( $feedback )
			: '';

		$entry = array(
			'id'            => self::generate_id( (int) $post->ID, $action ),
			'type'          => $action,
			'article_id'    => (int) $post->ID,
			'article_title' => $title,
			'message'       => self::build_message( $post, $action, $is_owner, $clean_feedback ),
			'feedback'      => $clean_feedback,
			'created_at'    => time(),
			'read_at'       => 0,
		);

		if ( ! Penalis_Editorial_Policy::is_valid_notification( $entry ) ) {
			return false;
		}

		$stored  = self::get_raw_for_user( $user_id );
		$updated = Penalis_Editorial_Policy::append_notification( $stored, $entry );

		return (bool) update_user_meta( $user_id, Penalis_Editorial_Policy::META_NOTIFICATIONS, $updated );
	}

	/**
	 * Return the formatted notifications visible to a user, newest first.
	 *
	 * Each notification is re-checked against current post ownership and
	 * reviewer permission on read so private article titles or feedback never
	 * leak if roles or ownership change after the notification was stored.
	 *
	 * @param int $user_id User ID.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_for_user( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return array();
		}

		$raw        = self::get_raw_for_user( $user_id );
		$can_review = Penalis_Editorial_Workflow::can_review( $user_id );
		$items      = array();

		foreach ( array_reverse( $raw ) as $entry ) {
			if ( ! self::can_user_read_entry( $user_id, $entry, $can_review ) ) {
				continue;
			}

			$article_id = (int) $entry['article_id'];
			$post       = get_post( $article_id );
			$title      = $post ? self::safe_title( $post ) : ( isset( $entry['article_title'] ) ? self::safe_text( $entry['article_title'] ) : 'Tanpa judul' );
			$read_at    = max( 0, (int) $entry['read_at'] );
			$type       = (string) $entry['type'];
			$target_url = add_query_arg(
				array(
					'view' => 'editor',
					'id'   => $article_id,
				),
				Penalis_Dashboard::dashboard_url()
			);

			$items[] = array(
				'id'           => (string) $entry['id'],
				'type'         => $type,
				'typeLabel'    => Penalis_Editorial_Policy::action_label( $type ),
				'articleId'    => $article_id,
				'articleTitle' => '' !== $title ? $title : 'Tanpa judul',
				'message'      => (string) $entry['message'],
				'feedback'     => isset( $entry['feedback'] ) && is_string( $entry['feedback'] ) ? $entry['feedback'] : '',
				'createdAt'    => (int) $entry['created_at'],
				'readAt'       => $read_at,
				'isRead'       => $read_at > 0,
				'targetView'   => 'editor',
				'targetUrl'    => $target_url,
				'reviewUrl'    => $can_review ? admin_url( 'admin.php?page=penalis-review&post_id=' . $article_id ) : '',
			);
		}

		return $items;
	}

	/**
	 * Count unread notifications visible to a user.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public static function count_unread( $user_id ) {
		$items  = self::get_for_user( $user_id );
		$unread = 0;

		foreach ( $items as $item ) {
			if ( empty( $item['isRead'] ) ) {
				++$unread;
			}
		}

		return $unread;
	}

	/**
	 * Mark one notification or all notifications as read for a user.
	 *
	 * Operations are strictly scoped to `$user_id`'s own metadata.
	 *
	 * @param int    $user_id         User ID.
	 * @param string $notification_id Notification ID, or empty/'all' for all.
	 * @return array{items:array<int,array<string,mixed>>,unreadCount:int}
	 */
	public static function mark_read( $user_id, $notification_id = '' ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return array(
				'items'       => array(),
				'unreadCount' => 0,
			);
		}

		$stored = self::get_raw_for_user( $user_id );
		$result = Penalis_Editorial_Policy::mark_notifications_read( $stored, (string) $notification_id, time() );

		if ( $result['changed'] ) {
			update_user_meta( $user_id, Penalis_Editorial_Policy::META_NOTIFICATIONS, $result['notifications'] );
		}

		$items = self::get_for_user( $user_id );

		return array(
			'items'       => $items,
			'unreadCount' => self::count_unread( $user_id ),
		);
	}

	/**
	 * Read the raw bounded notification array stored for a user.
	 *
	 * Invalid entries are filtered out and the list is clamped to
	 * `Penalis_Editorial_Policy::NOTIFICATION_LIMIT`.
	 *
	 * @param int $user_id User ID.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_raw_for_user( $user_id ) {
		$stored = get_user_meta( (int) $user_id, Penalis_Editorial_Policy::META_NOTIFICATIONS, true );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$valid = array();
		foreach ( $stored as $entry ) {
			if ( Penalis_Editorial_Policy::is_valid_notification( $entry ) ) {
				$valid[] = $entry;
			}
		}

		if ( count( $valid ) > Penalis_Editorial_Policy::NOTIFICATION_LIMIT ) {
			$valid = array_slice( $valid, -Penalis_Editorial_Policy::NOTIFICATION_LIMIT );
		}

		return array_values( $valid );
	}

	/**
	 * Whether a user may read a stored notification entry.
	 *
	 * @param int   $user_id    User ID.
	 * @param array $entry      Notification entry.
	 * @param bool  $can_review Whether the user is a reviewer.
	 * @return bool
	 */
	private static function can_user_read_entry( $user_id, $entry, $can_review ) {
		if ( ! Penalis_Editorial_Policy::is_valid_notification( $entry ) ) {
			return false;
		}

		$post = get_post( (int) $entry['article_id'] );
		if ( ! $post || 'post' !== $post->post_type ) {
			return false;
		}

		$is_owner = (int) $post->post_author === (int) $user_id;

		return Penalis_Editorial_Policy::can_receive_notification( (string) $entry['type'], $is_owner, (bool) $can_review );
	}

	/**
	 * Build the Indonesian dashboard notification message for an action.
	 *
	 * Never includes raw `post_content` or `post_excerpt`.
	 *
	 * @param WP_Post $post     Post object.
	 * @param string  $action   Workflow action.
	 * @param bool    $is_owner Whether the recipient is the article owner.
	 * @param string  $feedback Sanitized feedback note.
	 * @return string
	 */
	private static function build_message( $post, $action, $is_owner, $feedback ) {
		$title = self::safe_title( $post );

		switch ( $action ) {
			case Penalis_Editorial_Policy::ACTION_SUBMIT:
				if ( $is_owner ) {
					return sprintf( 'Karya tulis "%s" telah di-submit dan sedang menunggu review admin.', $title );
				}
				return sprintf( 'Karya tulis baru "%s" di-submit penulis dan menunggu review admin.', $title );

			case Penalis_Editorial_Policy::ACTION_RESUBMIT:
				if ( $is_owner ) {
					return sprintf( 'Revisi karya tulis "%s" telah di-submit ulang dan sedang menunggu review admin.', $title );
				}
				return sprintf( 'Revisi karya tulis "%s" di-submit ulang oleh penulis dan menunggu review admin.', $title );

			case Penalis_Editorial_Policy::ACTION_REQUEST_CHANGES:
				if ( '' !== $feedback ) {
					return sprintf( 'Admin meminta revisi pada karya tulis "%s": %s', $title, $feedback );
				}
				return sprintf( 'Admin meminta revisi pada karya tulis "%s". Buka editor untuk melihat catatan review.', $title );

			case Penalis_Editorial_Policy::ACTION_REJECT:
				if ( '' !== $feedback ) {
					return sprintf( 'Karya tulis "%s" belum dapat diterbitkan: %s', $title, $feedback );
				}
				return sprintf( 'Karya tulis "%s" belum dapat diterbitkan oleh admin.', $title );

			case Penalis_Editorial_Policy::ACTION_REOPEN:
				return sprintf( 'Karya tulis "%s" telah dibuka kembali oleh admin. Kamu kini dapat edit dan submit kembali.', $title );

			case Penalis_Editorial_Policy::ACTION_APPROVE:
				return sprintf( 'Selamat! Karya tulis "%s" telah disetujui admin dan resmi diterbitkan.', $title );
		}

		return sprintf( 'Status review karya tulis "%s" telah diperbarui.', $title );
	}

	/**
	 * Find all user IDs eligible to review editorial submissions.
	 *
	 * @return int[]
	 */
	private static function reviewer_user_ids() {
		$users = get_users(
			array(
				'capability' => 'edit_others_posts',
				'fields'     => 'ID',
				'number'     => 50,
			)
		);

		$reviewers = array();
		foreach ( (array) $users as $candidate ) {
			$candidate_id = is_object( $candidate ) && isset( $candidate->ID ) ? (int) $candidate->ID : (int) $candidate;
			if ( $candidate_id > 0 && Penalis_Editorial_Workflow::can_review( $candidate_id ) ) {
				$reviewers[] = $candidate_id;
			}
		}

		return array_values( array_unique( $reviewers ) );
	}

	/**
	 * Generate a unique notification identifier.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $action  Workflow action.
	 * @return string
	 */
	private static function generate_id( $post_id, $action ) {
		++self::$sequence;

		return sprintf(
			'notif_%d_%s_%d_%d',
			(int) $post_id,
			sanitize_key( (string) $action ),
			time(),
			self::$sequence
		);
	}

	/**
	 * Extract a sanitized plain-text post title.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	private static function safe_title( $post ) {
		$title = self::safe_text( (string) $post->post_title );

		return '' !== $title ? $title : 'Tanpa judul';
	}

	/**
	 * Normalize a plain-text string with tags removed.
	 *
	 * @param string $text Raw string.
	 * @return string
	 */
	private static function safe_text( $text ) {
		$clean = wp_strip_all_tags( wp_specialchars_decode( (string) $text, ENT_QUOTES ) );
		$clean = trim( preg_replace( '/\s+/', ' ', $clean ) );

		return $clean;
	}
}
