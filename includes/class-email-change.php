<?php
/**
 * Verified email address change.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Changes an account's email address only after the new address is proven.
 *
 * The address on file is never replaced on request. A request stores the
 * candidate address and emails a six-digit verification code to that candidate.
 * Until the code is consumed, the old address keeps receiving password resets
 * and notifications, so a mistyped or hostile address cannot take over the
 * account.
 *
 * This is deliberately separate from the registration verification challenge:
 * an email change must never advance the account status machine, and a
 * registration code must never be able to rewrite an address.
 */
final class Penalis_Email_Change {

	/**
	 * Request a change to a new address.
	 *
	 * @param int    $user_id          User ID.
	 * @param string $new_email        Requested address.
	 * @param string $current_password Current password, used as re-authentication.
	 * @return array|WP_Error
	 */
	public static function request( $user_id, $new_email, $current_password ) {
		$user_id = (int) $user_id;
		$user    = get_userdata( $user_id );

		if ( ! $user ) {
			return new WP_Error( 'penalis_not_authenticated', 'Kamu harus masuk terlebih dahulu.', array( 'status' => 401 ) );
		}

		if ( ! self::can_change( $user_id ) ) {
			return new WP_Error(
				'penalis_email_change_forbidden',
				'Alamat email akun ini belum dapat diubah. Silakan hubungi admin Penalis.',
				array( 'status' => 403 )
			);
		}

		$ip = Penalis_Rate_Limiter::client_ip();

		if ( ! Penalis_Rate_Limiter::is_allowed( 'email_change_ip', 5, HOUR_IN_SECONDS, $ip ) ) {
			return new WP_Error(
				'penalis_rate_limited',
				'Terlalu banyak permintaan perubahan email. Silakan coba lagi nanti.',
				array( 'status' => 429 )
			);
		}

		if ( ! Penalis_Rate_Limiter::is_allowed( 'email_change_user', 3, HOUR_IN_SECONDS, (string) $user_id ) ) {
			return new WP_Error(
				'penalis_rate_limited',
				'Terlalu banyak permintaan perubahan email. Silakan coba lagi nanti.',
				array( 'status' => 429 )
			);
		}

		Penalis_Rate_Limiter::hit( 'email_change_ip', HOUR_IN_SECONDS, $ip );
		Penalis_Rate_Limiter::hit( 'email_change_user', HOUR_IN_SECONDS, (string) $user_id );

		// Re-authentication: a hijacked session must not be able to move the
		// account to an address the attacker controls.
		if ( ! wp_check_password( (string) $current_password, $user->user_pass, $user_id ) ) {
			return new WP_Error(
				'penalis_password_invalid',
				'Kata sandi kamu saat ini salah.',
				array( 'status' => 400 )
			);
		}

		$email = sanitize_email( (string) $new_email );
		$error = Penalis_Policy::validate_email_change( $email, $user->user_email );

		if ( '' !== $error ) {
			return new WP_Error(
				'penalis_email_invalid',
				$error,
				array(
					'status' => 400,
					'fields' => array( 'new_email' => $error ),
				)
			);
		}

		// Do not disclose whether the address belongs to another account. The
		// message is the same for "taken" and "not allowed", so this endpoint
		// cannot be used to enumerate addresses.
		$existing = email_exists( $email );
		if ( $existing && (int) $existing !== $user_id ) {
			return new WP_Error(
				'penalis_email_unavailable',
				'Alamat email tersebut tidak dapat digunakan.',
				array(
					'status' => 400,
					'fields' => array( 'new_email' => 'Alamat email tersebut tidak dapat digunakan.' ),
				)
			);
		}

		Penalis_Account_Status::set_pending_email( $user_id, $email );

		$challenge = Penalis_Otp::issue( 'email_change', $user_id, $email );

		if ( is_wp_error( $challenge ) ) {
			// A rate-limited request keeps the pending address so the writer can
			// retry later without repeating the password. Any other failure
			// leaves a candidate nobody can confirm, so it is dropped.
			if ( 'penalis_rate_limited' !== $challenge->get_error_code() ) {
				Penalis_Account_Status::clear_email_change( $user_id );
			}

			return $challenge;
		}

		$challenge['pendingEmail'] = $email;

		return $challenge;
	}

