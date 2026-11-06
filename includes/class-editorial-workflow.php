<?php
/**
 * Editorial workflow persistence and transitions.
 *
 * This is the only class allowed to write the V2 editorial state. It applies
 * the pure rules from Penalis_Editorial_Policy and performs the WordPress side
 * effects: post status changes, workflow metadata, and bounded review history.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies editorial workflow actions to articles.
 */
final class Penalis_Editorial_Workflow {

	/**
	 * Resolve the current editorial state of a post.
	 *
	 * Posts that predate the workflow have no metadata. Their state is inferred
	 * from the WordPress post status instead of being migrated on read, which
	 * keeps GET requests free of side effects and makes every legacy published
	 * article read-only to its writer immediately.
	 *
	 * The stored metadata is never trusted over `post_status`: a post whose meta
	 * claims `draft` while the site already shows it as `publish` is treated as
	 * published. That closes the window where stale or hand-edited metadata would
	 * let a writer keep editing a live article.
	 *
	 * @param WP_Post|int $post Post or post ID.
	 * @return string Editorial state.
	 */
	public static function get_state( $post ) {
		$post = self::resolve_post( $post );

		if ( ! $post ) {
			return '';
		}

		$state = get_post_meta( $post->ID, Penalis_Editorial_Policy::META_STATE, true );

		return Penalis_Editorial_Policy::effective_state( (string) $post->post_status, $state );
	}

	/**
	 * Current review cycle number.
	 *
	 * @param WP_Post|int $post Post or post ID.
	 * @return int
	 */
	public static function get_review_cycle( $post ) {
		$post = self::resolve_post( $post );

		if ( ! $post ) {
			return 0;
		}

		return max( 0, (int) get_post_meta( $post->ID, Penalis_Editorial_Policy::META_REVIEW_CYCLE, true ) );
	}

	/**
	 * Feedback attached to the latest decision, if any.
	 *
	 * @param WP_Post|int $post Post or post ID.
	 * @return string
	 */
	public static function get_feedback( $post ) {
		$post = self::resolve_post( $post );

		if ( ! $post ) {
			return '';
		}

		$feedback = get_post_meta( $post->ID, Penalis_Editorial_Policy::META_FEEDBACK, true );

		return is_string( $feedback ) ? $feedback : '';
	}

	/**
	 * Bounded review history.
	 *
	 * @param WP_Post|int $post Post or post ID.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_history( $post ) {
		$post = self::resolve_post( $post );

		if ( ! $post ) {
			return array();
		}

		$history = get_post_meta( $post->ID, Penalis_Editorial_Policy::META_REVIEW_HISTORY, true );

		return Penalis_Editorial_Policy::is_valid_history( $history ) ? (array) $history : array();
	}

	/**
	 * Submission timestamp.
	 *
	 * @param WP_Post|int $post Post or post ID.
	 * @return int
	 */
	public static function get_submitted_at( $post ) {
		$post = self::resolve_post( $post );

		return $post ? (int) get_post_meta( $post->ID, Penalis_Editorial_Policy::META_SUBMITTED_AT, true ) : 0;
	}

	/**
	 * Latest review timestamp.
	 *
	 * @param WP_Post|int $post Post or post ID.
	 * @return int
	 */
	public static function get_reviewed_at( $post ) {
		$post = self::resolve_post( $post );

		return $post ? (int) get_post_meta( $post->ID, Penalis_Editorial_Policy::META_REVIEWED_AT, true ) : 0;
	}

