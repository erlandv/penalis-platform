<?php
/**
 * Transactional email helpers.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends plugin emails and reports delivery failures.
 *
 * Tokens are never logged. The caller decides whether an account state may
 * advance; a failed send must not advance verification state.
 */
final class Penalis_Emails {

	/**
	 * Send the verification email.
	 *
	 * @param int    $user_id User ID.
	 * @param string $token   Raw verification token.
	 * @return bool Whether wp_mail accepted the message.
	 */
	public static function send_verification( $user_id, $token ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			Penalis_Account_Status::record_email_failure( $user_id, 'invalid_recipient' );
			return false;
		}

		$verify_url = add_query_arg(
			array(
				'penalis_verify' => rawurlencode( $token ),
			),
			Penalis_Dashboard::registration_url()
		);

		$subject = sprintf( '[%s] Confirm your email address', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );

		$message  = self::greeting( $user ) . "\n\n";
		$message .= 'Thanks for applying to write for ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . ".\n\n";
		$message .= "Confirm your email address by opening this link and pressing the confirmation button:\n\n";
		$message .= $verify_url . "\n\n";
		$message .= "The link expires in 24 hours and can only be used once.\n\n";
		$message .= "If you did not create this account, you can ignore this email.\n";

		$sent = wp_mail( $user->user_email, $subject, $message );

		if ( ! $sent ) {
			Penalis_Account_Status::record_email_failure( $user_id, 'verification' );
		}

		return (bool) $sent;
	}

	/**
	 * Notify an administrator that an account is awaiting approval.
	 *
	 * Failures are recorded but do not affect the applicant's state.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function notify_admin_pending( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return false;
		}

		$admin_email = get_option( 'admin_email' );
		if ( ! is_email( $admin_email ) ) {
			return false;
		}

		$subject  = sprintf( '[%s] Writer application awaiting approval', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$message  = "A writer application is awaiting review.\n\n";
		$message .= 'Display name: ' . $user->display_name . "\n";
		$message .= 'Username: ' . $user->user_login . "\n";
		$message .= 'Registered: ' . $user->user_registered . "\n\n";
		$message .= 'Review applications: ' . admin_url( 'users.php?page=penalis-writers&status=pending_approval' ) . "\n";

		$sent = wp_mail( $admin_email, $subject, $message );

		if ( ! $sent ) {
			Penalis_Account_Status::record_email_failure( $user_id, 'admin_notification' );
		}

		return (bool) $sent;
	}

	/**
	 * Send an account status notification to the applicant.
	 *
	 * @param int    $user_id User ID.
	 * @param string $status  New status.
	 * @return bool
	 */
	public static function send_status_notification( $user_id, $status ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return false;
		}

		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$login   = Penalis_Dashboard::dashboard_url();
		$subject = '';
		$body    = '';

		switch ( $status ) {
			case Penalis_Policy::STATUS_ACTIVE:
				$subject = sprintf( '[%s] Your writer account is active', $site );
				$body    = self::greeting( $user ) . "\n\nYour Penalis writer account has been approved. You can now create and publish articles.\n\nOpen your dashboard: " . $login . "\n";
				break;

			case Penalis_Policy::STATUS_REJECTED:
				$subject = sprintf( '[%s] Update on your writer application', $site );
				$body    = self::greeting( $user ) . "\n\nYour writer application was not approved at this time. If you believe this is a mistake, please contact the site administrators.\n";
				break;

			case Penalis_Policy::STATUS_SUSPENDED:
				$subject = sprintf( '[%s] Your writer account has been suspended', $site );
				$body    = self::greeting( $user ) . "\n\nYour writer account has been suspended, so publishing and editing are currently unavailable. Please contact the site administrators if you have questions.\n";
				break;
		}

		if ( '' === $subject ) {
			return false;
		}

		$sent = wp_mail( $user->user_email, $subject, $body );

		if ( ! $sent ) {
			Penalis_Account_Status::record_email_failure( $user_id, 'status_notification' );
		}

		return (bool) $sent;
	}

	/**
	 * Greeting line.
	 *
	 * @param WP_User $user User.
	 * @return string
	 */
	private static function greeting( $user ) {
		return 'Hello ' . $user->display_name . ',';
	}
}
