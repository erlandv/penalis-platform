<?php
/**
 * Pure editorial workflow rules and data contracts.
 *
 * This class intentionally has no WordPress dependencies. Persistence and
 * side-effects belong to the future editorial workflow service.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralizes the V2 editorial state machine.
 */
final class Penalis_Editorial_Policy {

	public const STATE_DRAFT             = 'draft';
	public const STATE_PENDING_REVIEW    = 'pending_review';
	public const STATE_CHANGES_REQUESTED = 'changes_requested';
	public const STATE_REJECTED          = 'rejected';
	public const STATE_PUBLISHED         = 'published';

	public const ACTION_SUBMIT          = 'submit';
	public const ACTION_RESUBMIT        = 'resubmit';
	public const ACTION_REQUEST_CHANGES = 'request_changes';
	public const ACTION_REJECT          = 'reject';
	public const ACTION_REOPEN          = 'reopen';
	public const ACTION_APPROVE         = 'approve';

	public const META_STATE          = '_penalis_editorial_state';
	public const META_FEEDBACK       = '_penalis_review_feedback';
	public const META_SUBMITTED_AT   = '_penalis_submitted_at';
	public const META_SUBMITTED_BY   = '_penalis_submitted_by';
	public const META_REVIEWED_AT    = '_penalis_reviewed_at';
	public const META_REVIEWED_BY    = '_penalis_reviewed_by';
	public const META_REVIEW_CYCLE   = '_penalis_review_cycle';
	public const META_REVIEW_HISTORY = '_penalis_review_history';

	public const HISTORY_LIMIT = 50;

	/**
	 * Return all supported editorial states.
	 *
	 * @return string[]
	 */
	public static function states() {
		return array(
			self::STATE_DRAFT,
			self::STATE_PENDING_REVIEW,
			self::STATE_CHANGES_REQUESTED,
			self::STATE_REJECTED,
			self::STATE_PUBLISHED,
		);
	}

	/**
	 * Return all supported workflow actions.
	 *
	 * @return string[]
	 */
	public static function actions() {
		return array(
			self::ACTION_SUBMIT,
			self::ACTION_RESUBMIT,
			self::ACTION_REQUEST_CHANGES,
			self::ACTION_REJECT,
			self::ACTION_REOPEN,
			self::ACTION_APPROVE,
		);
	}

	/**
	 * Check whether a state is supported.
	 *
	 * @param mixed $state Editorial state.
	 * @return bool
	 */
	public static function is_valid_state( $state ) {
		return is_string( $state ) && in_array( $state, self::states(), true );
	}

	/**
	 * Check whether an action is supported.
	 *
	 * @param mixed $action Workflow action.
	 * @return bool
	 */
	public static function is_valid_action( $action ) {
		return is_string( $action ) && in_array( $action, self::actions(), true );
	}

	/**
	 * Return the state reached by an action, or an empty string if forbidden.
	 *
	 * Approval is deliberately the only action that reaches `published`.
	 *
	 * @param string $state  Current editorial state.
	 * @param string $action Workflow action.
	 * @return string
	 */
	public static function transition( $state, $action ) {
		$transitions = array(
			self::STATE_DRAFT             => array(
				self::ACTION_SUBMIT => self::STATE_PENDING_REVIEW,
			),
			self::STATE_PENDING_REVIEW    => array(
				self::ACTION_REQUEST_CHANGES => self::STATE_CHANGES_REQUESTED,
				self::ACTION_REJECT          => self::STATE_REJECTED,
				self::ACTION_APPROVE         => self::STATE_PUBLISHED,
			),
			self::STATE_CHANGES_REQUESTED => array(
				self::ACTION_RESUBMIT => self::STATE_PENDING_REVIEW,
			),
			self::STATE_REJECTED          => array(
				self::ACTION_REOPEN => self::STATE_CHANGES_REQUESTED,
			),
			self::STATE_PUBLISHED         => array(),
		);

		if ( ! self::is_valid_state( $state ) || ! self::is_valid_action( $action ) ) {
			return '';
		}

		return isset( $transitions[ $state ][ $action ] ) ? $transitions[ $state ][ $action ] : '';
	}

	/**
	 * Whether an action is valid for the current state.
	 *
	 * @param string $state  Current editorial state.
	 * @param string $action Workflow action.
	 * @return bool
	 */
	public static function can_transition( $state, $action ) {
		return '' !== self::transition( $state, $action );
	}

	/**
	 * Whether feedback is required for an action.
	 *
	 * @param string $action Workflow action.
	 * @return bool
	 */
	public static function requires_feedback( $action ) {
		return in_array(
			$action,
			array( self::ACTION_REQUEST_CHANGES, self::ACTION_REJECT ),
			true
		);
	}

