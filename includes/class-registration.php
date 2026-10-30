<?php
/**
 * Registration, verification, and approval workflow.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Account workflow service.
 */
final class Penalis_Registration {

	/**
	 * Whether public registration is enabled.
	 *
	 * @return bool
	 */
	public static function is_registration_enabled() {
		$enabled = get_option( 'penalis_registration_enabled', '1' );
		return '1' === (string) $enabled;
	}

	/**
	 * Create a pending account.
	 *
	 * @param array $data Raw request data.
	 * @return array|WP_Error
	 */
	public static function register( $data ) {
		if ( ! self::is_registration_enabled() ) {
			return new WP_Error(
				'penalis_registration_closed',
				'Pendaftaran saat ini ditutup.',
				array( 'status' => 403 )
			);
		}

		$ip = Penalis_Rate_Limiter::client_ip();

		if ( ! Penalis_Rate_Limiter::is_allowed( 'registration_ip', 5, HOUR_IN_SECONDS, $ip ) ) {
			return new WP_Error(
				'penalis_rate_limited',
				'Terlalu banyak percobaan pendaftaran. Silakan coba lagi nanti.',
				array( 'status' => 429 )
			);
		}

		// Count every attempt, including invalid ones, so validation and
		// duplicate checks cannot be used for high-volume probing.
		Penalis_Rate_Limiter::hit( 'registration_ip', HOUR_IN_SECONDS, $ip );

		$errors = Penalis_Policy::validate_registration( $data );

		// The honeypot and all validation failures share one generic response
		// shape so bots cannot distinguish reasons.
		if ( ! empty( $errors['_honeypot'] ) ) {
			return new WP_Error(
				'penalis_registration_failed',
				'Pendaftaran tidak dapat diproses. Periksa kembali data kamu lalu coba lagi.',
				array( 'status' => 400 )
			);
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error(
				'penalis_registration_invalid',
				'Perbaiki kolom yang ditandai.',
				array(
					'status' => 400,
					'fields' => $errors,
				)
			);
		}

		$email    = sanitize_email( $data['user_email'] );
		$login    = sanitize_user( $data['user_login'], true );
		$password = (string) $data['password'];
		$display  = sanitize_text_field( $data['display_name'] );

		// Detect duplicates without disclosing existence in the response.
		$existing_email = email_exists( $email );
		$existing_login = username_exists( $login );

		if ( $existing_email || $existing_login ) {
			self::handle_duplicate_attempt( $existing_email, $existing_login );

			// Return the same response as a successful registration. This
			// prevents the endpoint from confirming whether an email address or
			// username already exists.
			return array(
				'status'     => Penalis_Policy::STATUS_PENDING_VERIFICATION,
				'email_sent' => true,
				'message'    => 'Periksa email kamu untuk mendapatkan link verifikasi sebelum masuk.',
			);
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => $password,
				'display_name' => $display,
				// Subscribers cannot write. The writer role is only granted by
				// the approval transition in Penalis_Account_Status.
				'role'         => 'subscriber',
				'description'  => '',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			// Do not surface the specific core error (it may reveal existence).
			return new WP_Error(
				'penalis_registration_failed',
				'Pendaftaran tidak dapat diproses. Periksa kembali data kamu lalu coba lagi.',
				array( 'status' => 400 )
			);
		}

		Penalis_Account_Status::initialize_pending( (int) $user_id );

		$token = Penalis_Account_Status::issue_verification_token( (int) $user_id );
		$sent  = false;

		if ( ! is_wp_error( $token ) ) {
			$sent = Penalis_Emails::send_verification( (int) $user_id, $token );
		}

		return array(
			'status'     => Penalis_Policy::STATUS_PENDING_VERIFICATION,
			'email_sent' => (bool) $sent,
			'message'    => $sent
				? 'Periksa email kamu untuk mendapatkan link verifikasi sebelum masuk.'
				: 'Akun kamu telah dibuat, tetapi email verifikasi tidak dapat dikirim. Silakan hubungi admin Penalis.',
		);  }

