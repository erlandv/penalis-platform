<?php
/**
 * Account status persistence and transitions.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralizes all account-status reads and writes.
 */
final class Penalis_Account_Status {

	public const META_STATUS            = '_penalis_account_status';
	public const META_ACCESS            = '_penalis_access_enabled';
	public const META_VERIFIED          = '_penalis_email_verified';
	public const META_REGISTERED_AT     = '_penalis_registered_at';
	public const META_APPROVED_AT       = '_penalis_approved_at';
	public const META_APPROVED_BY       = '_penalis_approved_by';
	public const META_LOG               = '_penalis_status_log';
	public const META_TOKEN_HASH        = '_penalis_verification_token_hash';
	public const META_TOKEN_EXPIRES     = '_penalis_verification_token_expires';
	public const META_TOKEN_CREATED     = '_penalis_verification_token_created';
	public const META_MAIL_FAILED       = '_penalis_last_email_failed_at';
	public const META_MAIL_CONTEXT      = '_penalis_last_email_failed_context';
	public const META_DISPLAY_NAME_MODE = '_penalis_display_name_mode';

	public const LOG_LIMIT = 20;

	/**
	 * Read the status for a user.
	 *
	 * @param int $user_id User ID.
	 * @return string Status or empty string for legacy accounts.
	 */
	public static function get_status( $user_id ) {
		$status = get_user_meta( (int) $user_id, self::META_STATUS, true );
		return is_string( $status ) ? $status : '';
	}

	/**
	 * Whether plugin access is explicitly enabled.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_access_enabled( $user_id ) {
		return '1' === (string) get_user_meta( (int) $user_id, self::META_ACCESS, true );
	}

	/**
	 * Public-name preference for a user.
	 *
	 * Accounts created before the preference existed keep the display name
	 * WordPress already stored, so the fallback is `custom`.
	 *
	 * @param int $user_id User ID.
	 * @return string One of the Penalis_Policy display name modes.
	 */
	public static function get_display_name_mode( $user_id ) {
		$mode = get_user_meta( (int) $user_id, self::META_DISPLAY_NAME_MODE, true );

		return Penalis_Policy::is_valid_display_mode( $mode ) ? (string) $mode : 'custom';
	}

