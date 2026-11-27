<?php
/**
 * Centralized site-wide configuration store with safe defaults and clamping.
 *
 * Groups tunable Penalis Platform settings into cohesive WordPress options so
 * administrators can adjust pages, branding, editorial rules, daily quotes,
 * article display, account limits, OTP/Turnstile, and cache/log retention from
 * `Penalis -> Pengaturan` without editing plugin code.
 *
 * Pure policy constants in `Penalis_Policy` remain the canonical fallback
 * defaults; this class reads stored overrides and clamps every value inside
 * server-enforced safety bounds so a mistyped admin value can never weaken
 * security invariants or break runtime behaviour.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Typed configuration store and sanitizer for Penalis Platform.
 */
final class Penalis_Config {

	public const OPTION_GENERAL         = 'penalis_config_general';
	public const OPTION_EMAIL_BRANDING  = 'penalis_config_email_branding';
	public const OPTION_EDITORIAL       = 'penalis_config_editorial';
	public const OPTION_ARTICLE_DISPLAY = 'penalis_config_article_display';
	public const OPTION_ACCOUNT         = 'penalis_config_account';
	public const OPTION_SECURITY        = 'penalis_config_security';
	public const OPTION_PERFORMANCE     = 'penalis_config_performance';

	public const OPTION_AUDIT_PREFIX = 'penalis_config_audit_';

	public const DEFAULT_EMAIL_LOGO_URL = 'https://penalis.com/wp-content/uploads/2021/01/logo-penalis.png';
	public const DEFAULT_CONTACT_EMAIL  = 'admin@penalis.com';
	public const DEFAULT_EMAIL_FOOTER   = 'Email ini dikirim otomatis. Kamu menerima email ini karena kamu terdaftar sebagai penulis di platform Penalis. Beberapa isi email mungkin bersifat rahasia, sebaiknya jangan bagikan isi email ini kepada siapapun. Jika ada pertanyaan atau kendala, hubungi {email_kontak}.';
	public const DEFAULT_BUTTON_COLOR   = '#5145cd';
	public const DEFAULT_LINK_COLOR     = '#2873c8';

	public const DEFAULT_SHARE_BAR_TITLE = 'Suka karya tulis ini?';
	public const DEFAULT_SHARE_BAR_DESC  = 'Beri apresiasi untuk penulis atau bagikan sebagai gambar siap unggah ke media sosial.';

	/**
	 * Default quick-reason moderation presets for #KalimatHariIni.
	 *
	 * @return string[]
	 */
	public static function default_quote_removal_presets() {
		return array(
			'Spam atau promosi tidak relevan',
			'Mengandung SARA atau ujaran kebencian',
			'Mengandung kata kasar atau tidak pantas',
			'Tidak sesuai norma komunitas Penalis',
		);
	}

	/**
	 * All WordPress option names managed by this configuration store (for uninstall).
	 *
	 * @return string[]
	 */
	public static function all_option_names() {
		$groups = array(
			'general'         => self::OPTION_GENERAL,
			'email_branding'  => self::OPTION_EMAIL_BRANDING,
			'editorial'       => self::OPTION_EDITORIAL,
			'article_display' => self::OPTION_ARTICLE_DISPLAY,
			'account'         => self::OPTION_ACCOUNT,
			'security'        => self::OPTION_SECURITY,
			'performance'     => self::OPTION_PERFORMANCE,
		);

		$names = array_values( $groups );
		foreach ( array_keys( $groups ) as $key ) {
			$names[] = self::OPTION_AUDIT_PREFIX . $key;
		}

		return $names;
	}

	/*
	=====================================================================
	 * 1. General & Pages (`penalis_config_general`)
	 * =================================================================
	 */

