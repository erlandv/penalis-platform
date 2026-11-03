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
 * candidate address next to a hashed single-use token and emails the
 * confirmation link to that candidate. Until the link is consumed, the old
 * address keeps receiving password resets and notifications, so a mistyped or
 * hostile address cannot take over the account.
 *
 * This is deliberately separate from the registration verification token: an
 * email change must never advance the account status machine, and a
 * registration token must never be able to rewrite an address.
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

		// Throttle re-sends to the same candidate address so the form cannot be
		// used to flood a third party's inbox.
		$pending = Penalis_Account_Status::get_email_change( $user_id );
		if (
			'' !== $pending['email']
			&& 0 === strcasecmp( $pending['email'], $email )
			&& ! Penalis_Policy::can_resend_verification( $pending['created'], time() )
		) {
			return new WP_Error(
				'penalis_email_change_throttled',
				'Tautan konfirmasi baru saja dikirim. Periksa kotak masuk email baru kamu.',
				array( 'status' => 429 )
			);
		}

		$token = Penalis_Account_Status::issue_email_change_token( $user_id, $email );

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$sent = Penalis_Emails::send_email_change_confirmation( $user_id, $token, $email );

		if ( ! $sent ) {
			// The pending change is useless without the email, so drop it rather
			// than leaving a token nobody can consume.
			Penalis_Account_Status::clear_email_change( $user_id );

			return new WP_Error(
				'penalis_email_failed',
				'Email konfirmasi tidak dapat dikirim. Silakan coba lagi atau hubungi admin Penalis.',
				array( 'status' => 500 )
			);
		}

		return array(
			'pendingEmail' => $email,
			'message'      => 'Kami mengirim tautan konfirmasi ke alamat email baru kamu. Alamat email saat ini belum berubah sampai tautan tersebut dikonfirmasi.',
		);
	}

	/**
	 * Consume a confirmation token and apply the new address.
	 *
	 * @param string $raw_token Raw token from the confirmation link.
	 * @return array|WP_Error
	 */
	public static function confirm( $raw_token ) {
		$ip = Penalis_Rate_Limiter::client_ip();

		if ( ! Penalis_Rate_Limiter::is_allowed( 'email_change_confirm_ip', 10, HOUR_IN_SECONDS, $ip ) ) {
			return new WP_Error(
				'penalis_rate_limited',
				'Terlalu banyak percobaan. Silakan coba lagi nanti.',
				array( 'status' => 429 )
			);
		}

		Penalis_Rate_Limiter::hit( 'email_change_confirm_ip', HOUR_IN_SECONDS, $ip );

		if ( ! Penalis_Policy::is_token_format_valid( $raw_token ) ) {
			return new WP_Error(
				'penalis_invalid_token',
				'Tautan konfirmasi ini tidak valid atau sudah kedaluwarsa.',
				array( 'status' => 400 )
			);
		}

		$user_id = Penalis_Account_Status::find_user_by_email_change_token( $raw_token );

		if ( $user_id <= 0 ) {
			return new WP_Error(
				'penalis_invalid_token',
				'Tautan konfirmasi ini tidak valid atau sudah kedaluwarsa.',
				array( 'status' => 400 )
			);
		}

		$pending = Penalis_Account_Status::get_email_change( $user_id );

		if ( Penalis_Policy::is_token_expired( $pending['expires'], time() ) ) {
			Penalis_Account_Status::clear_email_change( $user_id );
			return new WP_Error(
				'penalis_expired_token',
				'Tautan konfirmasi ini sudah kedaluwarsa. Silakan minta tautan baru dari halaman Pengaturan.',
				array( 'status' => 400 )
			);
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			Penalis_Account_Status::clear_email_change( $user_id );
			return new WP_Error(
				'penalis_invalid_token',
				'Tautan konfirmasi ini tidak valid atau sudah kedaluwarsa.',
				array( 'status' => 400 )
			);
		}

		// The candidate address comes from storage, never from the request.
		$new_email = sanitize_email( $pending['email'] );

		if ( ! is_email( $new_email ) ) {
			Penalis_Account_Status::clear_email_change( $user_id );
			return new WP_Error(
				'penalis_invalid_token',
				'Tautan konfirmasi ini tidak valid atau sudah kedaluwarsa.',
				array( 'status' => 400 )
			);
		}

		if ( ! self::can_change( $user_id ) ) {
			Penalis_Account_Status::clear_email_change( $user_id );
			return new WP_Error(
				'penalis_email_change_forbidden',
				'Alamat email akun ini belum dapat diubah. Silakan hubungi admin Penalis.',
				array( 'status' => 403 )
			);
		}

		// Re-check availability: another account may have claimed the address
		// between the request and the confirmation.
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

		$result = Penalis_Access_Control::apply_verified_email_change( $user_id, $new_email );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'penalis_email_change_failed',
				'Alamat email kamu tidak dapat diperbarui. Silakan coba lagi.',
				array( 'status' => 500 )
			);
		}

		Penalis_Account_Status::clear_email_change( $user_id );

		// The new address was proven by the token, so the account's email is
		// verified regardless of what the flag said before. This never changes
		// the account status itself, and legacy accounts (no status) are left
		// alone so their metadata stays untouched.
		if ( '' !== Penalis_Account_Status::get_status( $user_id ) ) {
			update_user_meta( $user_id, Penalis_Account_Status::META_VERIFIED, '1' );
		}

		// Tell the previous address what happened. This is the only warning the
		// real owner gets if the change was not theirs.
		Penalis_Emails::send_email_change_notice( $user_id, $old_email, $new_email );

		return array(
			'email'   => $new_email,
			'message' => 'Alamat email kamu telah diperbarui. Gunakan alamat baru ini untuk masuk dan menerima email dari Penalis.',
		);
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
