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
				'Registration is currently closed.',
				array( 'status' => 403 )
			);
		}

		$ip = Penalis_Rate_Limiter::client_ip();

		if ( ! Penalis_Rate_Limiter::is_allowed( 'registration_ip', 5, HOUR_IN_SECONDS, $ip ) ) {
			return new WP_Error(
				'penalis_rate_limited',
				'Too many registration attempts. Please try again later.',
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
				'Registration could not be completed. Please check your details and try again.',
				array( 'status' => 400 )
			);
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error(
				'penalis_registration_invalid',
				'Please correct the highlighted fields.',
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
			self::send_duplicate_notice_if_appropriate( $existing_email, $existing_login );

			// Return the same response as a successful registration. This
			// prevents the endpoint from confirming whether an email address or
			// username already exists.
			return array(
				'status'     => Penalis_Policy::STATUS_PENDING_VERIFICATION,
				'email_sent' => true,
				'message'    => 'Check your email for a verification link before signing in.',
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
				'Registration could not be completed. Please check your details and try again.',
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
				? 'Check your email for a verification link before signing in.'
				: 'Your account was created, but the verification email could not be sent. Please contact the site administrators.',
		);  }

	/**
	 * Send a neutral notice to an existing account when a duplicate attempt is
	 * made. This never confirms to the requester whether the account exists.
	 *
	 * @param int $existing_email_id User ID by email.
	 * @param int $existing_login_id User ID by login.
	 * @return void
	 */
	private static function send_duplicate_notice_if_appropriate( $existing_email_id, $existing_login_id ) {
		$user_id = (int) $existing_email_id;
		if ( $user_id <= 0 ) {
			$user_id = (int) $existing_login_id;
		}

		if ( $user_id <= 0 ) {
			return;
		}

		// Throttle duplicate notices to avoid becoming a spam vector.
		$throttle_key = 'penalis_dup_notice_' . $user_id;
		if ( get_transient( $throttle_key ) ) {
			return;
		}
		set_transient( $throttle_key, '1', HOUR_IN_SECONDS );

		$user = get_userdata( $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}

		$site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject  = sprintf( '[%s] Registration attempt', $site );
		$message  = "Someone attempted to register with your email address or username.\n\n";
		$message .= "If this was you, try signing in or resetting your password instead. If it was not you, no action is needed.\n";

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
				'Too many verification attempts. Please try again later.',
				array( 'status' => 429 )
			);
		}

		Penalis_Rate_Limiter::hit( 'verification_ip', HOUR_IN_SECONDS, $ip );

		if ( ! Penalis_Policy::is_token_format_valid( $raw_token ) ) {
			return new WP_Error(
				'penalis_invalid_token',
				'This verification link is invalid or has expired.',
				array( 'status' => 400 )
			);
		}

		$user_id = Penalis_Account_Status::find_user_by_token( $raw_token );

		if ( $user_id <= 0 ) {
			return new WP_Error(
				'penalis_invalid_token',
				'This verification link is invalid or has expired.',
				array( 'status' => 400 )
			);
		}

		$meta = Penalis_Account_Status::get_token_meta( $user_id );

		if ( Penalis_Policy::is_token_expired( $meta['expires'], time() ) ) {
			Penalis_Account_Status::clear_token( $user_id );
			return new WP_Error(
				'penalis_expired_token',
				'This verification link has expired. Please request a new one.',
				array( 'status' => 400 )
			);
		}

		$status = Penalis_Account_Status::get_status( $user_id );

		if ( Penalis_Policy::STATUS_PENDING_VERIFICATION !== $status ) {
			// Token reuse after a successful verification.
			Penalis_Account_Status::clear_token( $user_id );
			return new WP_Error(
				'penalis_invalid_token',
				'This verification link is invalid or has already been used.',
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
			'message' => 'Your email address is confirmed. Your application is now awaiting administrator approval.',
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
				'Too many requests. Please try again later.',
				array( 'status' => 429 )
			);
		}

		Penalis_Rate_Limiter::hit( 'resend_ip', HOUR_IN_SECONDS, $ip );

		$email = sanitize_email( (string) $email );
		if ( ! is_email( $email ) ) {
			return array(
				'message' => 'If that address belongs to an account awaiting verification, a new email has been sent.',
			);
		}

		$user = get_user_by( 'email', $email );

		if ( ! $user ) {
			// Generic success to avoid user enumeration.
			return array(
				'message' => 'If that address belongs to an account awaiting verification, a new email has been sent.',
			);
		}

		$status = Penalis_Account_Status::get_status( $user->ID );

		if ( Penalis_Policy::STATUS_PENDING_VERIFICATION !== $status ) {
			return array(
				'message' => 'If that address belongs to an account awaiting verification, a new email has been sent.',
			);
		}

		$meta = Penalis_Account_Status::get_token_meta( $user->ID );

		if ( ! Penalis_Policy::can_resend_verification( $meta['created'], time() ) ) {
			// Silent no-op with a generic response to avoid revealing that the
			// account exists and was recently emailed.
			return array(
				'message' => 'If that address belongs to an account awaiting verification, a new email has been sent.',
			);
		}

		$token = Penalis_Account_Status::issue_verification_token( $user->ID );
		if ( is_wp_error( $token ) ) {
			// Generic response: do not reveal that the account exists.
			return array(
				'message' => 'If that address belongs to an account awaiting verification, a new email has been sent.',
			);
		}

		$sent = Penalis_Emails::send_verification( $user->ID, $token );

		if ( ! $sent ) {
			// Delivery failure is recorded in user meta and shown to
			// administrators. The account state does not advance, and the
			// public response stays generic to avoid enumeration.
			return array(
				'message' => 'If that address belongs to an account awaiting verification, a new email has been sent.',
			);
		}

		return array(
			'message' => 'If that address belongs to an account awaiting verification, a new email has been sent.',
		);
	}
}