	/**
	 * Default values for Umum & Halaman settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function general_defaults() {
		return array(
			'authors_page_id'             => 0,
			'authors_directory_url'       => '',
			'terms_url'                   => '/ketentuan-layanan/',
			'privacy_url'                 => '/kebijakan-privasi/',
			'use_dashboard_template'      => true,
			'use_authors_template'        => true,
			'use_daily_quotes_template'   => true,
			'use_author_archive_template' => true,
			'brand_logo_id'               => 0,
			'brand_icon_id'               => 0,
		);
	}

	/**
	 * Sanitize Umum & Halaman settings.
	 *
	 * @param mixed $raw Raw values.
	 * @return array<string,mixed>
	 */
	public static function sanitize_general( $raw ) {
		$defaults = self::general_defaults();
		$raw      = is_array( $raw ) ? $raw : array();

		return array(
			'authors_page_id'             => isset( $raw['authors_page_id'] ) ? max( 0, (int) $raw['authors_page_id'] ) : $defaults['authors_page_id'],
			'authors_directory_url'       => self::sanitize_path_or_url( $raw['authors_directory_url'] ?? '', '' ),
			'terms_url'                   => self::sanitize_path_or_url( $raw['terms_url'] ?? $defaults['terms_url'], $defaults['terms_url'] ),
			'privacy_url'                 => self::sanitize_path_or_url( $raw['privacy_url'] ?? $defaults['privacy_url'], $defaults['privacy_url'] ),
			'use_dashboard_template'      => array_key_exists( 'use_dashboard_template', $raw ) ? (bool) $raw['use_dashboard_template'] : $defaults['use_dashboard_template'],
			'use_authors_template'        => array_key_exists( 'use_authors_template', $raw ) ? (bool) $raw['use_authors_template'] : $defaults['use_authors_template'],
			'use_daily_quotes_template'   => array_key_exists( 'use_daily_quotes_template', $raw ) ? (bool) $raw['use_daily_quotes_template'] : $defaults['use_daily_quotes_template'],
			'use_author_archive_template' => array_key_exists( 'use_author_archive_template', $raw ) ? (bool) $raw['use_author_archive_template'] : $defaults['use_author_archive_template'],
			'brand_logo_id'               => isset( $raw['brand_logo_id'] ) ? max( 0, (int) $raw['brand_logo_id'] ) : $defaults['brand_logo_id'],
			'brand_icon_id'               => isset( $raw['brand_icon_id'] ) ? max( 0, (int) $raw['brand_icon_id'] ) : $defaults['brand_icon_id'],
		);
	}

	/**
	 * Return active Umum & Halaman settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function general() {
		$stored = self::read_option( self::OPTION_GENERAL );
		return is_array( $stored ) ? self::sanitize_general( $stored ) : self::general_defaults();
	}

	/*
	=====================================================================
	 * 2. Email Branding (`penalis_config_email_branding`)
	 * =================================================================
	 */

	/**
	 * Default values for Email Branding settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function email_branding_defaults() {
		return array(
			'logo_id'       => 0,
			'logo_url'      => self::DEFAULT_EMAIL_LOGO_URL,
			'contact_email' => self::DEFAULT_CONTACT_EMAIL,
			'footer_text'   => self::DEFAULT_EMAIL_FOOTER,
			'button_color'  => self::DEFAULT_BUTTON_COLOR,
			'link_color'    => self::DEFAULT_LINK_COLOR,
		);
	}

	/**
	 * Sanitize Email Branding settings.
	 *
	 * @param mixed $raw Raw values.
	 * @return array<string,mixed>
	 */
	public static function sanitize_email_branding( $raw ) {
		$defaults = self::email_branding_defaults();
		$raw      = is_array( $raw ) ? $raw : array();

		$logo_id  = isset( $raw['logo_id'] ) ? max( 0, (int) $raw['logo_id'] ) : 0;
		$logo_url = isset( $raw['logo_url'] ) ? self::sanitize_http_url( (string) $raw['logo_url'] ) : $defaults['logo_url'];
		if ( '' === $logo_url && $logo_id <= 0 ) {
			$logo_url = $defaults['logo_url'];
		}

		$contact_raw   = isset( $raw['contact_email'] ) ? trim( (string) $raw['contact_email'] ) : $defaults['contact_email'];
		$contact_email = self::is_valid_email( $contact_raw ) ? $contact_raw : $defaults['contact_email'];

		$footer_text = isset( $raw['footer_text'] ) ? trim( self::strip_tags_clean( (string) $raw['footer_text'], true ) ) : $defaults['footer_text'];
		if ( '' === $footer_text ) {
			$footer_text = $defaults['footer_text'];
		}
		$footer_text = self::substr_utf8( $footer_text, 600 );

		return array(
			'logo_id'       => $logo_id,
			'logo_url'      => $logo_url,
			'contact_email' => $contact_email,
			'footer_text'   => $footer_text,
			'button_color'  => self::sanitize_hex_color( $raw['button_color'] ?? $defaults['button_color'], $defaults['button_color'] ),
			'link_color'    => self::sanitize_hex_color( $raw['link_color'] ?? $defaults['link_color'], $defaults['link_color'] ),
		);
	}