	/**
	 * Whether a user is a plugin-managed account.
	 *
	 * Legacy accounts have neither a status nor an access flag.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_managed_account( $user_id ) {
		return '' !== self::get_status( $user_id )
			|| metadata_exists( 'user', (int) $user_id, self::META_ACCESS );
	}

	/**
	 * Whether the user may sign in through the plugin gate.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function can_login( $user_id ) {
		return Penalis_Policy::can_login( self::get_status( $user_id ), self::is_access_enabled( $user_id ) );
	}

	/**
	 * Whether the user may write content.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function can_write( $user_id ) {
		return Penalis_Policy::can_write( self::get_status( $user_id ), self::is_access_enabled( $user_id ) );
	}

	/**
	 * Whether the user may access the dashboard at all.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function can_view_dashboard( $user_id ) {
		return Penalis_Policy::can_view_dashboard( self::get_status( $user_id ) );
	}

	/**
	 * Record a newly registered account.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function initialize_pending( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return false;
		}

		update_user_meta( $user_id, self::META_STATUS, Penalis_Policy::STATUS_PENDING_VERIFICATION );
		update_user_meta( $user_id, self::META_ACCESS, '0' );
		update_user_meta( $user_id, self::META_VERIFIED, '0' );
		update_user_meta( $user_id, self::META_REGISTERED_AT, time() );
		self::append_log( $user_id, '', Penalis_Policy::STATUS_PENDING_VERIFICATION, 'registration' );

		return true;
	}

	/**
	 * Transition an account to a new status.
	 *
	 * @param int    $user_id User ID.
	 * @param string $to      Target status.
	 * @param string $reason  Reason for audit log.
	 * @param int    $actor   Acting user ID.
	 * @return true|WP_Error
	 */
	public static function transition( $user_id, $to, $reason = '', $actor = 0 ) {
		$user_id = (int) $user_id;
		$user    = get_userdata( $user_id );

		if ( ! $user ) {
			return new WP_Error( 'penalis_user_not_found', 'Akun tidak ditemukan.', array( 'status' => 404 ) );
		}

		$from = self::get_status( $user_id );

		if ( ! Penalis_Policy::can_transition( $from, $to ) ) {
			return new WP_Error(
				'penalis_invalid_transition',
				'Perubahan status akun tersebut tidak diizinkan.',
				array( 'status' => 409 )
			);
		}

		$previous_access = self::is_access_enabled( $user_id );

		// Approval must never silently promote an account that already holds a
		// higher role (editor, author, administrator). Those accounts require a
		// deliberate manual decision by a site administrator.
		if ( Penalis_Policy::STATUS_ACTIVE === $to ) {
			$roles = array_values( (array) $user->roles );
			if ( ! empty( $roles ) && array( 'subscriber' ) !== $roles && array( Penalis_Roles::ROLE ) !== $roles ) {
				return new WP_Error(
					'penalis_role_conflict',
					'Akun ini sudah memiliki peran yang tidak dapat disetujui melalui alur kerja penulis.',
					array( 'status' => 409 )
				);
			}
		}

		update_user_meta( $user_id, self::META_STATUS, $to );

		switch ( $to ) {
			case Penalis_Policy::STATUS_PENDING_APPROVAL:
				update_user_meta( $user_id, self::META_VERIFIED, '1' );
				update_user_meta( $user_id, self::META_ACCESS, '0' );
				self::clear_token( $user_id );
				break;

			case Penalis_Policy::STATUS_ACTIVE:
				update_user_meta( $user_id, self::META_VERIFIED, '1' );
				update_user_meta( $user_id, self::META_ACCESS, '1' );
				update_user_meta( $user_id, self::META_APPROVED_AT, time() );
				update_user_meta( $user_id, self::META_APPROVED_BY, (int) $actor );
				Penalis_Roles::assign_writer( $user_id );
				break;

			case Penalis_Policy::STATUS_REJECTED:
			case Penalis_Policy::STATUS_SUSPENDED:
				update_user_meta( $user_id, self::META_ACCESS, '0' );
				Penalis_Roles::revoke_writer( $user_id );
				// End existing sessions so a suspended/rejected account cannot
				// continue acting on an already-authenticated browser session.
				if ( class_exists( 'WP_Session_Tokens' ) ) {
					WP_Session_Tokens::get_instance( $user_id )->destroy_all();
				}
				break;

			case Penalis_Policy::STATUS_PENDING_VERIFICATION:
				update_user_meta( $user_id, self::META_VERIFIED, '0' );
				update_user_meta( $user_id, self::META_ACCESS, '0' );
				Penalis_Roles::revoke_writer( $user_id );
				break;
		}

		self::append_log( $user_id, $from, $to, $reason, (int) $actor );

		delete_transient( 'penalis_pending_approval_count' );

		/**
		 * Fires after an account status transition.
		 *
		 * @param int    $user_id  User ID.
		 * @param string $from     Previous status.
		 * @param string $to       New status.
		 * @param int    $actor    Acting user ID.
		 * @param bool   $previous Whether access was enabled previously.
		 */
		do_action( 'penalis_account_status_changed', $user_id, $from, $to, (int) $actor, $previous_access );

		return true;
	}

	/**
	 * Append a bounded audit log entry.
	 *
	 * @param int    $user_id User ID.
	 * @param string $from    Previous status.
	 * @param string $to      New status.
	 * @param string $reason  Reason.
	 * @param int    $actor   Acting user ID.
	 * @return void
	 */
	public static function append_log( $user_id, $from, $to, $reason = '', $actor = 0 ) {
		$log = get_user_meta( (int) $user_id, self::META_LOG, true );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$log[] = array(
			'time'   => time(),
			'from'   => (string) $from,
			'to'     => (string) $to,
			'reason' => sanitize_text_field( (string) $reason ),
			'actor'  => (int) $actor,
		);

		if ( count( $log ) > self::LOG_LIMIT ) {
			$log = array_slice( $log, -self::LOG_LIMIT );
		}

		update_user_meta( (int) $user_id, self::META_LOG, $log );
	}

