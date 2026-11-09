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
		$labels = array(
			'registration' => 'Verifikasi pendaftaran',
			'legacy'       => 'Aktivasi akun lama',
			'email_change' => 'Konfirmasi email baru',
		);

		if ( ! isset( $labels[ $purpose ] ) ) {
			return false;
		}

		$label = $labels[ $purpose ];
		$body  = $label . "\n\nKode verifikasi kamu:\n\n" . $code . "\n\nMasukkan kode ini di dashboard Penalis. Berlaku 10 menit, maksimal lima percobaan. Jangan bagikan kode ini kepada siapa pun. Jika bukan kamu yang meminta, abaikan email ini.";

		// The code is the only proof in this flow, so it must not be quoted in
		// the message someone forwards or in an auto-reply that quotes the
		// original message. The footer is set explicitly for that reason.
		$sent = self::send_mail(
			$email,
			'[Penalis] ' . $label,
			$body,
			'Email ini dikirim otomatis. Kamu menerima email ini karena ada permintaan verifikasi pada akun Penalis yang terhubung ke alamat ini. Jangan bagikan kode verifikasi kepada siapa pun, termasuk yang mengaku sebagai admin Penalis. Jika kamu tidak meminta kode ini, abaikan email ini dan kode akan kedaluwarsa dengan sendirinya. Jika ada pertanyaan atau kendala, hubungi admin@penalis.com.'
		);

		if ( ! $sent ) {
			Penalis_Account_Status::record_email_failure( $user_id, $purpose );
		}

		return $sent;
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
				'penalis_login' => rawurlencode( $user->user_login ),
			),
			Penalis_Dashboard::registration_url()
		);

		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = sprintf( '[%s] Reset Password Kamu', $site );

		$message  = self::greeting( $user ) . "\n\n";
		$message .= sprintf( 'Kami menerima permintaan untuk reset password akun %s kamu.', $site ) . "\n\n";
		$message .= "Buka link berikut lalu masukkan password baru:\n\n";
		$message .= $reset_url . "\n\n";
		$message .= "Link ini hanya dapat digunakan sekali. Jika kamu tidak meminta reset, abaikan email ini; password kamu tidak akan berubah.\n";

		$sent = self::send_mail( $user->user_email, $subject, $message, 'Email ini dikirim otomatis. Kamu menerima email ini karena kami menerima permintaan reset password untuk akun Penalis kamu. Jika kamu tidak meminta reset, abaikan email ini. Jangan bagikan isi email ini kepada siapapun. Jika ada pertanyaan atau kendala, hubungi admin@penalis.com.' );

		if ( ! $sent ) {
			Penalis_Account_Status::record_email_failure( $user_id, 'password_reset' );
		}

		return (bool) $sent;
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

		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = sprintf( '[%s] Username Akun Kamu Telah Diubah', $site );

		$message  = self::greeting( $user ) . "\n\n";
		$message .= sprintf( 'Username akun %s kamu baru saja diubah menjadi %s.', $site, (string) $new_login ) . "\n\n";
		$message .= "Semua sesi yang sedang aktif telah diakhiri. Login kembali dengan username baru kamu.\n\n";
		$message .= "Jika kamu yang melakukan perubahan ini, tidak ada tindakan lain yang perlu dilakukan.\n\n";
		$message .= "Jika bukan kamu, segera reset password akun kamu dan hubungi admin Penalis:\n\n";
		$message .= Penalis_Dashboard::dashboard_url() . "\n";

		$sent = self::send_mail( $email, $subject, $message, 'Email ini dikirim otomatis. Kamu menerima email ini karena username akun Penalis kamu baru saja diubah. Jika bukan kamu yang melakukan perubahan ini, segera reset password akun Penalis kamu. Jika ada pertanyaan atau kendala, hubungi admin@penalis.com.' );

		if ( ! $sent ) {
			Penalis_Account_Status::record_email_failure( $user_id, 'username_change_notice' );
		}

		return (bool) $sent;
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

		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = sprintf( '[%s] Alamat Email Akun Kamu Telah Diubah', $site );

		$message  = self::greeting( $user ) . "\n\n";
		$message .= sprintf( 'Alamat email akun %s kamu baru saja diubah menjadi %s.', $site, $new_email ) . "\n\n";
		$message .= "Jika kamu yang melakukan perubahan ini, tidak ada tindakan yang perlu dilakukan.\n\n";
		$message .= "Jika bukan kamu, segera ubah password akun kamu dan hubungi admin Penalis:\n\n";
		$message .= Penalis_Dashboard::dashboard_url() . "\n";

		$sent = self::send_mail( $old_email, $subject, $message, 'Email ini dikirim otomatis. Kamu menerima email ini karena alamat email akun Penalis kamu baru saja diubah. Jika bukan kamu yang melakukan perubahan ini, segera reset password akun Penalis kamu. Jika ada pertanyaan atau kendala, hubungi admin@penalis.com.' );

		if ( ! $sent ) {
			Penalis_Account_Status::record_email_failure( $user_id, 'email_change_notice' );
		}

		return (bool) $sent;
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

		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$login   = Penalis_Dashboard::dashboard_url();
		$subject = '';
		$body    = '';

		switch ( $status ) {
			case Penalis_Policy::STATUS_ACTIVE:
				$subject = sprintf( '[%s] Akun Kamu Sudah Aktif', $site );
				$body    = self::greeting( $user ) . "\n\n" . sprintf( 'Akun %s kamu kini sudah aktif. Kamu sudah dapat menulis dan mengajukan karya tulis melalui dasbor penulis.', $site ) . "\n\nBuka dasbor penulis:\n\n" . $login . "\n";
				break;

			case Penalis_Policy::STATUS_SUSPENDED:
				$subject = sprintf( '[%s] Akun Kamu Ditangguhkan', $site );
				$body    = self::greeting( $user ) . "\n\n" . sprintf( 'Akun %s kamu ditangguhkan, sehingga pengelolaan karya tulis untuk sementara tidak tersedia. Hubungi admin %s jika ada pertanyaan.', $site, $site ) . "\n";
				break;
		}

		if ( '' === $subject ) {
			return false;
		}

		$sent = self::send_mail( $user->user_email, $subject, $body, 'Email ini dikirim otomatis. Kamu menerima email ini karena kami perlu mengirim pemberitahuan tentang status akun Penalis kamu yang terhubung ke email ini. Jika ada pertanyaan atau kendala, hubungi admin@penalis.com.' );

		if ( ! $sent ) {
			Penalis_Account_Status::record_email_failure( $user_id, 'status_notification' );
		}

		return (bool) $sent;
	}

	/**
	 * Notify the site administrator that a writer submitted or resubmitted an article.
	 *
	 * Only the article title, author identity, review cycle, and review URL are
	 * included; private draft body content is never sent over email. Mail
	 * delivery failures are recorded on the author's account without rolling
	 * back the submission.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $action  Writer workflow action (`submit` or `resubmit`).
	 * @return bool
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

		$admin_email = get_option( 'admin_email' );
		if ( ! is_email( $admin_email ) ) {
			Penalis_Account_Status::record_email_failure( $author_id, 'editorial_' . $action );
			return false;
		}

		$site       = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$title      = self::safe_article_title( $post );
		$cycle      = max( 1, Penalis_Editorial_Workflow::get_review_cycle( $post ) );
		$review_url = admin_url( 'admin.php?page=penalis-review&post_id=' . (int) $post->ID );

		if ( Penalis_Editorial_Policy::ACTION_RESUBMIT === $action ) {
			$subject = sprintf( '[%s] Revisi Karya Tulis Menunggu Tinjauan', $site );
			$intro   = sprintf( 'Ada revisi karya tulis di %s yang diajukan ulang dan menunggu tinjauan admin.', $site );
		} else {
			$subject = sprintf( '[%s] Karya Tulis Baru Menunggu Tinjauan', $site );
			$intro   = sprintf( 'Ada karya tulis baru di %s yang menunggu tinjauan admin.', $site );
		}

		$message  = $intro . "\n\n";
		$message .= 'Judul Karya: ' . $title . "\n";
		$message .= 'Penulis: ' . $author->display_name . ' (' . $author->user_login . ")\n";
		$message .= 'Putaran Tinjauan: #' . $cycle . "\n\n";
		$message .= "Tinjau karya tulis melalui WordPress Admin:\n\n";
		$message .= $review_url . "\n";

		$sent = self::send_mail(
			$admin_email,
			$subject,
			$message,
			'Email ini dikirim otomatis untuk admin Penalis sebagai pemberitahuan bahwa ada karya tulis yang menunggu peninjauan editorial.'
		);

		if ( ! $sent ) {
			Penalis_Account_Status::record_email_failure( $author_id, 'editorial_' . $action );
		}

		return (bool) $sent;
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

		$site       = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$title      = self::safe_article_title( $post );
		$clean_note = wp_strip_all_tags( trim( (string) $feedback ) );
		$editor_url = add_query_arg(
			array(
				'view' => 'editor',
				'id'   => (int) $post->ID,
			),
			Penalis_Dashboard::dashboard_url()
		);

		$subject = '';
		$body    = self::greeting( $author ) . "\n\n";

		switch ( $action ) {
			case Penalis_Editorial_Policy::ACTION_REQUEST_CHANGES:
				$subject = sprintf( '[%s] Karya Tulis Kamu Perlu Revisi', $site );
				$body   .= sprintf( 'Admin %s telah meninjau karya tulis kamu yang berjudul "%s" dan meminta beberapa revisi sebelum dapat diterbitkan.', $site, $title ) . "\n\n";
				if ( '' !== $clean_note ) {
					$body .= "Catatan tinjauan admin:\n" . $clean_note . "\n\n";
				}
				$body .= "Buka dasbor penulis untuk memperbaiki draf dan mengajukannya ulang:\n\n";
				$body .= $editor_url . "\n";
				break;

			case Penalis_Editorial_Policy::ACTION_REJECT:
				$subject = sprintf( '[%s] Pembaruan Tinjauan Karya Tulis Kamu', $site );
				$body   .= sprintf( 'Terima kasih telah mengirimkan karya tulis berjudul "%s" ke %s. Setelah ditinjau oleh admin, karya tulis ini belum dapat diterbitkan.', $title, $site ) . "\n\n";
				if ( '' !== $clean_note ) {
					$body .= "Catatan tinjauan admin:\n" . $clean_note . "\n\n";
				}
				$body .= "Karya tulis yang ditolak tidak dapat diajukan ulang kecuali dibuka kembali oleh admin. Kamu dapat melihat detail tinjauan di dasbor penulis:\n\n";
				$body .= $editor_url . "\n";
				break;

			case Penalis_Editorial_Policy::ACTION_REOPEN:
				$subject = sprintf( '[%s] Karya Tulis Kamu Dibuka Kembali untuk Revisi', $site );
				$body   .= sprintf( 'Admin %s telah membuka kembali karya tulis kamu yang berjudul "%s". Kamu kini dapat menyunting draf tersebut dan mengajukannya ulang untuk ditinjau.', $site, $title ) . "\n\n";
				if ( '' !== $clean_note ) {
					$body .= "Catatan admin:\n" . $clean_note . "\n\n";
				}
				$body .= "Buka karya tulis kamu di dasbor penulis:\n\n";
				$body .= $editor_url . "\n";
				break;

			case Penalis_Editorial_Policy::ACTION_APPROVE:
				$subject    = sprintf( '[%s] Karya Tulis Kamu Telah Terbit', $site );
				$public_url = get_permalink( (int) $post->ID );
				$body      .= sprintf( 'Selamat! Karya tulis kamu yang berjudul "%s" telah disetujui admin dan kini resmi terbit di %s.', $title, $site ) . "\n\n";
				if ( '' !== $clean_note ) {
					$body .= "Catatan admin:\n" . $clean_note . "\n\n";
				}
				if ( is_string( $public_url ) && '' !== $public_url ) {
					$body .= 'Lihat karya tulis yang telah terbit: ' . $public_url . "\n\n";
				}
				$body .= "Buka detail karya tulis di dasbor penulis:\n\n";
				$body .= $editor_url . "\n";
				break;
		}

		if ( '' === $subject ) {
			return false;
		}

		$sent = self::send_mail(
			$author->user_email,
			$subject,
			$body,
			'Email ini dikirim otomatis. Kamu menerima email ini karena ada pembaruan status tinjauan karya tulis di akun Penalis kamu. Jika ada pertanyaan atau kendala, hubungi admin@penalis.com.'
		);

		if ( ! $sent ) {
			Penalis_Account_Status::record_email_failure( $author_id, 'editorial_' . $action );
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
		$title = wp_strip_all_tags( wp_specialchars_decode( (string) $post->post_title, ENT_QUOTES ) );
		$title = trim( preg_replace( '/\s+/', ' ', $title ) );

		return '' !== $title ? $title : 'Tanpa judul';
	}

	/**
	 * Render and send one branded HTML email.
	 *
	 * The content-type header is scoped to this message; no global mail filter
	 * can accidentally change messages sent by WordPress or another plugin.
	 *
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param string $message        Plain-text source content.
	 * @param string $footer_context Purpose-specific recipient guidance.
	 * @return bool Whether wp_mail accepted the message.
	 */
	private static function send_mail( $to, $subject, $message, $footer_context = '' ) {
		$html = Penalis_Email_Template::render( (string) $message, (string) $subject, (string) $footer_context );
		return (bool) wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/**
	 * Greeting line.
	 *
	 * @param WP_User $user User.
	 * @return string
	 */
	private static function greeting( $user ) {
		return 'Halo ' . $user->display_name . ',';
	}
}