	/**
	 * Return active Email Branding settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function email_branding() {
		$stored = self::read_option( self::OPTION_EMAIL_BRANDING );
		return is_array( $stored ) ? self::sanitize_email_branding( $stored ) : self::email_branding_defaults();
	}

	/**
	 * Resolve the active header logo URL for transactional HTML emails.
	 *
	 * @return string
	 */
	public static function resolved_email_logo_url() {
		$cfg = self::email_branding();
		if ( (int) $cfg['logo_id'] > 0 && function_exists( 'wp_get_attachment_image_url' ) ) {
			$url = wp_get_attachment_image_url( (int) $cfg['logo_id'], 'medium' );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}

		return '' !== (string) $cfg['logo_url'] ? (string) $cfg['logo_url'] : self::DEFAULT_EMAIL_LOGO_URL;
	}

	/*
	=====================================================================
	 * 3. Editorial & Community (`penalis_config_editorial`)
	 * =================================================================
	 */

	/**
	 * Supported writer upload image type keys.
	 *
	 * @return string[]
	 */
	public static function all_image_type_keys() {
		return array( 'jpeg', 'png', 'gif', 'webp' );
	}

	/**
	 * Default values for Editorial & Komunitas settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function editorial_defaults() {
		return array(
			'require_thumbnail'       => true,
			'require_category'        => true,
			'min_tags'                => 1,
			'draft_revisions'         => class_exists( 'Penalis_Revisions' ) ? Penalis_Revisions::DRAFT_LIMIT : 20,
			'allowed_image_types'     => self::all_image_type_keys(),
			'quote_enabled'           => true,
			'quote_max_length'        => Penalis_Policy::DAILY_QUOTE_MAX_LENGTH,
			'quote_max_lines'         => Penalis_Policy::DAILY_QUOTE_MAX_LINES,
			'quote_ttl_hours'         => (int) round( Penalis_Policy::DAILY_QUOTE_TTL / 3600 ),
			'quote_daily_limit'       => Penalis_Policy::DAILY_QUOTE_DAILY_LIMIT,
			'quote_per_page'          => Penalis_Policy::DAILY_QUOTE_PER_PAGE,
			'quote_reason_max_length' => Penalis_Policy::DAILY_QUOTE_REASON_MAX_LENGTH,
			'quote_removal_presets'   => self::default_quote_removal_presets(),
		);
	}

	/**
	 * Sanitize Editorial & Komunitas settings.
	 *
	 * @param mixed $raw Raw values.
	 * @return array<string,mixed>
	 */
	public static function sanitize_editorial( $raw ) {
		$defaults = self::editorial_defaults();
		$raw      = is_array( $raw ) ? $raw : array();

		$allowed_keys = self::all_image_type_keys();
		$image_types  = array();
		if ( isset( $raw['allowed_image_types'] ) && is_array( $raw['allowed_image_types'] ) ) {
			foreach ( $raw['allowed_image_types'] as $type ) {
				$type = strtolower( trim( (string) $type ) );
				if ( in_array( $type, $allowed_keys, true ) && ! in_array( $type, $image_types, true ) ) {
					$image_types[] = $type;
				}
			}
		}
		if ( empty( $image_types ) ) {
			$image_types = $defaults['allowed_image_types'];
		}

		$presets = array();
		if ( isset( $raw['quote_removal_presets'] ) ) {
			$lines = is_array( $raw['quote_removal_presets'] )
				? $raw['quote_removal_presets']
				: preg_split( '/\r\n|\r|\n/', (string) $raw['quote_removal_presets'] );

			foreach ( (array) $lines as $line ) {
				$clean = trim( preg_replace( '/\s+/u', ' ', self::strip_tags_clean( (string) $line, false ) ) );
				if ( '' !== $clean ) {
					$clean = self::substr_utf8( $clean, 160 );
					if ( ! in_array( $clean, $presets, true ) ) {
						$presets[] = $clean;
					}
				}
				if ( count( $presets ) >= 10 ) {
					break;
				}
			}
		}
		if ( empty( $presets ) ) {
			$presets = $defaults['quote_removal_presets'];
		}

		return array(
			'require_thumbnail'       => array_key_exists( 'require_thumbnail', $raw ) ? (bool) $raw['require_thumbnail'] : $defaults['require_thumbnail'],
			'require_category'        => array_key_exists( 'require_category', $raw ) ? (bool) $raw['require_category'] : $defaults['require_category'],
			'min_tags'                => self::clamp_int( $raw['min_tags'] ?? $defaults['min_tags'], 0, 10, $defaults['min_tags'] ),
			'draft_revisions'         => self::clamp_int( $raw['draft_revisions'] ?? $defaults['draft_revisions'], 0, 100, $defaults['draft_revisions'] ),
			'allowed_image_types'     => $image_types,
			'quote_enabled'           => array_key_exists( 'quote_enabled', $raw ) ? (bool) $raw['quote_enabled'] : $defaults['quote_enabled'],
			'quote_max_length'        => self::clamp_int( $raw['quote_max_length'] ?? $defaults['quote_max_length'], 40, 500, $defaults['quote_max_length'] ),
			'quote_max_lines'         => self::clamp_int( $raw['quote_max_lines'] ?? $defaults['quote_max_lines'], 1, 6, $defaults['quote_max_lines'] ),
			'quote_ttl_hours'         => self::clamp_int( $raw['quote_ttl_hours'] ?? $defaults['quote_ttl_hours'], 1, 168, $defaults['quote_ttl_hours'] ),
			'quote_daily_limit'       => self::clamp_int( $raw['quote_daily_limit'] ?? $defaults['quote_daily_limit'], 1, 20, $defaults['quote_daily_limit'] ),
			'quote_per_page'          => self::clamp_int( $raw['quote_per_page'] ?? $defaults['quote_per_page'], 6, Penalis_Policy::DAILY_QUOTE_MAX_PER_PAGE, $defaults['quote_per_page'] ),
			'quote_reason_max_length' => self::clamp_int( $raw['quote_reason_max_length'] ?? $defaults['quote_reason_max_length'], 50, 500, $defaults['quote_reason_max_length'] ),
			'quote_removal_presets'   => $presets,
		);
	}