	/**
	 * Whether the given user may edit the post.
	 *
	 * Writers may edit only their own post while its editorial state allows it.
	 * Published and rejected articles are closed to the writer, which is also
	 * what makes a rejected submission impossible to resubmit: the only way
	 * back is an administrator reopening it.
	 *
	 * @param WP_Post|int $post    Post or post ID.
	 * @param int         $user_id User ID.
	 * @return bool
	 */
	public static function can_writer_edit( $post, $user_id ) {
		$post    = self::resolve_post( $post );
		$user_id = (int) $user_id;

		if ( ! $post || $user_id <= 0 ) {
			return false;
		}

		if ( (int) $post->post_author !== $user_id ) {
			return false;
		}

		if ( ! Penalis_Account_Status::can_write( $user_id ) ) {
			return false;
		}

		if ( ! Penalis_Editorial_Policy::writer_can_edit_post_status( (string) $post->post_status ) ) {
			return false;
		}

		return Penalis_Editorial_Policy::writer_can_edit_state( self::get_state( $post ) );
	}

	/**
	 * Apply a workflow action to a post.
	 *
	 * @param WP_Post|int $post Post or post ID.
	 * @param string      $action Workflow action.
	 * @param array       $args  {
	 *     Optional arguments.
	 *
	 *     @type int    $actor_id       Acting user ID.
	 *     @type string $feedback       Review feedback.
	 *     @type int    $expected_cycle Review cycle the actor saw.
	 * }
	 * @return array{state:string,status:string,cycle:int,history:array}|WP_Error
	 */
	public static function apply( $post, $action, $args = array() ) {
		$post = self::resolve_post( $post );

		if ( ! $post ) {
			return new WP_Error(
				'penalis_not_found',
				'Karya tulis tidak ditemukan.',
				array( 'status' => 404 )
			);
		}

		$action = (string) $action;
		$actor  = isset( $args['actor_id'] ) ? (int) $args['actor_id'] : get_current_user_id();

		if ( $actor <= 0 ) {
			return new WP_Error(
				'penalis_not_authenticated',
				'Kamu harus masuk terlebih dahulu.',
				array( 'status' => 401 )
			);
		}

		// The service is a public entry point: REST handlers, the admin screen
		// and any future caller reach the state machine through it. The actor
		// therefore has to satisfy the same account rules here, not only in the
		// caller, so a direct invocation cannot submit content for a suspended
		// or pending account.
		if ( Penalis_Editorial_Policy::is_writer_action( $action ) ) {
			if ( ! Penalis_Account_Status::can_write( $actor ) ) {
				return new WP_Error(
					'penalis_account_inactive',
					'Akun kamu saat ini tidak diizinkan mengirim karya tulis.',
					array( 'status' => 403 )
				);
			}

			if ( (int) $post->post_author !== $actor ) {
				// Same 404-style refusal as the REST layer so the existence of
				// another writer's article is never confirmed.
				return new WP_Error(
					'penalis_not_found',
					'Karya tulis tidak ditemukan.',
					array( 'status' => 404 )
				);
			}
		}

		$current_state = self::get_state( $post );
		$current_cycle = self::get_review_cycle( $post );

		$verdict = Penalis_Editorial_Policy::evaluate_action(
			array(
				'state'          => $current_state,
				'action'         => $action,
				'is_owner'       => (int) $post->post_author === $actor,
				'can_review'     => self::can_review( $actor ),
				'feedback'       => isset( $args['feedback'] ) ? $args['feedback'] : '',
				'expected_cycle' => isset( $args['expected_cycle'] ) ? $args['expected_cycle'] : 0,
				'current_cycle'  => $current_cycle,
			)
		);

		if ( ! $verdict['allowed'] ) {
			return new WP_Error(
				'penalis_editorial_denied',
				$verdict['error'],
				array( 'status' => 409 )
			);
		}

		$next_state  = $verdict['state'];
		$next_status = Penalis_Editorial_Policy::post_status_for_state( $next_state );

		if ( '' === $next_status ) {
			return new WP_Error(
				'penalis_editorial_denied',
				'Aksi ini tidak dapat dilakukan pada status karya tulis saat ini.',
				array( 'status' => 409 )
			);
		}

		// A submission starts a new round of review. Reviewer decisions stay in
		// the cycle they were made for, so a second administrator acting on the
		// same round is refused by the stale-cycle guard.
		$next_cycle = Penalis_Editorial_Policy::is_writer_action( $action )
			? Penalis_Editorial_Policy::next_review_cycle( $current_cycle )
			: $current_cycle;

		$feedback = isset( $args['feedback'] ) && is_string( $args['feedback'] )
			? trim( $args['feedback'] )
			: '';

		$updated = wp_update_post(
			array(
				'ID'          => (int) $post->ID,
				'post_status' => $next_status,
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			return new WP_Error(
				'penalis_editorial_failed',
				'Status karya tulis tidak dapat diperbarui.',
				array( 'status' => 500 )
			);
		}

		$now = time();

		update_post_meta( $post->ID, Penalis_Editorial_Policy::META_STATE, $next_state );
		update_post_meta( $post->ID, Penalis_Editorial_Policy::META_REVIEW_CYCLE, $next_cycle );

		if ( Penalis_Editorial_Policy::is_writer_action( $action ) ) {
			update_post_meta( $post->ID, Penalis_Editorial_Policy::META_SUBMITTED_AT, $now );
			update_post_meta( $post->ID, Penalis_Editorial_Policy::META_SUBMITTED_BY, $actor );
			// The previous round's notes have been addressed; clearing them
			// keeps the dashboard from showing stale instructions.
			delete_post_meta( $post->ID, Penalis_Editorial_Policy::META_FEEDBACK );
		}

		if ( Penalis_Editorial_Policy::is_reviewer_action( $action ) ) {
			update_post_meta( $post->ID, Penalis_Editorial_Policy::META_REVIEWED_AT, $now );
			update_post_meta( $post->ID, Penalis_Editorial_Policy::META_REVIEWED_BY, $actor );

			if ( '' !== $feedback ) {
				update_post_meta( $post->ID, Penalis_Editorial_Policy::META_FEEDBACK, $feedback );
			} else {
				// Approval carries no instructions, so nothing should remain for
				// the writer to act on.
				delete_post_meta( $post->ID, Penalis_Editorial_Policy::META_FEEDBACK );
			}
		}

		$history = Penalis_Editorial_Policy::append_history_entry(
			self::get_history( $post ),
			array(
				'action'       => $action,
				'actor_id'     => $actor,
				'created_at'   => $now,
				'review_cycle' => $next_cycle,
				'feedback'     => $feedback,
			)
		);

		update_post_meta( $post->ID, Penalis_Editorial_Policy::META_REVIEW_HISTORY, $history );

		/**
		 * Fires after an editorial workflow action has been applied.
		 *
		 * @param int    $post_id  Post ID.
		 * @param string $action   Workflow action.
		 * @param string $state    New editorial state.
		 * @param string $status   New WordPress post status.
		 * @param int    $actor    Acting user ID.
		 * @param string $feedback Review feedback.
		 */
		do_action( 'penalis_editorial_action', (int) $post->ID, $action, $next_state, $next_status, $actor, $feedback );

		return array(
			'state'   => $next_state,
			'status'  => $next_status,
			'cycle'   => $next_cycle,
			'history' => $history,
		);
	}

	/**
	 * Whether a user may review submissions.
	 *
	 * Administrators review; editors and writers do not. Requiring
	 * `edit_others_posts` as well keeps the rule explicit if the site ever adds
	 * a custom reviewer role.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function can_review( $user_id ) {
		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return false;
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return false;
		}

		return Penalis_Editorial_Policy::can_review(
			in_array( 'administrator', (array) $user->roles, true ),
			user_can( $user_id, 'edit_others_posts' )
		);
	}

	/**
	 * Normalize a post reference.
	 *
	 * @param WP_Post|int $post Post or post ID.
	 * @return WP_Post|null
	 */
	private static function resolve_post( $post ) {
		if ( $post instanceof WP_Post ) {
			return $post->ID > 0 ? $post : null;
		}

		$post_id = (int) $post;

		if ( $post_id <= 0 ) {
			return null;
		}

		$resolved = get_post( $post_id );

		return $resolved instanceof WP_Post ? $resolved : null;
	}
}
