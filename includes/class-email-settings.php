<?php
/**
 * Customizable transactional email settings.
 *
 * Allows administrators to customize email subjects, body messages, and footers
 * directly from wp-admin while ensuring mandatory secrets (such as OTP codes
 * and password reset links) are safely preserved.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages email template configuration, placeholders, and admin settings interface.
 */
final class Penalis_Email_Settings {

	/**
	 * Retired page slug, kept only so old links can be redirected.
	 */
	public const PAGE_SLUG   = 'penalis-email-settings';
	public const OPTION_NAME = 'penalis_email_templates';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'redirect_legacy_slug' ) );
		add_action( 'admin_post_penalis_save_email_settings', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_penalis_reset_email_template', array( __CLASS__, 'handle_reset_single' ) );
		add_action( 'admin_post_penalis_reset_all_email_settings', array( __CLASS__, 'handle_reset_all' ) );
	}

	/**
	 * URL of the Email tab on the centralized settings screen.
	 *
	 * @param array<string,mixed> $args Optional extra query arguments.
	 * @return string
	 */
	public static function tab_url( array $args = array() ) {
		return Penalis_Settings::url( array_merge( array( 'tab' => 'email' ), $args ) );
	}

	/**
	 * Redirect the retired `?page=penalis-email-settings` URL to its new tab.
	 *
	 * The email templates moved into Penalis → Pengaturan so every site-wide
	 * setting lives in one place. Bookmarks and old notices still point at the
	 * previous slug, so they are forwarded instead of hitting an access error.
	 *
	 * @return void
	 */
	public static function redirect_legacy_slug() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect source.

		if ( self::PAGE_SLUG !== $page || ! self::can_manage() ) {
			return;
		}

		wp_safe_redirect( self::tab_url() );
		exit;
	}

	/**
	 * Build a definition entry for a six-digit OTP verification email.
	 *
	 * @param string $title       Admin template title.
	 * @param string $description Admin template description.
	 * @param string $heading     Subject suffix and first body line.
	 * @return array<string,mixed>
	 */
	private static function otp_definition( $title, $description, $heading ) {
		return array(
			'title'           => $title,
			'description'     => $description,
			'group'           => 'verifikasi',
			'default_subject' => '[Penalis] ' . $heading,
			'default_body'    => $heading . "\n\nKode verifikasi kamu:\n\n{kode_otp}\n\nMasukkan kode ini di dashboard Penalis. Berlaku {menit_berlaku} menit, maksimal {maksimal_percobaan} percobaan. Jangan bagikan kode ini kepada siapa pun. Jika bukan kamu yang meminta, abaikan email ini.",
			'default_footer'  => 'Email ini dikirim otomatis. Kamu menerima email ini karena ada permintaan verifikasi pada akun Penalis yang terhubung ke alamat ini. Jangan bagikan kode verifikasi kepada siapa pun, termasuk yang mengaku sebagai admin Penalis. Jika kamu tidak meminta kode ini, abaikan email ini dan kode akan kedaluwarsa dengan sendirinya. Jika ada pertanyaan atau kendala, hubungi admin@penalis.com.',
			'tags'            => array(
				'{kode_otp}'           => '6 digit kode verifikasi rahasia (Wajib)',
				'{menit_berlaku}'      => 'Masa berlaku kode (contoh: 10)',
				'{maksimal_percobaan}' => 'Batas percobaan input kode (contoh: 5)',
				'{nama_penulis}'       => 'Nama tampilan penulis',
				'{nama_situs}'         => 'Nama website situs',
				'{link_dashboard}'     => 'URL halaman dashboard penulis',
			),
			'required_tags'   => array( '{kode_otp}' ),
		);
	}

	/**
	 * Registry of all email template definitions and their defaults.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_registry() {
		return array(
			'otp_registration'          => self::otp_definition(
				'Verifikasi Pendaftaran Penulis Baru',
				'Dikirim ke calon penulis yang mendaftar di dashboard untuk memverifikasi alamat email menggunakan kode OTP 6 digit.',
				'Verifikasi pendaftaran'
			),
			'otp_legacy'                => self::otp_definition(
				'Aktivasi Akun Penulis Lama',
				'Dikirim saat penulis/kontributor lama meminta aktivasi akun atau diaktivasi oleh administrator.',
				'Aktivasi akun lama'
			),
			'otp_email_change'          => self::otp_definition(
				'Konfirmasi Penggantian Alamat Email',
				'Dikirim ke alamat email baru yang diajukan oleh penulis untuk memverifikasi kepemilikan email baru.',
				'Konfirmasi email baru'
			),
			'duplicate_notice'          => array(
				'title'           => 'Pemberitahuan Percobaan Pendaftaran Akun Ganda',
				'description'     => 'Dikirim ke pemilik akun terdaftar saat seseorang mencoba mendaftar menggunakan alamat email miliknya.',
				'group'           => 'keamanan',
				'default_subject' => '[{nama_situs}] Percobaan Pendaftaran',
				'default_body'    => "Seseorang mencoba mendaftar menggunakan alamat email kamu.\n\nJika ini adalah kamu, silakan masuk ke dashboard penulis atau reset password kamu.\n\n{link_dashboard}\n\nJika bukan kamu, tidak ada tindakan yang perlu dilakukan.",
				'default_footer'  => 'Email ini dikirim otomatis untuk keamanan akun Penalis kamu. Jika bukan kamu yang mencoba mendaftar, akun kamu tetap aman dan tidak ada tindakan yang perlu dilakukan. Hubungi admin@penalis.com jika ada pertanyaan.',
				'tags'            => array(
					'{nama_penulis}'   => 'Nama tampilan pemilik akun',
					'{nama_situs}'     => 'Nama website situs',
					'{link_dashboard}' => 'URL halaman dashboard penulis',
				),
				'required_tags'   => array(),
			),
			'legacy_register_notice'    => array(
				'title'           => 'Pemberitahuan Pendaftaran Akun Kontributor Lama',
				'description'     => 'Dikirim ke pemilik akun kontributor lama saat seseorang mencoba mendaftar menggunakan email yang sudah terdaftar.',
				'group'           => 'keamanan',
				'default_subject' => '[{nama_situs}] Akun Kontributor Kamu Sudah Terdaftar',
				'default_body'    => "Halo {nama_penulis},\n\nKami menerima upaya pendaftaran akun baru di {nama_situs} menggunakan alamat email ini.\n\nKamu sebelumnya sudah terdaftar sebagai kontributor Penalis, sehingga kamu tidak perlu mendaftar dari awal. Seluruh karya tulis yang pernah kamu kirimkan tetap aman dan terhubung dengan akun kamu.\n\nUntuk mulai menulis dan mengelola karya, silakan aktifkan akun lamamu dan buat password baru melalui tautan berikut:\n\n{link_aktivasi}\n\nJika bukan kamu yang mencoba mendaftar, abaikan email ini. Akun kamu tetap aman.",
				'default_footer'  => 'Email ini dikirim otomatis untuk keamanan akun Penalis kamu. Jika bukan kamu yang mencoba mendaftar, akun kamu tetap aman dan tidak ada tindakan yang perlu dilakukan. Hubungi admin@penalis.com jika ada pertanyaan.',
				'tags'            => array(
					'{nama_penulis}'  => 'Nama tampilan pemilik akun',
					'{nama_situs}'    => 'Nama website situs',
					'{link_aktivasi}' => 'Tautan langsung ke form aktivasi akun kontributor lama (Wajib)',
				),
				'required_tags'   => array( '{link_aktivasi}' ),
			),
			'password_reset'            => array(
				'title'           => 'Reset Password Akun Penulis',
				'description'     => 'Dikirim saat penulis meminta reset password melalui form Lupa Password.',
				'group'           => 'keamanan',
				'default_subject' => '[{nama_situs}] Reset Password Kamu',
				'default_body'    => "Halo {nama_penulis},\n\nKami menerima permintaan untuk reset password akun {nama_situs} kamu.\n\nBuka link berikut lalu masukkan password baru:\n\n{link_reset_password}\n\nLink ini hanya dapat digunakan sekali. Jika kamu tidak meminta reset, abaikan email ini; password kamu tidak akan berubah.",
				'default_footer'  => 'Email ini dikirim otomatis. Kamu menerima email ini karena kami menerima permintaan reset password untuk akun Penalis kamu. Jika kamu tidak meminta reset, abaikan email ini. Jangan bagikan isi email ini kepada siapapun. Jika ada pertanyaan atau kendala, hubungi admin@penalis.com.',
				'tags'            => array(
					'{nama_penulis}'        => 'Nama tampilan penulis',
					'{nama_situs}'          => 'Nama website situs',
					'{link_reset_password}' => 'Tautan sekali pakai untuk atur password baru (Wajib)',
				),
				'required_tags'   => array( '{link_reset_password}' ),
			),
			'username_change'           => array(
				'title'           => 'Notifikasi Perubahan Username',
				'description'     => 'Dikirim ke penulis setelah username akunnya berhasil diubah di menu Pengaturan.',
				'group'           => 'keamanan',
				'default_subject' => '[{nama_situs}] Username Akun Kamu Telah Diubah',
				'default_body'    => "Halo {nama_penulis},\n\nUsername akun {nama_situs} kamu baru saja diubah menjadi {username_baru}.\n\nSemua sesi yang sedang aktif telah diakhiri. Login kembali dengan username baru kamu.\n\nJika kamu yang melakukan perubahan ini, tidak ada tindakan lain yang perlu dilakukan.\n\nJika bukan kamu, segera reset password akun kamu dan hubungi admin Penalis:\n\n{link_dashboard}",
				'default_footer'  => 'Email ini dikirim otomatis. Kamu menerima email ini karena username akun Penalis kamu baru saja diubah. Jika bukan kamu yang melakukan perubahan ini, segera reset password akun Penalis kamu. Jika ada pertanyaan atau kendala, hubungi admin@penalis.com.',
				'tags'            => array(
					'{nama_penulis}'   => 'Nama tampilan penulis',
					'{username_baru}'  => 'Username baru yang telah disimpan',
					'{nama_situs}'     => 'Nama website situs',
					'{link_dashboard}' => 'URL halaman dashboard penulis',
				),
				'required_tags'   => array(),
			),
			'email_change'              => array(
				'title'           => 'Pemberitahuan Perubahan Email ke Alamat Lama',
				'description'     => 'Dikirim ke alamat email LAMA setelah alamat email akun berhasil diganti ke alamat baru.',
				'group'           => 'keamanan',
				'default_subject' => '[{nama_situs}] Alamat Email Akun Kamu Telah Diubah',
				'default_body'    => "Halo {nama_penulis},\n\nAlamat email akun {nama_situs} kamu baru saja diubah menjadi {email_baru}.\n\nJika kamu yang melakukan perubahan ini, tidak ada tindakan yang perlu dilakukan.\n\nJika bukan kamu, segera ubah password akun kamu dan hubungi admin Penalis:\n\n{link_dashboard}",
				'default_footer'  => 'Email ini dikirim otomatis. Kamu menerima email ini karena alamat email akun Penalis kamu baru saja diubah. Jika bukan kamu yang melakukan perubahan ini, segera reset password akun Penalis kamu. Jika ada pertanyaan atau kendala, hubungi admin@penalis.com.',
				'tags'            => array(
					'{nama_penulis}'   => 'Nama tampilan penulis',
					'{email_baru}'     => 'Alamat email baru',
					'{nama_situs}'     => 'Nama website situs',
					'{link_dashboard}' => 'URL halaman dashboard penulis',
				),
				'required_tags'   => array(),
			),
			'status_active'             => array(
				'title'           => 'Pemberitahuan Akun Penulis Aktif',
				'description'     => 'Dikirim ke penulis saat akunnya resmi aktif dan siap menulis (setelah verifikasi atau diaktifkan kembali).',
				'group'           => 'status',
				'default_subject' => '[{nama_situs}] Akun Kamu Sudah Aktif',
				'default_body'    => "Halo {nama_penulis},\n\nAkun {nama_situs} kamu kini sudah aktif. Kamu sudah dapat menulis dan submit karya tulis melalui dashboard penulis.\n\nBuka dashboard penulis:\n\n{link_dashboard}",
				'default_footer'  => 'Email ini dikirim otomatis. Kamu menerima email ini karena kami perlu mengirim pemberitahuan tentang status akun Penalis kamu yang terhubung ke email ini. Jika ada pertanyaan atau kendala, hubungi admin@penalis.com.',
				'tags'            => array(
					'{nama_penulis}'   => 'Nama tampilan penulis',
					'{nama_situs}'     => 'Nama website situs',
					'{link_dashboard}' => 'URL halaman dashboard penulis',
				),
				'required_tags'   => array(),
			),
			'status_suspended'          => array(
				'title'           => 'Pemberitahuan Akun Penulis Ditangguhkan',
				'description'     => 'Dikirim ke penulis saat akunnya ditangguhkan oleh administrator.',
				'group'           => 'status',
				'default_subject' => '[{nama_situs}] Akun Kamu Ditangguhkan',
				'default_body'    => "Halo {nama_penulis},\n\nAkun {nama_situs} kamu ditangguhkan, sehingga pengelolaan karya tulis untuk sementara tidak tersedia. Hubungi admin {nama_situs} jika ada pertanyaan.",
				'default_footer'  => 'Email ini dikirim otomatis. Kamu menerima email ini karena kami perlu mengirim pemberitahuan tentang status akun Penalis kamu yang terhubung ke email ini. Jika ada pertanyaan atau kendala, hubungi admin@penalis.com.',
				'tags'            => array(
					'{nama_penulis}' => 'Nama tampilan penulis',
					'{nama_situs}'   => 'Nama website situs',
				),
				'required_tags'   => array(),
			),
			'editorial_submit'          => array(
				'title'           => 'Karya Tulis Baru Masuk (Untuk Tim Senator)',
				'description'     => 'Dikirim ke semua Senator (Editor & Admin) saat penulis mengirimkan karya tulis baru untuk pertama kali.',
				'group'           => 'kurasi',
				'default_subject' => '[{nama_situs}] Karya Tulis Baru Menunggu Review',
				'default_body'    => "Ada karya tulis baru di {nama_situs} yang menunggu review tim editorial.\n\nJudul Karya: {judul_karya}\nPenulis: {nama_penulis} ({username_penulis})\nReview cycle: #{review_cycle}\n\nReview karya tulis melalui WordPress Admin:\n\n{link_review_admin}",
				'default_footer'  => 'Email ini dikirim otomatis untuk tim editorial Penalis sebagai pemberitahuan bahwa ada karya tulis yang menunggu review.',
				'tags'            => array(
					'{judul_karya}'       => 'Judul karya tulis yang di-submit',
					'{nama_penulis}'      => 'Nama tampilan penulis karya',
					'{username_penulis}'  => 'Username penulis',
					'{review_cycle}'      => 'Nomor putaran review (contoh: 1)',
					'{link_review_admin}' => 'Tautan ke layar review di wp-admin (Wajib)',
					'{nama_situs}'        => 'Nama website situs',
				),
				'required_tags'   => array( '{link_review_admin}' ),
			),
			'editorial_resubmit'        => array(
				'title'           => 'Revisi Karya Tulis Masuk (Untuk Tim Senator)',
				'description'     => 'Dikirim ke semua Senator (Editor & Admin) saat penulis mengirim ulang karya yang telah diperbaiki.',
				'group'           => 'kurasi',
				'default_subject' => '[{nama_situs}] Revisi Karya Tulis Menunggu Review',
				'default_body'    => "Ada revisi karya tulis di {nama_situs} yang di-submit ulang dan menunggu review tim editorial.\n\nJudul Karya: {judul_karya}\nPenulis: {nama_penulis} ({username_penulis})\nReview cycle: #{review_cycle}\n\nReview karya tulis melalui WordPress Admin:\n\n{link_review_admin}",
				'default_footer'  => 'Email ini dikirim otomatis untuk tim editorial Penalis sebagai pemberitahuan bahwa ada karya tulis yang menunggu review.',
				'tags'            => array(
					'{judul_karya}'       => 'Judul karya tulis yang direvisi',
					'{nama_penulis}'      => 'Nama tampilan penulis karya',
					'{username_penulis}'  => 'Username penulis',
					'{review_cycle}'      => 'Nomor putaran review (contoh: 2)',
					'{link_review_admin}' => 'Tautan ke layar review di wp-admin (Wajib)',
					'{nama_situs}'        => 'Nama website situs',
				),
				'required_tags'   => array( '{link_review_admin}' ),
			),
			'editorial_request_changes' => array(
				'title'           => 'Permintaan Revisi Karya Tulis (Untuk Penulis)',
				'description'     => 'Dikirim ke penulis ketika Senator meminta revisi atau perbaikan naskah sebelum terbit.',
				'group'           => 'kurasi',
				'default_subject' => '[{nama_situs}] Karya Tulis Kamu Perlu Revisi',
				'default_body'    => "Halo {nama_penulis},\n\nSenator {nama_situs} telah meninjau karya tulis kamu yang berjudul \"{judul_karya}\" dan meminta beberapa revisi sebelum dapat diterbitkan.\n\nCatatan review Senator:\n{catatan_review}\n\nBuka dashboard penulis untuk memperbaiki draft dan submit ulang:\n\n{link_editor}",
				'default_footer'  => 'Email ini dikirim otomatis. Kamu menerima email ini karena ada pembaruan status review karya tulis di akun Penalis kamu. Jika ada pertanyaan atau kendala, hubungi admin@penalis.com.',
				'tags'            => array(
					'{nama_penulis}'   => 'Nama tampilan penulis karya',
					'{judul_karya}'    => 'Judul karya tulis',
					'{catatan_review}' => 'Catatan revisi dari Senator',
					'{link_editor}'    => 'Tautan langsung ke editor dashboard penulis (Wajib)',
					'{nama_situs}'     => 'Nama website situs',
				),
				'required_tags'   => array( '{link_editor}' ),
			),
			'editorial_reject'          => array(
				'title'           => 'Karya Tulis Belum Disetujui / Ditolak (Untuk Penulis)',
				'description'     => 'Dikirim ke penulis ketika Senator menolak karya tulis yang di-submit.',
				'group'           => 'kurasi',
				'default_subject' => '[{nama_situs}] Pembaruan Review Karya Tulis Kamu',
				'default_body'    => "Halo {nama_penulis},\n\nTerima kasih telah mengirimkan karya tulis berjudul \"{judul_karya}\" ke {nama_situs}. Setelah di-review oleh Senator, karya tulis ini belum dapat diterbitkan.\n\nCatatan review Senator:\n{catatan_review}\n\nKarya tulis yang ditolak tidak dapat di-submit ulang kecuali dibuka kembali oleh Senator. Kamu dapat melihat detail review di dashboard penulis:\n\n{link_editor}",
				'default_footer'  => 'Email ini dikirim otomatis. Kamu menerima email ini karena ada pembaruan status review karya tulis di akun Penalis kamu. Jika ada pertanyaan atau kendala, hubungi admin@penalis.com.',
				'tags'            => array(
					'{nama_penulis}'   => 'Nama tampilan penulis karya',
					'{judul_karya}'    => 'Judul karya tulis',
					'{catatan_review}' => 'Alasan / catatan dari Senator',
					'{link_editor}'    => 'Tautan ke detail karya di dashboard penulis',
					'{nama_situs}'     => 'Nama website situs',
				),
				'required_tags'   => array(),
			),
			'editorial_reopen'          => array(
				'title'           => 'Karya Tulis Dibuka Kembali untuk Revisi (Untuk Penulis)',
				'description'     => 'Dikirim ke penulis ketika Senator membuka kembali karya yang sebelumnya ditolak agar dapat direvisi.',
				'group'           => 'kurasi',
				'default_subject' => '[{nama_situs}] Karya Tulis Kamu Dibuka Kembali untuk Revisi',
				'default_body'    => "Halo {nama_penulis},\n\nSenator {nama_situs} telah membuka kembali karya tulis kamu yang berjudul \"{judul_karya}\". Kamu kini dapat edit draft tersebut dan submit ulang untuk di-review.\n\nCatatan Senator:\n{catatan_review}\n\nBuka karya tulis kamu di dashboard penulis:\n\n{link_editor}",
				'default_footer'  => 'Email ini dikirim otomatis. Kamu menerima email ini karena ada pembaruan status review karya tulis di akun Penalis kamu. Jika ada pertanyaan atau kendala, hubungi admin@penalis.com.',
				'tags'            => array(
					'{nama_penulis}'   => 'Nama tampilan penulis karya',
					'{judul_karya}'    => 'Judul karya tulis',
					'{catatan_review}' => 'Catatan dari Senator',
					'{link_editor}'    => 'Tautan ke editor dashboard penulis (Wajib)',
					'{nama_situs}'     => 'Nama website situs',
				),
				'required_tags'   => array( '{link_editor}' ),
			),
			'editorial_approve'         => array(
				'title'           => 'Karya Tulis Disetujui & Diterbitkan (Untuk Penulis)',
				'description'     => 'Dikirim ke penulis saat karyanya telah disetujui kurator dan resmi terbit di situs publik.',
				'group'           => 'kurasi',
				'default_subject' => '[{nama_situs}] Karya Tulis Kamu Telah Terbit',
				'default_body'    => "Halo {nama_penulis},\n\nSelamat! Karya tulis kamu yang berjudul \"{judul_karya}\" telah disetujui Senator dan kini resmi terbit di {nama_situs}.\n\nCatatan Senator:\n{catatan_review}\n\nLihat karya tulis yang telah terbit: {link_karya_terbit}\n\nBuka detail karya tulis di dashboard penulis:\n\n{link_editor}",
				'default_footer'  => 'Email ini dikirim otomatis. Kamu menerima email ini karena ada pembaruan status review karya tulis di akun Penalis kamu. Jika ada pertanyaan atau kendala, hubungi admin@penalis.com.',
				'tags'            => array(
					'{nama_penulis}'      => 'Nama tampilan penulis karya',
					'{judul_karya}'       => 'Judul karya tulis',
					'{catatan_review}'    => 'Catatan kurator (jika ada)',
					'{link_karya_terbit}' => 'Tautan URL publik ke karya tulis yang terbit',
					'{link_editor}'       => 'Tautan ke detail karya di dashboard penulis',
					'{nama_situs}'        => 'Nama website situs',
				),
				'required_tags'   => array(),
			),
			'editorial_trashed'         => array(
				'title'           => 'Karya Terbit Masuk Trash (Untuk Tim Senator)',
				'description'     => 'Dikirim ke semua Senator (Editor & Admin) saat karya tulis yang sedang terbit masuk ke WordPress trash.',
				'group'           => 'kurasi',
				'default_subject' => '[{nama_situs}] Karya terbit masuk trash: {judul_karya}',
				'default_body'    => "Sebuah karya tulis yang sedang terbit di {nama_situs} telah masuk ke WordPress trash.\n\nJudul Karya: {judul_karya}\nPenulis: {nama_penulis} ({username_penulis})\n\nPeriksa dan pulihkan dari konsol Penalis:\n\n{link_trash_admin}",
				'default_footer'  => 'Email ini dikirim otomatis untuk tim editorial Penalis agar karya yang terbit tidak hilang tanpa tercatat.',
				'tags'            => array(
					'{judul_karya}'      => 'Judul karya tulis yang masuk trash',
					'{nama_penulis}'     => 'Nama tampilan penulis karya',
					'{username_penulis}' => 'Username penulis',
					'{link_trash_admin}' => 'Tautan ke halaman Karya Terhapus di wp-admin (Wajib)',
					'{nama_situs}'       => 'Nama website situs',
				),
				'required_tags'   => array( '{link_trash_admin}' ),
			),
		);
	}

	/**
	 * Get the stored template data or default for a specific email key.
	 *
	 * @param string $key Template identifier.
	 * @return array<string,string> Associative array containing 'subject', 'body', 'footer'.
	 */
	public static function get_template( $key ) {
		$registry = self::get_registry();

		if ( ! isset( $registry[ $key ] ) ) {
			return array(
				'subject' => '',
				'body'    => '',
				'footer'  => '',
			);
		}

		$meta   = $registry[ $key ];
		$saved  = get_option( self::OPTION_NAME, array() );
		$custom = is_array( $saved ) && isset( $saved[ $key ] ) && is_array( $saved[ $key ] ) ? $saved[ $key ] : array();

		$subject = isset( $custom['subject'] ) && '' !== trim( (string) $custom['subject'] )
			? (string) $custom['subject']
			: (string) $meta['default_subject'];

		$body = isset( $custom['body'] ) && '' !== trim( (string) $custom['body'] )
			? (string) $custom['body']
			: (string) $meta['default_body'];

		$footer = isset( $custom['footer'] ) && '' !== trim( (string) $custom['footer'] )
			? (string) $custom['footer']
			: (string) $meta['default_footer'];

		return array(
			'subject' => $subject,
			'body'    => $body,
			'footer'  => $footer,
		);
	}

	/**
	 * Render an email template with dynamic variable substitution and safety-net checks.
	 *
	 * @param string               $key       Template key.
	 * @param array<string,string> $variables Associative array of replacement variables (e.g. '{kode_otp}' => '123456').
	 * @return array<string,string> Resolved array containing 'subject', 'body', and 'footer'.
	 */
	public static function render( $key, array $variables = array() ) {
		$template = self::get_template( $key );
		$registry = self::get_registry();
		$meta     = isset( $registry[ $key ] ) ? $registry[ $key ] : null;

		$subject = $template['subject'];
		$body    = $template['body'];
		$footer  = $template['footer'];

		// Ensure standard global variables are present if not passed.
		if ( ! isset( $variables['{nama_situs}'] ) ) {
			$variables['{nama_situs}'] = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		}
		if ( ! isset( $variables['{link_dashboard}'] ) ) {
			$variables['{link_dashboard}'] = Penalis_Dashboard::dashboard_url();
		}

		// Perform substitutions.
		foreach ( $variables as $tag => $value ) {
			$tag_placeholder = 0 === strpos( $tag, '{' ) ? $tag : '{' . $tag . '}';
			$subject         = str_replace( $tag_placeholder, (string) $value, $subject );
			$body            = str_replace( $tag_placeholder, (string) $value, $body );
			$footer          = str_replace( $tag_placeholder, (string) $value, $footer );
		}

		// Safety Net: if a required secret or link tag was missing from custom body copy, append it.
		if ( $meta && ! empty( $meta['required_tags'] ) ) {
			foreach ( (array) $meta['required_tags'] as $required_tag ) {
				$val = isset( $variables[ $required_tag ] ) ? (string) $variables[ $required_tag ] : '';
				if ( '' !== $val && false === strpos( $body, $val ) ) {
					$body .= "\n\n" . $val;
				}
			}
		}

		return array(
			'subject' => $subject,
			'body'    => $body,
			'footer'  => $footer,
		);
	}

	/**
	 * Whether the current user can manage email settings.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Render the Email tab on the centralized settings screen.
	 *
	 * Rendered by `Penalis_Settings::tabs()`; the shell owns the page heading,
	 * the tab navigation, and the save notices.
	 *
	 * @return void
	 */
	public static function render_tab() {
		if ( ! self::can_manage() ) {
			wp_die( 'Kamu tidak memiliki izin untuk mengubah pengaturan email.', '', array( 'response' => 403 ) );
		}

		$registry = self::get_registry();
		$selected = isset( $_GET['template'] ) ? sanitize_key( wp_unslash( $_GET['template'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only template selection.
		$key      = isset( $registry[ $selected ] ) ? $selected : array_key_first( $registry );
		$meta     = $registry[ $key ];
		$current  = self::get_template( $key );
		$saved    = get_option( self::OPTION_NAME, array() );
		$custom   = is_array( $saved ) && isset( $saved[ $key ] );
		$groups   = array(
			'verifikasi' => 'Verifikasi & Aktivasi',
			'keamanan'   => 'Keamanan Akun',
			'status'     => 'Status Penulis',
			'kurasi'     => 'Kurasi Karya Tulis',
		);
		?>
		<section class="penalis-settings__panel penalis-settings__email" id="penalis-email-templates" aria-labelledby="penalis-email-templates-title">
			<h3 id="penalis-email-templates-title">Template Email</h3>
			<p class="description">Ubah subjek, isi, dan footer setiap pesan otomatis.</p>
			<p class="penalis-settings__link"><a href="#penalis-email-branding">Atur identitas &amp; tampilan email</a></p>

			<form class="penalis-settings__template-picker" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) . '#penalis-email-templates' ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( Penalis_Settings::PAGE_SLUG ); ?>">
				<input type="hidden" name="tab" value="email">
				<label for="penalis-email-template">Pilih template</label>
				<div>
					<select name="template" id="penalis-email-template">
						<?php foreach ( $groups as $group => $label ) : ?>
							<optgroup label="<?php echo esc_attr( $label ); ?>">
								<?php foreach ( $registry as $template_key => $definition ) : ?>
									<?php if ( $group === $definition['group'] ) : ?>
										<option value="<?php echo esc_attr( $template_key ); ?>" <?php selected( $template_key, $key ); ?>>
											<?php echo esc_html( $definition['title'] . ' · ' . ( is_array( $saved ) && isset( $saved[ $template_key ] ) ? 'Kustom' : 'Bawaan' ) ); ?>
										</option>
									<?php endif; ?>
								<?php endforeach; ?>
							</optgroup>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="button">Tampilkan</button>
				</div>
			</form>

			<div class="penalis-settings__panel-header penalis-settings__template-heading">
				<h4><?php echo esc_html( $meta['title'] ); ?></h4>
				<span class="penalis-status <?php echo $custom ? 'penalis-status--active' : 'penalis-status--legacy'; ?>"><?php echo $custom ? 'Kustom' : 'Bawaan'; ?></span>
			</div>
			<p class="description"><?php echo esc_html( $meta['description'] ); ?></p>

			<form class="penalis-settings__template-editor" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="penalis_save_email_settings">
				<input type="hidden" name="email_template" value="<?php echo esc_attr( $key ); ?>">
				<?php wp_nonce_field( 'penalis_save_email_settings' ); ?>
				<div class="penalis-settings__email-field">
					<label for="penalis-template-subject">Subjek email</label>
					<input id="penalis-template-subject" name="templates[<?php echo esc_attr( $key ); ?>][subject]" value="<?php echo esc_attr( $current['subject'] ); ?>" type="text">
				</div>

				<?php
				foreach ( array(
					'body'   => 'Isi email',
					'footer' => 'Footer email',
				) as $field => $label ) :
					?>
					<div class="penalis-settings__email-field">
						<div class="penalis-settings__panel-header">
							<label for="penalis-template-<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $label ); ?></label>
							<div class="penalis-settings__email-toolbar" data-email-toolbar hidden>
								<button type="button" class="button button-small" data-email-format="bold" data-email-target="penalis-template-<?php echo esc_attr( $field ); ?>">Tebal</button>
								<button type="button" class="button button-small" data-email-format="link" data-email-target="penalis-template-<?php echo esc_attr( $field ); ?>">Sisipkan Link</button>
							</div>
						</div>
						<textarea
							id="penalis-template-<?php echo esc_attr( $field ); ?>"
							name="templates[<?php echo esc_attr( $key ); ?>][<?php echo esc_attr( $field ); ?>]"
							rows="<?php echo 'body' === $field ? '7' : '3'; ?>"
							class="large-text"
						><?php echo esc_textarea( $current[ $field ] ); ?></textarea>
					</div>
				<?php endforeach; ?>

				<div class="penalis-settings__email-variables">
					<h5>Variabel Template</h5>
					<p class="description">Variabel bertanda Wajib harus muncul di isi email. Jika terlewat, Penalis menambahkannya saat disimpan.</p>
					<ul>
						<?php foreach ( $meta['tags'] as $tag => $description ) : ?>
							<li>
								<button type="button" class="button button-small" data-email-format="variable" data-email-target="penalis-template-body" data-email-variable="<?php echo esc_attr( $tag ); ?>" hidden>
									<code><?php echo esc_html( $tag ); ?></code><?php echo in_array( $tag, $meta['required_tags'], true ) ? ' · Wajib' : ''; ?>
								</button>
								<span data-email-variable-text><code><?php echo esc_html( $tag ); ?></code></span>
								<span><?php echo esc_html( $description ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>

				<details class="penalis-settings__disclosure penalis-settings__email-preview" data-email-preview hidden>
					<summary>Pratinjau teks email</summary>
					<p class="description">Menggunakan data contoh.</p>
					<strong data-email-preview-field="subject"></strong>
					<p data-email-preview-field="body"></p>
					<p data-email-preview-field="footer"></p>
				</details>
				<?php Penalis_Settings::render_save_action( 'Simpan Template' ); ?>
			</form>

			<div class="penalis-settings__reset">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'penalis_save_email_settings' ); ?>
					<input type="hidden" name="email_template" value="<?php echo esc_attr( $key ); ?>">
					<?php if ( $custom ) : ?>
						<button type="submit" class="button" name="action" value="penalis_reset_email_template" data-penalis-confirm="Kembalikan template ini ke teks bawaan?">Reset Template Ini</button>
					<?php endif; ?>
					<button type="submit" class="button" name="action" value="penalis_reset_all_email_settings" data-penalis-confirm="Kembalikan semua template ke teks bawaan? Semua isi email kustom akan dihapus.">Reset Semua Template</button>
				</form>
			</div>
		</section>
		<?php
	}

	/**
	 * Handle save submission.
	 *
	 * @return void
	 */
	public static function handle_save() {
		if ( ! self::can_manage() ) {
			wp_die( 'Kamu tidak memiliki izin untuk mengubah pengaturan email.', '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'penalis_save_email_settings' );

		// Accept submissions from an older settings screen already open in a browser.
		if ( ! empty( $_POST['penalis_reset_single_key'] ) ) {
			$key = sanitize_key( wp_unslash( $_POST['penalis_reset_single_key'] ) );
			self::redirect_result( $key, 'reset_single', self::reset_single( $key ) );
		}

		if ( ! empty( $_POST['penalis_reset_all_action'] ) ) {
			self::redirect_result( '', 'reset_all', self::reset_all() );
		}

		$key       = isset( $_POST['email_template'] ) ? sanitize_key( wp_unslash( $_POST['email_template'] ) ) : '';
		$submitted = isset( $_POST['templates'] ) && is_array( $_POST['templates'] ) ? wp_unslash( $_POST['templates'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is normalized below.
		$registry  = self::get_registry();
		if ( '' !== $key && ( ! isset( $registry[ $key ], $submitted[ $key ] ) || ! is_array( $submitted[ $key ] ) ) ) {
			self::redirect_result( '', '', false );
		}

		$clean = array();
		foreach ( $submitted as $submitted_key => $values ) {
			$submitted_key = sanitize_key( (string) $submitted_key );
			if ( ! isset( $registry[ $submitted_key ] ) || ! is_array( $values ) || ( '' !== $key && $submitted_key !== $key ) ) {
				continue;
			}
			$clean[ $submitted_key ] = self::normalize_template( $values, $registry[ $submitted_key ] );
		}

		$result = self::mutate_templates(
			static function ( $saved ) use ( $key, $clean, $registry ) {
				if ( '' === $key ) {
					return $clean;
				}

				$template = $clean[ $key ];
				$defaults = $registry[ $key ];
				if (
					$template['subject'] === $defaults['default_subject'] &&
					$template['body'] === $defaults['default_body'] &&
					$template['footer'] === $defaults['default_footer']
				) {
					unset( $saved[ $key ] );
				} else {
					$saved[ $key ] = $template;
				}

				return $saved;
			}
		);
		self::redirect_result( $key, 'saved', $result );
	}

	/**
	 * Normalize one submitted template, retaining required delivery placeholders.
	 *
	 * @param array<string,mixed> $values Submitted template, already unslashed.
	 * @param array<string,mixed> $meta   Template definition.
	 * @return array<string,string>
	 */
	private static function normalize_template( array $values, array $meta ) {
		$subject = isset( $values['subject'] ) && is_string( $values['subject'] ) ? sanitize_text_field( $values['subject'] ) : '';
		$body    = isset( $values['body'] ) && is_string( $values['body'] ) ? str_replace( "\r\n", "\n", trim( $values['body'] ) ) : '';
		$footer  = isset( $values['footer'] ) && is_string( $values['footer'] ) ? str_replace( "\r\n", "\n", trim( $values['footer'] ) ) : '';

		foreach ( $meta['required_tags'] as $required_tag ) {
			if ( false === strpos( $body, $required_tag ) ) {
				$body = '' === $body ? $required_tag : $body . "\n\n" . $required_tag;
			}
		}

		return array(
			'subject' => $subject,
			'body'    => $body,
			'footer'  => $footer,
		);
	}

	/**
	 * Serialize edits and resets to the shared template option.
	 *
	 * @param callable $change Takes the current overrides and returns replacements or null to delete the option.
	 * @return bool|WP_Error
	 */
	private static function mutate_templates( $change ) {
		global $wpdb;

		return Penalis_Account_Status::with_advisory_lock(
			hash( 'sha256', DB_NAME . ':' . $wpdb->options . ':' . self::OPTION_NAME ),
			3,
			static function () use ( $change ) {
				// Legacy installs may still have this option in the autoloaded cache.
				wp_cache_delete( 'alloptions', 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				wp_cache_delete( self::OPTION_NAME, 'options' );

				$current = get_option( self::OPTION_NAME, false );
				$next    = $change( is_array( $current ) ? $current : array() );
				if ( null === $next ) {
					return false === $current || delete_option( self::OPTION_NAME );
				}
				if ( $current === $next || ( false === $current && array() === $next ) ) {
					return true;
				}
				return update_option( self::OPTION_NAME, $next, false );
			},
			new WP_Error( 'email_templates_busy' )
		);
	}

	/**
	 * Return to the active template with feedback.
	 *
	 * @param string        $key     Template key, if selected.
	 * @param string        $message Success message key.
	 * @param bool|WP_Error $result  Result of storing the change.
	 * @return void
	 */
	private static function redirect_result( $key, $message, $result ) {
		$args = array( 'penalis_section' => 'email-templates' );
		if ( '' !== $key ) {
			$args['template'] = $key;
		}
		if ( true !== $result ) {
			$args['penalis_error'] = is_wp_error( $result ) ? $result->get_error_code() : 'email_templates_save';
			$message               = '';
		}
		Penalis_Settings::redirect( 'email', $message, $args );
	}

	/**
	 * Reset a single template key to its default state.
	 *
	 * @param string $key Template identifier.
	 * @return bool|WP_Error
	 */
	public static function reset_single( $key ) {
		$key = sanitize_key( $key );
		return self::mutate_templates(
			static function ( $saved ) use ( $key ) {
				unset( $saved[ $key ] );
				return $saved;
			}
		);
	}

	/**
	 * Handle admin post action for resetting a single template.
	 *
	 * @return void
	 */
	public static function handle_reset_single() {
		if ( ! self::can_manage() ) {
			wp_die( 'Kamu tidak memiliki izin untuk mengubah pengaturan email.', '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'penalis_save_email_settings' );
		$key = isset( $_POST['email_template'] ) ? sanitize_key( wp_unslash( $_POST['email_template'] ) ) : '';
		if ( '' === $key && isset( $_POST['penalis_reset_single_key'] ) ) {
			$key = sanitize_key( wp_unslash( $_POST['penalis_reset_single_key'] ) );
		}
		self::redirect_result( $key, 'reset_single', self::reset_single( $key ) );
	}

	/**
	 * Reset all email templates to default by deleting the option.
	 *
	 * @return bool|WP_Error
	 */
	public static function reset_all() {
		return self::mutate_templates( static fn() => null );
	}

	/**
	 * Handle admin post action for resetting all templates.
	 *
	 * @return void
	 */
	public static function handle_reset_all() {
		if ( ! self::can_manage() ) {
			wp_die( 'Kamu tidak memiliki izin untuk mengubah pengaturan email.', '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'penalis_save_email_settings' );
		$key = isset( $_POST['email_template'] ) ? sanitize_key( wp_unslash( $_POST['email_template'] ) ) : '';
		self::redirect_result( $key, 'reset_all', self::reset_all() );
	}
}