	/**
	 * Return active Editorial & Komunitas settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function editorial() {
		$stored = self::read_option( self::OPTION_EDITORIAL );
		return is_array( $stored ) ? self::sanitize_editorial( $stored ) : self::editorial_defaults();
	}

	/**
	 * Return active #KalimatHariIni TTL in seconds.
	 *
	 * @return int
	 */
	public static function quote_ttl_seconds() {
		return (int) self::editorial()['quote_ttl_hours'] * 3600;
	}

	/**
	 * Return allowed WordPress upload MIME map for writers (`ext => mime`).
	 *
	 * @return array<string,string>
	 */
	public static function allowed_upload_mimes() {
		$map = array(
			'jpeg' => array( 'jpg|jpeg|jpe', 'image/jpeg' ),
			'png'  => array( 'png', 'image/png' ),
			'gif'  => array( 'gif', 'image/gif' ),
			'webp' => array( 'webp', 'image/webp' ),
		);

		$result = array();
		foreach ( self::editorial()['allowed_image_types'] as $key ) {
			if ( isset( $map[ $key ] ) ) {
				$result[ $map[ $key ][0] ] = $map[ $key ][1];
			}
		}

		return $result;
	}

	/**
	 * Return allowed `IMAGETYPE_*` constants matching active upload types.
	 *
	 * @return int[]
	 */
	public static function allowed_imagetypes() {
		$map = array(
			'jpeg' => IMAGETYPE_JPEG,
			'png'  => IMAGETYPE_PNG,
			'gif'  => IMAGETYPE_GIF,
			'webp' => IMAGETYPE_WEBP,
		);

		$result = array();
		foreach ( self::editorial()['allowed_image_types'] as $key ) {
			if ( isset( $map[ $key ] ) ) {
				$result[] = $map[ $key ];
			}
		}

		return $result;
	}

	/*
	=====================================================================
	 * 4. Article Display: Share & Reactions (`penalis_config_article_display`)
	 * =================================================================
	 */

