<?php
/**
 * Pure policy and validation rules.
 *
 * This class intentionally has no WordPress dependencies so the security
 * critical rules can be unit tested inside the repository without a WordPress
 * installation. WordPress-specific persistence lives in Penalis_Account_Status.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central authorization and validation policy.
 */
final class Penalis_Policy {

	public const STATUS_PENDING_VERIFICATION = 'pending_verification';
	public const STATUS_PENDING_APPROVAL     = 'pending_approval';
	public const STATUS_ACTIVE               = 'active';
	public const STATUS_REJECTED             = 'rejected';
	public const STATUS_SUSPENDED            = 'suspended';

	public const TOKEN_TTL         = 86400;
	public const TOKEN_RESEND_WAIT = 600;
	public const MIN_PASSWORD      = 12;
	public const MAX_PASSWORD      = 200;

	public const MIN_DISPLAY_NAME = 2;
	public const MAX_DISPLAY_NAME = 80;
	public const MAX_PERSON_NAME  = 50;
	public const AVATAR_MIN_EDGE  = 100;

	/**
	 * Multibyte-safe string length with a non-mbstring fallback.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	private static function length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}

	/**
	 * Supported sources for the public display name.
	 *
	 * @return string[]
	 */
	public static function display_name_modes() {
		return array( 'auto', 'first', 'last', 'username', 'custom' );
	}

	/**
	 * Whether a display-name mode is known.
	 *
	 * @param mixed $mode Mode value.
	 * @return bool
	 */
	public static function is_valid_display_mode( $mode ) {
		return is_string( $mode ) && in_array( $mode, self::display_name_modes(), true );
	}

	/**
	 * Trim a person name and collapse internal whitespace.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function normalize_person_name( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		return (string) preg_replace( '/\s+/u', ' ', $value );
	}

	/**
	 * Resolve the public display name for a mode.
	 *
	 * Mirrors the WordPress core fallback used when no display name is supplied:
	 * first and last name, then first name, then last name, then the username.
	 *
	 * @param string $mode     Display name mode.
	 * @param string $first    First name.
	 * @param string $last     Last name.
	 * @param string $login    Username, used as the final fallback.
	 * @param string $custom   Custom display name for the `custom` mode.
	 * @return string
	 */
	public static function resolve_display_name( $mode, $first, $last, $login, $custom = '' ) {
		$first = self::normalize_person_name( $first );
		$last  = self::normalize_person_name( $last );
		$login = trim( (string) $login );

		switch ( $mode ) {
			case 'first':
				return $first;
			case 'last':
				return $last;
			case 'username':
				return $login;
			case 'custom':
				return self::normalize_person_name( $custom );
			case 'auto':
			default:
				if ( '' !== $first && '' !== $last ) {
					return $first . ' ' . $last;
				}
				if ( '' !== $first ) {
					return $first;
				}
				if ( '' !== $last ) {
					return $last;
				}
				return $login;
		}
	}

	/**
	 * All supported statuses.
	 *
	 * @return string[]
	 */
	public static function statuses() {
		return array(
			self::STATUS_PENDING_VERIFICATION,
			self::STATUS_PENDING_APPROVAL,
			self::STATUS_ACTIVE,
			self::STATUS_REJECTED,
			self::STATUS_SUSPENDED,
		);
	}

	/**
	 * Whether a status value is known.
	 *
	 * @param mixed $status Status value.
	 * @return bool
	 */
	public static function is_valid_status( $status ) {
		return is_string( $status ) && in_array( $status, self::statuses(), true );
	}

	/**
	 * Human-readable label.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			self::STATUS_PENDING_VERIFICATION => 'Menunggu verifikasi email',
			self::STATUS_PENDING_APPROVAL     => 'Menunggu persetujuan admin',
			self::STATUS_ACTIVE               => 'Penulis aktif',
			self::STATUS_REJECTED             => 'Pendaftaran ditolak',
			self::STATUS_SUSPENDED            => 'Akun ditangguhkan',
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : 'Unknown';
	}

	/**
	 * Whether an account may sign in.
	 *
	 * Pending accounts may sign in to see their status. Active accounts must
	 * also have explicit plugin access enabled. Rejected and suspended accounts
	 * may never sign in through the plugin gate.
	 *
	 * An empty status with explicit access enabled is the legacy escape hatch:
	 * it restores native WordPress login for an existing contributor without
	 * granting dashboard writing access.
	 *
	 * @param string $status         Account status.
	 * @param bool   $access_enabled Explicit access flag.
	 * @return bool
	 */
	public static function can_login( $status, $access_enabled ) {
		if ( self::STATUS_PENDING_VERIFICATION === $status || self::STATUS_PENDING_APPROVAL === $status ) {
			return true;
		}

		if ( self::STATUS_ACTIVE === $status ) {
			return (bool) $access_enabled;
		}

		if ( '' === $status || null === $status ) {
			return (bool) $access_enabled;
		}

		return false;
	}