	/**
	 * Whether the provided feedback satisfies the workflow requirement.
	 *
	 * @param string $action   Workflow action.
	 * @param mixed  $feedback Review feedback.
	 * @return bool
	 */
	public static function has_required_feedback( $action, $feedback ) {
		if ( ! self::requires_feedback( $action ) ) {
			return true;
		}

		return is_string( $feedback ) && '' !== trim( $feedback );
	}

	/**
	 * Whether a user may review submissions.
	 *
	 * The caller supplies resolved capabilities so this remains pure and can be
	 * reused by REST and wp-admin without coupling policy to a WP_User object.
	 *
	 * @param bool $is_administrator Whether the user is an administrator.
	 * @param bool $can_edit_others  Whether the user may edit others' posts.
	 * @return bool
	 */
	public static function can_review( $is_administrator, $can_edit_others ) {
		return (bool) $is_administrator && (bool) $can_edit_others;
	}

	/**
	 * Whether a writer may edit a post with the given WordPress status.
	 *
	 * This is an allow-list, not a deny-list. Writers work on `draft` only:
	 * `pending` is under review, `publish` is final, and `private` is not part of
	 * the workflow at all. Requesting changes moves the post back to `draft` so
	 * the writer can edit it again.
	 *
	 * @param string $post_status WordPress post status.
	 * @return bool
	 */
	public static function writer_can_edit_post_status( $post_status ) {
		return is_string( $post_status ) && 'draft' === $post_status;
	}

	/**
	 * Whether an action is performed by the writer who owns the article.
	 *
	 * @param string $action Workflow action.
	 * @return bool
	 */
	public static function is_writer_action( $action ) {
		return in_array(
			$action,
			array( self::ACTION_SUBMIT, self::ACTION_RESUBMIT ),
			true
		);
	}

	/**
	 * Whether an action is performed by an administrator reviewer.
	 *
	 * @param string $action Workflow action.
	 * @return bool
	 */
	public static function is_reviewer_action( $action ) {
		return in_array(
			$action,
			array(
				self::ACTION_REQUEST_CHANGES,
				self::ACTION_REJECT,
				self::ACTION_REOPEN,
				self::ACTION_APPROVE,
			),
			true
		);
	}

	/**
	 * Map an editorial state to the WordPress post status it requires.
	 *
	 * `changes_requested` and `rejected` both return the post to `draft`: the
	 * article must never be publicly visible while it is not approved, and the
	 * writer needs an editable post after requested changes. `published` is the
	 * only state that maps to `publish`.
	 *
	 * @param string $state Editorial state.
	 * @return string WordPress post status, or an empty string when unknown.
	 */
	public static function post_status_for_state( $state ) {
		$map = array(
			self::STATE_DRAFT             => 'draft',
			self::STATE_PENDING_REVIEW    => 'pending',
			self::STATE_CHANGES_REQUESTED => 'draft',
			self::STATE_REJECTED          => 'draft',
			self::STATE_PUBLISHED         => 'publish',
		);

		return isset( $map[ $state ] ) ? $map[ $state ] : '';
	}

	/**
	 * Whether a writer may edit an article in the given editorial state.
	 *
	 * Only `draft` and `changes_requested` are editable. `pending_review` is
	 * locked while an administrator reads it, `rejected` needs an administrator
	 * to reopen it first, and `published` is final.
	 *
	 * @param string $state Editorial state.
	 * @return bool
	 */
	public static function writer_can_edit_state( $state ) {
		return in_array(
			$state,
			array( self::STATE_DRAFT, self::STATE_CHANGES_REQUESTED ),
			true
		);
	}

	/**
	 * Whether an action resolves an active review and must carry a cycle.
	 *
	 * Approval, requested changes and rejection all answer a submission that is
	 * currently under review, so they must match the cycle the reviewer saw.
	 * Reopening a rejected submission is not a review decision (nothing is under
	 * review at that point), and submission actions start a new cycle, so neither
	 * carries one.
	 *
	 * @param string $action Workflow action.
	 * @return bool
	 */
	public static function requires_review_cycle( $action ) {
		return in_array(
			$action,
			array(
				self::ACTION_REQUEST_CHANGES,
				self::ACTION_REJECT,
				self::ACTION_APPROVE,
			),
			true
		);
	}

	/**
	 * Compute the next review cycle number.
	 *
	 * Every submission starts a new cycle so two administrators cannot apply
	 * conflicting decisions to the same round of review.
	 *
	 * @param mixed $current Current cycle value.
	 * @return int
	 */
	public static function next_review_cycle( $current ) {
		$current = (int) $current;

		return $current > 0 ? $current + 1 : 1;
	}