	/**
	 * Default values for single-post Share & Reactions bar settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function article_display_defaults() {
		return array(
			'auto_append_share_bar' => true,
			'share_bar_title'       => self::DEFAULT_SHARE_BAR_TITLE,
			'share_bar_description' => self::DEFAULT_SHARE_BAR_DESC,
			'reactions_mode'        => 'both',
			'reactions_cookie_days' => 365,
		);
	}

	/**
	 * Sanitize Article Display settings.
	 *
	 * @param mixed $raw Raw values.
	 * @return array<string,mixed>
	 */
	public static function sanitize_article_display( $raw ) {
		$defaults = self::article_display_defaults();
		$raw      = is_array( $raw ) ? $raw : array();

		$title = isset( $raw['share_bar_title'] ) ? trim( self::strip_tags_clean( (string) $raw['share_bar_title'], false ) ) : $defaults['share_bar_title'];
		if ( '' === $title ) {
			$title = $defaults['share_bar_title'];
		}
		$title = self::substr_utf8( $title, 120 );

		$desc = isset( $raw['share_bar_description'] ) ? trim( self::strip_tags_clean( (string) $raw['share_bar_description'], false ) ) : $defaults['share_bar_description'];
		if ( '' === $desc ) {
			$desc = $defaults['share_bar_description'];
		}
		$desc = self::substr_utf8( $desc, 240 );

		$mode = isset( $raw['reactions_mode'] ) ? strtolower( trim( (string) $raw['reactions_mode'] ) ) : $defaults['reactions_mode'];
		if ( ! in_array( $mode, array( 'both', 'like_only', 'off' ), true ) ) {
			$mode = $defaults['reactions_mode'];
		}

		return array(
			'auto_append_share_bar' => array_key_exists( 'auto_append_share_bar', $raw ) ? (bool) $raw['auto_append_share_bar'] : $defaults['auto_append_share_bar'],
			'share_bar_title'       => $title,
			'share_bar_description' => $desc,
			'reactions_mode'        => $mode,
			'reactions_cookie_days' => self::clamp_int( $raw['reactions_cookie_days'] ?? $defaults['reactions_cookie_days'], 1, 365, $defaults['reactions_cookie_days'] ),
		);
	}

	/**
	 * Return active Article Display settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function article_display() {
		$stored = self::read_option( self::OPTION_ARTICLE_DISPLAY );
		return is_array( $stored ) ? self::sanitize_article_display( $stored ) : self::article_display_defaults();
	}

	/*
	=====================================================================
	 * 5. Account & Profile (`penalis_config_account`)
	 * =================================================================
	 */

	/**
	 * Default values for Account, Profile & Avatar upload settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function account_defaults() {
		return array(
			'min_password'          => Penalis_Policy::MIN_PASSWORD,
			'max_bio'               => Penalis_Policy::MAX_BIO,
			'avatar_max_mb'         => 5,
			'avatar_output_size'    => 256,
			'avatar_output_quality' => 85,
			'avatar_min_edge'       => Penalis_Policy::AVATAR_MIN_EDGE,
		);
	}

	/**
	 * Sanitize Account & Profile settings.
	 *
	 * @param mixed $raw Raw values.
	 * @return array<string,mixed>
	 */
	public static function sanitize_account( $raw ) {
		$defaults = self::account_defaults();
		$raw      = is_array( $raw ) ? $raw : array();

		return array(
			'min_password'          => self::clamp_int( $raw['min_password'] ?? $defaults['min_password'], 8, 64, $defaults['min_password'] ),
			'max_bio'               => self::clamp_int( $raw['max_bio'] ?? $defaults['max_bio'], 50, 1000, $defaults['max_bio'] ),
			'avatar_max_mb'         => self::clamp_int( $raw['avatar_max_mb'] ?? $defaults['avatar_max_mb'], 1, 10, $defaults['avatar_max_mb'] ),
			'avatar_output_size'    => self::clamp_int( $raw['avatar_output_size'] ?? $defaults['avatar_output_size'], 128, 512, $defaults['avatar_output_size'] ),
			'avatar_output_quality' => self::clamp_int( $raw['avatar_output_quality'] ?? $defaults['avatar_output_quality'], 60, 95, $defaults['avatar_output_quality'] ),
			'avatar_min_edge'       => self::clamp_int( $raw['avatar_min_edge'] ?? $defaults['avatar_min_edge'], 50, 512, $defaults['avatar_min_edge'] ),
		);
	}

