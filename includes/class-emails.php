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

		$subject = sprintf( '[%s] Konfirmasi Alamat Email Kamu', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );

		$message  = self::greeting( $user ) . "\n\n";
		$message .= sprintf( 'Terima kasih telah mendaftar untuk menulis di %s.', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) . "\n\n";
		$message .= "Konfirmasi alamat email kamu dengan membuka link ini lalu menekan tombol konfirmasi:\n\n";
		$message .= $verify_url . "\n\n";
		$message .= "Link berlaku 24 jam dan hanya dapat digunakan sekali.\n\n";
		$message .= "Jika kamu tidak membuat akun ini, abaikan email ini.\n";

		$sent = wp_mail( $user->user_email, $subject, $message );

		if ( ! $sent ) {
			Penalis_Account_Status::record_email_failure( $user_id, 'verification' );
		}

		return (bool) $sent;
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

		$sent = wp_mail( $user->user_email, $subject, $message );

		if ( ! $sent ) {
			Penalis_Account_Status::record_email_failure( $user_id, 'password_reset' );
		}

		return (bool) $sent;
	}

	/**
	 * Send the legacy account activation email.
	 *
	 * Legacy contributors never received a password, so this email is the only
	 * way for them to reach their existing profile. The link points at the
	 * dashboard and the token is never logged.
	 *
	 * @param int    $user_id User ID.
	 * @param string $token   Raw activation token.
	 * @return bool Whether wp_mail accepted the message.
	 */
	public static function send_legacy_activation( $user_id, $token ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			Penalis_Account_Status::record_email_failure( $user_id, 'invalid_recipient' );
			return false;
		}

		$activate_url = add_query_arg(
			array(
				'penalis_activate' => rawurlencode( (string) $token ),
			),
			Penalis_Dashboard::registration_url()
		);

		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = sprintf( '[%s] Aktifkan Akun Kontributor Kamu', $site );

		$message  = self::greeting( $user ) . "\n\n";
		$message .= sprintf( 'Kamu pernah menjadi kontributor dan mengirim karya tulis ke %s, dan kami sudah membuatkan akun untukmu.', $site ) . "\n\n";
		$message .= "Aktifkan akun tersebut dengan membuka link ini lalu membuat password:\n\n";
		$message .= $activate_url . "\n\n";
		$message .= "Karya tulis yang sudah publish dari akun kontributor akan tetap terhubung ke akun ini.\n\n";
		$message .= "Link berlaku 24 jam dan hanya dapat digunakan sekali. Jika kamu tidak meminta aktivasi, abaikan email ini.\n";

		$sent = wp_mail( $user->user_email, $subject, $message );

		if ( ! $sent ) {
			Penalis_Account_Status::record_email_failure( $user_id, 'legacy_activation' );
		}

		return (bool) $sent;
	}

	/**
	 * Send the confirmation link for a requested email address change.
	 *
	 * The message goes to the *new* address, because that is the address whose
	 * ownership is being proven. The old address is untouched until the link is
	 * consumed.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $token     Raw confirmation token.
	 * @param string $new_email Requested address.
	 * @return bool Whether wp_mail accepted the message.
	 */
	public static function send_email_change_confirmation( $user_id, $token, $new_email ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user || ! is_email( $new_email ) ) {
			Penalis_Account_Status::record_email_failure( $user_id, 'invalid_recipient' );
			return false;
		}

		$confirm_url = add_query_arg(
			array(
				'penalis_email_change' => rawurlencode( (string) $token ),
			),
			Penalis_Dashboard::registration_url()
		);

		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = sprintf( '[%s] Konfirmasi Alamat Email Baru Kamu', $site );

		$message  = self::greeting( $user ) . "\n\n";
		$message .= sprintf( 'Kami menerima permintaan untuk mengganti alamat email akun %s kamu menjadi alamat ini.', $site ) . "\n\n";
		$message .= "Konfirmasi alamat email baru dengan membuka link ini lalu menekan tombol konfirmasi:\n\n";
		$message .= $confirm_url . "\n\n";
		$message .= "Alamat email kamu yang sekarang belum berubah. Alamat baru hanya dipakai setelah kamu mengonfirmasi.\n\n";
		$message .= "Link berlaku 24 jam dan hanya dapat digunakan sekali. Jika kamu tidak meminta perubahan ini, abaikan email ini.\n";

		$sent = wp_mail( $new_email, $subject, $message );

		if ( ! $sent ) {
			Penalis_Account_Status::record_email_failure( $user_id, 'email_change_confirmation' );
		}

		return (bool) $sent;
	}

	/**
	 * Notify the previous address that the account email changed.
	 *
	 * This is the only warning the real owner receives if the change was not
	 * theirs, so it is sent after the change is committed and never blocks it.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $old_email Previous address.
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

		$sent = wp_mail( $old_email, $subject, $message );

		if ( ! $sent ) {
			Penalis_Account_Status::record_email_failure( $user_id, 'email_change_notice' );
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

		$subject  = sprintf( '[%s] Pendaftaran Akun Menunggu Persetujuan', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$message  = "Ada pendaftaran akun %s yang menunggu tinjauan.\n\n";
		$message .= 'Nama Penulis: ' . $user->display_name . "\n";
		$message .= 'Username Penulis: ' . $user->user_login . "\n";
		$message .= 'Waktu Terdaftar: ' . $user->user_registered . "\n\n";
		$message .= 'Tinjau pendaftaran melalui WordPress Admin: ' . admin_url( 'users.php?page=penalis-writers&status=pending_approval' ) . "\n";

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
				$subject = sprintf( '[%s] Akun Kamu Sudah Aktif', $site );
				$body    = self::greeting( $user ) . "\n\nAkun %s kamu sudah disetujui. Kamu kini dapat mengelola karya tulis.\n\nBuka dashboard Penulis: " . $login . "\n";
				break;

			case Penalis_Policy::STATUS_REJECTED:
				$subject = sprintf( '[%s] Pembaruan Pendaftaran Akun Kamu', $site );
				$body    = self::greeting( $user ) . "\n\nPendaftaran akun %s kamu belum disetujui untuk saat ini. Jika kamu merasa ini keliru, silakan hubungi admin %s.\n";
				break;

			case Penalis_Policy::STATUS_SUSPENDED:
				$subject = sprintf( '[%s] Akun Kamu Ditangguhkan', $site );
				$body    = self::greeting( $user ) . "\n\nAkun %s kamu ditangguhkan, sehingga pengelolaan karya tulis untuk sementara tidak tersedia. Hubungi admin %s jika ada pertanyaan.\n";
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
		return 'Halo ' . $user->display_name . ',';
	}
}