	/**
	 * Whether a reviewer decision targets an outdated review cycle.
	 *
	 * Two administrators can open the same submission at once. The decision
	 * carries the cycle the reviewer saw; if the stored cycle has moved on, the
	 * decision is stale and must be rejected instead of overwriting a newer
	 * round of review.
	 *
	 * A missing expected cycle is treated as stale: the caller must always send
	 * the cycle it reviewed.
	 *
	 * @param mixed $expected_cycle Cycle the reviewer saw.
	 * @param mixed $current_cycle  Cycle currently stored.
	 * @return bool
	 */
	public static function is_stale_decision( $expected_cycle, $current_cycle ) {
		$expected = (int) $expected_cycle;
		$current  = (int) $current_cycle;

		if ( $expected <= 0 || $current <= 0 ) {
			return true;
		}

		return $expected !== $current;
	}

	/**
	 * Reconcile stored editorial metadata with the WordPress post status.
	 *
	 * `post_status` stays the public-visibility authority, so it wins over stale
	 * or tampered metadata. Without this rule a post whose meta still said
	 * `draft` could be moved to `pending` while it is already live, or a reviewer
	 * could act on metadata claiming a round of review that no longer matches
	 * what the site is showing. Visibility states are derived from the post
	 * status; only the two draft-rooted states (`changes_requested`, `rejected`)
	 * keep their stored value, because both map to the same post status and the
	 * distinction only exists in metadata.
	 *
	 * @param string $post_status  WordPress post status.
	 * @param mixed  $stored_state Stored editorial state, if any.
	 * @return string Effective editorial state.
	 */
	public static function effective_state( $post_status, $stored_state ) {
		if ( 'publish' === $post_status ) {
			return self::STATE_PUBLISHED;
		}

		if ( 'pending' === $post_status ) {
			return self::STATE_PENDING_REVIEW;
		}

		if (
			is_string( $stored_state )
			&& self::is_valid_state( $stored_state )
			&& self::STATE_PUBLISHED !== $stored_state
			&& self::STATE_PENDING_REVIEW !== $stored_state
		) {
			return $stored_state;
		}

		return self::STATE_DRAFT;
	}

	/**
	 * Derive the editorial state for a post that has no workflow metadata yet.
	 *
	 * Existing posts predate V2, so their state has to be inferred from the
	 * WordPress post status instead of being written by a migration. A published
	 * legacy post becomes `published` (and therefore read-only to its writer); a
	 * pending one becomes `pending_review`; everything else is a draft.
	 *
	 * @param string $post_status WordPress post status.
	 * @return string
	 */
	public static function initial_state_for_post_status( $post_status ) {
		if ( 'publish' === $post_status ) {
			return self::STATE_PUBLISHED;
		}

		if ( 'pending' === $post_status ) {
			return self::STATE_PENDING_REVIEW;
		}

		return self::STATE_DRAFT;
	}

	/**
	 * Append an entry to a bounded review history.
	 *
	 * The newest entry is kept last and the oldest entries are dropped once the
	 * limit is reached, so post meta cannot grow without bound.
	 *
	 * @param mixed $history Existing history.
	 * @param array $entry   New entry.
	 * @return array
	 */
	public static function append_history_entry( $history, $entry ) {
		$history   = is_array( $history ) ? array_values( $history ) : array();
		$history[] = $entry;

		if ( count( $history ) > self::HISTORY_LIMIT ) {
			$history = array_slice( $history, -self::HISTORY_LIMIT );
		}

		return $history;
	}

