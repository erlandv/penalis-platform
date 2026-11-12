<?php
/**
 * Timezone-aware date and time formatting.
 *
 * WordPress stores timestamps and GMT columns in UTC. Displaying them must
 * always go through the site timezone, never through the server's default
 * timezone or the browser's locale. This helper centralizes that policy.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Formats UTC timestamps and GMT strings in the site timezone.
 */
final class Penalis_Datetime {

	/**
	 * Default date format when the site has not configured one.
	 */
	public const DEFAULT_DATE_FORMAT = 'j M Y';

	/**
	 * Default time format when the site has not configured one.
	 */
	public const DEFAULT_TIME_FORMAT = 'H:i';

	/**
	 * Site date format.
	 *
	 * @return string
	 */
	public static function date_format() {
		$format = get_option( 'date_format' );

		return is_string( $format ) && '' !== $format ? $format : self::DEFAULT_DATE_FORMAT;
	}

	/**
	 * Site time format.
	 *
	 * @return string
	 */
	public static function time_format() {
		$format = get_option( 'time_format' );

		return is_string( $format ) && '' !== $format ? $format : self::DEFAULT_TIME_FORMAT;
	}

	/**
	 * Combined date and time format.
	 *
	 * @return string
	 */
	public static function datetime_format() {
		return self::date_format() . ' ' . self::time_format();
	}

	/**
	 * Format a Unix timestamp in the site timezone.
	 *
	 * @param int    $timestamp Unix timestamp.
	 * @param string $format    Optional format; defaults to the site datetime format.
	 * @return string
	 */
	public static function format( $timestamp, $format = '' ) {
		$timestamp = (int) $timestamp;

		if ( $timestamp <= 0 ) {
			return '';
		}

		$format = '' !== (string) $format ? (string) $format : self::datetime_format();

		return (string) wp_date( $format, $timestamp, self::site_timezone() );
	}

	/**
	 * Format a MySQL GMT datetime string in the site timezone.
	 *
	 * WordPress stores `user_registered`, `post_date_gmt`, and
	 * `post_modified_gmt` as UTC without an offset. Parsing them with
	 * `strtotime()` or `mysql2date()` would interpret them in the server's
	 * timezone, shifting the displayed value. This method parses them
	 * explicitly as UTC.
	 *
	 * @param string $mysql_gmt MySQL datetime in UTC.
	 * @param string $format    Optional format; defaults to the site datetime format.
	 * @return string
	 */
	public static function format_gmt( $mysql_gmt, $format = '' ) {
		$timestamp = self::parse_gmt( $mysql_gmt );

		if ( $timestamp <= 0 ) {
			return '';
		}

		return self::format( $timestamp, $format );
	}

	/**
	 * Format a MySQL datetime that is already in the site timezone.
	 *
	 * WordPress stores `post_modified` (without the `_gmt` suffix) in the
	 * site's local time. Parsing it as UTC would shift the displayed value, so
	 * it is parsed and formatted in the site timezone without conversion.
	 *
	 * @param string $mysql_local MySQL datetime in the site timezone.
	 * @param string $format      Optional format; defaults to the site datetime format.
	 * @return string
	 */
	public static function format_local( $mysql_local, $format = '' ) {
		$value = trim( (string) $mysql_local );

		if ( '' === $value ) {
			return '';
		}

		$format   = '' !== (string) $format ? (string) $format : self::datetime_format();
		$timezone = self::site_timezone();

		foreach ( array( 'Y-m-d H:i:s', 'Y-m-d\TH:i:s' ) as $pattern ) {
			$date = DateTimeImmutable::createFromFormat( $pattern, $value, $timezone );

			if ( false === $date ) {
				continue;
			}

			$errors = DateTimeImmutable::getLastErrors();

			if ( false === $errors || ( 0 === (int) $errors['warning_count'] && 0 === (int) $errors['error_count'] ) ) {
				return $date->format( $format );
			}

			// The value matched a MySQL pattern but described an impossible
			// calendar date (for example 30 February). Do not let it roll over.
			return '';
		}

		try {
			return ( new DateTimeImmutable( $value, $timezone ) )->format( $format );
		} catch ( Exception $exception ) {
			unset( $exception );
			return '';
		}
	}

