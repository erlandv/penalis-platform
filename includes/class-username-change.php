<?php
/**
 * Re-authenticated username changes.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Changes the authenticated account's login and public author slug.
 *
 * WordPress has no API for changing `user_login`: `wp_update_user()` silently
 * ignores the field, so the only route is a direct users-table update. That is
 * why this class exists instead of a call to core.
 *
 * Two invariants drive the design:
 *
 * - The new `user_nicename` follows the login. Before changing it, the old
 *   author slug is reserved and recorded for a permanent redirect, preserving
 *   existing author links. Login-only identifiers are reserved without
 *   redirecting.
 * - The new login is validated, never sanitized. A value that only becomes
 *   valid after `sanitize_user()` is rejected, because silently rewriting it
 *   would hand the writer a login they did not type and cannot reproduce.
 */
final class Penalis_Username_Change {

	/**
	 * Change only the authenticated account's login.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $username New login.
	 * @param string $password Current password, used as re-authentication.
	 * @return array|WP_Error
	 */
	public static function change( $user_id, $username, $password ) {
		global $wpdb;

		$user_id = (int) $user_id;
		$user    = get_userdata( $user_id );

		if ( ! $user ) {
			return new WP_Error( 'penalis_not_authenticated', 'Kamu harus login terlebih dahulu.', array( 'status' => 401 ) );
		}

		if ( ! self::can_change( $user_id ) ) {
			return new WP_Error(
				'penalis_username_forbidden',
				'Username akun ini belum dapat diubah. Silakan hubungi admin Penalis.',
				array( 'status' => 403 )
			);
		}

		$ip = Penalis_Rate_Limiter::client_ip();

		if ( ! Penalis_Rate_Limiter::is_allowed( 'username_change_ip', 5, HOUR_IN_SECONDS, $ip ) ) {
			return self::rate_limited();
		}

		if ( ! Penalis_Rate_Limiter::is_allowed( 'username_change_user', 3, HOUR_IN_SECONDS, (string) $user_id ) ) {
			return self::rate_limited();
		}

		Penalis_Rate_Limiter::hit( 'username_change_ip', HOUR_IN_SECONDS, $ip );
		Penalis_Rate_Limiter::hit( 'username_change_user', HOUR_IN_SECONDS, (string) $user_id );

		// Re-authentication: a hijacked session must not be able to rename the
		// account and lock the real owner out of their own login.
		if ( ! wp_check_password( (string) $password, $user->user_pass, $user_id ) ) {
			return new WP_Error(
				'penalis_password_invalid',
				'Password saat ini salah.',
				array( 'status' => 400 )
			);
		}

		$username = (string) $username;
		$error    = Penalis_Policy::validate_username_change( $username, (string) $user->user_login );

		if ( '' !== $error ) {
			return new WP_Error(
				'penalis_username_invalid',
				$error,
				array(
					'status' => 400,
					'fields' => array( 'new_username' => $error ),
				)
			);
		}

		if ( self::is_reserved( $username ) ) {
			return self::unavailable();
		}

		// WordPress has no unique index on user_login, so two concurrent
		// requests could both pass the availability check and both write. A
		// users-table-scoped advisory lock serializes this endpoint's check and
		// update. It is best effort: native registration, other plugins and
		// direct SQL do not take it. Fail closed when the database cannot
		// provide it rather than silently dropping the protection.
		$lock = 'penalis_login_' . md5( (string) $wpdb->users );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory lock is the serialization primitive; it is not cacheable.
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			return self::unavailable();
		}

