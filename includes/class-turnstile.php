<?php
/**
 * Optional Cloudflare Turnstile integration via Simple Cloudflare Turnstile.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adapter around the third-party Simple Cloudflare Turnstile plugin.
 *
 * All interactions are guarded with `function_exists()` so Penalis Platform
 * degrades gracefully when Simple Cloudflare Turnstile is inactive or
 * unconfigured.
 */
final class Penalis_Turnstile {

	/**
	 * Supported dashboard auth contexts.
	 *
	 * @var string[]
	 */
	private const CONTEXTS = array( 'login', 'register', 'activate', 'forgot', 'reset' );

	/**
	 * Whether Simple Cloudflare Turnstile is active, configured, and not bypassed.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$state = self::availability();

		return (bool) $state['available'];
	}

	/**
	 * Availability of the third-party plugin, with the reason behind it.
	 *
	 * "Not available" covers six situations, and only two of them are
	 * configuration problems an administrator can fix on the Turnstile settings
	 * page (missing keys, untested keys). Whitelist rules, the outage failover, and
	 * the `cfturnstile_widget_disable` filter bypass Turnstile for a single request
	 * on a site that is otherwise fully protected, so the reason is reported
	 * separately instead of being collapsed into one "unconfigured" verdict.
	 *
	 * Reasons are ordered from the most fundamental to the most transient, which
	 * matches the order in which the third-party plugin itself decides whether to
	 * load its integrations.
	 *
	 * @return array{available:bool,reason:string} `reason` is one of
	 *         `plugin_missing`, `keys_missing`, `untested`, `whitelisted`,
	 *         `filtered`, `failover`, or `available`.
	 */
	public static function availability() {
		if ( ! function_exists( 'cfturnstile_check' ) ) {
			return self::state( 'plugin_missing' );
		}

		$site_key   = trim( sanitize_text_field( (string) get_option( 'cfturnstile_key', '' ) ) );
		$secret_key = trim( sanitize_text_field( (string) get_option( 'cfturnstile_secret', '' ) ) );

		if ( '' === $site_key || '' === $secret_key ) {
			return self::state( 'keys_missing' );
		}

		// The plugin sets this to "no" whenever an administrator saves new keys and
		// only flips it back to "yes" after a manual test, so it is the one piece of
		// configuration an administrator still has to finish.
		if ( 'no' === (string) get_option( 'cfturnstile_tested', '' ) ) {
			return self::state( 'untested' );
		}

		if ( function_exists( 'cfturnstile_whitelisted' ) && cfturnstile_whitelisted() ) {
			return self::state( 'whitelisted' );
		}

		if ( apply_filters( 'cfturnstile_widget_disable', false ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party Simple Cloudflare Turnstile hook.
			return self::state( 'filtered' );
		}

		if (
			get_option( 'cfturnstile_failover' )
			&& 'allow' === (string) get_option( 'cfturnstile_failsafe_type', 'allow' )
			&& function_exists( 'cfturnstile_is_cloudflare_down' )
			&& cfturnstile_is_cloudflare_down()
		) {
			return self::state( 'failover' );
		}

		return self::state( 'available' );
	}

	/**
	 * Build an availability result.
	 *
	 * @param string $reason Availability reason.
	 * @return array{available:bool,reason:string}
	 */
	private static function state( $reason ) {
		return array(
			'available' => 'available' === $reason,
			'reason'    => $reason,
		);
	}

	/**
	 * Whether the dashboard CAPTCHA switch allows Turnstile at all.
	 *
	 * This is a Penalis-level override that sits above the third-party options:
	 * disabling it silences every dashboard form without rewriting
	 * `cfturnstile_login`, `cfturnstile_register`, or `cfturnstile_reset`, so
	 * the native WordPress forms keep their protection.
	 *
	 * @return bool
	 */
	public static function is_dashboard_enabled() {
		return class_exists( 'Penalis_Settings' ) ? Penalis_Settings::is_dashboard_captcha_enabled() : true;
	}

	/**
	 * Whether Turnstile protection is enabled for a specific AuthGate context.
	 *
	 * Maps Penalis dashboard forms onto Simple Cloudflare Turnstile's existing
	 * WordPress auth toggles so administrators can manage everything from one
	 * settings screen.
	 *
	 * @param string $context One of `login`, `register`, `activate`, `forgot`, `reset`.
	 * @return bool
	 */
	public static function is_enabled_for( $context ) {
		if ( ! in_array( $context, self::CONTEXTS, true ) ) {
			return false;
		}

		if ( ! self::is_available() ) {
			return false;
		}

		$enabled = false;

		switch ( $context ) {
			case 'login':
				$enabled = (bool) get_option( 'cfturnstile_login', false );
				break;
			case 'register':
			case 'activate':
				$enabled = (bool) get_option( 'cfturnstile_register', false );
				break;
			case 'forgot':
			case 'reset':
				$enabled = (bool) get_option( 'cfturnstile_reset', false );
				break;
		}

		if ( ! self::is_dashboard_enabled() ) {
			$enabled = false;
		}

		/**
		 * Filter whether Turnstile is enabled for a given Penalis auth context.
		 *
		 * @param bool   $enabled Whether Turnstile is enabled.
		 * @param string $context Auth context (`login`, `register`, `activate`, `forgot`, `reset`).
		 */
		return (bool) apply_filters( 'penalis_turnstile_enabled', $enabled, $context );
	}

	/**
	 * Whether at least one dashboard auth form has Turnstile enabled.
	 *
	 * @return bool
	 */
	public static function has_any_enabled_form() {
		foreach ( self::CONTEXTS as $context ) {
			if ( self::is_enabled_for( $context ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Enqueue Cloudflare Turnstile scripts via Simple Cloudflare Turnstile when needed.
	 *
	 * @return void
	 */
	public static function enqueue_scripts() {
		if ( ! self::has_any_enabled_form() ) {
			return;
		}

		do_action( 'cfturnstile_enqueue_scripts' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party Simple Cloudflare Turnstile hook.
	}

	/**
	 * Build frontend bootstrap configuration for the dashboard SPA.
	 *
	 * @return array<string,mixed>
	 */
	public static function frontend_config() {
		if ( ! self::has_any_enabled_form() ) {
			return array(
				'enabled' => false,
			);
		}

		$language = sanitize_text_field( (string) get_option( 'cfturnstile_language', 'auto' ) );

		return array(
			'enabled'    => true,
			'siteKey'    => sanitize_text_field( (string) get_option( 'cfturnstile_key', '' ) ),
			'theme'      => self::sanitize_choice( (string) get_option( 'cfturnstile_theme', 'auto' ), array( 'auto', 'light', 'dark' ), 'auto' ),
			'size'       => self::sanitize_choice( (string) get_option( 'cfturnstile_size', 'normal' ), array( 'normal', 'compact', 'flexible' ), 'normal' ),
			'language'   => '' !== $language ? $language : 'auto',
			'appearance' => self::sanitize_choice( (string) get_option( 'cfturnstile_appearance', 'always' ), array( 'always', 'execute', 'interaction-only' ), 'always' ),
			'forms'      => array(
				'login'    => self::is_enabled_for( 'login' ),
				'register' => self::is_enabled_for( 'register' ),
				'activate' => self::is_enabled_for( 'activate' ),
				'forgot'   => self::is_enabled_for( 'forgot' ),
				'reset'    => self::is_enabled_for( 'reset' ),
			),
		);
	}

	/**
	 * Verify a Turnstile token for a given dashboard context.
	 *
	 * @param mixed  $token       Submitted `cf-turnstile-response` token.
	 * @param string $context     Auth context (`login`, `register`, `activate`, `forgot`, `reset`).
	 * @param string $form_action Optional Turnstile action name for analytics.
	 * @return true|WP_Error True when verification passes or is not required; WP_Error otherwise.
	 */
	public static function verify( $token, $context, $form_action = '' ) {
		if ( ! self::is_enabled_for( $context ) ) {
			return true;
		}

		$clean_token = is_scalar( $token ) ? trim( sanitize_text_field( (string) $token ) ) : '';

		if ( '' === $clean_token ) {
			return new WP_Error(
				'penalis_turnstile_failed',
				self::error_message(),
				array( 'status' => 400 )
			);
		}

		$action = '' !== $form_action ? $form_action : 'penalis-' . sanitize_key( $context );
		$result = cfturnstile_check( $clean_token, $action );

		if ( is_array( $result ) && ! empty( $result['success'] ) ) {
			return true;
		}

		return new WP_Error(
			'penalis_turnstile_failed',
			self::error_message(),
			array( 'status' => 400 )
		);
	}

	/**
	 * Resolve a user-facing verification failure message.
	 *
	 * @return string
	 */
	public static function error_message() {
		$custom = trim( sanitize_text_field( wp_kses_post( (string) get_option( 'cfturnstile_error_message', '' ) ) ) );
		if ( '' !== $custom ) {
			return $custom;
		}

		return 'Harap selesaikan verifikasi keamanan (CAPTCHA) terlebih dahulu.';
	}

	/**
	 * Restrict an option value to an allowed set.
	 *
	 * @param string   $value    Raw option value.
	 * @param string[] $allowed  Allowed values.
	 * @param string   $fallback Fallback value.
	 * @return string
	 */
	private static function sanitize_choice( $value, $allowed, $fallback ) {
		$clean = strtolower( trim( sanitize_text_field( $value ) ) );
		return in_array( $clean, $allowed, true ) ? $clean : $fallback;
	}
}