	/**
	 * Return active Account & Profile settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function account() {
		$stored = self::read_option( self::OPTION_ACCOUNT );
		return is_array( $stored ) ? self::sanitize_account( $stored ) : self::account_defaults();
	}

	/*
	=====================================================================
	 * 6. Security: Turnstile Forms, OTP & CDN (`penalis_config_security`)
	 * =================================================================
	 */

	/**
	 * Supported dashboard Turnstile form contexts.
	 *
	 * @return string[]
	 */
	public static function turnstile_form_keys() {
		return array( 'login', 'register', 'activate', 'forgot', 'reset' );
	}

	/**
	 * Default values for Security (Sensitive) settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function security_defaults() {
		return array(
			'turnstile_forms'  => array(
				'login'    => true,
				'register' => true,
				'activate' => true,
				'forgot'   => true,
				'reset'    => true,
			),
			'otp_ttl_minutes'  => (int) round( Penalis_Policy::OTP_TTL / 60 ),
			'otp_resend_wait'  => Penalis_Policy::OTP_RESEND_WAIT,
			'otp_max_attempts' => Penalis_Policy::OTP_MAX_ATTEMPTS,
			'otp_max_issues'   => Penalis_Policy::OTP_MAX_ISSUES,
			'otp_daily_issues' => Penalis_Policy::OTP_DAILY_ISSUES,
			'asset_base_url'   => '',
		);
	}

	/**
	 * Sanitize Security (Sensitive) settings.
	 *
	 * @param mixed $raw Raw values.
	 * @return array<string,mixed>
	 */
	public static function sanitize_security( $raw ) {
		$defaults = self::security_defaults();
		$raw      = is_array( $raw ) ? $raw : array();

		$forms     = array();
		$raw_forms = isset( $raw['turnstile_forms'] ) && is_array( $raw['turnstile_forms'] )
			? $raw['turnstile_forms']
			: null;

		foreach ( self::turnstile_form_keys() as $key ) {
			$forms[ $key ] = null === $raw_forms
				? (bool) $defaults['turnstile_forms'][ $key ]
				: ! empty( $raw_forms[ $key ] );
		}

		$otp_max_issues   = self::clamp_int( $raw['otp_max_issues'] ?? $defaults['otp_max_issues'], 2, 20, $defaults['otp_max_issues'] );
		$otp_daily_issues = self::clamp_int( $raw['otp_daily_issues'] ?? $defaults['otp_daily_issues'], $otp_max_issues, 50, $defaults['otp_daily_issues'] );

		$asset_base_url = isset( $raw['asset_base_url'] ) ? rtrim( self::sanitize_http_url( (string) $raw['asset_base_url'] ), '/' ) : '';

		return array(
			'turnstile_forms'  => $forms,
			'otp_ttl_minutes'  => self::clamp_int( $raw['otp_ttl_minutes'] ?? $defaults['otp_ttl_minutes'], 3, 30, $defaults['otp_ttl_minutes'] ),
			'otp_resend_wait'  => self::clamp_int( $raw['otp_resend_wait'] ?? $defaults['otp_resend_wait'], 30, 300, $defaults['otp_resend_wait'] ),
			'otp_max_attempts' => self::clamp_int( $raw['otp_max_attempts'] ?? $defaults['otp_max_attempts'], 3, 10, $defaults['otp_max_attempts'] ),
			'otp_max_issues'   => $otp_max_issues,
			'otp_daily_issues' => $otp_daily_issues,
			'asset_base_url'   => $asset_base_url,
		);
	}

	/**
	 * Return active Security settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function security() {
		$stored = self::read_option( self::OPTION_SECURITY );
		return is_array( $stored ) ? self::sanitize_security( $stored ) : self::security_defaults();
	}

	/**
	 * Return active OTP lifetime in seconds.
	 *
	 * @return int
	 */
	public static function otp_ttl_seconds() {
		return (int) self::security()['otp_ttl_minutes'] * 60;
	}

	/*
	=====================================================================
	 * 7. Performance, Cache & Retention (`penalis_config_performance`)
	 * =================================================================
	 */

