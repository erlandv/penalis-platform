<?php
/**
 * Self-service password reset for the dashboard.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles password reset without leaving the dashboard.
 *
 * WordPress core's `wp-login.php?action=lostpassword` remains available as a
 * fallback. This class mirrors its security properties while keeping the
 * writer inside the dashboard:
 *
 * - The request endpoint always answers generically, so it cannot be used to
 *   enumerate accounts.
 * - The request endpoint accepts an email address only. Usernames are public
 *   (they appear in author archives), so accepting them would let anyone turn
 *   a public profile into a reset attempt against that account.
 * - Reset keys come from `get_password_reset_key()`, which stores only a hash
 *   in `user_activation_key`.
 * - The key is consumed only after the visitor submits a new password, so
 *   email link scanners cannot burn it.
 * - `allow_password_reset` is honoured, so suspended, rejected, and dormant
 *   legacy accounts cannot reset their way back in.
 */
final class Penalis_Password_Reset {

	/**
	 * Response used for every request outcome.
	 *
	 * The wording never confirms whether an address is registered.
	 */
	public const GENERIC_MESSAGE = 'Jika alamat email tersebut terdaftar, kami telah mengirim link untuk reset password akun Penalis kamu.';

	/**
	 * Request a reset link.
	 *
	 * @param array $data Raw request data.
	 * @return array|WP_Error
	 */
	public static function request( $data ) {
		$ip = Penalis_Rate_Limiter::client_ip();

		if ( ! Penalis_Rate_Limiter::is_allowed( 'password_forgot_ip', 5, HOUR_IN_SECONDS, $ip ) ) {
			return new WP_Error(
				'penalis_rate_limited',
				'Terlalu banyak permintaan. Silakan coba lagi nanti.',
				array( 'status' => 429 )
			);
		}

		Penalis_Rate_Limiter::hit( 'password_forgot_ip', HOUR_IN_SECONDS, $ip );

		// A filled honeypot is a silent no-op so bots cannot distinguish a
		// rejected request from an accepted one.
		if ( ! empty( $data['penalis_website'] ) ) {
			return array( 'message' => self::GENERIC_MESSAGE );
		}

		$email = isset( $data['user_email'] ) ? trim( (string) $data['user_email'] ) : '';

		// Only a well-formed address is looked up. A username is rejected here
		// rather than resolved, because usernames are public on author archives
		// and must not be usable as a reset handle.
		if ( '' === $email || strlen( $email ) > 254 || ! is_email( $email ) ) {
			return array( 'message' => self::GENERIC_MESSAGE );
		}

		$user = get_user_by( 'email', $email );

		if ( ! $user ) {
			return array( 'message' => self::GENERIC_MESSAGE );
		}

		// Per-account throttle: stops this endpoint from being used to
		// mail-bomb a single address. The subject is hashed by the limiter.
		if ( ! Penalis_Rate_Limiter::is_allowed( 'password_forgot_account', 3, HOUR_IN_SECONDS, $user->user_email ) ) {
			return array( 'message' => self::GENERIC_MESSAGE );
		}

		Penalis_Rate_Limiter::hit( 'password_forgot_account', HOUR_IN_SECONDS, $user->user_email );

		if ( ! self::is_reset_allowed( $user ) ) {
			return array( 'message' => self::GENERIC_MESSAGE );
		}

		$key = get_password_reset_key( $user );

		if ( is_wp_error( $key ) ) {
			return array( 'message' => self::GENERIC_MESSAGE );
		}

		// A delivery failure is recorded in user meta for administrators. The
		// public response stays generic either way.
		Penalis_Emails::send_password_reset( $user->ID, $key );

		return array( 'message' => self::GENERIC_MESSAGE );
	}

	/**
	 * Consume a reset key and set a new password.
	 *
	 * @param array $data Raw request data.
	 * @return array|WP_Error
	 */
	public static function reset( $data ) {
		$ip = Penalis_Rate_Limiter::client_ip();

		if ( ! Penalis_Rate_Limiter::is_allowed( 'password_reset_ip', 10, HOUR_IN_SECONDS, $ip ) ) {
			return new WP_Error(
				'penalis_rate_limited',
				'Terlalu banyak percobaan. Silakan coba lagi nanti.',
				array( 'status' => 429 )
			);
		}

		Penalis_Rate_Limiter::hit( 'password_reset_ip', HOUR_IN_SECONDS, $ip );

		$key      = isset( $data['key'] ) ? trim( (string) $data['key'] ) : '';
		$login    = isset( $data['login'] ) ? trim( (string) $data['login'] ) : '';
		$password = isset( $data['password'] ) ? (string) $data['password'] : '';

		if ( '' === $key || '' === $login || strlen( $key ) > 100 || strlen( $login ) > 254 ) {
			return self::invalid_key_error();
		}

		$user = check_password_reset_key( $key, $login );

		if ( is_wp_error( $user ) ) {
			return self::invalid_key_error();
		}

		// The key proves ownership of the address, so an explicit message here
		// is actionable without being an enumeration vector.
		if ( ! self::is_reset_allowed( $user ) ) {
			return new WP_Error(
				'penalis_reset_forbidden',
				'Akun ini tidak dapat reset password. Silakan hubungi admin Penalis.',
				array( 'status' => 403 )
			);
		}

		$error = Penalis_Policy::validate_password( $password );

		if ( '' !== $error ) {
			return new WP_Error(
				'penalis_password_weak',
				$error,
				array(
					'status' => 400,
					'fields' => array( 'password' => $error ),
				)
			);
		}

		reset_password( $user, $password );

		// A legacy account converted by an administrator has no password yet.
		// Setting one clears the flag that asks for it.
		Penalis_Account_Status::clear_needs_password( $user->ID );

		// reset_password() does not end sessions. A reset is often the response
		// to a compromised account, so every existing session is destroyed.
		if ( class_exists( 'WP_Session_Tokens' ) ) {
			WP_Session_Tokens::get_instance( $user->ID )->destroy_all();
		}

		Penalis_Rate_Limiter::clear( 'password_forgot_account', $user->user_email );

		return array(
			'message' => 'Password kamu telah diperbarui. Silakan login dengan password baru.',
		);
	}

	/**
	 * Whether the account may reset its password.
	 *
	 * Plugin-managed accounts follow the same rule as the login gate: pending
	 * accounts may reset (they still cannot write), active accounts need
	 * explicit access, and suspended or rejected accounts may not.
	 *
	 * Accounts without Penalis metadata keep native WordPress behavior, except
	 * dormant legacy contributors, which are gated exactly like the login and
	 * `allow_password_reset` filters. Editors, authors, and administrators are
	 * therefore unaffected.
	 *
	 * @param WP_User $user User.
	 * @return bool
	 */
	private static function is_reset_allowed( $user ) {
		if ( ! Penalis_Account_Status::is_password_reset_allowed( $user ) ) {
			return false;
		}

		/**
		 * Core's own gate. Keeps third-party restrictions working.
		 *
		 * @param bool $allow   Whether reset is allowed.
		 * @param int  $user_id User ID.
		 */
		return (bool) apply_filters( 'allow_password_reset', true, $user->ID ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
	}

	/**
	 * Error for a missing, expired, or already-used key.
	 *
	 * @return WP_Error
	 */
	private static function invalid_key_error() {
		return new WP_Error(
			'penalis_invalid_reset_key',
			'Link reset password tidak valid atau sudah kedaluwarsa. Silakan minta link baru.',
			array( 'status' => 400 )
		);
	}
}