	/**
	 * Reissue the code for the pending address.
	 *
	 * The candidate address comes from storage, so a resend can never move the
	 * change to a different address. Re-authentication is not repeated: the
	 * request that created the pending change already proved the password, and
	 * the challenge is bound to the signed-in account.
	 *
	 * @param int $user_id Signed-in owner.
	 * @return array|WP_Error
	 */
	public static function resend( $user_id ) {
		$user_id = (int) $user_id;

		if ( ! self::can_change( $user_id ) ) {
			return new WP_Error(
				'penalis_email_change_forbidden',
				'Alamat email akun ini belum dapat diubah. Silakan hubungi admin Penalis.',
				array( 'status' => 403 )
			);
		}

		$email = Penalis_Account_Status::get_pending_email( $user_id );

		if ( ! is_email( $email ) ) {
			return new WP_Error(
				'penalis_email_change_missing',
				'Tidak ada perubahan email yang menunggu konfirmasi. Silakan ajukan perubahan baru.',
				array( 'status' => 409 )
			);
		}

		$challenge = Penalis_Otp::issue( 'email_change', $user_id, $email );

		if ( is_wp_error( $challenge ) ) {
			// A throttled resend must not invalidate the code already sent.
			if ( 'penalis_rate_limited' === $challenge->get_error_code() ) {
				$current = Penalis_Otp::current( 'email_change', $user_id );

				if ( '' !== $current ) {
					$response                 = Penalis_Otp::response( $current );
					$response['pendingEmail'] = $email;

					return $response;
				}
			}

			return $challenge;
		}

		$challenge['pendingEmail'] = $email;

		return $challenge;
	}

	/**
	 * Consume an email-change challenge and apply the new address.
	 *
	 * @param int    $user_id      Signed-in owner.
	 * @param string $challenge_id Opaque challenge ID.
	 * @param string $code         Six-digit code.
	 * @return array|WP_Error
	 */
	public static function confirm( $user_id, $challenge_id, $code ) {
		$user_id = (int) $user_id;
		$ip      = Penalis_Rate_Limiter::client_ip();

		if ( ! Penalis_Rate_Limiter::is_allowed( 'otp_verify_ip', 20, HOUR_IN_SECONDS, $ip ) ) {
			return new WP_Error(
				'penalis_rate_limited',
				'Terlalu banyak percobaan. Silakan coba lagi nanti.',
				array( 'status' => 429 )
			);
		}

		Penalis_Rate_Limiter::hit( 'otp_verify_ip', HOUR_IN_SECONDS, $ip );

		$result = Penalis_Otp::consume(
			'email_change',
			(string) $challenge_id,
			(string) $code,
			static function ( $state ) use ( $user_id ) {
				// The challenge is bound to the account that requested it. A
				// signed-in session for another account cannot consume it.
				if ( (int) $state['user_id'] !== $user_id || ! self::can_change( $user_id ) ) {
					return Penalis_Otp::invalid();
				}

				$user = get_userdata( $user_id );

				if ( ! $user ) {
					return Penalis_Otp::invalid();
				}

				// The candidate address comes from storage, never from the request.
				$new_email = sanitize_email( (string) $state['email'] );

				if ( ! is_email( $new_email ) ) {
					Penalis_Account_Status::clear_email_change( $user_id );
					return Penalis_Otp::invalid();
				}

				// Re-check availability: another account may have claimed the
				// address between the request and the confirmation.
				$existing = email_exists( $new_email );
				if ( $existing && (int) $existing !== $user_id ) {
					Penalis_Account_Status::clear_email_change( $user_id );
					return new WP_Error(
						'penalis_email_unavailable',
						'Alamat email tersebut sudah digunakan akun lain. Silakan minta perubahan dengan alamat lain.',
						array( 'status' => 409 )
					);
				}

				$old_email = $user->user_email;
				$applied   = Penalis_Access_Control::apply_verified_email_change( $user_id, $new_email );

				if ( is_wp_error( $applied ) ) {
					return new WP_Error(
						'penalis_email_change_failed',
						'Alamat email kamu tidak dapat diperbarui. Silakan coba lagi.',
						array( 'status' => 500 )
					);
				}

				Penalis_Account_Status::clear_email_change( $user_id );

				// The new address was proven by the code, so the account's email
				// is verified regardless of what the flag said before. This never
				// changes the account status itself, and legacy accounts (no
				// status) are left alone so their metadata stays untouched.
				if ( '' !== Penalis_Account_Status::get_status( $user_id ) ) {
					update_user_meta( $user_id, Penalis_Account_Status::META_VERIFIED, '1' );
				}

				return array(
					'email'     => $new_email,
					'message'   => 'Alamat email kamu telah diperbarui. Gunakan alamat baru ini untuk masuk dan menerima email dari Penalis.',
					'user_id'   => $user_id,
					'old_email' => $old_email,
				);
			}
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Tell the previous address what happened. This is the only warning the
		// real owner gets if the change was not theirs. Sent after the lock is
		// released so an SMTP call cannot stall other verifications.
		Penalis_Emails::send_email_change_notice( (int) $result['user_id'], (string) $result['old_email'], (string) $result['email'] );

		unset( $result['user_id'], $result['old_email'] );

		return $result;
	}

	/**
	 * Whether the account may change its address.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	private static function can_change( $user_id ) {
		// Administrators keep native WordPress behaviour; the writer gate does
		// not apply to them.
		if ( user_can( (int) $user_id, 'manage_options' ) ) {
			return true;
		}

		return Penalis_Policy::can_change_email(
			Penalis_Account_Status::get_status( $user_id ),
			Penalis_Account_Status::is_access_enabled( $user_id )
		);
	}
}