	/**
	 * Default values for Cache, Retention & Analytics settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function performance_defaults() {
		return array(
			'authors_cache_ttl'     => 600,
			'authors_per_page'      => Penalis_Policy::AUTHORS_PER_PAGE,
			'quotes_cache_ttl'      => 120,
			'email_log_limit'       => 100,
			'notification_limit'    => Penalis_Editorial_Policy::NOTIFICATION_LIMIT,
			'review_history_limit'  => Penalis_Editorial_Policy::HISTORY_LIMIT,
			'account_log_limit'     => 20,
			'analytics_weeks'       => 8,
			'analytics_top_authors' => 3,
		);
	}

	/**
	 * Sanitize Cache, Retention & Analytics settings.
	 *
	 * @param mixed $raw Raw values.
	 * @return array<string,mixed>
	 */
	public static function sanitize_performance( $raw ) {
		$defaults = self::performance_defaults();
		$raw      = is_array( $raw ) ? $raw : array();

		return array(
			'authors_cache_ttl'     => self::clamp_int( $raw['authors_cache_ttl'] ?? $defaults['authors_cache_ttl'], 0, 3600, $defaults['authors_cache_ttl'] ),
			'authors_per_page'      => self::clamp_int( $raw['authors_per_page'] ?? $defaults['authors_per_page'], 12, Penalis_Policy::AUTHORS_MAX_PER_PAGE, $defaults['authors_per_page'] ),
			'quotes_cache_ttl'      => self::clamp_int( $raw['quotes_cache_ttl'] ?? $defaults['quotes_cache_ttl'], 0, 3600, $defaults['quotes_cache_ttl'] ),
			'email_log_limit'       => self::clamp_int( $raw['email_log_limit'] ?? $defaults['email_log_limit'], 20, 500, $defaults['email_log_limit'] ),
			'notification_limit'    => self::clamp_int( $raw['notification_limit'] ?? $defaults['notification_limit'], 10, 200, $defaults['notification_limit'] ),
			'review_history_limit'  => self::clamp_int( $raw['review_history_limit'] ?? $defaults['review_history_limit'], 10, 200, $defaults['review_history_limit'] ),
			'account_log_limit'     => self::clamp_int( $raw['account_log_limit'] ?? $defaults['account_log_limit'], 10, 100, $defaults['account_log_limit'] ),
			'analytics_weeks'       => self::clamp_int( $raw['analytics_weeks'] ?? $defaults['analytics_weeks'], 4, 26, $defaults['analytics_weeks'] ),
			'analytics_top_authors' => self::clamp_int( $raw['analytics_top_authors'] ?? $defaults['analytics_top_authors'], 3, 10, $defaults['analytics_top_authors'] ),
		);
	}

	/**
	 * Return active Cache, Retention & Analytics settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function performance() {
		$stored = self::read_option( self::OPTION_PERFORMANCE );
		return is_array( $stored ) ? self::sanitize_performance( $stored ) : self::performance_defaults();
	}

	/*
	=====================================================================
	 * Frontend Bootstrap Payload
	 * =================================================================
	 */

	/**
	 * Export public/writer-relevant limits and URLs for the React dashboard SPA.
	 *
	 * Keeps React validation hints and links synchronized with server settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function frontend_config() {
		$general   = self::general();
		$editorial = self::editorial();
		$account   = self::account();

		return array(
			'termsUrl'             => (string) $general['terms_url'],
			'privacyUrl'           => (string) $general['privacy_url'],
			'minPassword'          => (int) $account['min_password'],
			'maxBio'               => (int) $account['max_bio'],
			'avatarMaxBytes'       => (int) $account['avatar_max_mb'] * 1024 * 1024,
			'avatarOutputSize'     => (int) $account['avatar_output_size'],
			'avatarMinEdge'        => (int) $account['avatar_min_edge'],
			'allowedImageMimes'    => array_values( self::allowed_upload_mimes() ),
			'requireThumbnail'     => (bool) $editorial['require_thumbnail'],
			'requireCategory'      => (bool) $editorial['require_category'],
			'minTags'              => (int) $editorial['min_tags'],
			'quoteEnabled'         => (bool) $editorial['quote_enabled'],
			'quoteMaxLength'       => (int) $editorial['quote_max_length'],
			'quoteMaxLines'        => (int) $editorial['quote_max_lines'],
			'quoteTtlHours'        => (int) $editorial['quote_ttl_hours'],
			'quoteDailyLimit'      => (int) $editorial['quote_daily_limit'],
			'quoteReasonMaxLength' => (int) $editorial['quote_reason_max_length'],
			'quoteRemovalPresets'  => array_values( $editorial['quote_removal_presets'] ),
		);
	}

	/*
	=====================================================================
	 * Internal Helpers
	 * =================================================================
	 */

