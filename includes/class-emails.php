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

		$subject = sprintf( '[%s] Konfirmasi alamat email kamu', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );

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
		$subject = sprintf( '[%s] Atur ulang kata sandi kamu', $site );

		$message  = self::greeting( $user ) . "\n\n";
		$message .= sprintf( 'Kami menerima permintaan untuk mengatur ulang kata sandi akun %s kamu.', $site ) . "\n\n";
		$message .= "Buka tautan berikut lalu masukkan kata sandi baru:\n\n";
		$message .= $reset_url . "\n\n";
		$message .= "Tautan ini hanya dapat digunakan sekali. Jika kamu tidak meminta pengaturan ulang, abaikan email ini; kata sandi kamu tidak akan berubah.\n";

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
		$subject = sprintf( '[%s] Aktifkan akun penulis lama kamu', $site );

		$message  = self::greeting( $user ) . "\n\n";
		$message .= sprintf( 'Kamu pernah mengirim karya tulis ke %s, dan kami sudah membuatkan akun penulis untukmu.', $site ) . "\n\n";
		$message .= "Aktifkan akun tersebut dengan membuka tautan ini lalu membuat kata sandi:\n\n";
		$message .= $activate_url . "\n\n";
		$message .= "Karya tulis yang sudah terbit tetap terhubung ke akun ini.\n\n";
		$message .= "Tautan berlaku 24 jam dan hanya dapat digunakan sekali. Jika kamu tidak meminta aktivasi, abaikan email ini.\n";

		$sent = wp_mail( $user->user_email, $subject, $message );

		if ( ! $sent ) {
			Penalis_Account_Status::record_email_failure( $user_id, 'legacy_activation' );
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

		$subject  = sprintf( '[%s] Pendaftaran penulis menunggu persetujuan', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$message  = "Ada pendaftaran penulis yang menunggu tinjauan.\n\n";
		$message .= 'Nama tampilan: ' . $user->display_name . "\n";
		$message .= 'Nama pengguna: ' . $user->user_login . "\n";
		$message .= 'Terdaftar: ' . $user->user_registered . "\n\n";
		$message .= 'Tinjau pendaftaran: ' . admin_url( 'users.php?page=penalis-writers&status=pending_approval' ) . "\n";

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
				$subject = sprintf( '[%s] Akun penulis kamu telah aktif', $site );
				$body    = self::greeting( $user ) . "\n\nAkun penulis kamu telah disetujui. Kamu kini dapat membuat dan menerbitkan karya tulis.\n\nBuka dasbor kamu: " . $login . "\n";
				break;

			case Penalis_Policy::STATUS_REJECTED:
				$subject = sprintf( '[%s] Pembaruan pendaftaran penulis kamu', $site );
				$body    = self::greeting( $user ) . "\n\nPendaftaran penulis kamu belum disetujui saat ini. Jika kamu merasa ini keliru, silakan hubungi admin Penalis.\n";
				break;

			case Penalis_Policy::STATUS_SUSPENDED:
				$subject = sprintf( '[%s] Akun penulis kamu ditangguhkan', $site );
				$body    = self::greeting( $user ) . "\n\nAkun penulis kamu ditangguhkan, sehingga menerbitkan dan mengubah karya tulis untuk sementara tidak tersedia. Hubungi admin Penalis jika ada pertanyaan.\n";
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
