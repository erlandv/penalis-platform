<?php
/**
 * Centralized Penalis settings screen.
 *
 * Site-wide plugin settings live here instead of being scattered across the
 * curation screens. Each group of settings gets its own tab so a change can be
 * reviewed in isolation, and new groups can be added without touching the
 * screen shell.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the Penalis → Pengaturan screen.
 */
final class Penalis_Settings {

	/**
	 * Admin page slug.
	 */
	public const PAGE_SLUG = 'penalis-settings';

	/**
	 * Option holding the dashboard CAPTCHA switch (`1` enabled, `0` disabled).
	 *
	 * The switch is deliberately Penalis-owned: the Simple Cloudflare Turnstile
	 * options (`cfturnstile_login`, `cfturnstile_register`, `cfturnstile_reset`)
	 * also drive the native WordPress forms, so writing them from here would
	 * strip protection from the rest of the site.
	 */
	public const OPTION_CAPTCHA_ENABLED = 'penalis_dashboard_captcha_enabled';

	/**
	 * Option recording who last changed the CAPTCHA switch and when.
	 */
	public const OPTION_CAPTCHA_AUDIT = 'penalis_dashboard_captcha_audit';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 14 );
		add_action( 'admin_notices', array( __CLASS__, 'render_captcha_warning' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_scripts' ) );
		add_action( 'admin_post_penalis_save_captcha', array( __CLASS__, 'handle_save_captcha' ) );
	}

	/**
	 * Register the Pengaturan submenu under Penalis Platform.
	 *
	 * Registered at priority 14 so it lands after Ringkasan (10), Review Karya
	 * Tulis (11), and Penulis Penalis (12).
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			Penalis_Admin::MENU_SLUG,
			'Pengaturan — Penalis Platform',
			'Pengaturan',
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Whether the current admin request is the settings screen.
	 *
	 * Checks both the page hook and the `page` query argument, matching the
	 * approach used for the other Penalis screens.
	 *
	 * @param string $hook Current admin page hook.
	 * @return bool
	 */
	public static function is_current_screen( $hook = '' ) {
		if ( false !== strpos( (string) $hook, self::PAGE_SLUG ) ) {
			return true;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.

		return self::PAGE_SLUG === $page;
	}

	/**
	 * Enqueue the media picker for the Foto Profil tab.
	 *
	 * The avatar picker is only needed on that one tab, so the media frame and
	 * its handler never load on the other setting groups.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function admin_scripts( $hook ) {
		if ( ! self::is_current_screen( $hook ) || ! self::can_manage() ) {
			return;
		}

		if ( 'avatar' !== self::current_tab() ) {
			return;
		}

		wp_enqueue_media();

		wp_register_script( 'penalis-admin-avatar', '', array( 'jquery' ), PENALIS_PLATFORM_VERSION, true );
		wp_enqueue_script( 'penalis-admin-avatar' );

		$script = <<<'JS'
jQuery( function ( $ ) {
	var frame;

	$( '#penalis-default-avatar-choose' ).on( 'click', function ( event ) {
		event.preventDefault();

		if ( ! frame ) {
			frame = wp.media( {
				title: 'Foto profil bawaan',
				button: { text: 'Gunakan gambar ini' },
				library: { type: 'image' },
				multiple: false
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				$( '#penalis-default-avatar-id' ).val( attachment.id );
				$( '#penalis-default-avatar-preview' ).attr( 'src', attachment.url ).show();
			} );
		}

		frame.open();
	} );
} );
JS;

		wp_add_inline_script( 'penalis-admin-avatar', $script );
	}

	/**
	 * Whether the current user may change site-wide Penalis settings.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Settings tabs, in display order.
	 *
	 * Each entry carries the short menu label and the callable that renders the
	 * tab body. Future setting groups only need an entry here.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function tabs() {
		return array(
			'captcha' => array(
				'label'  => 'Keamanan',
				'title'  => 'Keamanan & CAPTCHA',
				'render' => array( __CLASS__, 'render_captcha_tab' ),
			),
			'avatar'  => array(
				'label'  => 'Foto Profil',
				'title'  => 'Foto Profil Bawaan',
				'render' => array( __CLASS__, 'render_avatar_tab' ),
			),
			'email'   => array(
				'label'  => 'Email',
				'title'  => 'Pengaturan Email',
				'render' => array( 'Penalis_Email_Settings', 'render_tab' ),
			),
		);
	}

	/**
	 * URL of the settings screen.
	 *
	 * @param array<string,mixed> $args Optional extra query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg(
			array_merge( array( 'page' => self::PAGE_SLUG ), $args ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Whether the dashboard CAPTCHA switch is on.
	 *
	 * A missing option means enabled, so existing sites keep their current
	 * behaviour without a migration step.
	 *
	 * @return bool
	 */
	public static function is_dashboard_captcha_enabled() {
		return '0' !== (string) get_option( self::OPTION_CAPTCHA_ENABLED, '1' );
	}

	/**
	 * Normalize a submitted CAPTCHA switch value.
	 *
	 * Only an explicit `0` disables the switch; anything else falls back to
	 * enabled so a malformed request can never silently remove protection.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string `1` or `0`.
	 */
	public static function sanitize_state( $value ) {
		$value = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';

		return '0' === $value ? '0' : '1';
	}

	/**
	 * Audit entry for the most recent CAPTCHA switch change.
	 *
	 * @return array<string,mixed> Empty when the switch has never been changed.
	 */
	public static function audit() {
		$audit = get_option( self::OPTION_CAPTCHA_AUDIT, array() );

		return is_array( $audit ) && isset( $audit['time'] ) ? $audit : array();
	}

	/**
	 * Resolve the tab to render from the `tab` query argument.
	 *
	 * @return string Registered tab slug.
	 */
	private static function current_tab() {
		$tabs      = self::tabs();
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection.

		if ( isset( $tabs[ $requested ] ) ) {
			return $requested;
		}

		$slugs = array_keys( $tabs );

		return (string) reset( $slugs );
	}

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! self::can_manage() ) {
			wp_die( 'Kamu tidak memiliki izin untuk melihat halaman ini.', '', array( 'response' => 403 ) );
		}

		$tabs = self::tabs();
		$tab  = self::current_tab();
		?>
		<div class="wrap penalis-settings">
			<h1 class="wp-heading-inline">Pengaturan Penalis</h1>
			<hr class="wp-header-end">

			<?php self::render_notices(); ?>

			<p class="penalis-intro">
				Pengaturan Penalis Platform yang berlaku untuk seluruh situs Penalis. Setiap kelompok pengaturan punya
				tab sendiri, sehingga perubahan dapat ditinjau satu per satu.
			</p>

			<nav class="nav-tab-wrapper penalis-settings__tabs" aria-label="Kelompok pengaturan">
				<?php foreach ( $tabs as $slug => $meta ) : ?>
					<a
						href="<?php echo esc_url( self::url( array( 'tab' => $slug ) ) ); ?>"
						class="nav-tab <?php echo $slug === $tab ? 'nav-tab-active' : ''; ?>"
						<?php echo $slug === $tab ? 'aria-current="page"' : ''; ?>
					>
						<?php echo esc_html( (string) $meta['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php
			if ( isset( $tabs[ $tab ]['render'] ) && is_callable( $tabs[ $tab ]['render'] ) ) {
				call_user_func( $tabs[ $tab ]['render'] );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Map a success message key to its user-facing text.
	 *
	 * Every settings handler redirects back here with `penalis_message`, so the
	 * feedback lives in one place instead of one notice block per tab.
	 *
	 * @param string $key Message key.
	 * @return string
	 */
	public static function message_for( $key ) {
		$messages = array(
			'saved'        => 'Pengaturan berhasil disimpan.',
			'reset_single' => 'Template email telah dikembalikan ke teks bawaan.',
			'reset_all'    => 'Semua template email telah dikembalikan ke teks bawaan.',
			'avatar_saved' => 'Foto profil bawaan telah diperbarui.',
		);

		$key = sanitize_key( (string) $key );

		return isset( $messages[ $key ] ) ? $messages[ $key ] : 'Permintaan telah diselesaikan.';
	}

	/**
	 * Map an error key to its user-facing text.
	 *
	 * @param string $key Error key.
	 * @return string
	 */
	public static function error_for( $key ) {
		$errors = array(
			'avatar_invalid' => 'Pilih gambar yang valid untuk foto profil bawaan.',
		);

		$key = sanitize_key( (string) $key );

		return isset( $errors[ $key ] ) ? $errors[ $key ] : 'Terjadi kesalahan saat memproses pengaturan. Silakan coba lagi.';
	}

	/**
	 * Render the save confirmation or the error returned by a settings handler.
	 *
	 * @return void
	 */
	private static function render_notices() {
		if ( isset( $_GET['penalis_message'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flag.
			$message = sanitize_key( wp_unslash( $_GET['penalis_message'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flag.

			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( self::message_for( $message ) )
			);
		}

		if ( isset( $_GET['penalis_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flag.
			$error = sanitize_key( wp_unslash( $_GET['penalis_error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flag.

			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html( self::error_for( $error ) )
			);
		}
	}

	/**
	 * Warn on every Penalis screen while the dashboard CAPTCHA is off.
	 *
	 * The warning is deliberately not dismissible: a disabled CAPTCHA is an
	 * operational state that must stay visible until somebody turns it back on,
	 * and it explains why the dashboard auth forms stop asking for verification.
	 *
	 * @return void
	 */
	public static function render_captcha_warning() {
		if ( self::is_dashboard_captcha_enabled() || ! Penalis_Admin::is_penalis_screen() ) {
			return;
		}

		$audit   = self::audit();
		$details = '';

		if ( ! empty( $audit ) ) {
			$who     = isset( $audit['user_login'] ) && '' !== (string) $audit['user_login'] ? (string) $audit['user_login'] : 'administrator';
			$details = sprintf(
				' Dinonaktifkan pada %1$s oleh %2$s.',
				Penalis_Datetime::format( (int) $audit['time'], 'j M Y H:i' ),
				$who
			);
		}

		printf(
			'<div class="notice notice-warning"><p><strong>CAPTCHA dashboard sedang nonaktif.</strong> Form login, pendaftaran, aktivasi, dan reset password di dashboard penulis tidak meminta verifikasi Turnstile.%s Halaman WordPress lain tetap terlindungi.</p></div>',
			esc_html( $details )
		);
	}

	/**
	 * Render the Keamanan (CAPTCHA) tab.
	 *
	 * @return void
	 */
	public static function render_captcha_tab() {
		$enabled = self::is_dashboard_captcha_enabled();
		?>
		<div class="penalis-settings__panel">
			<h2>CAPTCHA di dashboard penulis</h2>
			<p class="description">
				Mengatur apakah form login, pendaftaran, aktivasi akun, dan reset password di
				<code>/dashboard/</code> memakai Cloudflare Turnstile. Pengaturan ini hanya berlaku
				untuk dashboard Penalis; halaman WordPress lain tetap mengikuti pengaturan plugin
				Simple Cloudflare Turnstile.
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="penalis_save_captcha" />
				<?php wp_nonce_field( 'penalis_save_captcha' ); ?>

				<fieldset class="penalis-settings__group">
					<legend>Status CAPTCHA untuk form dashboard</legend>

					<div class="penalis-settings__choice">
						<input
							type="radio"
							id="penalis-captcha-enabled"
							name="penalis_dashboard_captcha"
							value="1"
							aria-describedby="penalis-captcha-enabled-help"
							<?php checked( $enabled ); ?>
						/>
						<div class="penalis-settings__choice-text">
							<label for="penalis-captcha-enabled">Aktif (disarankan)</label>
							<p class="description" id="penalis-captcha-enabled-help">
								Widget Turnstile tampil di form dashboard dan tokennya diverifikasi sebelum
								permintaan diproses.
							</p>
						</div>
					</div>

					<div class="penalis-settings__choice">
						<input
							type="radio"
							id="penalis-captcha-disabled"
							name="penalis_dashboard_captcha"
							value="0"
							aria-describedby="penalis-captcha-disabled-help"
							<?php checked( ! $enabled ); ?>
						/>
						<div class="penalis-settings__choice-text">
							<label for="penalis-captcha-disabled">Nonaktif sementara</label>
							<p class="description" id="penalis-captcha-disabled-help">
								Widget tidak ditampilkan dan token tidak diminta di dashboard. Gunakan hanya
								saat ada kendala, lalu nyalakan kembali.
							</p>
						</div>
					</div>
				</fieldset>

				<p class="penalis-settings__note">
					Selama CAPTCHA nonaktif, perlindungan lain tetap berjalan: honeypot, pembatasan
					percobaan per alamat IP, dan verifikasi email OTP.
				</p>

				<p class="submit">
					<button type="submit" class="button button-primary">Simpan pengaturan</button>
				</p>
			</form>
		</div>

		<div class="penalis-settings__panel">
			<h2>Status plugin Simple Cloudflare Turnstile</h2>
			<p class="description">
				Bagian ini hanya informasi. Penalis membaca konfigurasi Turnstile dan tidak pernah
				mengubahnya, sehingga mematikan CAPTCHA dashboard tidak melemahkan halaman WordPress
				lain.
			</p>

			<table class="widefat striped penalis-settings__status">
				<tbody>
					<tr>
						<th scope="row">Status plugin</th>
						<td><?php echo esc_html( self::plugin_status_label() ); ?></td>
					</tr>
					<tr>
						<th scope="row">Kunci situs</th>
						<td><?php echo esc_html( '' !== self::turnstile_option( 'cfturnstile_key' ) ? 'Terpasang' : 'Belum diisi' ); ?></td>
					</tr>
					<tr>
						<th scope="row">Kunci rahasia</th>
						<td><?php echo esc_html( '' !== self::turnstile_option( 'cfturnstile_secret' ) ? 'Terpasang' : 'Belum diisi' ); ?></td>
					</tr>
					<tr>
						<th scope="row">Halaman WordPress yang dilindungi</th>
						<td><?php echo esc_html( self::protected_wordpress_forms() ); ?></td>
					</tr>
				</tbody>
			</table>

			<p class="penalis-settings__link">
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=cfturnstile' ) ); ?>">
					Buka pengaturan Turnstile &nearr;
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the Foto Profil tab.
	 *
	 * @return void
	 */
	public static function render_avatar_tab() {
		$avatar_id  = (int) get_option( 'avatar_default_wp_user_avatar', 0 );
		$avatar_url = $avatar_id > 0 ? (string) wp_get_attachment_image_url( $avatar_id, 'thumbnail' ) : '';
		?>
		<div class="penalis-settings__panel">
			<h2>Foto profil bawaan</h2>
			<p class="description">
				Dipakai untuk penulis yang belum memilih foto sendiri. Gambar ini disimpan sebagai
				foto profil bawaan situs, sehingga tampil konsisten di arsip penulis, direktori
				kontributor, dan komentar.
				<?php if ( Penalis_Avatar::is_supported() ) : ?>
					Plugin One User Avatar aktif dan mengelola pengaturan yang sama.
				<?php endif; ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="penalis_save_default_avatar" />
				<?php wp_nonce_field( 'penalis_default_avatar' ); ?>
				<input type="hidden" name="penalis_default_avatar_id" id="penalis-default-avatar-id" value="<?php echo esc_attr( (string) $avatar_id ); ?>" />

				<div class="penalis-settings__avatar">
					<img
						id="penalis-default-avatar-preview"
						class="penalis-settings__avatar-preview"
						src="<?php echo esc_url( $avatar_url ); ?>"
						alt=""
						<?php echo '' === $avatar_url ? 'style="display:none;"' : ''; ?>
					/>
					<div class="penalis-settings__avatar-controls">
						<button type="button" class="button" id="penalis-default-avatar-choose">Pilih gambar</button>
						<button type="submit" class="button button-primary">Simpan</button>
						<?php if ( $avatar_id > 0 ) : ?>
							<button type="submit" class="button penalis-button--danger" name="penalis_clear_default_avatar" value="1">Hapus foto bawaan</button>
						<?php endif; ?>
					</div>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Read a Turnstile option as a trimmed string.
	 *
	 * @param string $name Option name.
	 * @return string
	 */
	private static function turnstile_option( $name ) {
		return trim( (string) get_option( $name, '' ) );
	}

	/**
	 * Describe the third-party plugin state in one line.
	 *
	 * "Inactive" and "active but unconfigured" are different situations for an
	 * administrator, so they are never collapsed into one label.
	 *
	 * @return string
	 */
	private static function plugin_status_label() {
		if ( ! function_exists( 'cfturnstile_check' ) ) {
			return 'Tidak aktif';
		}

		return Penalis_Turnstile::is_available() ? 'Aktif dan terkonfigurasi' : 'Aktif, tetapi belum terkonfigurasi';
	}

	/**
	 * Whether Simple Cloudflare Turnstile protects a specific native form.
	 *
	 * @param string $name Third-party option name.
	 * @return bool
	 */
	private static function turnstile_form_enabled( $name ) {
		$value = self::turnstile_option( $name );

		return '' !== $value && '0' !== $value;
	}

	/**
	 * Human-readable list of the WordPress forms Turnstile protects.
	 *
	 * Reads the third-party toggles directly so the label describes the whole
	 * site, not the dashboard.
	 *
	 * @return string
	 */
	private static function protected_wordpress_forms() {
		if ( ! function_exists( 'cfturnstile_check' ) ) {
			return 'Tidak ada, karena plugin Turnstile tidak aktif';
		}

		$forms = array();

		if ( self::turnstile_form_enabled( 'cfturnstile_login' ) ) {
			$forms[] = 'login';
		}

		if ( self::turnstile_form_enabled( 'cfturnstile_register' ) ) {
			$forms[] = 'pendaftaran';
		}

		if ( self::turnstile_form_enabled( 'cfturnstile_reset' ) ) {
			$forms[] = 'reset password';
		}

		return empty( $forms ) ? 'Tidak ada' : implode( ', ', $forms );
	}

	/**
	 * Handle the CAPTCHA switch submission.
	 *
	 * @return void
	 */
	public static function handle_save_captcha() {
		if ( ! self::can_manage() ) {
			wp_die( 'Kamu tidak memiliki izin untuk mengubah pengaturan ini.', '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'penalis_save_captcha' );

		$state = self::sanitize_state( isset( $_POST['penalis_dashboard_captcha'] ) ? wp_unslash( $_POST['penalis_dashboard_captcha'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Normalized by sanitize_state().

		$current = self::is_dashboard_captcha_enabled() ? '1' : '0';

		if ( $state !== $current ) {
			update_option( self::OPTION_CAPTCHA_ENABLED, $state, false );
			self::record_audit( $state );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => self::PAGE_SLUG,
					'tab'             => 'captcha',
					'penalis_message' => 'saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Record who changed the switch and when.
	 *
	 * @param string $state New state (`1` or `0`).
	 * @return void
	 */
	private static function record_audit( $state ) {
		$user = wp_get_current_user();

		update_option(
			self::OPTION_CAPTCHA_AUDIT,
			array(
				'state'      => (string) $state,
				'user_id'    => $user ? (int) $user->ID : 0,
				'user_login' => $user ? (string) $user->user_login : '',
				'time'       => time(),
			),
			false
		);
	}
}
