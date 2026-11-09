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
	public const GENERIC_MESSAGE = 'Jika alamat tersebut terdaftar sebagai penulis lama, kami telah mengirim kode verifikasi.';

	/**
	 * Request an activation code.
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
			return Penalis_Otp::response();
		}

		$email = isset( $data['user_email'] ) ? trim( (string) $data['user_email'] ) : '';

		if ( '' === $email || strlen( $email ) > 254 || ! is_email( $email ) ) {
			return Penalis_Otp::response();
		}

		$user = get_user_by( 'email', $email );

		if ( ! $user || ! Penalis_Account_Status::is_legacy_account( $user->ID ) ) {
			return Penalis_Otp::response();
		}

		$challenge = Penalis_Otp::issue( 'legacy', (int) $user->ID, $user->user_email );

		if ( is_wp_error( $challenge ) ) {
			// Keep the code the writer already received usable when the resend
			// is merely throttled.
			return Penalis_Otp::response( Penalis_Otp::current( 'legacy', (int) $user->ID ) );
		}

		return $challenge;
	}

	/**
	 * Consume an activation challenge and set the first password.
	 *
	 * @param array $data Raw request data.
	 * @return array|WP_Error
	 */
	public static function activate( $data ) {
		$ip = Penalis_Rate_Limiter::client_ip();

		if ( ! Penalis_Rate_Limiter::is_allowed( 'otp_verify_ip', 20, HOUR_IN_SECONDS, $ip ) ) {
			return new WP_Error(
				'penalis_rate_limited',
				'Terlalu banyak percobaan. Silakan coba lagi nanti.',
				array( 'status' => 429 )
			);
		}

		Penalis_Rate_Limiter::hit( 'otp_verify_ip', HOUR_IN_SECONDS, $ip );

		$password = isset( $data['password'] ) ? (string) $data['password'] : '';
		$error    = Penalis_Policy::validate_password( $password );

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

		$result = Penalis_Otp::consume(
			'legacy',
			isset( $data['challenge_id'] ) ? (string) $data['challenge_id'] : '',
			isset( $data['code'] ) ? (string) $data['code'] : '',
			static function ( $state ) use ( $password ) {
				$user_id = (int) $state['user_id'];

				// The challenge is only meaningful while the account is still
				// legacy. Once converted, a second use must fail rather than
				// reset the password.
				if ( ! Penalis_Account_Status::is_legacy_account( $user_id ) ) {
					return Penalis_Otp::invalid();
				}

				$user = get_userdata( $user_id );

				if ( ! $user ) {
					return Penalis_Otp::invalid();
				}

				// Set the first password. reset_password() hashes it and fires
				// the standard core hooks, so other plugins see a normal change.
				reset_password( $user, $password );

				$converted = Penalis_Account_Status::convert_legacy(
					$user_id,
					Penalis_Policy::STATUS_ACTIVE,
					'legacy_self_activated',
					0,
					false
				);

				if ( is_wp_error( $converted ) ) {
					return $converted;
				}

				return array(
					'status'  => Penalis_Policy::STATUS_ACTIVE,
					'message' => 'Akun kamu telah diaktifkan. Kamu kini dapat masuk ke dasbor dan mulai menulis.',
					'user_id' => $user_id,
				);
			}
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Sent after the lock is released so an SMTP call cannot stall other
		// writers who are confirming their own codes.
		Penalis_Emails::send_status_notification( (int) $result['user_id'], Penalis_Policy::STATUS_ACTIVE );

		unset( $result['user_id'] );

		return $result;
	}
}
