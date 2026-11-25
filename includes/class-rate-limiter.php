<?php
/**
 * Small transient-based rate limiter.
 *
 * This is intentionally simple and suitable for a small VPS. It is not a
 * distributed rate limiter and should be complemented by host-level controls.
 *
 * Two things make it usable on a real host:
 *
 * 1. Limits are configurable (Pengaturan → Batas Percobaan) instead of being
 *    constants scattered across seven call sites.
 * 2. `client_ip()` understands a reverse proxy or CDN — but only one the site
 *    has explicitly named. Trusting `X-Forwarded-For` unconditionally would let
 *    any client send a header and pick its own rate-limit bucket, which is
 *    worse than having no limiter at all.
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

	/** Option holding per-action overrides. */
	const OPTION_LIMITS = 'penalis_rate_limits';

	/** Option holding the trusted proxy list, one entry per line. */
	const OPTION_PROXIES = 'penalis_trusted_proxies';

	/** Upper bounds accepted from the settings screen. */
	const MAX_LIMIT  = 1000;
	const MAX_WINDOW = DAY_IN_SECONDS;

	/**
	 * Rate-limited actions, their defaults, and their UI copy.
	 *
	 * Windows are in minutes here because that is what the settings screen
	 * asks for; `defaults()` converts them to seconds once.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function actions() {
		return array(
			'registration_ip'         => array(
				'label'       => 'Pendaftaran akun',
				'description' => 'Percobaan pendaftaran dari satu alamat IP.',
				'limit'       => 5,
				'window'      => 60,
			),
			'otp_verify_ip'           => array(
				'label'       => 'Verifikasi kode OTP',
				'description' => 'Percobaan memasukkan kode verifikasi dari satu alamat IP.',
				'limit'       => 20,
				'window'      => 60,
			),
			'legacy_activate_ip'      => array(
				'label'       => 'Aktivasi akun kontributor lama',
				'description' => 'Permintaan aktivasi akun lama dari satu alamat IP.',
				'limit'       => 5,
				'window'      => 60,
			),
			'password_forgot_ip'      => array(
				'label'       => 'Permintaan reset password (IP)',
				'description' => 'Permintaan tautan reset password dari satu alamat IP.',
				'limit'       => 5,
				'window'      => 60,
			),
			'password_forgot_account' => array(
				'label'       => 'Permintaan reset password (akun)',
				'description' => 'Permintaan tautan reset untuk satu alamat email, dari IP mana pun.',
				'limit'       => 3,
				'window'      => 60,
			),
			'password_reset_ip'       => array(
				'label'       => 'Menyimpan password baru',
				'description' => 'Percobaan menyimpan password baru dari satu alamat IP.',
				'limit'       => 10,
				'window'      => 60,
			),
			'password_change'         => array(
				'label'       => 'Ganti password dari dashboard',
				'description' => 'Percobaan mengganti password oleh satu akun.',
				'limit'       => 5,
				'window'      => 60,
			),
			'email_change_ip'         => array(
				'label'       => 'Permintaan ganti email (IP)',
				'description' => 'Permintaan perubahan alamat email dari satu alamat IP.',
				'limit'       => 5,
				'window'      => 60,
			),
			'email_change_user'       => array(
				'label'       => 'Permintaan ganti email (akun)',
				'description' => 'Permintaan perubahan alamat email oleh satu akun.',
				'limit'       => 3,
				'window'      => 60,
			),
			'username_change_ip'      => array(
				'label'       => 'Ganti username (IP)',
				'description' => 'Permintaan perubahan username dari satu alamat IP.',
				'limit'       => 5,
				'window'      => 60,
			),
			'username_change_user'    => array(
				'label'       => 'Ganti username (akun)',
				'description' => 'Permintaan perubahan username oleh satu akun.',
				'limit'       => 3,
				'window'      => 60,
			),
			'tag_create'              => array(
				'label'       => 'Membuat tag baru',
				'description' => 'Tag baru yang dibuat satu akun dalam satu jam.',
				'limit'       => 30,
				'window'      => 60,
			),
			'avatar_upload'           => array(
				'label'       => 'Mengunggah foto profil',
				'description' => 'Percobaan mengganti foto profil oleh satu akun dalam satu jam.',
				'limit'       => 20,
				'window'      => 60,
			),
			'media_delete'            => array(
				'label'       => 'Menghapus media',
				'description' => 'Permintaan menghapus gambar di Pustaka Media oleh satu akun dalam satu jam.',
				'limit'       => 60,
				'window'      => 60,
			),
			'post_reaction_ip'        => array(
				'label'       => 'Reaksi karya tulis (Like / Dislike)',
				'description' => 'Klik reaksi Like atau Dislike pada karya tulis dari satu alamat IP.',
				'limit'       => 60,
				'window'      => 60,
			),
		);
	}

	/**
	 * Default rule per action, with the window in seconds.
	 *
	 * @return array<string,array<string,int>>
	 */
	public static function defaults() {
		$defaults = array();

		foreach ( self::actions() as $action => $meta ) {
			$defaults[ $action ] = array(
				'limit'  => (int) $meta['limit'],
				'window' => (int) $meta['window'] * MINUTE_IN_SECONDS,
			);
		}

		return $defaults;
	}

	/**
	 * Effective rules: stored overrides merged over the defaults.
	 *
	 * Values outside the accepted range fall back to the default rather than
	 * being clamped, so a corrupted option cannot silently become "one attempt
	 * per day".
	 *
	 * @return array<string,array<string,int>>
	 */
	public static function limits() {
		$stored = get_option( self::OPTION_LIMITS, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$rules  = array();

		foreach ( self::defaults() as $action => $default ) {
			$entry  = isset( $stored[ $action ] ) && is_array( $stored[ $action ] ) ? $stored[ $action ] : array();
			$limit  = isset( $entry['limit'] ) ? (int) $entry['limit'] : 0;
			$window = isset( $entry['window'] ) ? (int) $entry['window'] : 0;

			$rules[ $action ] = array(
				'limit'  => ( $limit > 0 && $limit <= self::MAX_LIMIT ) ? $limit : $default['limit'],
				'window' => ( $window >= MINUTE_IN_SECONDS && $window <= self::MAX_WINDOW ) ? $window : $default['window'],
			);
		}

		return $rules;
	}

	/**
	 * Effective rule for one action.
	 *
	 * @param string $action Action name.
	 * @return array<string,int>
	 */
	public static function rule( $action ) {
		$rules = self::limits();

		return isset( $rules[ $action ] ) ? $rules[ $action ] : array(
			'limit'  => 5,
			'window' => HOUR_IN_SECONDS,
		);
	}

	/**
	 * Compare two rule sets and return only what changed.
	 *
	 * The audit entry stores this instead of the full table: a reader wants to
	 * know "who raised registration from 5 to 8", not the twelve values that
	 * stayed the same.
	 *
	 * @param array<string,array<string,int>> $before Rules before the change.
	 * @param array<string,array<string,int>> $after  Rules after the change.
	 * @return array<string,array<string,array<string,int>>> Action => from/to pair.
	 */
	public static function diff( array $before, array $after ) {
		$changes = array();

		foreach ( $after as $action => $rule ) {
			$previous = isset( $before[ $action ] ) ? $before[ $action ] : $rule;

			if ( (int) $previous['limit'] === (int) $rule['limit'] && (int) $previous['window'] === (int) $rule['window'] ) {
				continue;
			}

			$changes[ $action ] = array(
				'from' => array(
					'limit'  => (int) $previous['limit'],
					'window' => (int) $previous['window'],
				),
				'to'   => array(
					'limit'  => (int) $rule['limit'],
					'window' => (int) $rule['window'],
				),
			);
		}

		return $changes;
	}

	/**
	 * Whether an action is still allowed for a subject, using the configured rule.
	 *
	 * @param string $action  Action name.
	 * @param string $subject Subject; defaults to the client IP.
	 * @return bool
	 */
	public static function guard( $action, $subject = '' ) {
		$rule = self::rule( $action );

		return self::is_allowed( $action, $rule['limit'], $rule['window'], $subject );
	}

	/**
	 * Record one attempt with the configured window.
	 *
	 * @param string $action  Action name.
	 * @param string $subject Subject; defaults to the client IP.
	 * @return void
	 */
	public static function register( $action, $subject = '' ) {
		$rule = self::rule( $action );

		self::hit( $action, $rule['window'], $subject );
	}

	/**
	 * Client IP address.
	 *
	 * `REMOTE_ADDR` is the only value that cannot be forged by the client. Proxy
	 * headers are read only when the immediate peer is in the trusted list, and
	 * then the chain is walked from the right: your own proxy appends the hop it
	 * saw, so everything to the left of the last trusted hop is client-supplied.
	 *
	 * @return string
	 */
	public static function client_ip() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated by sanitize_ip().
		$remote = self::sanitize_ip( isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) : '' );

		if ( '' === $remote ) {
			return 'unknown';
		}

		$proxies = self::trusted_proxies();

		if ( ! self::may_read_proxy_headers( $remote, $proxies ) ) {
			return $remote;
		}

		// Cloudflare states the client address directly; when the request came
		// through Cloudflare there is nothing to parse.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated by sanitize_ip().
		$cloudflare = self::sanitize_ip( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? wp_unslash( (string) $_SERVER['HTTP_CF_CONNECTING_IP'] ) : '' );

		if ( '' !== $cloudflare ) {
			return $cloudflare;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each hop is validated by sanitize_ip().
		$forwarded = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? wp_unslash( (string) $_SERVER['HTTP_X_FORWARDED_FOR'] ) : '';
		$chain     = array_values( array_filter( array_map( 'trim', explode( ',', $forwarded ) ) ) );

		if ( empty( $chain ) ) {
			return $remote;
		}

		for ( $index = count( $chain ) - 1; $index >= 0; $index-- ) {
			$candidate = self::sanitize_ip( (string) $chain[ $index ] );

			if ( '' === $candidate || self::is_trusted_proxy( $candidate, $proxies ) ) {
				continue;
			}

			return $candidate;
		}

		return $remote;
	}

	/**
	 * Configured proxy addresses and ranges.
	 *
	 * @return string[]
	 */
	public static function trusted_proxies() {
		$raw = get_option( self::OPTION_PROXIES, '' );
		$raw = is_string( $raw ) ? $raw : '';

		$list = array();

		foreach ( preg_split( '/[\r\n,]+/', $raw ) as $entry ) {
			$entry = trim( (string) $entry );

			if ( '' !== $entry && self::is_valid_proxy_entry( $entry ) ) {
				$list[] = $entry;
			}
		}

		return array_values( array_unique( $list ) );
	}

	/**
	 * Whether an entry is an IP address or a CIDR range.
	 *
	 * @param string $entry Raw entry.
	 * @return bool
	 */
	public static function is_valid_proxy_entry( $entry ) {
		$entry = trim( (string) $entry );

		if ( '' === $entry ) {
			return false;
		}

		if ( false === strpos( $entry, '/' ) ) {
			return '' !== self::sanitize_ip( $entry );
		}

		list( $subnet, $bits ) = explode( '/', $entry, 2 );

		$subnet = self::sanitize_ip( $subnet );

		if ( '' === $subnet || ! ctype_digit( $bits ) ) {
			return false;
		}

		$packed = inet_pton( $subnet );

		return false !== $packed && (int) $bits <= strlen( $packed ) * 8;
	}

	/**
	 * Whether an address falls inside an address or CIDR range.
	 *
	 * @param string $ip    Address to test.
	 * @param string $range Address or `address/bits`.
	 * @return bool
	 */
	public static function ip_in_range( $ip, $range ) {
		$ip = self::sanitize_ip( $ip );

		if ( '' === $ip ) {
			return false;
		}

		$range = trim( (string) $range );

		if ( false === strpos( $range, '/' ) ) {
			return self::sanitize_ip( $range ) === $ip;
		}

		list( $subnet, $bits ) = explode( '/', $range, 2 );

		$subnet = self::sanitize_ip( $subnet );

		if ( '' === $subnet || ! ctype_digit( $bits ) ) {
			return false;
		}

		$ip_bin     = inet_pton( $ip );
		$subnet_bin = inet_pton( $subnet );

		// An IPv4 address never matches an IPv6 range, and vice versa.
		if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
			return false;
		}

		$bits = (int) $bits;

		if ( $bits > strlen( $ip_bin ) * 8 ) {
			return false;
		}

		$bytes = intdiv( $bits, 8 );
		$rest  = $bits % 8;

		if ( 0 !== $bytes && substr( $ip_bin, 0, $bytes ) !== substr( $subnet_bin, 0, $bytes ) ) {
			return false;
		}

		if ( 0 === $rest ) {
			return true;
		}

		$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;

		return ( ord( $ip_bin[ $bytes ] ) & $mask ) === ( ord( $subnet_bin[ $bytes ] ) & $mask );
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

	/**
	 * Whether proxy headers may be read for this request.
	 *
	 * With a proxy list, only a request that arrived from one of those
	 * addresses may carry them. Without one, the legacy filter is the only way
	 * to opt in, and it is documented as safe only when the host strips inbound
	 * `X-Forwarded-For`.
	 *
	 * @param string   $remote  Immediate peer address.
	 * @param string[] $proxies Configured proxies.
	 * @return bool
	 */
	private static function may_read_proxy_headers( $remote, array $proxies ) {
		if ( ! empty( $proxies ) ) {
			return self::is_trusted_proxy( $remote, $proxies );
		}

		/**
		 * Whether to trust proxy headers without a configured proxy list.
		 *
		 * @param bool $trust Trust proxy headers.
		 */
		return (bool) apply_filters( 'penalis_trust_proxy_headers', false );
	}

	/**
	 * Whether an address matches one of the configured proxies.
	 *
	 * @param string   $ip      Address.
	 * @param string[] $proxies Configured proxies.
	 * @return bool
	 */
	private static function is_trusted_proxy( $ip, array $proxies ) {
		foreach ( $proxies as $proxy ) {
			if ( self::ip_in_range( $ip, $proxy ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalise an address, returning an empty string when it is not one.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function sanitize_ip( $value ) {
		$value = trim( (string) $value );

		return false !== filter_var( $value, FILTER_VALIDATE_IP ) ? $value : '';
	}
}