	/**
	 * Whether an account may create, edit, upload, or publish content.
	 *
	 * @param string $status         Account status.
	 * @param bool   $access_enabled Explicit access flag.
	 * @return bool
	 */
	public static function can_write( $status, $access_enabled ) {
		return self::STATUS_ACTIVE === $status && (bool) $access_enabled;
	}

	/**
	 * Whether a status can be displayed on the dashboard.
	 *
	 * @param string $status Status.
	 * @return bool
	 */
	public static function can_view_dashboard( $status ) {
		return in_array(
			$status,
			array(
				self::STATUS_PENDING_VERIFICATION,
				self::STATUS_PENDING_APPROVAL,
				self::STATUS_ACTIVE,
			),
			true
		);
	}

	/**
	 * Whether a transition is allowed.
	 *
	 * @param string $from Current status.
	 * @param string $to   Target status.
	 * @return bool
	 */
	public static function can_transition( $from, $to ) {
		if ( ! self::is_valid_status( $from ) || ! self::is_valid_status( $to ) ) {
			return false;
		}

		if ( $from === $to ) {
			return false;
		}

		$allowed = array(
			self::STATUS_PENDING_VERIFICATION => array(
				self::STATUS_PENDING_APPROVAL,
				self::STATUS_REJECTED,
			),
			self::STATUS_PENDING_APPROVAL     => array(
				self::STATUS_ACTIVE,
				self::STATUS_REJECTED,
			),
			self::STATUS_ACTIVE               => array(
				self::STATUS_SUSPENDED,
			),
			self::STATUS_SUSPENDED            => array(
				self::STATUS_ACTIVE,
			),
			self::STATUS_REJECTED             => array(
				self::STATUS_PENDING_APPROVAL,
			),
		);

		return isset( $allowed[ $from ] ) && in_array( $to, $allowed[ $from ], true );
	}

	/**
	 * Post statuses a writer may set through the editor.
	 *
	 * @return string[]
	 */
	public static function allowed_post_statuses() {
		return array( 'draft', 'publish', 'pending' );
	}

	/**
	 * Whether a writer may edit a specific post.
	 *
	 * @param int    $user_id        Current user ID.
	 * @param int    $post_author_id Post author ID.
	 * @param string $status         Account status.
	 * @param bool   $access_enabled Explicit access flag.
	 * @return bool
	 */
	public static function can_edit_article( $user_id, $post_author_id, $status, $access_enabled ) {
		return self::can_write( $status, $access_enabled )
			&& (int) $user_id > 0
			&& (int) $user_id === (int) $post_author_id;
	}

	/**
	 * Resolve an author assignment.
	 *
	 * Returns an array with either `author` (int) or `error` (string).
	 *
	 * @param mixed $requested_author Client-provided author, if any.
	 * @param int   $current_user_id  Authenticated user ID.
	 * @return array{author?:int,error?:string}
	 */
	public static function resolve_author( $requested_author, $current_user_id ) {
		$current_user_id = (int) $current_user_id;
		if ( $current_user_id <= 0 ) {
			return array( 'error' => 'Autentikasi diperlukan.' );
		}

		if ( null === $requested_author || '' === $requested_author ) {
			return array( 'author' => $current_user_id );
		}

		if ( ! is_numeric( $requested_author ) ) {
			return array( 'error' => 'Nilai penulis tidak valid.' );
		}

		if ( (int) $requested_author !== $current_user_id ) {
			return array( 'error' => 'Kamu tidak dapat menetapkan karya tulis ini kepada penulis lain.' );
		}

		return array( 'author' => $current_user_id );
	}