	/**
	 * Parse a MySQL GMT datetime string into a Unix timestamp.
	 *
	 * @param string $mysql_gmt MySQL datetime in UTC.
	 * @return int Unix timestamp, or 0 when the value cannot be parsed.
	 */
	public static function parse_gmt( $mysql_gmt ) {
		$value = trim( (string) $mysql_gmt );

		if ( '' === $value ) {
			return 0;
		}

		$utc = new DateTimeZone( 'UTC' );

		foreach ( array( 'Y-m-d H:i:s', 'Y-m-d\TH:i:s' ) as $pattern ) {
			$date = DateTimeImmutable::createFromFormat( $pattern, $value, $utc );

			if ( false === $date ) {
				continue;
			}

			$errors = DateTimeImmutable::getLastErrors();

			if ( false === $errors || ( 0 === (int) $errors['warning_count'] && 0 === (int) $errors['error_count'] ) ) {
				return $date->getTimestamp();
			}

			// The value matched a MySQL pattern but described an impossible
			// calendar date (for example 30 February). Do not let strtotime()
			// silently roll it over.
			return 0;
		}

		// Values that already carry an explicit offset are parsed as-is.
		if ( 1 === preg_match( '/(?:Z|[+-]\d{2}:?\d{2})$/', $value ) ) {
			$timestamp = strtotime( $value );

			return false === $timestamp ? 0 : (int) $timestamp;
		}

		$timestamp = strtotime( $value . ' UTC' );

		return false === $timestamp ? 0 : (int) $timestamp;
	}

	/**
	 * Site timezone.
	 *
	 * Supports both IANA timezone strings and WordPress's fixed `gmt_offset`
	 * setting. `wp_timezone()` already handles both on WordPress 5.3+, but the
	 * fallback keeps the helper usable in isolated tests and older contexts.
	 *
	 * @return DateTimeZone
	 */
	public static function site_timezone() {
		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}

		$timezone_string = (string) get_option( 'timezone_string' );

		if ( '' !== $timezone_string ) {
			try {
				return new DateTimeZone( $timezone_string );
			} catch ( Exception $exception ) {
				unset( $exception );
			}
		}

		return self::timezone_from_offset( (float) get_option( 'gmt_offset', 0 ) );
	}

	/**
	 * Build a DateTimeZone from a WordPress GMT offset in hours.
	 *
	 * @param float $offset Offset in hours, for example 7 or 5.5.
	 * @return DateTimeZone
	 */
	public static function timezone_from_offset( $offset ) {
		$seconds  = (int) round( (float) $offset * HOUR_IN_SECONDS );
		$sign     = $seconds < 0 ? '-' : '+';
		$absolute = abs( $seconds );
		$hours    = intdiv( $absolute, HOUR_IN_SECONDS );
		$minutes  = intdiv( $absolute % HOUR_IN_SECONDS, MINUTE_IN_SECONDS );

		return new DateTimeZone( sprintf( '%s%02d:%02d', $sign, $hours, $minutes ) );
	}

	/**
	 * Bootstrap metadata describing the site timezone.
	 *
	 * The dashboard formats timestamps in the browser's timezone. This payload
	 * is informational: it lets the UI explain which timezone the site uses
	 * without overriding the reader's own timezone.
	 *
	 * @param int|null $timestamp Optional reference timestamp for deterministic offsets.
	 * @return array<string,mixed>
	 */
	public static function site_timezone_payload( $timestamp = null ) {
		$timezone = self::site_timezone();
		$now      = null === $timestamp
			? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) )
			: new DateTimeImmutable( '@' . (int) $timestamp );
		$local    = $now->setTimezone( $timezone );
		$name     = $timezone->getName();

		if ( 1 === preg_match( '/^[+-]\d{2}:\d{2}$/', $name ) ) {
			$label = 'UTC' . $name;
		} else {
			$label = $local->format( 'T' );
		}

		return array(
			'string'        => $name,
			'offsetSeconds' => (int) $timezone->getOffset( $now ),
			'label'         => $label,
		);
	}
}