	/**
	 * Evaluate whether an action may be applied to a submission.
	 *
	 * This is the single guard the REST handlers and the admin screen should
	 * call, so the state machine, the actor rule, the feedback rule and the
	 * stale-cycle rule cannot drift apart between entry points.
	 *
	 * @param array $context {
	 *     Action context.
	 *
	 *     @type string $state          Current editorial state.
	 *     @type string $action         Workflow action.
	 *     @type bool   $is_owner       Whether the actor owns the article.
	 *     @type bool   $can_review     Whether the actor may review submissions.
	 *     @type string $feedback       Review feedback.
	 *     @type int    $expected_cycle Cycle the actor saw.
	 *     @type int    $current_cycle  Cycle currently stored.
	 * }
	 * @return array{allowed:bool,state:string,error:string}
	 */
	public static function evaluate_action( $context ) {
		$state          = isset( $context['state'] ) ? (string) $context['state'] : '';
		$action         = isset( $context['action'] ) ? (string) $context['action'] : '';
		$is_owner       = ! empty( $context['is_owner'] );
		$can_review     = ! empty( $context['can_review'] );
		$feedback       = isset( $context['feedback'] ) ? $context['feedback'] : '';
		$expected_cycle = isset( $context['expected_cycle'] ) ? $context['expected_cycle'] : 0;
		$current_cycle  = isset( $context['current_cycle'] ) ? $context['current_cycle'] : 0;

		$denied = array(
			'allowed' => false,
			'state'   => '',
			'error'   => '',
		);

		if ( ! self::is_valid_action( $action ) ) {
			$denied['error'] = 'Aksi tinjauan tidak dikenal.';
			return $denied;
		}

		if ( self::is_writer_action( $action ) && ! $is_owner ) {
			$denied['error'] = 'Kamu hanya dapat mengajukan karya tulis milikmu sendiri.';
			return $denied;
		}

		if ( self::is_reviewer_action( $action ) && ! $can_review ) {
			$denied['error'] = 'Kamu tidak diizinkan meninjau karya tulis ini.';
			return $denied;
		}

		$next = self::transition( $state, $action );

		if ( '' === $next ) {
			$denied['error'] = 'Aksi ini tidak dapat dilakukan pada status karya tulis saat ini.';
			return $denied;
		}

		if ( ! self::has_required_feedback( $action, $feedback ) ) {
			$denied['error'] = 'Tuliskan catatan untuk penulis sebelum melanjutkan.';
			return $denied;
		}

		if ( self::requires_review_cycle( $action ) && self::is_stale_decision( $expected_cycle, $current_cycle ) ) {
			$denied['error'] = 'Karya tulis ini sudah diproses pada siklus tinjauan yang lebih baru. Muat ulang halaman.';
			return $denied;
		}

		return array(
			'allowed' => true,
			'state'   => $next,
			'error'   => '',
		);
	}

	/**
	 * Human-readable label for an editorial state.
	 *
	 * @param string $state Editorial state.
	 * @return string
	 */
	public static function state_label( $state ) {
		$labels = array(
			self::STATE_DRAFT             => 'Draf',
			self::STATE_PENDING_REVIEW    => 'Menunggu tinjauan',
			self::STATE_CHANGES_REQUESTED => 'Perlu revisi',
			self::STATE_REJECTED          => 'Ditolak',
			self::STATE_PUBLISHED         => 'Terbit',
		);

		return isset( $labels[ $state ] ) ? $labels[ $state ] : 'Tidak diketahui';
	}

	/**
	 * Human-readable label for a workflow action.
	 *
	 * @param string $action Workflow action.
	 * @return string
	 */
	public static function action_label( $action ) {
		$labels = array(
			self::ACTION_SUBMIT          => 'Diajukan penulis',
			self::ACTION_RESUBMIT        => 'Diajukan ulang penulis',
			self::ACTION_REQUEST_CHANGES => 'Diminta revisi',
			self::ACTION_REJECT          => 'Ditolak',
			self::ACTION_REOPEN          => 'Dibuka kembali',
			self::ACTION_APPROVE         => 'Disetujui dan diterbitkan',
		);

		return isset( $labels[ $action ] ) ? $labels[ $action ] : $action;
	}

	/**
	 * Return the metadata keys owned by this workflow.
	 *
	 * @return string[]
	 */
	public static function metadata_keys() {
		return array(
			self::META_STATE,
			self::META_FEEDBACK,
			self::META_SUBMITTED_AT,
			self::META_SUBMITTED_BY,
			self::META_REVIEWED_AT,
			self::META_REVIEWED_BY,
			self::META_REVIEW_CYCLE,
			self::META_REVIEW_HISTORY,
		);
	}

	/**
	 * Validate the shape of one bounded review-history entry.
	 *
	 * @param mixed $entry History entry.
	 * @return bool
	 */
	public static function is_valid_history_entry( $entry ) {
		if ( ! is_array( $entry ) ) {
			return false;
		}

		$required = array( 'action', 'actor_id', 'created_at', 'review_cycle' );
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $entry ) ) {
				return false;
			}
		}

		return self::is_valid_action( $entry['action'] )
			&& (int) $entry['actor_id'] > 0
			&& (int) $entry['created_at'] > 0
			&& (int) $entry['review_cycle'] > 0
			&& ( ! array_key_exists( 'feedback', $entry ) || is_string( $entry['feedback'] ) );
	}

	/**
	 * Validate a bounded history list.
	 *
	 * @param mixed $history Review history.
	 * @return bool
	 */
	public static function is_valid_history( $history ) {
		if ( ! is_array( $history ) || count( $history ) > self::HISTORY_LIMIT ) {
			return false;
		}

		foreach ( $history as $entry ) {
			if ( ! self::is_valid_history_entry( $entry ) ) {
				return false;
			}
		}

		return true;
	}
}