	/**
	 * Validate registration input.
	 *
	 * @param array $data Raw input.
	 * @return array<string,string> Field => error message.
	 */
	public static function validate_registration( $data ) {
		$errors = array();

		$display_name = isset( $data['display_name'] ) ? trim( (string) $data['display_name'] ) : '';
		if ( self::length( $display_name ) < 2 || self::length( $display_name ) > 80 ) {
			$errors['display_name'] = 'Masukkan nama tampilan 2-80 karakter.';
		}

		$user_login = isset( $data['user_login'] ) ? trim( (string) $data['user_login'] ) : '';
		if ( ! preg_match( '/^[a-zA-Z0-9._-]{3,60}$/', $user_login ) ) {
			$errors['user_login'] = 'Nama pengguna harus 3-60 karakter berupa huruf, angka, titik, garis bawah, atau tanda hubung.';
		}

		$email = isset( $data['user_email'] ) ? trim( (string) $data['user_email'] ) : '';
		if ( '' === $email || false === filter_var( $email, FILTER_VALIDATE_EMAIL ) || self::length( $email ) > 254 ) {
			$errors['user_email'] = 'Masukkan alamat email yang valid.';
		}

		$password = isset( $data['password'] ) ? (string) $data['password'] : '';
		$pw_error = self::validate_password( $password );
		if ( '' !== $pw_error ) {
			$errors['password'] = $pw_error;
		}

		if ( empty( $data['penalis_consent'] ) || '1' !== (string) $data['penalis_consent'] ) {
			$errors['penalis_consent'] = 'Kamu harus menyetujui ketentuan untuk mendaftar.';
		}

		// Honeypot must remain empty. A filled value is treated as a validation
		// failure but the API responds generically.
		if ( ! empty( $data['penalis_website'] ) ) {
			$errors['_honeypot'] = 'Pendaftaran tidak dapat diproses.';
		}

		return $errors;
	}

	/**
	 * Validate a new password.
	 *
	 * @param string $password Password.
	 * @return string Error message or empty string.
	 */
	public static function validate_password( $password ) {
		if ( ! is_string( $password ) || strlen( $password ) < self::MIN_PASSWORD ) {
			return 'Passwords must be at least ' . self::MIN_PASSWORD . ' characters long.';
		}

		if ( strlen( $password ) > self::MAX_PASSWORD ) {
			return 'Passwords must be ' . self::MAX_PASSWORD . ' characters or fewer.';
		}

		if ( trim( $password ) === '' ) {
			return 'Pilih kata sandi yang lebih kuat.';
		}

		return '';
	}

