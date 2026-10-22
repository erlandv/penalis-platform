<?php
/**
 * Small transient-based rate limiter.
 *
 * This is intentionally simple and suitable for a small VPS. It is not a
 * distributed rate limiter and should be complemented by host-level controls.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-IP / per-account throttling using transients.
 */
final class Penalis_Rate_Limiter {

	/**
	 * Client IP address.
	 *
	 * Only REMOTE_ADDR is trusted by default. Proxy headers are ignored unless
	 * a site explicitly opts in via the penalis_trust_proxy_headers filter.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Whether to trust X-Forwarded-For. Defaults to false because the
		 * header is spoofable without a trusted proxy.
		 *
		 * @param bool $trust Trust proxy headers.
		 */
		if ( apply_filters( 'penalis_trust_proxy_headers', false ) && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$parts     = explode( ',', $forwarded );
			$candidate = trim( $parts[0] );
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				$ip = $candidate;
			}
		}

		if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$ip = 'unknown';
		}

		return $ip;
	}

	/**
	 * Build a non-reversible transient key.
	 *
	 * @param string $action Action name.
	 * @param string $subject Subject (IP, email, user ID).
	 * @return string
	 */
	private static function key( $action, $subject ) {
		return 'penalis_rl_' . md5( $action . '|' . strtolower( (string) $subject ) );
	}

	/**
	 * Check whether an action is currently allowed.
	 *
	 * @param string $action  Action name.
	 * @param int    $limit   Maximum attempts per window.
	 * @param int    $window  Window in seconds.
	 * @param string $subject Subject; defaults to client IP.
	 * @return bool
	 */
	public static function is_allowed( $action, $limit, $window, $subject = '' ) {
		if ( '' === $subject ) {
			$subject = self::client_ip();
		}

		$key   = self::key( $action, $subject );
		$state = get_transient( $key );

		if ( ! is_array( $state ) ) {
			return true;
		}

		return (int) $state['count'] < (int) $limit;
	}

	/**
	 * Record an attempt.
	 *
	 * @param string $action  Action name.
	 * @param int    $window  Window in seconds.
	 * @param string $subject Subject; defaults to client IP.
	 * @return void
	 */
	public static function hit( $action, $window, $subject = '' ) {
		if ( '' === $subject ) {
			$subject = self::client_ip();
		}

		$key   = self::key( $action, $subject );
		$state = get_transient( $key );

		if ( ! is_array( $state ) || ! isset( $state['count'] ) ) {
			$state = array(
				'count' => 0,
				'start' => time(),
			);
		}

		$state['count'] = (int) $state['count'] + 1;
		set_transient( $key, $state, (int) $window );
	}

	/**
	 * Clear a limiter (used after successful verification, for example).
	 *
	 * @param string $action  Action.
	 * @param string $subject Subject.
	 * @return void
	 */
	public static function clear( $action, $subject ) {
		delete_transient( self::key( $action, $subject ) );
	}
}