	/**
	 * Handle a registration attempt that matches an existing account.
	 *
	 * The response to the requester is always the same generic success, so this
	 * method only decides what to send to the account owner:
	 *
	 * - Legacy contributor: send an activation link. These accounts have no
	 *   password and cannot sign in, so telling them to "sign in or reset the
	 *   password" would be a dead end.
	 * - Any other account: send a neutral notice.
	 *
	 * @param int $existing_email_id User ID by email.
	 * @param int $existing_login_id User ID by login.
	 * @return void
	 */
	private static function handle_duplicate_attempt( $existing_email_id, $existing_login_id ) {
		$user_id = (int) $existing_email_id;
		if ( $user_id <= 0 ) {
			$user_id = (int) $existing_login_id;
		}

		if ( $user_id <= 0 ) {
			return;
		}

		// Throttle to avoid becoming a spam vector.
		$throttle_key = 'penalis_dup_notice_' . $user_id;
		if ( get_transient( $throttle_key ) ) {
			return;
		}
		set_transient( $throttle_key, '1', HOUR_IN_SECONDS );

		if ( Penalis_Account_Status::is_legacy_account( $user_id ) ) {
			$token = Penalis_Account_Status::issue_verification_token( $user_id );

			if ( ! is_wp_error( $token ) ) {
				Penalis_Emails::send_legacy_activation( $user_id, $token );
			}

			return;
		}

		self::send_duplicate_notice( $user_id );
	}

