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
	public const META_MAIL_FAILED       = '_penalis_last_email_failed_at';
	public const META_MAIL_CONTEXT      = '_penalis_last_email_failed_context';
	public const META_DISPLAY_NAME_MODE = '_penalis_display_name_mode';
	public const META_NEEDS_PASSWORD    = '_penalis_needs_password';
	public const META_INSTAGRAM         = '_penalis_instagram';

	public const META_EMAIL_CHANGE_NEW = '_penalis_email_change_new_email';

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
	 * Read the normalized Instagram username for a user.
	 *
	 * @param int $user_id User ID.
	 * @return string Instagram handle without `@`, or empty string.
	 */
	public static function get_instagram( $user_id ) {
		$value = get_user_meta( (int) $user_id, self::META_INSTAGRAM, true );

		return is_string( $value ) ? Penalis_Policy::normalize_instagram( $value ) : '';
	}

	/**
	 * Store or clear the Instagram username for a user.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $instagram Raw or normalized Instagram value.
	 * @return void
	 */
	public static function set_instagram( $user_id, $instagram ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return;
		}

		$handle = Penalis_Policy::normalize_instagram( (string) $instagram );

		if ( '' === $handle ) {
			delete_user_meta( $user_id, self::META_INSTAGRAM );
			return;
		}

		update_user_meta( $user_id, self::META_INSTAGRAM, sanitize_text_field( $handle ) );
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
	 * Whether a user is a legacy contributor profile.
	 *
	 * These accounts were created by an administrator before this plugin
	 * existed. They hold the `contributor` role, have no Penalis metadata, and
	 * were never given a password, so they cannot sign in.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_legacy_account( $user_id ) {
		$user_id = (int) $user_id;

		if ( $user_id <= 0 || self::is_managed_account( $user_id ) ) {
			return false;
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return false;
		}

		return in_array( 'contributor', (array) $user->roles, true );
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
	 * Whether the account still needs its owner to set a password.
	 *
	 * Legacy accounts converted by an administrator ("vouched") skip the email
	 * step, so they still have no password. The dashboard uses this flag to ask
	 * the owner to set one.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function needs_password( $user_id ) {
		return '1' === (string) get_user_meta( (int) $user_id, self::META_NEEDS_PASSWORD, true );
	}

	/**
	 * Clear the "needs password" flag after a password is set.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function clear_needs_password( $user_id ) {
		delete_user_meta( (int) $user_id, self::META_NEEDS_PASSWORD );
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
		// deliberate manual decision by a site administrator. Legacy
		// contributors are allowed: they are promoted to the writer role.
		if ( Penalis_Policy::STATUS_ACTIVE === $to ) {
			if ( ! Penalis_Policy::can_promote_roles( (array) $user->roles ) ) {
				return new WP_Error(
					'penalis_role_conflict',
					'Akun ini sudah memiliki peran yang tidak dapat disetujui melalui alur kerja penulis.',
					array( 'status' => 409 )
				);
			}
		}

		update_user_meta( $user_id, self::META_STATUS, $to );

		switch ( $to ) {
			case Penalis_Policy::STATUS_ACTIVE:
				update_user_meta( $user_id, self::META_VERIFIED, '1' );
				update_user_meta( $user_id, self::META_ACCESS, '1' );
				update_user_meta( $user_id, self::META_APPROVED_AT, time() );
				update_user_meta( $user_id, self::META_APPROVED_BY, (int) $actor );
				Penalis_Roles::assign_writer( $user_id );
				break;

			case Penalis_Policy::STATUS_SUSPENDED:
				update_user_meta( $user_id, self::META_ACCESS, '0' );
				Penalis_Roles::revoke_writer( $user_id );
				// End existing sessions so a suspended account cannot continue
				// acting on an already-authenticated browser session.
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
	 * Convert a legacy contributor profile into a managed account.
	 *
	 * Legacy accounts have no status metadata, so `transition()` cannot be used
	 * (an empty status is not a valid source state). This method performs the
	 * same bookkeeping for the supported entry points.
	 *
	 * The user ID never changes, so existing posts, author archives, and
	 * metadata stay attached to the same account. Posts are never modified.
	 *
	 * @param int    $user_id        User ID.
	 * @param string $to             Target status: pending_verification or active.
	 * @param string $reason         Reason for the audit log.
	 * @param int    $actor          Acting user ID.
	 * @param bool   $needs_password Whether the owner still has to set a password.
	 *                               Only the administrator "vouch" path passes
	 *                               true: it skips the email step, so the owner
	 *                               never chose a password. Self-service
	 *                               activation sets one before converting and
	 *                               must pass false.
	 * @return true|WP_Error
	 */
	public static function convert_legacy( $user_id, $to, $reason = 'legacy_converted', $actor = 0, $needs_password = false ) {
		$user_id = (int) $user_id;

		if ( ! self::is_legacy_account( $user_id ) ) {
			return new WP_Error(
				'penalis_not_legacy',
				'Akun ini bukan akun kontributor lama yang dapat diaktifkan.',
				array( 'status' => 409 )
			);
		}

		if ( ! Penalis_Policy::can_convert_legacy( $to ) ) {
			return new WP_Error(
				'penalis_invalid_transition',
				'Perubahan status akun tersebut tidak diizinkan.',
				array( 'status' => 409 )
			);
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'penalis_user_not_found', 'Akun tidak ditemukan.', array( 'status' => 404 ) );
		}

		if ( Penalis_Policy::STATUS_ACTIVE === $to ) {
			if ( ! Penalis_Policy::can_promote_roles( (array) $user->roles ) ) {
				return new WP_Error(
					'penalis_role_conflict',
					'Akun ini sudah memiliki peran yang tidak dapat disetujui melalui alur kerja penulis.',
					array( 'status' => 409 )
				);
			}

			update_user_meta( $user_id, self::META_STATUS, $to );
			update_user_meta( $user_id, self::META_VERIFIED, '1' );
			update_user_meta( $user_id, self::META_ACCESS, '1' );
			update_user_meta( $user_id, self::META_APPROVED_AT, time() );
			update_user_meta( $user_id, self::META_APPROVED_BY, (int) $actor );
			Penalis_Roles::assign_writer( $user_id );
		} else {
			update_user_meta( $user_id, self::META_STATUS, $to );
			update_user_meta( $user_id, self::META_ACCESS, '0' );
			update_user_meta( $user_id, self::META_VERIFIED, '0' );
		}

		// The flag is driven by the caller, not by the target status. A
		// self-service activation has already set a password by this point, so
		// setting the flag here would wrongly tell the owner they have none.
		if ( $needs_password ) {
			update_user_meta( $user_id, self::META_NEEDS_PASSWORD, '1' );
		} else {
			delete_user_meta( $user_id, self::META_NEEDS_PASSWORD );
		}

		if ( '' === (string) get_user_meta( $user_id, self::META_REGISTERED_AT, true ) ) {
			$registered = ! empty( $user->user_registered )
				? (int) strtotime( $user->user_registered . ' UTC' )
				: time();
			update_user_meta( $user_id, self::META_REGISTERED_AT, $registered );
		}

		self::append_log( $user_id, '', $to, $reason, (int) $actor );

		/**
		 * Fires after a legacy account is converted.
		 *
		 * @param int    $user_id User ID.
		 * @param string $to      New status.
		 * @param int    $actor   Acting user ID.
		 */
		do_action( 'penalis_legacy_converted', $user_id, $to, (int) $actor );

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
	 * Store the candidate address for a pending email change.
	 *
	 * The address is only a display hint until the OTP challenge is consumed;
	 * the challenge itself is the authority.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $new_email Requested address.
	 * @return bool
	 */
	public static function set_pending_email( $user_id, $new_email ) {
		return update_user_meta( (int) $user_id, self::META_EMAIL_CHANGE_NEW, sanitize_email( (string) $new_email ) );
	}

	/**
	 * Read the pending email change for a user.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function get_pending_email( $user_id ) {
		return (string) get_user_meta( (int) $user_id, self::META_EMAIL_CHANGE_NEW, true );
	}

	/**
	 * Discard a pending email change.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function clear_email_change( $user_id ) {
		delete_user_meta( (int) $user_id, self::META_EMAIL_CHANGE_NEW );
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
