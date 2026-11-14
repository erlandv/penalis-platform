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

	public const PAGE_SLUG   = 'penalis-email-settings';
	public const OPTION_NAME = 'penalis_email_templates';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 13 );
		add_action( 'admin_post_penalis_save_email_settings', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_penalis_reset_email_template', array( __CLASS__, 'handle_reset_single' ) );
		add_action( 'admin_post_penalis_reset_all_email_settings', array( __CLASS__, 'handle_reset_all' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_scripts' ) );
	}

	/**
	 * Register the Pengaturan Email submenu under Penalis Platform.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			Penalis_Admin::MENU_SLUG,
			'Pengaturan Email — Penalis Platform',
			'Pengaturan Email',
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue scripts and styles for the email settings page.
	 *
	 * @param string $hook Admin page hook.
	 * @return void
	 */
	public static function admin_scripts( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE_SLUG ) && ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		Penalis_Admin::enqueue_stylesheet();

		$script = <<<'JS'
jQuery( function( $ ) {
	// Tab navigation
	$( '.penalis-email-nav__tab' ).on( 'click', function( event ) {
		event.preventDefault();
		var target = $( this ).data( 'group' );

		$( '.penalis-email-nav__tab' ).removeClass( 'current' ).removeAttr( 'aria-current' );
		$( this ).addClass( 'current' ).attr( 'aria-current', 'page' );

		if ( 'all' === target ) {
			$( '.penalis-email-card' ).show();
		} else {
			$( '.penalis-email-card' ).hide();
			$( '.penalis-email-card[data-group="' + target + '"]' ).show();
		}
	} );

	// Insert text at cursor position helper
	function insertAtCursor( element, text ) {
		if ( ! element ) return;
		var start = element.selectionStart;
		var end   = element.selectionEnd;
		var val   = element.value;

		element.value = val.substring( 0, start ) + text + val.substring( end );
		element.selectionStart = element.selectionEnd = start + text.length;
		element.focus();
		$( element ).trigger( 'change' );
	}

	// Wrap selection helper
	function wrapSelection( element, before, after, defaultText ) {
		if ( ! element ) return;
		var start = element.selectionStart;
		var end   = element.selectionEnd;
		var val   = element.value;
		var selected = val.substring( start, end ) || defaultText;

		element.value = val.substring( 0, start ) + before + selected + after + val.substring( end );
		element.selectionStart = start + before.length;
		element.selectionEnd   = start + before.length + selected.length;
		element.focus();
		$( element ).trigger( 'change' );
	}

	// Tag chip insertion
	$( document ).on( 'click', '.penalis-tag-chip', function( event ) {
		event.preventDefault();
		var targetId = $( this ).data( 'target' );
		var tag      = $( this ).data( 'tag' );
		var textarea = document.getElementById( targetId );
		insertAtCursor( textarea, tag );
	} );

	// Toolbar action: bold
	$( document ).on( 'click', '.penalis-toolbar-bold', function( event ) {
		event.preventDefault();
		var targetId = $( this ).data( 'target' );
		var textarea = document.getElementById( targetId );
		wrapSelection( textarea, '**', '**', 'teks tebal' );
	} );

	// Toolbar action: link
	$( document ).on( 'click', '.penalis-toolbar-link', function( event ) {
		event.preventDefault();
		var targetId = $( this ).data( 'target' );
		var textarea = document.getElementById( targetId );
		if ( ! textarea ) return;

		var start    = textarea.selectionStart;
		var end      = textarea.selectionEnd;
		var selected = textarea.value.substring( start, end );

		var text = prompt( 'Masukkan teks yang akan ditampilkan sebagai link:', selected || 'Teks Link' );
		if ( null === text || '' === text.trim() ) {
			return;
		}

		var url = prompt( 'Masukkan alamat URL (contoh: https://chat.whatsapp.com/...):', 'https://' );
		if ( null === url || '' === url.trim() || 'https://' === url.trim() ) {
			return;
		}

		var markdownLink = '[' + text.trim() + '](' + url.trim() + ')';
		insertAtCursor( textarea, markdownLink );
	} );
} );
JS;

		wp_add_inline_script( 'jquery', $script );
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
				'title'           => 'Karya Tulis Baru Masuk (Untuk Tim Reviewer)',
				'description'     => 'Dikirim ke semua reviewer (Editor & Admin) saat penulis mengirimkan karya tulis baru untuk pertama kali.',
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
				'title'           => 'Revisi Karya Tulis Masuk (Untuk Tim Reviewer)',
				'description'     => 'Dikirim ke semua reviewer (Editor & Admin) saat penulis mengirim ulang karya yang telah diperbaiki.',
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
				'description'     => 'Dikirim ke penulis ketika reviewer meminta revisi atau perbaikan naskah sebelum terbit.',
				'group'           => 'kurasi',
				'default_subject' => '[{nama_situs}] Karya Tulis Kamu Perlu Revisi',
				'default_body'    => "Halo {nama_penulis},\n\nAdmin {nama_situs} telah meninjau karya tulis kamu yang berjudul \"{judul_karya}\" dan meminta beberapa revisi sebelum dapat diterbitkan.\n\nCatatan review admin:\n{catatan_review}\n\nBuka dashboard penulis untuk memperbaiki draft dan submit ulang:\n\n{link_editor}",
				'default_footer'  => 'Email ini dikirim otomatis. Kamu menerima email ini karena ada pembaruan status review karya tulis di akun Penalis kamu. Jika ada pertanyaan atau kendala, hubungi admin@penalis.com.',
				'tags'            => array(
					'{nama_penulis}'   => 'Nama tampilan penulis karya',
					'{judul_karya}'    => 'Judul karya tulis',
					'{catatan_review}' => 'Catatan revisi dari reviewer',
					'{link_editor}'    => 'Tautan langsung ke editor dashboard penulis (Wajib)',
					'{nama_situs}'     => 'Nama website situs',
				),
				'required_tags'   => array( '{link_editor}' ),
			),
			'editorial_reject'          => array(
				'title'           => 'Karya Tulis Belum Disetujui / Ditolak (Untuk Penulis)',
				'description'     => 'Dikirim ke penulis ketika reviewer menolak karya tulis yang di-submit.',
				'group'           => 'kurasi',
				'default_subject' => '[{nama_situs}] Pembaruan Review Karya Tulis Kamu',
				'default_body'    => "Halo {nama_penulis},\n\nTerima kasih telah mengirimkan karya tulis berjudul \"{judul_karya}\" ke {nama_situs}. Setelah di-review oleh admin, karya tulis ini belum dapat diterbitkan.\n\nCatatan review admin:\n{catatan_review}\n\nKarya tulis yang ditolak tidak dapat di-submit ulang kecuali dibuka kembali oleh admin. Kamu dapat melihat detail review di dashboard penulis:\n\n{link_editor}",
				'default_footer'  => 'Email ini dikirim otomatis. Kamu menerima email ini karena ada pembaruan status review karya tulis di akun Penalis kamu. Jika ada pertanyaan atau kendala, hubungi admin@penalis.com.',
				'tags'            => array(
					'{nama_penulis}'   => 'Nama tampilan penulis karya',
					'{judul_karya}'    => 'Judul karya tulis',
					'{catatan_review}' => 'Alasan / catatan dari reviewer',
					'{link_editor}'    => 'Tautan ke detail karya di dashboard penulis',
					'{nama_situs}'     => 'Nama website situs',
				),
				'required_tags'   => array(),
			),
			'editorial_reopen'          => array(
				'title'           => 'Karya Tulis Dibuka Kembali untuk Revisi (Untuk Penulis)',
				'description'     => 'Dikirim ke penulis ketika reviewer membuka kembali karya yang sebelumnya ditolak agar dapat direvisi.',
				'group'           => 'kurasi',
				'default_subject' => '[{nama_situs}] Karya Tulis Kamu Dibuka Kembali untuk Revisi',
				'default_body'    => "Halo {nama_penulis},\n\nAdmin {nama_situs} telah membuka kembali karya tulis kamu yang berjudul \"{judul_karya}\". Kamu kini dapat edit draft tersebut dan submit ulang untuk di-review.\n\nCatatan admin:\n{catatan_review}\n\nBuka karya tulis kamu di dashboard penulis:\n\n{link_editor}",
				'default_footer'  => 'Email ini dikirim otomatis. Kamu menerima email ini karena ada pembaruan status review karya tulis di akun Penalis kamu. Jika ada pertanyaan atau kendala, hubungi admin@penalis.com.',
				'tags'            => array(
					'{nama_penulis}'   => 'Nama tampilan penulis karya',
					'{judul_karya}'    => 'Judul karya tulis',
					'{catatan_review}' => 'Catatan dari reviewer',
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
				'default_body'    => "Halo {nama_penulis},\n\nSelamat! Karya tulis kamu yang berjudul \"{judul_karya}\" telah disetujui admin dan kini resmi terbit di {nama_situs}.\n\nCatatan admin:\n{catatan_review}\n\nLihat karya tulis yang telah terbit: {link_karya_terbit}\n\nBuka detail karya tulis di dashboard penulis:\n\n{link_editor}",
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
	 * Render the Settings admin screen.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Kamu tidak memiliki izin untuk melihat halaman ini.', 'penalis-platform' ), 403 );
		}

		$registry   = self::get_registry();
		$saved_opts = get_option( self::OPTION_NAME, array() );
		$groups     = array(
			'all'        => 'Semua Email',
			'verifikasi' => 'Verifikasi & Aktivasi',
			'keamanan'   => 'Keamanan Akun',
			'status'     => 'Status Penulis',
			'kurasi'     => 'Kurasi Karya Tulis',
		);

		?>
		<div class="wrap penalis-email-settings">
			<h1 class="wp-heading-inline">Pengaturan Email Penalis</h1>
			<hr class="wp-header-end">

			<?php if ( isset( $_GET['penalis_message'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<?php $msg = sanitize_key( wp_unslash( $_GET['penalis_message'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<?php if ( 'saved' === $msg ) : ?>
					<div class="notice notice-success is-dismissible"><p>Pengaturan email berhasil disimpan.</p></div>
				<?php elseif ( 'reset_single' === $msg ) : ?>
					<div class="notice notice-info is-dismissible"><p>Template email telah dikembalikan ke teks bawaan.</p></div>
				<?php elseif ( 'reset_all' === $msg ) : ?>
					<div class="notice notice-info is-dismissible"><p>Semua template email telah dikembalikan ke teks bawaan.</p></div>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( isset( $_GET['penalis_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-error is-dismissible"><p>Terjadi kesalahan saat memproses pengaturan email. Pastikan tag wajib terpasang.</p></div>
			<?php endif; ?>

			<p class="penalis-intro">
				Sesuaikan teks subjek, isi pesan (*body*), dan catatan kaki (*footer*) untuk setiap email transaksional
				otomatis yang dikirim oleh Penalis. Kamu dapat menyisipkan tautan menggunakan format Markdown
				<code>[Teks Link](https://alamat-web.com)</code> atau teks tebal <code>**teks tebal**</code>.
			</p>

			<ul class="subsubsub penalis-email-nav" style="margin-bottom:20px;">
				<?php $first = true; ?>
				<?php foreach ( $groups as $slug => $label ) : ?>
					<li>
						<a
							href="#group-<?php echo esc_attr( $slug ); ?>"
							class="penalis-email-nav__tab <?php echo $first ? 'current' : ''; ?>"
							data-group="<?php echo esc_attr( $slug ); ?>"
							<?php echo $first ? 'aria-current="page"' : ''; ?>
						>
							<?php echo esc_html( $label ); ?>
						</a>
						<?php
						if ( 'kurasi' !== $slug ) :
							?>
							| <?php endif; ?>
					</li>
					<?php $first = false; ?>
				<?php endforeach; ?>
			</ul>

			<div class="clear"></div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="penalis_save_email_settings" />
				<?php wp_nonce_field( 'penalis_save_email_settings' ); ?>

				<div class="penalis-email-cards">
					<?php foreach ( $registry as $key => $meta ) : ?>
						<?php
						$current   = self::get_template( $key );
						$is_custom = is_array( $saved_opts ) && isset( $saved_opts[ $key ] );
						?>
						<div class="penalis-panel penalis-email-card" data-group="<?php echo esc_attr( $meta['group'] ); ?>" style="margin-bottom:24px;padding:20px;background:#fff;border:1px solid #ccd0d4;border-radius:6px;box-shadow:0 1px 1px rgba(0,0,0,.04);">
							<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;">
								<div>
									<h2 style="margin:0 0 4px;font-size:16px;">
										<?php echo esc_html( $meta['title'] ); ?>
										<?php if ( $is_custom ) : ?>
											<span class="penalis-badge" style="background:#5145cd;color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:normal;margin-left:6px;">Kustom</span>
										<?php else : ?>
											<span class="penalis-badge" style="background:#e0e0e0;color:#555;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:normal;margin-left:6px;">Bawaan</span>
										<?php endif; ?>
									</h2>
									<p class="description" style="margin:0;"><?php echo esc_html( $meta['description'] ); ?></p>
								</div>
								<?php if ( $is_custom ) : ?>
									<button
										type="submit"
										formaction="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
										name="penalis_reset_single_key"
										value="<?php echo esc_attr( $key ); ?>"
										class="button button-secondary button-small"
										onclick="return confirm('Kembalikan email ini ke teks bawaan plugin?');"
									>
										Reset ke Bawaan
									</button>
								<?php endif; ?>
							</div>

							<div style="margin-bottom:14px;">
								<label for="subjek-<?php echo esc_attr( $key ); ?>" style="display:block;font-weight:600;margin-bottom:4px;">Subjek Email:</label>
								<input
									type="text"
									id="subjek-<?php echo esc_attr( $key ); ?>"
									name="templates[<?php echo esc_attr( $key ); ?>][subject]"
									value="<?php echo esc_attr( $current['subject'] ); ?>"
									class="regular-text"
									style="width:100%;max-width:100%;"
								/>
							</div>

							<div style="margin-bottom:14px;">
								<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
									<label for="body-<?php echo esc_attr( $key ); ?>" style="font-weight:600;">Isi Body Email:</label>
									<div class="penalis-email-toolbar">
										<button type="button" class="button button-small penalis-toolbar-bold" data-target="body-<?php echo esc_attr( $key ); ?>"><strong>B</strong> Tebal</button>
										<button type="button" class="button button-small penalis-toolbar-link" data-target="body-<?php echo esc_attr( $key ); ?>">🔗 Sisipkan Link</button>
									</div>
								</div>
								<textarea
									id="body-<?php echo esc_attr( $key ); ?>"
									name="templates[<?php echo esc_attr( $key ); ?>][body]"
									rows="8"
									class="large-text code"
									style="font-family:monospace;font-size:13px;line-height:1.5;"
								><?php echo esc_textarea( $current['body'] ); ?></textarea>
							</div>

							<div style="margin-bottom:14px;">
								<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
									<label for="footer-<?php echo esc_attr( $key ); ?>" style="font-weight:600;">Isi Footer Email:</label>
									<div class="penalis-email-toolbar">
										<button type="button" class="button button-small penalis-toolbar-bold" data-target="footer-<?php echo esc_attr( $key ); ?>"><strong>B</strong> Tebal</button>
										<button type="button" class="button button-small penalis-toolbar-link" data-target="footer-<?php echo esc_attr( $key ); ?>">🔗 Sisipkan Link</button>
									</div>
								</div>
								<textarea
									id="footer-<?php echo esc_attr( $key ); ?>"
									name="templates[<?php echo esc_attr( $key ); ?>][footer]"
									rows="3"
									class="large-text code"
									style="font-family:monospace;font-size:13px;line-height:1.5;"
								><?php echo esc_textarea( $current['footer'] ); ?></textarea>
							</div>

							<div style="background:#f9f9f9;border:1px solid #e5e5e5;padding:10px 14px;border-radius:4px;font-size:12px;">
								<strong style="display:block;margin-bottom:6px;color:#333;">Tag yang dapat digunakan pada email ini (klik untuk menyisipkan):</strong>
								<div style="display:flex;flex-wrap:wrap;gap:6px;">
									<?php foreach ( (array) $meta['tags'] as $tag => $desc ) : ?>
										<?php if ( is_string( $tag ) ) : ?>
											<button
												type="button"
												class="button button-small penalis-tag-chip"
												data-target="body-<?php echo esc_attr( $key ); ?>"
												data-tag="<?php echo esc_attr( $tag ); ?>"
												title="<?php echo esc_attr( $desc ); ?>"
												style="border-radius:12px;background:#fff;border-color:#ccc;"
											>
												+ <?php echo esc_html( $tag ); ?>
											</button>
										<?php endif; ?>
									<?php endforeach; ?>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

				<p class="submit" style="display:flex;gap:12px;align-items:center;position:sticky;bottom:0;background:#f1f1f1;padding:14px 20px;border-top:1px solid #ccd0d4;box-shadow:0 -2px 6px rgba(0,0,0,.05);margin-left:-20px;margin-right:-20px;z-index:99;">
					<button type="submit" name="submit" class="button button-primary button-large">
						Simpan Semua Pengaturan Email
					</button>
					<button
						type="submit"
						formaction="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						name="penalis_reset_all_action"
						value="1"
						class="button button-link-delete"
						onclick="return confirm('PERINGATAN: Apakah kamu yakin ingin mengembalikan SEMUA template email ke bawaan plugin? Semua perubahan custom akan dihapus.');"
						style="margin-left:auto;"
					>
						Kembalikan Semua ke Bawaan Plugin
					</button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle save submission.
	 *
	 * @return void
	 */
	public static function handle_save() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Kamu tidak memiliki izin untuk melakukan tindakan ini.', 'penalis-platform' ), 403 );
		}

		check_admin_referer( 'penalis_save_email_settings' );

		// Check if a single reset button was clicked via formaction.
		if ( ! empty( $_POST['penalis_reset_single_key'] ) ) {
			$single_key = sanitize_key( wp_unslash( $_POST['penalis_reset_single_key'] ) );
			self::reset_single( $single_key );
			wp_safe_redirect( add_query_arg( 'penalis_message', 'reset_single', admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
			exit;
		}

		// Check if reset all was requested.
		if ( ! empty( $_POST['penalis_reset_all_action'] ) ) {
			delete_option( self::OPTION_NAME );
			wp_safe_redirect( add_query_arg( 'penalis_message', 'reset_all', admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
			exit;
		}

		$submitted  = isset( $_POST['templates'] ) && is_array( $_POST['templates'] ) ? (array) wp_unslash( $_POST['templates'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$registry   = self::get_registry();
		$clean_opts = array();

		foreach ( $submitted as $key => $values ) {
			$key = sanitize_key( (string) $key );
			if ( ! isset( $registry[ $key ] ) || ! is_array( $values ) ) {
				continue;
			}

			$meta = $registry[ $key ];

			$subject = isset( $values['subject'] ) ? sanitize_text_field( wp_unslash( $values['subject'] ) ) : '';
			$body    = isset( $values['body'] ) ? wp_unslash( $values['body'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$footer  = isset( $values['footer'] ) ? wp_unslash( $values['footer'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			// Normalize line breaks.
			$body   = str_replace( "\r\n", "\n", trim( (string) $body ) );
			$footer = str_replace( "\r\n", "\n", trim( (string) $footer ) );

			// Check required tags: ensure required placeholders are present.
			if ( ! empty( $meta['required_tags'] ) ) {
				foreach ( (array) $meta['required_tags'] as $req_tag ) {
					if ( false === strpos( $body, $req_tag ) ) {
						// Auto-append missing critical secret tag as safety net.
						$body .= "\n\n" . $req_tag;
					}
				}
			}

			// Store only if different from default, or keep clean array.
			$clean_opts[ $key ] = array(
				'subject' => $subject,
				'body'    => $body,
				'footer'  => $footer,
			);
		}

		update_option( self::OPTION_NAME, $clean_opts );

		wp_safe_redirect( add_query_arg( 'penalis_message', 'saved', admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
		exit;
	}

	/**
	 * Reset a single template key to its default state.
	 *
	 * @param string $key Template identifier.
	 * @return void
	 */
	public static function reset_single( $key ) {
		$key   = sanitize_key( $key );
		$saved = get_option( self::OPTION_NAME, array() );

		if ( is_array( $saved ) && isset( $saved[ $key ] ) ) {
			unset( $saved[ $key ] );
			update_option( self::OPTION_NAME, $saved );
		}
	}

	/**
	 * Handle admin post action for resetting a single template.
	 *
	 * @return void
	 */
	public static function handle_reset_single() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Kamu tidak memiliki izin untuk melakukan tindakan ini.', 'penalis-platform' ), 403 );
		}

		$key = isset( $_POST['penalis_reset_single_key'] ) ? sanitize_key( wp_unslash( $_POST['penalis_reset_single_key'] ) ) : '';
		check_admin_referer( 'penalis_save_email_settings' );

		if ( '' !== $key ) {
			self::reset_single( $key );
		}

		wp_safe_redirect( add_query_arg( 'penalis_message', 'reset_single', admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
		exit;
	}

	/**
	 * Reset all email templates to default by deleting the option.
	 *
	 * @return void
	 */
	public static function reset_all() {
		delete_option( self::OPTION_NAME );
	}

	/**
	 * Handle admin post action for resetting all templates.
	 *
	 * @return void
	 */
	public static function handle_reset_all() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Kamu tidak memiliki izin untuk melakukan tindakan ini.', 'penalis-platform' ), 403 );
		}

		check_admin_referer( 'penalis_save_email_settings' );
		self::reset_all();

		wp_safe_redirect( add_query_arg( 'penalis_message', 'reset_all', admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
		exit;
	}
}
