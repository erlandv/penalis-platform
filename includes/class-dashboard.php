<?php
/**
 * Frontend dashboard integration.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the dashboard shortcode, assets, and cache behavior.
 */
final class Penalis_Dashboard {

	public const SHORTCODE = 'penalis_dashboard';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_assets' ) );
		add_filter( 'template_include', array( __CLASS__, 'maybe_use_dashboard_template' ) );
		add_action( 'template_redirect', array( __CLASS__, 'send_private_headers' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'set_login_test_cookie' ), 2 );
		add_filter( 'wp_robots', array( __CLASS__, 'noindex' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
	}
	/**
	 * Dashboard page ID.
	 *
	 * @return int
	 */
	public static function page_id() {
		return (int) get_option( 'penalis_dashboard_page_id', 0 );
	}

	/**
	 * Dashboard URL.
	 *
	 * @param array<string,mixed> $args Query args.
	 * @return string
	 */
	public static function dashboard_url( $args = array() ) {
		$page_id = self::page_id();
		$url     = $page_id > 0 ? get_permalink( $page_id ) : home_url( '/penalis-dashboard/' );

		if ( ! $url ) {
			$url = home_url( '/' );
		}

		return empty( $args ) ? $url : add_query_arg( $args, $url );
	}

	/**
	 * Registration/login URL for logged-out visitors.
	 *
	 * @return string
	 */
	public static function registration_url() {
		return self::dashboard_url();
	}

	/**
	 * Add a body class to the dashboard.
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public static function body_class( $classes ) {
		if ( self::is_dashboard_request() ) {
			$classes[] = 'penalis-dashboard-page';
		}

		return $classes;
	}

	/**
	 * Whether the current request is the dashboard page.
	 *
	 * @return bool
	 */
	public static function is_dashboard_request() {
		$page_id = self::page_id();

		if ( $page_id > 0 && is_page( $page_id ) ) {
			return true;
		}

		return is_page() && has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), self::SHORTCODE );
	}

	/**
	 * Send private cache headers for the dashboard.
	 *
	 * @return void
	 */
	public static function send_private_headers() {
		if ( ! self::is_dashboard_request() ) {
			return;
		}

		nocache_headers();
		header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		// Verification links carry a single-use token in the query string.
		// Avoid leaking it to third-party resources loaded by the theme.
		header( 'Referrer-Policy: no-referrer' );
	}

	/**
	 * Set the WordPress test cookie so the inline login form works when the
	 * visitor has not previously visited wp-login.php.
	 *
	 * @return void
	 */
	public static function set_login_test_cookie() {
		if ( ! self::is_dashboard_request() || is_user_logged_in() || headers_sent() ) {
			return;
		}

		if ( isset( $_COOKIE[ TEST_COOKIE ] ) ) {
			return;
		}

		setcookie( TEST_COOKIE, 'WP Cookie check', 0, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
	}

	/**
	 * Add noindex to the dashboard page.
	 *
	 * @param array<string,mixed> $robots Robots directives.
	 * @return array<string,mixed>
	 */
	public static function noindex( $robots ) {
		if ( self::is_dashboard_request() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}

		return $robots;
	}

	/**
	 * Optionally load a minimal template that avoids theme sidebars for the
	 * dashboard, while still using the active theme's header and footer.
	 *
	 * @param string $template Template path.
	 * @return string
	 */
	public static function maybe_use_dashboard_template( $template ) {
		if ( ! self::is_dashboard_request() ) {
			return $template;
		}

		/**
		 * Allow a site to opt out of the bundled dashboard template.
		 *
		 * @param bool $use_template Whether to use the bundled template.
		 */
		if ( ! apply_filters( 'penalis_use_dashboard_template', true ) ) {
			return $template;
		}

		$custom = locate_template( 'penalis-dashboard.php' );
		if ( $custom ) {
			return $custom;
		}

		$bundled = PENALIS_PLATFORM_DIR . 'templates/dashboard.php';
		if ( is_readable( $bundled ) ) {
			return $bundled;
		}

		return $template;
	}

	/**
	 * Enqueue dashboard assets only on the dashboard page.
	 *
	 * @return void
	 */
	public static function maybe_enqueue_assets() {
		if ( ! self::is_dashboard_request() ) {
			return;
		}

		$asset_file = PENALIS_PLATFORM_DIR . 'build/manifest.json';

		if ( ! is_readable( $asset_file ) ) {
			return;
		}

		$manifest = json_decode( (string) file_get_contents( $asset_file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $manifest ) || ! isset( $manifest['src/app/index.tsx'] ) ) {
			return;
		}

		$entry = $manifest['src/app/index.tsx'];
		$base  = PENALIS_PLATFORM_URL . 'build/';

		if ( ! empty( $entry['css'] ) && is_array( $entry['css'] ) ) {
			foreach ( $entry['css'] as $index => $css_file ) {
				wp_enqueue_style(
					'penalis-dashboard-' . $index,
					$base . ltrim( (string) $css_file, '/' ),
					array(),
					PENALIS_PLATFORM_VERSION
				);
			}
		}

		wp_enqueue_script(
			'penalis-dashboard',
			$base . ltrim( (string) $entry['file'], '/' ),
			array(),
			PENALIS_PLATFORM_VERSION,
			true
		);

		$user_id = get_current_user_id();
		$user    = $user_id > 0 ? get_userdata( $user_id ) : null;

		wp_localize_script(
			'penalis-dashboard',
			'penalisDashboard',
			array(
				'restUrl'      => esc_url_raw( rest_url( Penalis_Rest_Api::NAMESPACE_V1 ) ),
				'wpRestUrl'    => esc_url_raw( rest_url( 'wp/v2' ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'dashboardUrl' => esc_url_raw( self::dashboard_url() ),
				'loginUrl'     => esc_url_raw( wp_login_url( self::dashboard_url() ) ),
				'loginPostUrl' => esc_url_raw( site_url( 'wp-login.php', 'login_post' ) ),
				'logoutUrl'    => esc_url_raw( wp_logout_url( self::dashboard_url() ) ),
				'lostPassword' => esc_url_raw( wp_lostpassword_url() ),
				'homeUrl'      => esc_url_raw( home_url( '/' ) ),
				'adminUrl'     => current_user_can( 'list_users' ) ? esc_url_raw( admin_url( 'users.php?page=penalis-writers' ) ) : '',
				'siteName'     => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'isLoggedIn'   => $user_id > 0,
				'registration' => Penalis_Registration::is_registration_enabled(),
				'canWrite'     => $user_id > 0 ? Penalis_Account_Status::can_write( $user_id ) : false,
				'status'       => $user_id > 0 ? Penalis_Account_Status::get_status( $user_id ) : '',
				'statusLabel'  => $user_id > 0 ? Penalis_Policy::status_label( Penalis_Account_Status::get_status( $user_id ) ) : '',
				'user'         => $user ? array(
					'id'              => (int) $user_id,
					'displayName'     => $user->display_name,
					'firstName'       => (string) get_user_meta( $user_id, 'first_name', true ),
					'lastName'        => (string) get_user_meta( $user_id, 'last_name', true ),
					'displayNameMode' => Penalis_Account_Status::get_display_name_mode( $user_id ),
					'email'           => $user->user_email,
					'description'     => (string) get_user_meta( $user_id, 'description', true ),
					'website'         => $user->user_url,
				) : null,
				'i18n'         => array(
					'signIn'       => __( 'Sign in', 'penalis-platform' ),
					'signOut'      => __( 'Sign out', 'penalis-platform' ),
					'register'     => __( 'Create account', 'penalis-platform' ),
					'saveDraft'    => __( 'Save draft', 'penalis-platform' ),
					'publish'      => __( 'Publish', 'penalis-platform' ),
					'preview'      => __( 'Preview', 'penalis-platform' ),
					'delete'       => __( 'Delete', 'penalis-platform' ),
					'loading'      => __( 'Loading…', 'penalis-platform' ),
					'noArticles'   => __( 'You have not created any articles yet.', 'penalis-platform' ),
					'noMedia'      => __( 'You have not uploaded any media yet.', 'penalis-platform' ),
					'genericError' => __( 'Something went wrong. Please try again.', 'penalis-platform' ),
					'saved'        => __( 'Saved.', 'penalis-platform' ),
				),
			)
		);
	}

	/**
	 * Render the dashboard mount point.
	 *
	 * @return string
	 */
	public static function render_shortcode() {
		$has_build = is_readable( PENALIS_PLATFORM_DIR . 'build/manifest.json' );

		ob_start();

		if ( ! $has_build && current_user_can( 'manage_options' ) ) {
			?>
			<div class="penalis-build-missing" style="max-width:720px;margin:2rem auto;padding:1.25rem;border:1px solid #f0c36d;background:#fff8e5;border-radius:8px;font-family:system-ui,sans-serif;">
				<strong><?php esc_html_e( 'Penalis dashboard assets are missing.', 'penalis-platform' ); ?></strong>
				<p><?php esc_html_e( 'Run "npm ci && npm run build" in the plugin directory and deploy the generated build/ folder.', 'penalis-platform' ); ?></p>
			</div>
			<?php
		}
		?>
		<div id="penalis-dashboard-root" class="penalis-dashboard-root">
			<noscript>
				<p><?php esc_html_e( 'The Penalis writing dashboard requires JavaScript.', 'penalis-platform' ); ?></p>
			</noscript>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