	/**
	 * Send a neutral notice to an existing managed account.
	 *
	 * This never confirms to the requester whether the account exists.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	private static function send_duplicate_notice( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}

		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = sprintf( '[%s] Percobaan pendaftaran', $site );

		$message  = "Seseorang mencoba mendaftar menggunakan alamat email atau nama pengguna kamu.\n\n";
		$message .= "Jika ini adalah kamu, silakan masuk ke dasbor penulis atau atur ulang kata sandi kamu.\n";
		$message .= Penalis_Dashboard::dashboard_url() . "\n\n";
		$message .= "Jika bukan kamu, tidak ada tindakan yang perlu dilakukan.\n";

		wp_mail( $user->user_email, $subject, $message );
	}

	/**
	 * Consume a verification token.
	 *
	 * @param string $raw_token Raw token.
	 * @return array|WP_Error
	 */
	public static function verify( $raw_token ) {
		$ip = Penalis_Rate_Limiter::client_ip();

		if ( ! Penalis_Rate_Limiter::is_allowed( 'verification_ip', 20, HOUR_IN_SECONDS, $ip ) ) {
			return new WP_Error(
				'penalis_rate_limited',
				'Terlalu banyak percobaan verifikasi. Silakan coba lagi nanti.',
				array( 'status' => 429 )
			);
		}

		Penalis_Rate_Limiter::hit( 'verification_ip', HOUR_IN_SECONDS, $ip );

		if ( ! Penalis_Policy::is_token_format_valid( $raw_token ) ) {
			return new WP_Error(
				'penalis_invalid_token',
				'Link verifikasi ini tidak valid atau sudah kedaluwarsa.',
				array( 'status' => 400 )
			);
		}

		$user_id = Penalis_Account_Status::find_user_by_token( $raw_token );

		if ( $user_id <= 0 ) {
			return new WP_Error(
				'penalis_invalid_token',
				'Link verifikasi ini tidak valid atau sudah kedaluwarsa.',
				array( 'status' => 400 )
			);
		}

		$meta = Penalis_Account_Status::get_token_meta( $user_id );

		if ( Penalis_Policy::is_token_expired( $meta['expires'], time() ) ) {
			Penalis_Account_Status::clear_token( $user_id );
			return new WP_Error(
				'penalis_expired_token',
				'Link verifikasi ini sudah kedaluwarsa. Silakan minta yang baru.',
				array( 'status' => 400 )
			);
		}

		$status = Penalis_Account_Status::get_status( $user_id );

		if ( Penalis_Policy::STATUS_PENDING_VERIFICATION !== $status ) {
			// Token reuse after a successful verification.
			Penalis_Account_Status::clear_token( $user_id );
			return new WP_Error(
				'penalis_invalid_token',
				'Link verifikasi ini tidak valid atau sudah digunakan.',
				array( 'status' => 400 )
			);
		}

		$result = Penalis_Account_Status::transition(
			$user_id,
			Penalis_Policy::STATUS_PENDING_APPROVAL,
			'email_verified',
			0
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// The token is cleared by the transition. Send admin notification after
		// state is committed; a mail failure does not roll back verification.
		Penalis_Emails::notify_admin_pending( $user_id );

		return array(
			'status'  => Penalis_Policy::STATUS_PENDING_APPROVAL,
			'message' => 'Alamat email kamu telah dikonfirmasi. Pendaftaran kamu kini menunggu persetujuan administrator.',
		);
	}

	/**
	 * Reissue a verification token.
	 *
	 * @param string $email Email address.
	 * @return array|WP_Error
	 */
	public static function resend_verification( $email ) {
		$ip = Penalis_Rate_Limiter::client_ip();

		if ( ! Penalis_Rate_Limiter::is_allowed( 'resend_ip', 5, HOUR_IN_SECONDS, $ip ) ) {
			return new WP_Error(
				'penalis_rate_limited',
				'Terlalu banyak permintaan. Silakan coba lagi nanti.',
				array( 'status' => 429 )
			);
		}

		Penalis_Rate_Limiter::hit( 'resend_ip', HOUR_IN_SECONDS, $ip );

		$email = sanitize_email( (string) $email );
		if ( ! is_email( $email ) ) {
			return array(
				'message' => 'Jika alamat tersebut milik akun yang menunggu verifikasi, email baru telah dikirim.',
			);
		}

		$user = get_user_by( 'email', $email );

		if ( ! $user ) {
			// Generic success to avoid user enumeration.
			return array(
				'message' => 'Jika alamat tersebut milik akun yang menunggu verifikasi, email baru telah dikirim.',
			);
		}

		$status = Penalis_Account_Status::get_status( $user->ID );

		if ( Penalis_Policy::STATUS_PENDING_VERIFICATION !== $status ) {
			return array(
				'message' => 'Jika alamat tersebut milik akun yang menunggu verifikasi, email baru telah dikirim.',
			);
		}

		$meta = Penalis_Account_Status::get_token_meta( $user->ID );

		if ( ! Penalis_Policy::can_resend_verification( $meta['created'], time() ) ) {
			// Silent no-op with a generic response to avoid revealing that the
			// account exists and was recently emailed.
			return array(
				'message' => 'Jika alamat tersebut milik akun yang menunggu verifikasi, email baru telah dikirim.',
			);
		}

		$token = Penalis_Account_Status::issue_verification_token( $user->ID );
		if ( is_wp_error( $token ) ) {
			// Generic response: do not reveal that the account exists.
			return array(
				'message' => 'Jika alamat tersebut milik akun yang menunggu verifikasi, email baru telah dikirim.',
			);
		}

		$sent = Penalis_Emails::send_verification( $user->ID, $token );

		if ( ! $sent ) {
			// Delivery failure is recorded in user meta and shown to
			// administrators. The account state does not advance, and the
			// public response stays generic to avoid enumeration.
			return array(
				'message' => 'Jika alamat tersebut milik akun yang menunggu verifikasi, email baru telah dikirim.',
			);
		}

		return array(
			'message' => 'Jika alamat tersebut milik akun yang menunggu verifikasi, email baru telah dikirim.',
		);
	}
}
