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
		add_action( 'template_redirect', array( __CLASS__, 'handle_login_post' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'handle_logout' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'send_private_headers' ), 1 );
		add_filter( 'script_loader_tag', array( __CLASS__, 'module_script_tag' ), 10, 3 );
		add_filter( 'wp_robots', array( __CLASS__, 'noindex' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_legacy_slug' ), 5 );
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
		$url     = $page_id > 0 ? get_permalink( $page_id ) : home_url( '/' . Penalis_Activator::SLUG . '/' );

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
	 * Redirect the pre-0.2.0 dashboard URL to the current one.
	 *
	 * Links in emails sent before the rename would otherwise dead-end.
	 *
	 * @return void
	 */
	public static function redirect_legacy_slug() {
		if ( ! is_404() ) {
			return;
		}

		$path = trim( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '', PHP_URL_PATH ), '/' );
		$home = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );

		if ( '' !== $home && 0 === strpos( $path, $home . '/' ) ) {
			$path = substr( $path, strlen( $home ) + 1 );
		}

		if ( Penalis_Activator::LEGACY_SLUG !== $path ) {
			return;
		}

		// Verification links sent before the rename carry a single-use token in
		// the query string, so the arguments have to survive the redirect.
		$args = array();

		foreach ( wp_unslash( $_GET ) as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( is_scalar( $value ) ) {
				$args[ sanitize_key( (string) $key ) ] = sanitize_text_field( (string) $value );
			}
		}

		$target = empty( $args ) ? self::dashboard_url() : add_query_arg( $args, self::dashboard_url() );

		wp_safe_redirect( $target, 301 );
		exit;
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
	 * Dashboard logout URL.
	 *
	 * `wp_logout_url()` routes through wp-login.php. Writers should never see
	 * that screen, so the dashboard handles logout itself with a nonce.
	 *
	 * @return string
	 */
	public static function logout_url() {
		return add_query_arg(
			array(
				'penalis_logout' => '1',
				'_wpnonce'       => wp_create_nonce( 'penalis_logout' ),
			),
			self::dashboard_url()
		);
	}

	/**
	 * Handle dashboard logout.
	 *
	 * @return void
	 */
	public static function handle_logout() {
		if ( ! self::is_dashboard_request() ) {
			return;
		}

		if ( empty( $_GET['penalis_logout'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below.
			return;
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified on the next line.

		if ( ! wp_verify_nonce( $nonce, 'penalis_logout' ) ) {
			wp_safe_redirect( self::dashboard_url() );
			exit;
		}

		wp_logout();
		wp_safe_redirect( self::dashboard_url() );
		exit;
	}

	/**
	 * Handle the dashboard login form submission.
	 *
	 * The form posts to the dashboard itself rather than wp-login.php, so a
	 * writer never sees the WordPress login screen. That includes failures:
	 * wp-login.php renders its own page on a bad password, which would leak the
	 * writer out of the dashboard.
	 *
	 * Runs at `template_redirect` priority 0, before any output, so
	 * `wp_signon()` can still set the authentication cookies.
	 *
	 * @return void
	 */
	public static function handle_login_post() {
		if ( ! self::is_dashboard_request() ) {
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

		if ( 'POST' !== $method ) {
			return;
		}

		// Only handle submissions from the dashboard form. Anything else is
		// left to WordPress.
		//
		// A nonce is deliberately not used: the visitor is anonymous, so there
		// is no session to bind a nonce to. WordPress core's own login form has
		// the same constraint. The credentials themselves are the proof, and
		// the login gate plus rate limiting handle abuse.
		if ( empty( $_POST['penalis_login'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Anonymous login cannot carry a session nonce.
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Anonymous login; the password must not be sanitized before wp_signon().
		$login    = isset( $_POST['log'] ) ? sanitize_user( wp_unslash( $_POST['log'] ) ) : '';
		$password = isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : '';
		$remember = ! empty( $_POST['rememberme'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$user = wp_signon(
			array(
				'user_login'    => $login,
				'user_password' => $password,
				'remember'      => $remember,
			),
			is_ssl()
		);

		if ( is_wp_error( $user ) ) {
			// The message stays generic so the form cannot be used to discover
			// which accounts exist or why they were blocked.
			wp_safe_redirect( add_query_arg( 'penalis_login_error', '1', self::dashboard_url() ) );
			exit;
		}

		wp_safe_redirect( self::dashboard_url() );
		exit;
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

		// Vite emits an ES module. WordPress's `wp_enqueue_script()` prints a
		// classic <script> tag, which cannot parse `import.meta` or `export` and
		// fails with "Cannot use 'import.meta' outside a module", leaving the
		// dashboard root empty. `module_script_tag()` adds type="module".
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
				'loginUrl'     => esc_url_raw( self::dashboard_url() ),
				'logoutUrl'    => esc_url_raw( self::logout_url() ),
				'lostPassword' => esc_url_raw( self::dashboard_url( array( 'penalis_forgot' => '1' ) ) ),
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
					'avatarId'        => Penalis_Avatar::get_id( $user_id ),
					'avatarUrl'       => Penalis_Avatar::get_url( $user_id ),
					'email'           => $user->user_email,
					'description'     => (string) get_user_meta( $user_id, 'description', true ),
					'instagram'       => Penalis_Account_Status::get_instagram( $user_id ),
					'authorUrl'       => get_author_posts_url( $user_id ),
				) : null,
			)
		);
	}

	/**
	 * Load the dashboard bundle as an ES module.
	 *
	 * `wp_enqueue_script()` always prints a classic script tag. Vite's output is
	 * an ES module, so it must be loaded with `type="module"`; otherwise the
	 * browser throws "Cannot use 'import.meta' outside a module" and nothing
	 * renders.
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Script handle.
	 * @param string $src    Script source URL.
	 * @return string
	 */
	public static function module_script_tag( $tag, $handle, $src ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
		if ( 'penalis-dashboard' !== $handle ) {
			return $tag;
		}

		return sprintf(
			'<script type="module" src="%s" id="penalis-dashboard-js"></script>' . "\n", // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Rewrites the tag for an already-enqueued handle; wp_enqueue_script() cannot emit type="module".
			esc_url( $src )
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
				<strong><?php echo esc_html( 'Aset dasbor Penalis tidak ditemukan.' ); ?></strong>
				<p><?php echo esc_html( 'Jalankan "npm ci && npm run build" di direktori plugin, lalu pasang folder build/ yang dihasilkan.' ); ?></p>
			</div>
			<?php
		}
		?>
		<div id="penalis-dashboard-root" class="penalis-dashboard-root">
			<noscript>
				<p><?php echo esc_html( 'Dasbor menulis Penalis memerlukan JavaScript.' ); ?></p>
			</noscript>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
