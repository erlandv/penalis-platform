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
 * Sends branded HTML plugin emails and reports delivery failures.
 *
 * Tokens are never logged. The caller decides whether an account state may
 * advance; a failed send must not advance verification state.
 */
final class Penalis_Emails {

	/**
	 * Send a purpose-specific verification code, never a bearer link.
	 *
	 * @param int    $user_id User ID.
	 * @param string $email   Destination.
	 * @param string $code    Six-digit string.
	 * @param string $purpose Challenge purpose.
	 * @return bool
	 */
	public static function send_otp( $user_id, $email, $code, $purpose ) {
		$template_key = 'otp_' . $purpose;
		$registry     = class_exists( 'Penalis_Email_Settings' ) ? Penalis_Email_Settings::get_registry() : array();

		if ( ! isset( $registry[ $template_key ] ) ) {
			return false;
		}

		$user    = get_userdata( (int) $user_id );
		$minutes = (int) round( Penalis_Policy::OTP_TTL / 60 );

		return self::send_template(
			(string) $email,
			$template_key,
			array(
				'{kode_otp}'           => (string) $code,
				'{menit_berlaku}'      => (string) $minutes,
				'{maksimal_percobaan}' => (string) Penalis_Policy::OTP_MAX_ATTEMPTS,
				'{nama_penulis}'       => $user ? $user->display_name : 'Penulis',
				'{email}'              => (string) $email,
			),
			(int) $user_id,
			(string) $purpose
		);
	}

	/**
	 * Send the password reset email.
	 *
	 * The link points at the dashboard rather than wp-login.php so the writer
	 * stays inside the same interface. The key is never logged.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Password reset key from get_password_reset_key().
	 * @return bool Whether wp_mail accepted the message.
	 */
	public static function send_password_reset( $user_id, $key ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			Penalis_Account_Status::record_email_failure( $user_id, 'invalid_recipient' );
			return false;
		}

		$reset_url = add_query_arg(
			array(
				'penalis_reset' => rawurlencode( (string) $key ),
				'penalis_user'  => rawurlencode( $user->user_login ),
			),
			Penalis_Dashboard::registration_url()
		);

