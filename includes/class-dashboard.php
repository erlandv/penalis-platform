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

		// Legacy links sent before the rename may still carry query arguments,
		// so they have to survive the redirect.
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

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard WordPress page caching constant.
		}

		nocache_headers();
		header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		// The dashboard may be reached with query arguments (for example a
		// password reset key). Avoid leaking them to third-party resources
		// loaded by the theme.
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
		$login           = isset( $_POST['log'] ) ? sanitize_user( wp_unslash( $_POST['log'] ) ) : '';
		$password        = isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : '';
		$remember        = ! empty( $_POST['rememberme'] );
		$turnstile_token = isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$turnstile_check = Penalis_Turnstile::verify( $turnstile_token, 'login', 'penalis-login' );
		if ( is_wp_error( $turnstile_check ) ) {
			wp_safe_redirect( add_query_arg( 'penalis_turnstile_error', '1', self::dashboard_url() ) );
			exit;
		}

		// Simple Cloudflare Turnstile hooks `authenticate` whenever its
		// wp-login.php toggle is on, and that check runs for every wp_signon()
		// call — including this one. Penalis has already verified the token
		// above, and the token is single-use, so the third-party check must be
		// bypassed unconditionally. It must not be conditional on the dashboard
		// switch: with the switch off there is no token to check, and a
		// conditional bypass would block every dashboard login.
		add_filter( 'cfturnstile_wp_login_checks', '__return_true', 999 );

		$user = wp_signon(
			array(
				'user_login'    => $login,
				'user_password' => $password,
				'remember'      => $remember,
			),
			is_ssl()
		);

		remove_filter( 'cfturnstile_wp_login_checks', '__return_true', 999 );

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
		if ( ! apply_filters( 'penalis_use_dashboard_template', (bool) Penalis_Config::general()['use_dashboard_template'] ) ) {
			return $template;
		}

		return self::resolve_bundled_template(
			$template,
			'penalis-dashboard.php',
			'dashboard.php'
		);
	}

	/**
	 * Resolve a theme-overridable or bundled plugin template.
	 *
	 * @param string $template         Current WordPress template path.
	 * @param string $theme_template   Template filename looked up in the active theme.
	 * @param string $bundled_template Template filename inside the plugin's `templates/` directory.
	 * @return string
	 */
	public static function resolve_bundled_template( $template, $theme_template, $bundled_template ) {
		$custom = locate_template( $theme_template );
		if ( $custom ) {
			return $custom;
		}

		$bundled = PENALIS_PLATFORM_DIR . 'templates/' . ltrim( (string) $bundled_template, '/' );
		if ( is_readable( $bundled ) ) {
			return $bundled;
		}

		return $template;
	}

	/**
	 * Enqueue CSS and JS assets for a Vite manifest entry.
	 *
	 * @param string $entry_key Vite manifest entry key (e.g. `src/app/index.tsx`).
	 * @param string $handle    WordPress script/style handle prefix.
	 * @return bool True when the entry script was enqueued.
	 */
	public static function enqueue_vite_entry( $entry_key, $handle ) {
		$asset_file = PENALIS_PLATFORM_DIR . 'build/manifest.json';

		if ( ! is_readable( $asset_file ) ) {
			return false;
		}

		$manifest = json_decode( (string) file_get_contents( $asset_file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $manifest ) || ! isset( $manifest[ $entry_key ] ) || ! is_array( $manifest[ $entry_key ] ) ) {
			return false;
		}

		$entry = $manifest[ $entry_key ];
		if ( empty( $entry['file'] ) ) {
			return false;
		}

		$base = self::asset_base_url();

		if ( ! empty( $entry['css'] ) && is_array( $entry['css'] ) ) {
			foreach ( $entry['css'] as $index => $css_file ) {
				wp_enqueue_style(
					$handle . '-' . $index,
					$base . ltrim( (string) $css_file, '/' ),
					array(),
					PENALIS_PLATFORM_VERSION
				);
			}
		}

		// Vite emits an ES module. WordPress's `wp_enqueue_script()` prints a
		// classic <script> tag, which cannot parse `import.meta` or `export` and
		// fails with "Cannot use 'import.meta' outside a module", leaving the
		// root empty. `format_module_script_tag()` adds type="module".
		wp_enqueue_script(
			$handle,
			$base . ltrim( (string) $entry['file'], '/' ),
			array(),
			PENALIS_PLATFORM_VERSION,
			true
		);

		return true;
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

		if ( ! self::enqueue_vite_entry( 'src/app/index.tsx', 'penalis-dashboard' ) ) {
			return;
		}

		$user_id = get_current_user_id();
		$user    = $user_id > 0 ? get_userdata( $user_id ) : null;

		if ( $user_id <= 0 ) {
			Penalis_Turnstile::enqueue_scripts();
		}

		wp_localize_script(
			'penalis-dashboard',
			'penalisDashboard',
			array(
				'restUrl'        => esc_url_raw( rest_url( Penalis_Rest_Api::NAMESPACE_V1 ) ),
				'wpRestUrl'      => esc_url_raw( rest_url( 'wp/v2' ) ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'dashboardUrl'   => esc_url_raw( self::dashboard_url() ),
				'loginUrl'       => esc_url_raw( self::dashboard_url() ),
				'logoutUrl'      => esc_url_raw( self::logout_url() ),
				'lostPassword'   => esc_url_raw( self::dashboard_url( array( 'penalis_forgot' => '1' ) ) ),
				'homeUrl'        => esc_url_raw( home_url( '/' ) ),
				'authorsUrl'     => esc_url_raw( Penalis_Author_Archive::directory_url() ),
				'dailyQuotesUrl' => esc_url_raw( Penalis_Daily_Quotes::page_url() ),
				'adminUrl'       => current_user_can( 'list_users' ) ? esc_url_raw( admin_url( 'admin.php?page=' . Penalis_Admin::PAGE_SLUG ) ) : '',
				'reviewUrl'      => current_user_can( 'edit_others_posts' ) ? esc_url_raw( admin_url( 'admin.php?page=' . Penalis_Editorial_Admin::PAGE_SLUG ) ) : '',
				'siteName'       => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'siteTimezone'   => Penalis_Datetime::site_timezone_payload(),
				'brandLogoUrl'   => self::brand_assets()['logoUrl'],
				'brandIconUrl'   => self::brand_assets()['iconUrl'],
				'config'         => Penalis_Config::frontend_config(),
				'isLoggedIn'     => $user_id > 0,
				'registration'   => Penalis_Registration::is_registration_enabled(),
				'turnstile'      => $user_id > 0 ? array( 'enabled' => false ) : Penalis_Turnstile::frontend_config(),
				'role'           => $user_id > 0
					? Penalis_Policy::dashboard_role(
						current_user_can( 'manage_options' ),
						current_user_can( 'edit_others_posts' ),
						Penalis_Account_Status::get_status( $user_id ),
						Penalis_Account_Status::can_write( $user_id )
					)
					: '',
				'status'         => $user_id > 0 ? Penalis_Account_Status::get_status( $user_id ) : '',
				'statusLabel'    => $user_id > 0 ? Penalis_Policy::status_label( Penalis_Account_Status::get_status( $user_id ) ) : '',
				'user'           => $user ? array(
					'id'              => (int) $user_id,
					'displayName'     => $user->display_name,
					'firstName'       => (string) get_user_meta( $user_id, 'first_name', true ),
					'lastName'        => (string) get_user_meta( $user_id, 'last_name', true ),
					'displayNameMode' => Penalis_Account_Status::get_display_name_mode( $user_id ),
					'avatarId'        => Penalis_Avatar::get_id( $user_id ),
					'hasAvatar'       => Penalis_Avatar::has_avatar( $user_id ),
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
	 * Base URL for dashboard build assets.
	 *
	 * ES module scripts (`<script type="module">`) are strictly subject to CORS
	 * when loaded cross-origin (e.g. from a CDN subdomain like cdn.penalis.com).
	 * If the CDN does not send `Access-Control-Allow-Origin`, browsers block the
	 * script from executing, leaving the dashboard root empty.
	 *
	 * To keep the dashboard resilient against CDN environments lacking CORS
	 * headers, this method resolves the asset base URL to the site's origin
	 * (`home_url()`, including its port) when the origin differs.
	 *
	 * @return string
	 */
	public static function asset_base_url() {
		$custom = (string) Penalis_Config::security()['asset_base_url'];
		$url    = '' !== $custom
			? rtrim( $custom, '/' ) . '/'
			: self::ensure_same_origin_url( PENALIS_PLATFORM_URL . 'build/' );

		/**
		 * Filter the asset base URL for the dashboard SPA.
		 *
		 * Stylesheets honour this value as-is. The module script source is
		 * re-normalised to the site origin by `format_module_script_tag()` so a
		 * cross-origin CDN without CORS headers cannot blank the dashboard.
		 *
		 * @param string $url Base URL pointing to the build/ directory.
		 */
		return (string) apply_filters( 'penalis_dashboard_asset_base_url', $url );
	}

	/**
	 * Same-origin URLs for the bundled Penalis brand wordmark and square icon.
	 *
	 * Used by the client-side Canvas share card generator so PNG exports never
	 * taint the canvas on CDN-rewritten installs.
	 *
	 * @return array{logoUrl:string,iconUrl:string}
	 */
	public static function brand_assets() {
		$general  = Penalis_Config::general();
		$logo_url = PENALIS_PLATFORM_URL . 'assets/images/logo.png';
		$icon_url = PENALIS_PLATFORM_URL . 'assets/images/icon.png';

		if ( (int) $general['brand_logo_id'] > 0 && function_exists( 'wp_get_attachment_image_url' ) ) {
			$custom_logo = wp_get_attachment_image_url( (int) $general['brand_logo_id'], 'full' );
			if ( is_string( $custom_logo ) && '' !== $custom_logo ) {
				$logo_url = $custom_logo;
			}
		}

		if ( (int) $general['brand_icon_id'] > 0 && function_exists( 'wp_get_attachment_image_url' ) ) {
			$custom_icon = wp_get_attachment_image_url( (int) $general['brand_icon_id'], 'full' );
			if ( is_string( $custom_icon ) && '' !== $custom_icon ) {
				$icon_url = $custom_icon;
			}
		}

		return array(
			'logoUrl' => esc_url_raw( self::ensure_same_origin_url( $logo_url ) ),
			'iconUrl' => esc_url_raw( self::ensure_same_origin_url( $icon_url ) ),
		);
	}

	/**
	 * Ensure a build asset URL uses the site's origin when its own origin differs.
	 *
	 * Browsers compare origins, not hosts: `scheme://host[:port]`. A non-standard
	 * port or an `http`/`https` mismatch is therefore a *different* origin even
	 * when the host matches, and a cross-origin ES module without
	 * `Access-Control-Allow-Origin` (or an `http` asset on an `https` page) is
	 * blocked, leaving the dashboard and authors directory empty. Rebuilding from
	 * `home_url()` preserves its scheme and port so installs on a non-standard
	 * port keep loading their assets.
	 *
	 * @param string $url Asset URL.
	 * @return string Same-origin asset URL, or the original URL when it has no host or path.
	 */
	public static function ensure_same_origin_url( $url ) {
		return self::rewrite_origin( $url, self::origin_parts( home_url(), true ) );
	}

	/**
	 * Rewrite a URL onto a target origin, keeping its path, query, and fragment.
	 *
	 * @param string                                            $url    Asset URL.
	 * @param array{scheme:string,host:string,port:string}|null $origin Target origin parts.
	 * @return string Rewritten URL, or the original URL when either origin is unavailable.
	 */
	private static function rewrite_origin( $url, $origin ) {
		$url = (string) $url;

		if ( ! is_array( $origin ) ) {
			return $url;
		}

		$current = self::origin_parts( $url );

		// Relative or host-less URLs already resolve against the site origin.
		if ( null === $current ) {
			return $url;
		}

		if (
			$current['scheme'] === $origin['scheme']
			&& $current['host'] === $origin['host']
			&& $current['port'] === $origin['port']
		) {
			return $url;
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );

		// Without a path there is no asset to relocate, so keep the URL untouched.
		if ( ! $path ) {
			return $url;
		}

		$rebuilt = $origin['scheme'] . '://' . $origin['host'];
		if ( '' !== $origin['port'] ) {
			$rebuilt .= ':' . $origin['port'];
		}
		$rebuilt .= $path;

		$query = wp_parse_url( $url, PHP_URL_QUERY );
		if ( $query ) {
			$rebuilt .= '?' . $query;
		}

		$fragment = wp_parse_url( $url, PHP_URL_FRAGMENT );
		if ( $fragment ) {
			$rebuilt .= '#' . $fragment;
		}

		return $rebuilt;
	}

	/**
	 * Split a URL into a normalized origin (`scheme://host[:port]`).
	 *
	 * Default ports (80 for http, 443 for https) are dropped so an explicit
	 * default port compares equal to an omitted one, matching how browsers
	 * compare origins. A URL without a scheme falls back to the current request
	 * protocol.
	 *
	 * @param string $url               URL.
	 * @param bool   $upgrade_insecure Whether to upgrade an `http` origin to
	 *                                 `https` on a TLS request. Only the target
	 *                                 (site) origin should set this: leaving the
	 *                                 compared URL on `http` lets the caller
	 *                                 rewrite it, instead of returning mixed content.
	 * @return array{scheme:string,host:string,port:string}|null Origin parts, or null when the URL has no host.
	 */
	private static function origin_parts( $url, $upgrade_insecure = false ) {
		$url  = (string) $url;
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! $host ) {
			return null;
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$port   = wp_parse_url( $url, PHP_URL_PORT );

		if ( '' === $scheme ) {
			$scheme = is_ssl() ? 'https' : 'http';
		} elseif ( $upgrade_insecure && 'http' === $scheme && is_ssl() ) {
			// A reverse proxy may terminate TLS while `home_url()` still reports
			// http; assets loaded over http would be blocked as mixed content.
			$scheme = 'https';
			if ( 80 === (int) $port ) {
				$port = null;
			}
		}

		return array(
			'scheme' => $scheme,
			'host'   => strtolower( (string) $host ),
			'port'   => self::normalize_port( $scheme, $port ),
		);
	}

	/**
	 * Normalise a parsed port by dropping the default port for the scheme.
	 *
	 * @param string   $scheme Lowercase URL scheme.
	 * @param int|null $port   Parsed port.
	 * @return string Normalised port, or an empty string when absent or default.
	 */
	private static function normalize_port( $scheme, $port ) {
		if ( null === $port || '' === $port ) {
			return '';
		}

		$port     = (string) (int) $port;
		$defaults = array(
			'http'  => '80',
			'https' => '443',
		);

		if ( isset( $defaults[ $scheme ] ) && $defaults[ $scheme ] === $port ) {
			return '';
		}

		return $port;
	}

	/**
	 * Rewrite an enqueued Vite script tag as `<script type="module">` when its handle matches.
	 *
	 * @param string $tag             Script tag HTML.
	 * @param string $handle          Enqueued script handle.
	 * @param string $src             Script source URL.
	 * @param string $expected_handle Target handle to rewrite.
	 * @return string
	 */
	public static function format_module_script_tag( $tag, $handle, $src, $expected_handle ) {
		if ( $expected_handle !== $handle ) {
			return $tag;
		}

		// Re-normalise the source to the site origin: a CDN plugin may rewrite
		// `script_loader_src` after `wp_enqueue_script()`, and a cross-origin ES
		// module without CORS headers would be blocked.
		$origin = self::origin_parts( self::asset_base_url(), true );
		if ( null === $origin ) {
			$origin = self::origin_parts( home_url(), true );
		}
		$src = self::rewrite_origin( $src, $origin );

		return sprintf(
			'<script type="module" crossorigin="anonymous" src="%s" id="%s-js"></script>' . "\n", // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Rewrites the tag for an already-enqueued handle; wp_enqueue_script() cannot emit type="module".
			esc_url( $src ),
			esc_attr( $expected_handle )
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
	public static function module_script_tag( $tag, $handle, $src ) {
		return self::format_module_script_tag( $tag, $handle, $src, 'penalis-dashboard' );
	}

	/**
	 * Render a frontend React mount point and an optional missing-build notice for admins.
	 *
	 * @param string $root_id               DOM ID and CSS class for the root container.
	 * @param string $missing_build_heading Admin warning heading when `build/manifest.json` is absent.
	 * @param string $noscript_text         Fallback text shown inside `<noscript>`.
	 * @return string
	 */
	public static function render_spa_mount( $root_id, $missing_build_heading, $noscript_text ) {
		$has_build = is_readable( PENALIS_PLATFORM_DIR . 'build/manifest.json' );

		ob_start();

		if ( ! $has_build && current_user_can( 'manage_options' ) ) {
			?>
			<div class="penalis-build-missing" style="max-width:720px;margin:2rem auto;padding:1.25rem;border:1px solid #f0c36d;background:#fff8e5;border-radius:8px;font-family:system-ui,sans-serif;">
				<strong><?php echo esc_html( $missing_build_heading ); ?></strong>
				<p><?php echo esc_html( 'Jalankan "npm ci && npm run build" di direktori plugin, lalu pasang folder build/ yang dihasilkan.' ); ?></p>
			</div>
			<?php
		}
		?>
		<div id="<?php echo esc_attr( $root_id ); ?>" class="<?php echo esc_attr( $root_id ); ?>">
			<noscript>
				<p><?php echo esc_html( $noscript_text ); ?></p>
			</noscript>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render the dashboard mount point.
	 *
	 * @return string
	 */
	public static function render_shortcode() {
		return self::render_spa_mount(
			'penalis-dashboard-root',
			'Aset dashboard Penalis tidak ditemukan.',
			'Dashboard Penulis memerlukan JavaScript.'
		);
	}
}
