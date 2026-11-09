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
		$login    = (string) $data['user_login'];
		$password = (string) $data['password'];
		$display  = sanitize_text_field( $data['display_name'] );

		// Detect duplicates without disclosing existence in the response.
		$existing_email = email_exists( $email );
		$existing_login = username_exists( $login );

		if ( $existing_email || $existing_login || Penalis_Author_History::unavailable( $login ) ) {
			self::handle_duplicate_attempt( $existing_email, $existing_login );

			// Return the same challenge shape as a successful registration. This
			// prevents the endpoint from confirming whether an email address or
			// username already exists.
			return Penalis_Otp::response();
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

		if ( isset( $data['instagram'] ) && '' !== trim( (string) $data['instagram'] ) ) {
			Penalis_Account_Status::set_instagram( (int) $user_id, (string) $data['instagram'] );
		}

		$challenge = Penalis_Otp::issue( 'registration', (int) $user_id, $email );

		if ( is_wp_error( $challenge ) ) {
			// The account exists but no code was delivered. Keep the public
			// response generic; administrators see the recorded mail failure.
			return Penalis_Otp::response();
		}

		return $challenge;
	}

	/**
	 * Handle a registration attempt that matches an existing account.
	 *
	 * The response to the requester is always the same generic success, so this
	 * method only decides what to send to the account owner:
	 *
	 * - Legacy contributor: send an activation code. These accounts have no
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
			$user = get_userdata( $user_id );

			if ( $user ) {
				Penalis_Otp::issue( 'legacy', $user_id, $user->user_email );
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
		$subject = sprintf( '[%s] Percobaan Pendaftaran', $site );

		$message  = "Seseorang mencoba mendaftar menggunakan alamat email atau username kamu.\n\n";
		$message .= "Jika ini adalah kamu, silakan masuk ke dashboard penulis atau reset password kamu.\n";
		$message .= Penalis_Dashboard::dashboard_url() . "\n\n";
		$message .= "Jika bukan kamu, tidak ada tindakan yang perlu dilakukan.\n";

		wp_mail( $user->user_email, $subject, $message );
	}

	/**
	 * Consume a registration challenge.
	 *
	 * @param string $challenge_id Opaque challenge ID.
	 * @param string $code         Six-digit code.
	 * @return array|WP_Error
	 */
	public static function verify( $challenge_id, $code ) {
		$ip = Penalis_Rate_Limiter::client_ip();

		if ( ! Penalis_Rate_Limiter::is_allowed( 'otp_verify_ip', 20, HOUR_IN_SECONDS, $ip ) ) {
			return new WP_Error(
				'penalis_rate_limited',
				'Terlalu banyak percobaan verifikasi. Silakan coba lagi nanti.',
				array( 'status' => 429 )
			);
		}

		Penalis_Rate_Limiter::hit( 'otp_verify_ip', HOUR_IN_SECONDS, $ip );

		$result = Penalis_Otp::consume(
			'registration',
			(string) $challenge_id,
			(string) $code,
			static function ( $state ) {
				$user_id = (int) $state['user_id'];

				if ( Penalis_Policy::STATUS_PENDING_VERIFICATION !== Penalis_Account_Status::get_status( $user_id ) ) {
					return Penalis_Otp::invalid();
				}

				$transition = Penalis_Account_Status::transition( $user_id, Penalis_Policy::STATUS_ACTIVE, 'email_verified', 0 );

				if ( is_wp_error( $transition ) ) {
					return $transition;
				}

				return array(
					'status'  => Penalis_Policy::STATUS_ACTIVE,
					'message' => 'Alamat email kamu telah dikonfirmasi. Akun penulis kamu kini aktif dan siap digunakan untuk menulis.',
					'user_id' => $user_id,
				);
			}
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Sent after the lock is released: an SMTP call must not stall other
		// writers who are confirming their own codes.
		Penalis_Emails::send_status_notification( (int) $result['user_id'], Penalis_Policy::STATUS_ACTIVE );

		unset( $result['user_id'] );

		return $result;
	}

	/**
	 * Reissue a registration challenge.
	 *
	 * @param string $email Email address.
	 * @return array
	 */
	public static function resend_verification( $email ) {
		$email = sanitize_email( (string) $email );

		if ( ! is_email( $email ) ) {
			return Penalis_Otp::response();
		}

		$user = get_user_by( 'email', $email );

		if ( ! $user || Penalis_Policy::STATUS_PENDING_VERIFICATION !== Penalis_Account_Status::get_status( $user->ID ) ) {
			// Generic success to avoid user enumeration.
			return Penalis_Otp::response();
		}

		$challenge = Penalis_Otp::issue( 'registration', (int) $user->ID, $email );

		if ( is_wp_error( $challenge ) ) {
			return Penalis_Otp::response( Penalis_Otp::current( 'registration', (int) $user->ID ) );
		}

		return $challenge;
	}
}
