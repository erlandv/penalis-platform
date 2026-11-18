<?php
/**
 * Published-article takedown audit and trash recovery.
 *
 * A published article is a live part of the public archive. Writers keep
 * ownership of their draft work, but they must not be able to take a live
 * article down silently. This class records and reports every published post
 * that enters the WordPress trash, and exposes a safe restore path for
 * reviewers. It never deletes content itself.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Audits published articles moved to trash and restores them from there.
 */
final class Penalis_Article_Takedown {

	public const META_TRASHED_AT    = '_penalis_trashed_at';
	public const META_TRASHED_BY    = '_penalis_trashed_by';
	public const META_TRASHED_STATE = '_penalis_trashed_state';
	public const META_RESTORED_AT   = '_penalis_restored_at';
	public const META_RESTORED_BY   = '_penalis_restored_by';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'transition_post_status', array( __CLASS__, 'handle_status_transition' ), 10, 3 );
		add_action( 'untrashed_post', array( __CLASS__, 'handle_untrashed_post' ), 10, 1 );
	}

	/**
	 * Whether a post is a live published article protected from writer deletion.
	 *
	 * The editorial state is authoritative so a legacy published post without
	 * workflow metadata is protected as well.
	 *
	 * @param WP_Post|null $post Post to inspect.
	 * @return bool
	 */
	public static function is_protected_post( $post ) {
		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
			return false;
		}

		if ( 'publish' === $post->post_status ) {
			return true;
		}

		return Penalis_Editorial_Policy::STATE_PUBLISHED === Penalis_Editorial_Workflow::get_state( $post );
	}

	/**
	 * Record and report a published article entering the trash.
	 *
	 * Mail failures never roll back the trash transition: the record and the
	 * admin screen remain the authoritative audit trail.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Previous post status.
	 * @param WP_Post $post       Post being transitioned.
	 * @return void
	 */
	public static function handle_status_transition( $new_status, $old_status, $post ) {
		if ( 'trash' !== $new_status || 'publish' !== $old_status ) {
			return;
		}

		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
			return;
		}

		if ( self::has_takedown_record( $post ) ) {
			return;
		}

		$now = time();
		update_post_meta( $post->ID, self::META_TRASHED_AT, $now );
		update_post_meta( $post->ID, self::META_TRASHED_BY, (int) get_current_user_id() );
		update_post_meta( $post->ID, self::META_TRASHED_STATE, Penalis_Editorial_Policy::STATE_PUBLISHED );

		self::notify_reviewers( $post );
	}

	/**
	 * Clear the takedown record when a post is restored by any path.
	 *
	 * Reviewers may untrash directly from the native Posts screen. The admin
	 * recovery screen must not keep showing an article that is already live.
	 *
	 * @param int $post_id Restored post ID.
	 * @return void
	 */
	public static function handle_untrashed_post( $post_id ) {
		$post = get_post( (int) $post_id );

		if ( ! $post || ! self::has_takedown_record( $post ) ) {
			return;
		}

		self::clear_takedown_record( (int) $post_id );
		update_post_meta( (int) $post_id, self::META_RESTORED_AT, time() );
		update_post_meta( (int) $post_id, self::META_RESTORED_BY, (int) get_current_user_id() );
	}

	/**
	 * Whether a post carries an active published-article takedown record.
	 *
	 * @param WP_Post|null $post Post to inspect.
	 * @return bool
	 */
	public static function has_takedown_record( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		return (int) get_post_meta( $post->ID, self::META_TRASHED_AT, true ) > 0;
	}

	/**
	 * Published articles currently in the trash, newest takedown first.
	 *
	 * @param int $limit Maximum number of posts.
	 * @return WP_Post[]
	 */
	public static function trashed_posts( $limit = 50 ) {
		$query = new WP_Query(
			self::query_args( max( 1, (int) $limit ) )
		);

		return is_array( $query->posts ) ? $query->posts : array();
	}

	/**
	 * Number of published articles currently in the trash.
	 *
	 * @return int
	 */
	public static function trashed_count() {
		return Penalis_Editorial_Workflow::count_matching_posts( self::query_args( 1 ) );
	}

	/**
	 * Restore one recorded published article from the trash.
	 *
	 * @param int $post_id  Post ID.
	 * @param int $actor_id Acting reviewer ID.
	 * @return bool|WP_Error
	 */
	public static function restore( $post_id, $actor_id = 0 ) {
		$post = get_post( (int) $post_id );

		if ( ! $post || 'trash' !== $post->post_status || ! self::has_takedown_record( $post ) ) {
			return new WP_Error(
				'penalis_not_found',
				'Karya tulis yang dihapus tidak ditemukan.',
				array( 'status' => 404 )
			);
		}

		$restored = wp_untrash_post( (int) $post_id );

		if ( ! $restored || is_wp_error( $restored ) ) {
			return new WP_Error(
				'penalis_restore_failed',
				'Karya tulis tidak dapat dipulihkan dari trash.',
				array( 'status' => 500 )
			);
		}

		self::clear_takedown_record( (int) $post_id );
		update_post_meta( (int) $post_id, self::META_RESTORED_AT, time() );
		update_post_meta( (int) $post_id, self::META_RESTORED_BY, (int) $actor_id );

		return true;
	}

	/**
	 * Send the takedown report to every reviewer and the site admin email.
	 *
	 * @param WP_Post $post Post that entered the trash.
	 * @return bool Whether at least one message was accepted.
	 */
	private static function notify_reviewers( $post ) {
		$author = get_userdata( (int) $post->post_author );
		$vars   = array(
			'{judul_karya}'      => Penalis_Editorial_Notifications::safe_title( $post ),
			'{nama_penulis}'     => $author ? (string) $author->display_name : '',
			'{username_penulis}' => $author ? (string) $author->user_login : '',
			'{link_trash_admin}' => admin_url( 'admin.php?page=penalis-trashed' ),
		);

		$recipients = Penalis_Emails::submission_notification_recipients();
		$sent       = false;

		foreach ( $recipients as $email ) {
			if ( Penalis_Emails::send_template( $email, 'editorial_trashed', $vars, (int) $post->post_author, 'editorial_trashed' ) ) {
				$sent = true;
			}
		}

		return $sent;
	}

	/**
	 * Query arguments for recorded published articles in the trash.
	 *
	 * @param int $per_page Items to fetch.
	 * @return array<string,mixed>
	 */
	private static function query_args( $per_page ) {
		return array(
			'post_type'      => 'post',
			'post_status'    => array( 'trash' ),
			'posts_per_page' => (int) $per_page,
			'orderby'        => self::META_TRASHED_AT,
			'order'          => 'DESC',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => self::META_TRASHED_AT,
					'compare' => 'EXISTS',
					'type'    => 'NUMERIC',
				),
			),
		);
	}

	/**
	 * Remove the active takedown markers while keeping the restore record.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private static function clear_takedown_record( $post_id ) {
		delete_post_meta( $post_id, self::META_TRASHED_AT );
		delete_post_meta( $post_id, self::META_TRASHED_BY );
		delete_post_meta( $post_id, self::META_TRASHED_STATE );
	}
}
