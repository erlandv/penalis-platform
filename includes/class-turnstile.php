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
		if ( ! function_exists( 'cfturnstile_check' ) ) {
			return false;
		}

		$site_key   = trim( sanitize_text_field( (string) get_option( 'cfturnstile_key', '' ) ) );
		$secret_key = trim( sanitize_text_field( (string) get_option( 'cfturnstile_secret', '' ) ) );

		if ( '' === $site_key || '' === $secret_key ) {
			return false;
		}

		if ( 'no' === (string) get_option( 'cfturnstile_tested', '' ) ) {
			return false;
		}

		if ( function_exists( 'cfturnstile_whitelisted' ) && cfturnstile_whitelisted() ) {
			return false;
		}

		if ( apply_filters( 'cfturnstile_widget_disable', false ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party Simple Cloudflare Turnstile hook.
			return false;
		}

		if (
			get_option( 'cfturnstile_failover' )
			&& 'allow' === (string) get_option( 'cfturnstile_failsafe_type', 'allow' )
			&& function_exists( 'cfturnstile_is_cloudflare_down' )
			&& cfturnstile_is_cloudflare_down()
		) {
			return false;
		}

		return true;
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