		return self::send_template(
			$user->user_email,
			'password_reset',
			array(
				'{nama_penulis}'        => $user->display_name,
				'{link_reset_password}' => $reset_url,
			),
			(int) $user_id,
			'password_reset'
		);
	}

	/**
	 * Notify the current address that the account login changed.
	 *
	 * This is the only warning the real owner receives if the rename was not
	 * theirs, so it is sent after the change is committed and never blocks it.
	 * The message deliberately does not repeat the old login: the recipient
	 * already knows it, and an attacker reading the mailbox gains nothing.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $email     Current address.
	 * @param string $old_login Previous login.
	 * @param string $new_login New login.
	 * @return bool Whether wp_mail accepted the message.
	 */
	public static function send_username_change_notice( $user_id, $email, $old_login = '', $new_login = '' ) {
		$user = get_userdata( (int) $user_id );

		if ( ! $user || ! is_email( $email ) ) {
			Penalis_Account_Status::record_email_failure( $user_id, 'invalid_recipient' );
			return false;
		}

		return self::send_template(
			$email,
			'username_change',
			array(
				'{nama_penulis}'  => $user->display_name,
				'{username_baru}' => (string) $new_login,
				'{username_lama}' => (string) $old_login,
			),
			(int) $user_id,
			'username_change_notice'
		);
	}

	/**
	 * Notify the previous email address.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $old_email Old address.
	 * @param string $new_email New address.
	 * @return bool
	 */
	public static function send_email_change_notice( $user_id, $old_email, $new_email ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user || ! is_email( $old_email ) ) {
			return false;
		}

		return self::send_template(
			$old_email,
			'email_change',
			array(
				'{nama_penulis}' => $user->display_name,
				'{email_lama}'   => (string) $old_email,
				'{email_baru}'   => (string) $new_email,
			),
			(int) $user_id,
			'email_change_notice'
		);
	}

	/**
	 * Send an account status notification to the writer.
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

		$key      = 'status_' . $status;
		$registry = class_exists( 'Penalis_Email_Settings' ) ? Penalis_Email_Settings::get_registry() : array();
		if ( ! isset( $registry[ $key ] ) ) {
			return false;
		}

		return self::send_template(
			$user->user_email,
			$key,
			array(
				'{nama_penulis}' => $user->display_name,
				'{email_admin}'  => (string) get_option( 'admin_email' ),
			),
			(int) $user_id,
			'status_notification'
		);
	}

	/**
	 * Send an editorial submission or resubmission email to reviewers.
	 *
	 * Emails are delivered individually (one email per recipient address) to
	 * all active reviewers (administrators and editors) as well as the site
	 * admin email. This preserves recipient privacy (no Cc header leaking) and
	 * maximizes deliverability. Mail delivery failures are recorded on the
	 * author's account without rolling back the submission.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $action  Writer workflow action (`submit` or `resubmit`).
	 * @return bool Whether at least one notification email was accepted.
	 */
	public static function send_editorial_submission_notification( $post_id, $action ) {
		$post   = get_post( (int) $post_id );
		$action = (string) $action;

		if ( ! $post || 'post' !== $post->post_type || ! Penalis_Editorial_Policy::is_writer_action( $action ) ) {
			return false;
		}

		$author_id = (int) $post->post_author;
		$author    = get_userdata( $author_id );
		if ( ! $author ) {
			return false;
		}

		$recipients = self::submission_notification_recipients();
		if ( empty( $recipients ) ) {
			Penalis_Account_Status::record_email_failure( $author_id, 'editorial_' . $action );
			return false;
		}

		$title      = self::safe_article_title( $post );
		$cycle      = max( 1, Penalis_Editorial_Workflow::get_review_cycle( $post ) );
		$review_url = admin_url( 'admin.php?page=penalis-review&post_id=' . (int) $post->ID );

		$key      = 'editorial_' . $action;
		$rendered = Penalis_Email_Settings::render(
			$key,
			array(
				'{judul_karya}'       => $title,
				'{nama_penulis}'      => $author->display_name,
				'{username_penulis}'  => $author->user_login,
				'{review_cycle}'      => (string) $cycle,
				'{link_review_admin}' => $review_url,
			)
		);

		$any_sent = false;

		foreach ( $recipients as $recipient_email ) {
			$sent = self::send_mail(
				$recipient_email,
				$rendered['subject'],
				$rendered['body'],
				$rendered['footer']
			);

			if ( $sent ) {
				$any_sent = true;
			}
		}

		if ( ! $any_sent ) {
			Penalis_Account_Status::record_email_failure( $author_id, 'editorial_' . $action );
		}

		return (bool) $any_sent;
	}

	/**
	 * Collect unique email addresses for reviewers entitled to receive
	 * submission notification emails.
	 *
	 * Includes all active reviewers holding the review capability
	 * (editors and administrators) plus the site admin_email as a fallback.
	 *
	 * @return string[] Unique valid email addresses.
	 */
	public static function submission_notification_recipients() {
		$recipients = array();

		if ( class_exists( 'Penalis_Editorial_Notifications' ) ) {
			$reviewer_ids = Penalis_Editorial_Notifications::reviewer_user_ids();
			foreach ( $reviewer_ids as $reviewer_id ) {
				$user = get_userdata( (int) $reviewer_id );
				if ( $user && is_email( $user->user_email ) ) {
					$recipients[] = sanitize_email( $user->user_email );
				}
			}
		}

		$admin_email = get_option( 'admin_email' );
		if ( is_email( $admin_email ) ) {
			$recipients[] = sanitize_email( $admin_email );
		}

		return array_values( array_unique( array_filter( $recipients ) ) );
	}

	/**
	 * Send an editorial review decision email to the article owner.
	 *
	 * The recipient is always derived from `$post->post_author` so a reviewer
	 * decision can never be sent to another writer. Private article body content
	 * is never included in the email. Mail failures are recorded on the author
	 * account without rolling back the editorial decision.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $action   Reviewer action (`request_changes`, `reject`, `reopen`, `approve`).
	 * @param string $feedback Optional reviewer feedback.
	 * @return bool
	 */
	public static function send_editorial_decision_notification( $post_id, $action, $feedback = '' ) {
		$post   = get_post( (int) $post_id );
		$action = (string) $action;

		if ( ! $post || 'post' !== $post->post_type || ! Penalis_Editorial_Policy::is_reviewer_action( $action ) ) {
			return false;
		}

		$author_id = (int) $post->post_author;
		$author    = get_userdata( $author_id );
		if ( ! $author || ! is_email( $author->user_email ) ) {
			if ( $author_id > 0 ) {
				Penalis_Account_Status::record_email_failure( $author_id, 'invalid_recipient' );
			}
			return false;
		}

		$title      = self::safe_article_title( $post );
		$clean_note = wp_strip_all_tags( trim( (string) $feedback ) );
		$editor_url = add_query_arg(
			array(
				'view' => 'editor',
				'id'   => (int) $post->ID,
			),
			Penalis_Dashboard::dashboard_url()
		);
		$public_url = get_permalink( (int) $post->ID );

		$key = 'editorial_' . $action;

		return self::send_template(
			$author->user_email,
			$key,
			array(
				'{nama_penulis}'      => $author->display_name,
				'{judul_karya}'       => $title,
				'{catatan_review}'    => '' !== $clean_note ? $clean_note : '',
				'{link_editor}'       => $editor_url,
				'{link_karya_terbit}' => is_string( $public_url ) ? $public_url : '',
			),
			$author_id,
			$key
		);
	}

	/**
	 * Render a configured email template, send it to `$recipient`, and optionally
	 * record a delivery failure on `$failure_user_id`.
	 *
	 * @param string               $recipient       Destination email address.
	 * @param string               $template_key    Email settings registry key.
	 * @param array<string,string> $vars            Placeholder replacements.
	 * @param int                  $failure_user_id Optional user ID to record mail failure on.
	 * @param string               $failure_context Optional failure context key.
	 * @return bool
	 */
	public static function send_template( $recipient, $template_key, $vars = array(), $failure_user_id = 0, $failure_context = '' ) {
		$rendered = Penalis_Email_Settings::render( $template_key, $vars );
		$sent     = self::send_mail(
			$recipient,
			$rendered['subject'],
			$rendered['body'],
			$rendered['footer']
		);

		if ( ! $sent && $failure_user_id > 0 && '' !== $failure_context ) {
			Penalis_Account_Status::record_email_failure( (int) $failure_user_id, $failure_context );
		}

		return (bool) $sent;
	}

	/**
	 * Extract a sanitized plain-text article title without exposing post body.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	private static function safe_article_title( $post ) {
		return Penalis_Editorial_Notifications::safe_title( $post );
	}

	/**
	 * Render and send one branded HTML email.
	 *
	 * The content-type header is scoped to this message; no global mail filter
	 * can accidentally change messages sent by WordPress or another plugin.
	 *
	 * @param string $to             Recipient.
	 * @param string $subject        Subject.
	 * @param string $message        Plain-text source content.
	 * @param string $footer_context Purpose-specific recipient guidance.
	 * @return bool Whether wp_mail accepted the message.
	 */
	public static function send_mail( $to, $subject, $message, $footer_context = '' ) {
		$html = Penalis_Email_Template::render( (string) $message, (string) $subject, (string) $footer_context );
		return (bool) wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}
}