	/**
	 * Read the audit log.
	 *
	 * @param int $user_id User ID.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_log( $user_id ) {
		$log = get_user_meta( (int) $user_id, self::META_LOG, true );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Issue a new verification token and store only its hash.
	 *
	 * @param int $user_id User ID.
	 * @return string|WP_Error Raw token.
	 */
	public static function issue_verification_token( $user_id ) {
		$user_id = (int) $user_id;
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'penalis_user_not_found', 'Akun tidak ditemukan.', array( 'status' => 404 ) );
		}

		$token   = Penalis_Policy::generate_token();
		$now     = time();
		$expires = $now + Penalis_Policy::TOKEN_TTL;

		update_user_meta( $user_id, self::META_TOKEN_HASH, Penalis_Policy::hash_token( $token ) );
		update_user_meta( $user_id, self::META_TOKEN_EXPIRES, $expires );
		update_user_meta( $user_id, self::META_TOKEN_CREATED, $now );

		return $token;
	}

	/**
	 * Find a user by a raw verification token.
	 *
	 * Uses a bounded indexed lookup on the token hash. Hash lookup is exact, so
	 * at most one account can match.
	 *
	 * @param string $token Raw token.
	 * @return int User ID or 0.
	 */
	public static function find_user_by_token( $token ) {
		if ( ! Penalis_Policy::is_token_format_valid( $token ) ) {
			return 0;
		}

		$hash = Penalis_Policy::hash_token( $token );

		$users = get_users(
			array(
				'fields'     => 'ID',
				'number'     => 1,
				'meta_key'   => self::META_TOKEN_HASH, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $hash, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		if ( empty( $users ) ) {
			return 0;
		}

		return (int) $users[0];
	}

	/**
	 * Clear stored verification token data.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function clear_token( $user_id ) {
		delete_user_meta( (int) $user_id, self::META_TOKEN_HASH );
		delete_user_meta( (int) $user_id, self::META_TOKEN_EXPIRES );
		delete_user_meta( (int) $user_id, self::META_TOKEN_CREATED );
	}

	/**
	 * Read token metadata.
	 *
	 * @param int $user_id User ID.
	 * @return array{expires:int,created:int}
	 */
	public static function get_token_meta( $user_id ) {
		return array(
			'expires' => (int) get_user_meta( (int) $user_id, self::META_TOKEN_EXPIRES, true ),
			'created' => (int) get_user_meta( (int) $user_id, self::META_TOKEN_CREATED, true ),
		);
	}

	/**
	 * Record a failed email delivery without advancing account state.
	 *
	 * @param int    $user_id User ID.
	 * @param string $context Context label.
	 * @return void
	 */
	public static function record_email_failure( $user_id, $context ) {
		update_user_meta( (int) $user_id, self::META_MAIL_FAILED, time() );
		update_user_meta( (int) $user_id, self::META_MAIL_CONTEXT, sanitize_key( (string) $context ) );
	}

	/**
	 * Whether an email failure was recorded recently.
	 *
	 * @param int $user_id User ID.
	 * @param int $window  Window in seconds.
	 * @return bool
	 */
	public static function has_recent_email_failure( $user_id, $window = 3600 ) {
		$failed = (int) get_user_meta( (int) $user_id, self::META_MAIL_FAILED, true );
		return $failed > 0 && ( time() - $failed ) <= (int) $window;
	}

	/**
	 * Count users by status using a paginated user query.
	 *
	 * @param string $status Status.
	 * @return int
	 */
	public static function count_by_status( $status ) {
		$query = new WP_User_Query(
			array(
				'fields'      => 'ID',
				'number'      => 1,
				'count_total' => true,
				'meta_key'    => self::META_STATUS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => $status, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return (int) $query->get_total();
	}
}
