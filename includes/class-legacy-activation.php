<?php
/**
 * Self-service activation of legacy contributor accounts.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets a legacy contributor claim their existing profile.
 *
 * Legacy accounts were created by an administrator before this plugin existed.
 * They hold the `contributor` role, have no Penalis metadata, and were never
 * given a password, so their owners cannot sign in at all.
 *
 * Activation is the supported way in. It is deliberately modelled on password
 * reset, because it proves exactly the same thing: control of the stored email
 * address. Once confirmed, the account immediately becomes an `active` writer.
 *
 * Security properties:
 *
 * - The request endpoint always answers generically, so it cannot be used to
 *   enumerate accounts.
 * - The token is issued only for accounts that are genuinely legacy, so a
 *   managed account can never be re-claimed.
 * - The token is stored as a SHA-256 hash and is single use.
 * - The token is consumed only after the visitor submits a password, so email
 *   link scanners cannot burn it.
 * - The password is set through `reset_password()`, which hashes it and fires
 *   the standard core hooks.
 */
final class Penalis_Legacy_Activation {

	/**
	 * Response used for every request outcome.
	 *
	 * The wording never confirms whether an address belongs to a legacy
	 * account.
	 */
	public const GENERIC_MESSAGE = 'Jika alamat tersebut terdaftar sebagai penulis lama, kami telah mengirim tautan aktivasi.';

	/**
	 * Request an activation link.
	 *
	 * @param array $data Raw request data.
	 * @return array|WP_Error
	 */
	public static function request( $data ) {
		$ip = Penalis_Rate_Limiter::client_ip();

		if ( ! Penalis_Rate_Limiter::is_allowed( 'legacy_activate_ip', 5, HOUR_IN_SECONDS, $ip ) ) {
			return new WP_Error(
				'penalis_rate_limited',
				'Terlalu banyak permintaan. Silakan coba lagi nanti.',
				array( 'status' => 429 )
			);
		}

		Penalis_Rate_Limiter::hit( 'legacy_activate_ip', HOUR_IN_SECONDS, $ip );

		// A filled honeypot is a silent no-op so bots cannot distinguish a
		// rejected request from an accepted one.
		if ( ! empty( $data['penalis_website'] ) ) {
			return array( 'message' => self::GENERIC_MESSAGE );
		}

		$email = isset( $data['user_email'] ) ? trim( (string) $data['user_email'] ) : '';

		if ( '' === $email || strlen( $email ) > 254 || ! is_email( $email ) ) {
			return array( 'message' => self::GENERIC_MESSAGE );
		}

		$user = get_user_by( 'email', $email );

		if ( ! $user || ! Penalis_Account_Status::is_legacy_account( $user->ID ) ) {
			return array( 'message' => self::GENERIC_MESSAGE );
		}

		// Per-account throttle: stops this endpoint from being used to
		// mail-bomb a single address.
		if ( ! Penalis_Rate_Limiter::is_allowed( 'legacy_activate_account', 3, HOUR_IN_SECONDS, $user->user_email ) ) {
			return array( 'message' => self::GENERIC_MESSAGE );
		}

		Penalis_Rate_Limiter::hit( 'legacy_activate_account', HOUR_IN_SECONDS, $user->user_email );

		$token = Penalis_Account_Status::issue_verification_token( $user->ID );

		if ( is_wp_error( $token ) ) {
			return array( 'message' => self::GENERIC_MESSAGE );
		}

		// A delivery failure is recorded in user meta for administrators. The
		// public response stays generic either way.
		Penalis_Emails::send_legacy_activation( $user->ID, $token );

		return array( 'message' => self::GENERIC_MESSAGE );
	}

	/**
	 * Consume an activation token and set the first password.
	 *
	 * @param array $data Raw request data.
	 * @return array|WP_Error
	 */
	public static function activate( $data ) {
		$ip = Penalis_Rate_Limiter::client_ip();

		if ( ! Penalis_Rate_Limiter::is_allowed( 'legacy_activate_confirm_ip', 10, HOUR_IN_SECONDS, $ip ) ) {
			return new WP_Error(
				'penalis_rate_limited',
				'Terlalu banyak percobaan. Silakan coba lagi nanti.',
				array( 'status' => 429 )
			);
		}

		Penalis_Rate_Limiter::hit( 'legacy_activate_confirm_ip', HOUR_IN_SECONDS, $ip );

		$raw_token = isset( $data['token'] ) ? trim( (string) $data['token'] ) : '';
		$password  = isset( $data['password'] ) ? (string) $data['password'] : '';

		if ( ! Penalis_Policy::is_token_format_valid( $raw_token ) ) {
			return self::invalid_token_error();
		}

		$user_id = Penalis_Account_Status::find_user_by_token( $raw_token );

		if ( $user_id <= 0 ) {
			return self::invalid_token_error();
		}

		$meta = Penalis_Account_Status::get_token_meta( $user_id );

		if ( Penalis_Policy::is_token_expired( $meta['expires'], time() ) ) {
			Penalis_Account_Status::clear_token( $user_id );
			return new WP_Error(
				'penalis_expired_token',
				'Tautan aktivasi ini sudah kedaluwarsa. Silakan minta tautan baru.',
				array( 'status' => 400 )
			);
		}

		// The token is only meaningful while the account is still legacy. Once
		// converted, a second use must fail rather than reset the password.
		if ( ! Penalis_Account_Status::is_legacy_account( $user_id ) ) {
			Penalis_Account_Status::clear_token( $user_id );
			return self::invalid_token_error();
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

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return self::invalid_token_error();
		}

		// Set the first password. reset_password() hashes it and fires the
		// standard core hooks, so other plugins see a normal password change.
		reset_password( $user, $password );

		// The owner has just chosen a password, so the account must not be
		// flagged as needing one. The flag is passed explicitly because the
		// administrator "vouch" path also targets active for accounts that
		// still have no password.
		$result = Penalis_Account_Status::convert_legacy(
			$user_id,
			Penalis_Policy::STATUS_ACTIVE,
			'legacy_self_activated',
			0,
			false
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// The conversion clears the token. Send active notice after the state
		// is committed; a mail failure does not roll back activation.
		Penalis_Emails::send_status_notification( $user_id, Penalis_Policy::STATUS_ACTIVE );

		Penalis_Rate_Limiter::clear( 'legacy_activate_account', $user->user_email );

		return array(
			'status'  => Penalis_Policy::STATUS_ACTIVE,
			'message' => 'Akun kamu telah diaktifkan. Kamu kini dapat masuk ke dasbor dan mulai menulis.',
		);
	}

	/**
	 * Error for a missing, expired, or already-used token.
	 *
	 * @return WP_Error
	 */
	private static function invalid_token_error() {
		return new WP_Error(
			'penalis_invalid_token',
			'Tautan aktivasi ini tidak valid atau sudah digunakan.',
			array( 'status' => 400 )
		);
	}
}
