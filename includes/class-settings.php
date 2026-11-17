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
		add_action( 'admin_post_penalis_save_captcha', array( __CLASS__, 'handle_save_captcha' ) );
	}

	/**
	 * Register the Pengaturan submenu under Penalis Platform.
	 *
	 * Registered at priority 14 so it lands after Ringkasan (10), Review Karya
	 * Tulis (11), Penulis Penalis (12), and Pengaturan Email (13).
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

			<?php self::render_saved_notice(); ?>

			<p class="penalis-intro">
				Pengaturan situs yang berlaku untuk seluruh Penalis. Setiap kelompok pengaturan punya
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
	 * Render the save confirmation.
	 *
	 * @return void
	 */
	private static function render_saved_notice() {
		if ( ! isset( $_GET['penalis_message'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flag.
			return;
		}

		$message = sanitize_key( wp_unslash( $_GET['penalis_message'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flag.

		if ( 'saved' === $message ) {
			echo '<div class="notice notice-success is-dismissible"><p>Pengaturan berhasil disimpan.</p></div>';
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