	/**
	 * Validate safe profile fields.
	 *
	 * @param array $data Raw input.
	 * @return array<string,string> Field => error message.
	 */
	public static function validate_profile( $data ) {
		$errors = array();

		$mode = isset( $data['display_name_mode'] ) ? (string) $data['display_name_mode'] : '';

		if ( '' !== $mode && ! self::is_valid_display_mode( $mode ) ) {
			$errors['display_name_mode'] = 'Pilih opsi nama publik yang valid.';
		}

		$first = isset( $data['first_name'] ) ? self::normalize_person_name( $data['first_name'] ) : '';
		$last  = isset( $data['last_name'] ) ? self::normalize_person_name( $data['last_name'] ) : '';

		if ( isset( $data['first_name'] ) && self::length( $first ) > self::MAX_PERSON_NAME ) {
			$errors['first_name'] = 'Nama depan maksimal 50 karakter.';
		}

		if ( isset( $data['last_name'] ) && self::length( $last ) > self::MAX_PERSON_NAME ) {
			$errors['last_name'] = 'Nama belakang maksimal 50 karakter.';
		}

		if ( 'first' === $mode && '' === $first ) {
			$errors['first_name'] = 'Masukkan nama depan untuk menggunakannya sebagai nama publik.';
		}

		if ( 'last' === $mode && '' === $last ) {
			$errors['last_name'] = 'Masukkan nama belakang untuk menggunakannya sebagai nama publik.';
		}

		if ( array_key_exists( 'display_name', $data ) && ( '' === $mode || 'custom' === $mode ) ) {
			$display_name = self::normalize_person_name( $data['display_name'] );

			if (
				self::length( $display_name ) < self::MIN_DISPLAY_NAME ||
				self::length( $display_name ) > self::MAX_DISPLAY_NAME
			) {
				$errors['display_name'] = 'Masukkan nama tampilan 2-80 karakter.';
			}
		}

		if ( '' !== $mode && 'custom' !== $mode ) {
			// The username is the final fallback and is always within the limit,
			// so only the composed name fields can overflow.
			$resolved = self::resolve_display_name( $mode, $first, $last, '' );

			if ( self::length( $resolved ) > self::MAX_DISPLAY_NAME ) {
				$errors['display_name_mode'] = 'Nama publik yang dipilih lebih dari 80 karakter. Silakan persingkat nama kamu.';
			}
		}

		if ( array_key_exists( 'description', $data ) ) {
			$description = (string) $data['description'];
			if ( self::length( $description ) > 500 ) {
				$errors['description'] = 'Bio maksimal 500 karakter.';
			}
		}

		if ( array_key_exists( 'user_url', $data ) && '' !== trim( (string) $data['user_url'] ) ) {
			$url    = trim( (string) $data['user_url'] );
			$scheme = parse_url( $url, PHP_URL_SCHEME ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Intentionally WordPress-independent for testability.
			if ( ! filter_var( $url, FILTER_VALIDATE_URL ) || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				$errors['user_url'] = 'Masukkan URL situs web http atau https yang valid.';
			}
		}

		return $errors;
	}

	/**
	 * Mime types accepted for profile photos.
	 *
	 * @return string[]
	 */
	public static function avatar_mimes() {
		return array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
	}

	/**
	 * Validate an avatar attachment.
	 *
	 * Returns an empty string when the attachment is acceptable. SVG is
	 * deliberately excluded: avatar URLs are public and SVG can carry script.
	 *
	 * @param string $mime      Attachment mime type.
	 * @param int    $width     Image width.
	 * @param int    $height    Image height.
	 * @param bool   $permitted Whether the user may use this attachment.
	 * @return string Validation message, empty when valid.
	 */
	public static function validate_avatar( $mime, $width, $height, $permitted ) {
		if ( ! $permitted ) {
			return 'Kamu hanya dapat menggunakan gambar yang kamu unggah sendiri.';
		}

		if ( ! in_array( (string) $mime, self::avatar_mimes(), true ) ) {
			return 'Avatar harus berupa gambar JPEG, PNG, GIF, atau WebP.';
		}

		if (
			(int) $width > 0 &&
			(int) $height > 0 &&
			( (int) $width < self::AVATAR_MIN_EDGE || (int) $height < self::AVATAR_MIN_EDGE )
		) {
			return 'Pilih gambar minimal 100 x 100 piksel.';
		}

		return '';
	}

	/**
	 * Validate tag names submitted by a writer.
	 *
	 * @param mixed $names Tag names.
	 * @return array{names?:string[],error?:string}
	 */
	public static function validate_tag_names( $names ) {
		if ( ! is_array( $names ) ) {
			return array( 'error' => 'Tag harus dikirimkan sebagai daftar.' );
		}

		$clean = array();
		foreach ( $names as $name ) {
			if ( ! is_string( $name ) ) {
				continue;
			}
			$name = trim( strip_tags( $name ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Pure-policy class must not depend on WordPress.
			if ( '' === $name ) {
				continue;
			}
			if ( self::length( $name ) > 50 ) {
				return array( 'error' => 'Setiap tag maksimal 50 karakter.' );
			}
			$key = strtolower( $name );
			if ( ! isset( $clean[ $key ] ) ) {
				$clean[ $key ] = $name;
			}
		}

		if ( count( $clean ) > 10 ) {
			return array( 'error' => 'Gunakan maksimal 10 tag.' );
		}

		return array( 'names' => array_values( $clean ) );
	}

	/**
	 * Generate a URL-safe verification token.
	 *
	 * Uses a cryptographic random source. Base64url encoding is only used to
	 * make the token URL-safe; it is not obfuscation.
	 *
	 * @return string
	 */
	public static function generate_token() {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe encoding of random bytes, not obfuscation.
	}

	/**
	 * Hash a verification token for storage.
	 *
	 * @param string $token Raw token.
	 * @return string
	 */
	public static function hash_token( $token ) {
		return hash( 'sha256', (string) $token );
	}

	/**
	 * Validate the shape of a raw token before querying storage.
	 *
	 * @param mixed $token Token.
	 * @return bool
	 */
	public static function is_token_format_valid( $token ) {
		return is_string( $token ) && 1 === preg_match( '/^[A-Za-z0-9_-]{40,64}$/', $token );
	}

	/**
	 * Whether a stored token has expired.
	 *
	 * @param int $expires Expiry timestamp.
	 * @param int $now     Current timestamp.
	 * @return bool
	 */
	public static function is_token_expired( $expires, $now ) {
		return (int) $expires <= (int) $now;
	}

	/**
	 * Whether a verification resend is allowed by the per-account throttle.
	 *
	 * @param int $created_at Last issue timestamp.
	 * @param int $now        Current timestamp.
	 * @return bool
	 */
	public static function can_resend_verification( $created_at, $now ) {
		return (int) $created_at <= 0 || ( (int) $now - (int) $created_at ) >= self::TOKEN_RESEND_WAIT;
	}

	/**
	 * Whether a post status is allowed for a writer.
	 *
	 * @param mixed $status Post status.
	 * @return bool
	 */
	public static function is_allowed_post_status( $status ) {
		return is_string( $status ) && in_array( $status, self::allowed_post_statuses(), true );
	}
}