		try {
			// Read the authoritative row inside the lock. A cached lookup could
			// miss a row another connection committed a moment ago.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must read the authoritative row while holding the lock.
			$existing = Penalis_Author_History::unavailable( $username, $user_id );

			if ( $existing ) {
				return self::unavailable();
			}

			$old_login = (string) $user->user_login;
			$old_slug  = (string) $user->user_nicename;

			// The old slug only needs a redirect when it is actually going
			// away. Reserving the slug we are about to take would block the
			// next writer for no reason.
			$remembered = $old_slug === $username || Penalis_Author_History::remember( $user_id, $old_slug, true );

			if ( ! $remembered ||
				( $old_login !== $old_slug && $old_login !== $username && ! Penalis_Author_History::remember( $user_id, $old_login, false ) ) ) {
				return self::unavailable();
			}
			$data = array(
				'user_login'    => $username,
				'user_nicename' => $username,
			);

			// Only a public name derived from the login follows it. A custom pen
			// name, or a first/last name composition, must not change.
			if ( 'username' === Penalis_Account_Status::get_display_name_mode( $user_id ) ) {
				$data['display_name'] = $username;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core has no API for changing user_login.
			$updated = $wpdb->update(
				$wpdb->users,
				$data,
				array(
					'ID'         => $user_id,
					'user_login' => $old_login,
				)
			);

			if ( false === $updated ) {
				return self::unavailable();
			}

			// A case-only change is a no-op for a case-insensitive collation, so
			// zero affected rows is not proof of failure. Re-read the row.
			if ( 0 === (int) $updated && ! self::login_is( $user_id, $username ) ) {
				return self::unavailable();
			}

			self::flush_caches( $user, $old_login, $username );

			// The login is a credential: every session must be re-established
			// with the new one, including the browser that made the change.
			WP_Session_Tokens::get_instance( $user_id )->destroy_all();

			if ( get_current_user_id() === $user_id ) {
				wp_clear_auth_cookie();
			}

			Penalis_Emails::send_username_change_notice( $user_id, $user->user_email, $old_login, $username );

			return array(
				'message' => 'Username kamu telah diperbarui. Semua sesi diakhiri; login kembali dengan username baru.',
			);
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the advisory lock taken above.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Whether the account may change its login.
	 *
	 * Administrators keep native WordPress behaviour; the writer gate does not
	 * apply to them. Everyone else follows the same rule as an email change.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	private static function can_change( $user_id ) {
		if ( user_can( (int) $user_id, 'manage_options' ) ) {
			return true;
		}

		return Penalis_Policy::can_change_username(
			Penalis_Account_Status::get_status( $user_id ),
			Penalis_Account_Status::is_access_enabled( $user_id )
		);
	}

	/**
	 * Whether a login is blocked by the `illegal_user_logins` filter.
	 *
	 * Core only applies that filter on insert, so a rename would otherwise be
	 * able to take a name the site explicitly reserved.
	 *
	 * @param string $username Candidate login.
	 * @return bool
	 */
	private static function is_reserved( $username ) {
		// Core's own reserved-login filter. It is read here because core only
		// applies it on insert, and a rename is an insert into the same
		// namespace.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, not a plugin hook.
		$reserved = apply_filters( 'illegal_user_logins', array() );

		if ( ! is_array( $reserved ) ) {
			return false;
		}

		foreach ( $reserved as $entry ) {
			if ( 0 === strcasecmp( (string) $entry, $username ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Read the stored login for a user, bypassing the object cache.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $username Expected login.
	 * @return bool
	 */
	private static function login_is( $user_id, $username ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verifies the write that just happened; a cached read could be stale.
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT user_login FROM {$wpdb->users} WHERE ID = %d LIMIT 1", $user_id ) );

		return is_string( $current ) && $current === $username;
	}

	/**
	 * Drop every cache entry that still points at the old login.
	 *
	 * `clean_user_cache()` clears the ID-keyed groups and the login key of the
	 * object it is given, which is the stale one. The new login key is cleared
	 * explicitly so a negative lookup cached during the availability check
	 * cannot survive the write.
	 *
	 * @param WP_User $user      User object, still holding the old login.
	 * @param string  $old_login Previous login.
	 * @param string  $new_login New login.
	 * @return void
	 */
	private static function flush_caches( $user, $old_login, $new_login ) {
		clean_user_cache( $user );
		wp_cache_delete( $old_login, 'userlogins' );
		wp_cache_delete( $new_login, 'userlogins' );
		wp_cache_delete( $user->user_nicename, 'userslugs' );
		wp_cache_delete( $new_login, 'userslugs' );
	}

	/**
	 * Generic throttle response.
	 *
	 * @return WP_Error
	 */
	private static function rate_limited() {
		return new WP_Error(
			'penalis_rate_limited',
			'Terlalu banyak percobaan. Silakan coba lagi nanti.',
			array( 'status' => 429 )
		);
	}

	/**
	 * Keep duplicate, reserved and lock-failure errors indistinguishable.
	 *
	 * @return WP_Error
	 */
	private static function unavailable() {
		return new WP_Error(
			'penalis_username_unavailable',
			'Username tersebut tidak dapat digunakan. Pilih username lain atau coba lagi nanti.',
			array( 'status' => 400 )
		);
	}
}