	/**
	 * Safely read a WordPress option, returning null when WP is not loaded.
	 *
	 * @param string $option Option name.
	 * @return mixed
	 */
	private static function read_option( $option ) {
		if ( ! function_exists( 'get_option' ) ) {
			return null;
		}

		return get_option( $option, null );
	}

	/**
	 * Clamp an integer value inside inclusive bounds, falling back when non-numeric.
	 *
	 * @param mixed $value    Raw value.
	 * @param int   $min      Minimum allowed value.
	 * @param int   $max      Maximum allowed value.
	 * @param int   $fallback Default when missing or non-numeric.
	 * @return int
	 */
	private static function clamp_int( $value, $min, $max, $fallback ) {
		if ( ! is_scalar( $value ) || '' === trim( (string) $value ) || ! is_numeric( $value ) ) {
			return (int) $fallback;
		}

		$int = (int) $value;
		if ( $int < $min ) {
			return $min;
		}
		if ( $int > $max ) {
			return $max;
		}

		return $int;
	}

	/**
	 * Sanitize a relative site path (`/slug/`) or absolute HTTP(S) URL.
	 *
	 * @param mixed  $value    Raw value.
	 * @param string $fallback Fallback when empty or unsafe.
	 * @return string
	 */
	private static function sanitize_path_or_url( $value, $fallback ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return $fallback;
		}

		if ( 0 === strpos( $value, '/' ) && 0 !== strpos( $value, '//' ) ) {
			$clean = preg_replace( '/[\x00-\x1F\x7F\s"\'<>]/', '', $value );
			return is_string( $clean ) && '' !== $clean ? $clean : $fallback;
		}

		$url = self::sanitize_http_url( $value );
		return '' !== $url ? $url : $fallback;
	}

	/**
	 * Sanitize an absolute HTTP(S) URL, rejecting other schemes.
	 *
	 * @param string $value Raw URL.
	 * @return string
	 */
	private static function sanitize_http_url( $value ) {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		$parts  = parse_url( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Must work without WP loaded.
		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		$host   = isset( $parts['host'] ) ? (string) $parts['host'] : '';

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
			return '';
		}

		if ( function_exists( 'esc_url_raw' ) ) {
			return (string) esc_url_raw( $value, array( 'http', 'https' ) );
		}

		return $value;
	}

	/**
	 * Validate a `#rrggbb` hex color string.
	 *
	 * @param mixed  $value    Raw value.
	 * @param string $fallback Fallback hex color.
	 * @return string
	 */
	private static function sanitize_hex_color( $value, $fallback ) {
		$value = strtolower( trim( (string) $value ) );
		if ( 1 === preg_match( '/^#[0-9a-f]{6}$/', $value ) ) {
			return $value;
		}

		return $fallback;
	}

	/**
	 * Validate an email address with or without WordPress loaded.
	 *
	 * @param string $email Candidate email.
	 * @return bool
	 */
	private static function is_valid_email( $email ) {
		if ( '' === $email ) {
			return false;
		}

		if ( function_exists( 'is_email' ) ) {
			return false !== is_email( $email );
		}

		return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
	}

	/**
	 * Strip HTML tags cleanly with optional newline preservation.
	 *
	 * @param string $text           Raw string.
	 * @param bool   $keep_newlines  Whether to preserve newlines.
	 * @return string
	 */
	private static function strip_tags_clean( $text, $keep_newlines ) {
		$text = strip_tags( $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Works in WP-free unit tests.
		if ( $keep_newlines ) {
			return str_replace( array( "\r\n", "\r" ), "\n", $text );
		}

		return str_replace( array( "\r\n", "\r", "\n" ), ' ', $text );
	}

	/**
	 * Multibyte-safe string truncation.
	 *
	 * @param string $text   Text.
	 * @param int    $length Max characters.
	 * @return string
	 */
	private static function substr_utf8( $text, $length ) {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, $length, 'UTF-8' );
		}

		return substr( $text, 0, $length );
	}
}
