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
	public const STATUS_ACTIVE               = 'active';
	public const STATUS_SUSPENDED            = 'suspended';

	// Email verification codes (OTP).
	public const OTP_TTL          = 600;
	public const OTP_RESEND_WAIT  = 60;
	public const OTP_MAX_ATTEMPTS = 5;
	public const OTP_MAX_ISSUES   = 5;
	public const OTP_WINDOW       = 3600;
	public const OTP_CODE_LENGTH  = 6;
	public const OTP_DAILY_ISSUES = 10;
	public const OTP_MAX_FAILURES = 30;

	public const MIN_PASSWORD = 12;
	public const MAX_PASSWORD = 200;

	public const MIN_DISPLAY_NAME = 2;
	public const MAX_DISPLAY_NAME = 80;
	public const MAX_PERSON_NAME  = 50;
	public const MAX_INSTAGRAM    = 30;
	public const MAX_BIO          = 250;
	public const AVATAR_MIN_EDGE  = 100;

	public const AUTHORS_PER_PAGE     = 100;
	public const AUTHORS_MAX_PER_PAGE = 100;

	public const DAILY_QUOTE_MAX_LENGTH        = 200;
	public const DAILY_QUOTE_MAX_LINES         = 3;
	public const DAILY_QUOTE_TTL               = 86400;
	public const DAILY_QUOTE_DAILY_LIMIT       = 3;
	public const DAILY_QUOTE_PER_PAGE          = 24;
	public const DAILY_QUOTE_MAX_PER_PAGE      = 50;
	public const DAILY_QUOTE_REASON_MAX_LENGTH = 300;

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
	 * Normalize a writer bio: unify line endings, strip blank lines so at most
	 * one consecutive newline remains, and trim outer whitespace.
	 *
	 * @param string $value Raw bio text.
	 * @return string
	 */
	public static function normalize_bio( $value ) {
		$value = str_replace( array( "\r\n", "\r" ), "\n", (string) $value );
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		// Strip whitespace-only lines and collapse consecutive newlines to a single
		// line break so repeated Enter presses cannot stretch profile cards.
		$value = (string) preg_replace( '/[ \t]*\n(?:[ \t]*\n)*/u', "\n", $value );

		return trim( $value );
	}

	/**
	 * Character length of a normalized writer bio.
	 *
	 * @param string $value Raw or normalized bio text.
	 * @return int
	 */
	public static function bio_length( $value ) {
		return self::length( self::normalize_bio( $value ) );
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
			self::STATUS_ACTIVE,
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
			self::STATUS_ACTIVE               => 'Penulis aktif',
			self::STATUS_SUSPENDED            => 'Akun ditangguhkan',
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : 'Unknown';
	}

	/**
	 * Resolve the dashboard role for one account.
	 *
	 * The React dashboard renders one of two mutually exclusive experiences:
	 * writer or staff ("Senator" in user-facing copy). Penalis policy is that an
	 * administrator or editor never writes with the staff account, so the staff
	 * branch is evaluated first — an account that somehow carries both the
	 * reviewer capability and writer access is still treated as staff, never as
	 * a hybrid.
	 *
	 * This is the single source of truth for the `role` field; the client mirrors
	 * it in `src/lib/permissions.ts` for payloads from older deployments, and the
	 * REST routes still enforce every capability on their own.
	 *
	 * @param bool   $is_administrator Whether the account has `manage_options`.
	 * @param bool   $is_reviewer      Whether the account has `edit_others_posts`.
	 * @param string $status           Penalis account status.
	 * @param bool   $can_write        Whether the account may write.
	 * @return string One of administrator, editor, writer, pending, suspended.
	 */
	public static function dashboard_role( $is_administrator, $is_reviewer, $status, $can_write ) {
		if ( $is_reviewer ) {
			return $is_administrator ? 'administrator' : 'editor';
		}

		if ( $can_write ) {
			return 'writer';
		}

		return self::STATUS_SUSPENDED === $status ? 'suspended' : 'pending';
	}

	/**
	 * Whether an account may sign in.
	 *
	 * Pending verification accounts may sign in to see their status. Active
	 * accounts must also have explicit plugin access enabled. Suspended accounts
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
		if ( self::STATUS_PENDING_VERIFICATION === $status ) {
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
	 * Whether an account may reset its own password.
	 *
	 * Pending verification accounts may reset: the reset link proves control of
	 * the email address, and the account still cannot write until email
	 * verification is completed. Suspended accounts may not, so a reset cannot
	 * be used to regain access that an administrator revoked.
	 *
	 * @param string $status         Account status.
	 * @param bool   $access_enabled Explicit access flag.
	 * @return bool
	 */
	public static function can_reset_password( $status, $access_enabled ) {
		return self::can_login( $status, $access_enabled );
	}

	/**
	 * Whether an account may request an email address change.
	 *
	 * `active` accounts need the explicit access flag, and legacy accounts
	 * follow the same flag.
	 *
	 * `pending_verification` is deliberately excluded. That account has not
	 * proven control of the address on file yet, and the status machine has no
	 * state for "address changed while unverified": confirming the new address
	 * would have to advance the status, which an email change must never do.
	 * Those accounts use the verification resend flow instead.
	 *
	 * `suspended` is denied for the same reason password reset is denied: a
	 * credential change must not restore access an administrator revoked.
	 *
	 * @param string $status         Account status.
	 * @param bool   $access_enabled Explicit access flag.
	 * @return bool
	 */
	public static function can_change_email( $status, $access_enabled ) {
		if ( self::STATUS_ACTIVE === $status ) {
			return (bool) $access_enabled;
		}

		if ( '' === $status || null === $status ) {
			return (bool) $access_enabled;
		}

		return false;
	}

	/**
	 * Whether an account may change its login.
	 *
	 * A login change is a credential change, so it shares the email-change
	 * rule: `active` accounts need the explicit access flag, and `suspended`
	 * accounts are denied so a credential change cannot restore revoked access.
	 *
	 * @param string $status         Account status.
	 * @param bool   $access_enabled Explicit access flag.
	 * @return bool
	 */
	public static function can_change_username( $status, $access_enabled ) {
		return self::can_change_email( $status, $access_enabled );
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
				self::STATUS_ACTIVE,
				self::STATUS_SUSPENDED,
			),
			self::STATUS_ACTIVE               => array(
				self::STATUS_SUSPENDED,
			),
			self::STATUS_SUSPENDED            => array(
				self::STATUS_ACTIVE,
			),
		);

		return isset( $allowed[ $from ] ) && in_array( $to, $allowed[ $from ], true );
	}

	/**
	 * Whether a legacy account may be converted into a managed account.
	 *
	 * Legacy accounts are contributor profiles created by an administrator
	 * before this plugin existed. They have no status metadata and no password,
	 * so they cannot sign in at all. Conversion is the only supported way to
	 * bring one into the writer workflow.
	 *
	 * Allowed entry points:
	 *
	 * - `pending_verification`: the account owner must prove control of the
	 *   stored email address before activation.
	 * - `active`: either the owner confirmed the self-service activation code
	 *   and chose a password, or an administrator directly activated the legacy
	 *   account ("Aktifkan langsung").
	 *
	 * @param string $to Target status.
	 * @return bool
	 */
	public static function can_convert_legacy( $to ) {
		return in_array(
			$to,
			array(
				self::STATUS_PENDING_VERIFICATION,
				self::STATUS_ACTIVE,
			),
			true
		);
	}

	/**
	 * Whether a role may be promoted to the writer role.
	 *
	 * `contributor` is included because legacy accounts hold it. Approval must
	 * never silently promote a higher role (editor, author, administrator).
	 *
	 * @param string[] $roles Current roles.
	 * @return bool
	 */
	public static function can_promote_roles( $roles ) {
		$roles = array_values( array_filter( (array) $roles, 'is_string' ) );

		if ( empty( $roles ) ) {
			return true;
		}

		return array( 'subscriber' ) === $roles
			|| array( 'contributor' ) === $roles
			|| array( Penalis_Roles::ROLE ) === $roles;
	}

	/**
	 * Post statuses a writer may set through the editor.
	 *
	 * `pending` is deliberately absent: the dashboard has no submit-for-review
	 * step, so accepting it would let a writer park a post in a state the UI
	 * cannot explain or act on. Legacy pending posts still exist in the
	 * database and remain readable and publishable; they just cannot be
	 * created through this workflow.
	 *
	 * @return string[]
	 */
	public static function allowed_post_statuses() {
		return array( 'draft', 'publish' );
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

		$user_login = isset( $data['user_login'] ) ? (string) $data['user_login'] : '';
		if ( '' !== self::validate_username_change( $user_login ) ) {
			$errors['user_login'] = self::validate_username_change( $user_login );
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

		if ( isset( $data['instagram'] ) && '' !== trim( (string) $data['instagram'] ) ) {
			$ig_error = self::validate_instagram( (string) $data['instagram'] );
			if ( '' !== $ig_error ) {
				$errors['instagram'] = $ig_error;
			}
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
			return 'Password minimal ' . self::MIN_PASSWORD . ' karakter.';
		}

		if ( strlen( $password ) > self::MAX_PASSWORD ) {
			return 'Password maksimal ' . self::MAX_PASSWORD . ' karakter.';
		}

		if ( trim( $password ) === '' ) {
			return 'Pilih password yang lebih kuat.';
		}

		return '';
	}

	/**
	 * Validate a requested new email address.
	 *
	 * Only the shape is checked here; whether the address is already taken is a
	 * database question and belongs to the feature class. The current address is
	 * passed in so "same as today" is rejected before any token is issued.
	 *
	 * @param string $email    Requested address.
	 * @param string $current  Address currently on file.
	 * @return string Error message or empty string.
	 */
	public static function validate_email_change( $email, $current = '' ) {
		$email = trim( (string) $email );

		if ( '' === $email ) {
			return 'Masukkan alamat email baru kamu.';
		}

		if ( self::length( $email ) > 254 ) {
			return 'Alamat email terlalu panjang.';
		}

		if ( false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return 'Masukkan alamat email yang valid.';
		}

		if ( '' !== (string) $current && 0 === strcasecmp( $email, (string) $current ) ) {
			return 'Alamat email tersebut sudah digunakan akun kamu.';
		}

		return '';
	}

	/**
	 * Validate a requested new login.
	 *
	 * The shape is checked here so the feature class can keep its WordPress
	 * calls to the minimum. A login that only becomes valid after
	 * `sanitize_user()` is rejected rather than rewritten: silently changing
	 * what the writer typed would give them a login they cannot reproduce.
	 *
	 * @param string $username Requested login.
	 * @param string $current  Login currently on file.
	 * @return string Error message or empty string.
	 */
	public static function validate_username_change( $username, $current = '' ) {
		$username = (string) $username;

		if ( '' === $username ) {
			return 'Masukkan username baru kamu.';
		}

		if ( self::length( $username ) < 3 || self::length( $username ) > 50 ) {
			return 'Username harus 3-50 karakter.';
		}

		if ( ! preg_match( '/\A[a-z0-9][a-z0-9_-]{1,48}[a-z0-9]\z/', $username ) ) {
			return 'Gunakan huruf kecil, angka, garis bawah, atau tanda hubung; awali dan akhiri dengan huruf atau angka.';
		}

		if ( '' !== (string) $current && 0 === strcasecmp( $username, (string) $current ) ) {
			return 'Username baru sama dengan username kamu saat ini.';
		}

		return '';
	}

	/**
	 * Normalize an optional Instagram username or profile URL into a bare handle.
	 *
	 * @param string $value Raw value.
	 * @return string Normalized handle without leading `@`, or empty string.
	 */
	public static function normalize_instagram( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( '#\A(?:https?://)?(?:www\.)?instagram\.com/([^/?\#]+)#i', $value, $matches ) ) {
			$value = $matches[1];
		}

		$value = ltrim( trim( $value, " \t\n\r\0\x0B/" ), '@' );

		return $value;
	}

	/**
	 * Validate an optional Instagram username.
	 *
	 * @param string $value Raw value.
	 * @return string Error message or empty string.
	 */
	public static function validate_instagram( $value ) {
		$handle = self::normalize_instagram( $value );

		if ( '' === $handle ) {
			return '';
		}

		if (
			self::length( $handle ) > self::MAX_INSTAGRAM ||
			! preg_match( '/\A[A-Za-z0-9._]{1,30}\z/', $handle ) ||
			! preg_match( '/[A-Za-z0-9]/', $handle )
		) {
			return 'Masukkan username Instagram yang valid (huruf, angka, titik, atau garis bawah, maksimal 30 karakter).';
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
			$description = self::normalize_bio( (string) $data['description'] );
			if ( self::length( $description ) > self::MAX_BIO ) {
				$errors['description'] = sprintf( 'Bio maksimal %d karakter.', self::MAX_BIO );
			}
		}

		if ( array_key_exists( 'instagram', $data ) ) {
			$ig_error = self::validate_instagram( (string) $data['instagram'] );
			if ( '' !== $ig_error ) {
				$errors['instagram'] = $ig_error;
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
			return 'Kamu hanya dapat menggunakan gambar yang kamu upload sendiri.';
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
	 * Generate an opaque, URL-safe challenge identifier.
	 *
	 * Uses a cryptographic random source. Base64url encoding is only used to
	 * make the value URL-safe; it is not obfuscation. The identifier is a
	 * locator, not a secret: the six-digit code is the proof.
	 *
	 * @return string
	 */
	public static function generate_token() {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe encoding of random bytes, not obfuscation.
	}

	/**
	 * Validate the shape of a challenge identifier before querying storage.
	 *
	 * @param mixed $token Token.
	 * @return bool
	 */
	public static function is_token_format_valid( $token ) {
		return is_string( $token ) && 1 === preg_match( '/^[A-Za-z0-9_-]{40,64}$/', $token );
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

	/**
	 * Roles eligible for the public authors directory.
	 *
	 * @return string[]
	 */
	public static function public_author_roles() {
		return array( 'penalis_writer', 'contributor' );
	}

	/**
	 * Whether a user is eligible to appear in the public authors directory.
	 *
	 * Strictly includes `penalis_writer` and `contributor` roles with at least
	 * one published post, and excludes suspended accounts.
	 *
	 * @param string[] $roles      User role slugs.
	 * @param string   $status     Account status meta value.
	 * @param int      $post_count Published post count.
	 * @return bool
	 */
	public static function is_public_author_eligible( $roles, $status, $post_count ) {
		if ( ! is_array( $roles ) || (int) $post_count < 1 ) {
			return false;
		}

		if ( self::STATUS_SUSPENDED === (string) $status ) {
			return false;
		}

		return ! empty( array_intersect( $roles, self::public_author_roles() ) );
	}

	/**
	 * Whether an author qualifies for the verified badge.
	 *
	 * An author is verified when they are an active writer (`penalis_writer`
	 * role, `active` account status, and access enabled).
	 *
	 * @param string[] $roles          User role slugs.
	 * @param string   $status         Account status meta value.
	 * @param bool     $access_enabled Whether plugin access is enabled.
	 * @return bool
	 */
	public static function is_verified_author( $roles, $status, $access_enabled ) {
		if ( ! is_array( $roles ) || ! $access_enabled ) {
			return false;
		}

		return self::STATUS_ACTIVE === (string) $status && in_array( 'penalis_writer', $roles, true );
	}

	/**
	 * Whether an author qualifies for the public Senator badge.
	 *
	 * Applies to writer/contributor accounts explicitly marked as Senator by an
	 * administrator (including legacy contributors), excluding suspended accounts.
	 *
	 * @param string[] $roles      User role slugs.
	 * @param string   $status     Account status meta value.
	 * @param bool     $is_senator Whether the Senator flag is set.
	 * @return bool
	 */
	public static function is_senator_author( $roles, $status, $is_senator ) {
		if ( ! is_array( $roles ) || ! $is_senator ) {
			return false;
		}

		if ( self::STATUS_SUSPENDED === (string) $status ) {
			return false;
		}

		return ! empty( array_intersect( $roles, self::public_author_roles() ) );
	}

	/**
	 * Whether an account may be marked as Senator from wp-admin.
	 *
	 * Allows active writers (`penalis_writer`) and legacy contributors without
	 * Penalis status metadata, while denying suspended, pending-verification,
	 * and staff-role accounts.
	 *
	 * @param string[] $roles  User role slugs.
	 * @param string   $status Account status meta value.
	 * @return bool
	 */
	public static function can_mark_senator( $roles, $status ) {
		if ( ! is_array( $roles ) ) {
			return false;
		}

		if ( self::STATUS_ACTIVE !== (string) $status && '' !== (string) $status ) {
			return false;
		}

		return ! empty( array_intersect( $roles, self::public_author_roles() ) );
	}

	/**
	 * Normalize the sort key for the public authors directory.
	 *
	 * @param mixed $sort Requested sort mode.
	 * @return string
	 */
	public static function normalize_authors_sort( $sort ) {
		$allowed = array(
			'most_posts',
			'least_posts',
			'most_views',
			'least_views',
			'name_asc',
			'name_desc',
			'random',
		);
		if ( is_string( $sort ) && in_array( $sort, $allowed, true ) ) {
			return $sort;
		}

		return 'most_posts';
	}

	/**
	 * Sort normalized public author records.
	 *
	 * @param array<int,array<string,mixed>> $authors Author records.
	 * @param string                         $sort    Normalized sort mode.
	 * @return array<int,array<string,mixed>>
	 */
	public static function sort_public_authors( $authors, $sort ) {
		$mode    = self::normalize_authors_sort( $sort );
		$authors = array_values( $authors );

		if ( 'random' === $mode ) {
			if ( count( $authors ) > 1 ) {
				shuffle( $authors );
			}
			return $authors;
		}

		usort(
			$authors,
			static function ( $a, $b ) use ( $mode ) {
				$name_a  = isset( $a['displayName'] ) ? (string) $a['displayName'] : '';
				$name_b  = isset( $b['displayName'] ) ? (string) $b['displayName'] : '';
				$count_a = isset( $a['postCount'] ) ? (int) $a['postCount'] : 0;
				$count_b = isset( $b['postCount'] ) ? (int) $b['postCount'] : 0;
				$views_a = isset( $a['views'] ) ? (int) $a['views'] : 0;
				$views_b = isset( $b['views'] ) ? (int) $b['views'] : 0;
				$id_a    = isset( $a['id'] ) ? (int) $a['id'] : 0;
				$id_b    = isset( $b['id'] ) ? (int) $b['id'] : 0;

				$name_cmp = strnatcasecmp( $name_a, $name_b );

				if ( 'name_asc' === $mode ) {
					if ( 0 !== $name_cmp ) {
						return $name_cmp;
					}
					return $id_a <=> $id_b;
				}

				if ( 'name_desc' === $mode ) {
					if ( 0 !== $name_cmp ) {
						return -$name_cmp;
					}
					return $id_a <=> $id_b;
				}

				if ( 'least_posts' === $mode ) {
					if ( $count_a !== $count_b ) {
						return $count_a <=> $count_b;
					}
					if ( 0 !== $name_cmp ) {
						return $name_cmp;
					}
					return $id_a <=> $id_b;
				}

				if ( 'most_views' === $mode ) {
					if ( $views_a !== $views_b ) {
						return $views_b <=> $views_a;
					}
					if ( 0 !== $name_cmp ) {
						return $name_cmp;
					}
					return $id_a <=> $id_b;
				}

				if ( 'least_views' === $mode ) {
					if ( $views_a !== $views_b ) {
						return $views_a <=> $views_b;
					}
					if ( 0 !== $name_cmp ) {
						return $name_cmp;
					}
					return $id_a <=> $id_b;
				}

				// Default: most_posts.
				if ( $count_a !== $count_b ) {
					return $count_b <=> $count_a;
				}
				if ( 0 !== $name_cmp ) {
					return $name_cmp;
				}
				return $id_a <=> $id_b;
			}
		);

		return $authors;
	}

	/**
	 * Normalize a #KalimatHariIni text: strip HTML tags, unify line endings,
	 * collapse horizontal whitespace per line, drop blank lines, and trim.
	 *
	 * @param string $value Raw quote text.
	 * @return string
	 */
	public static function normalize_daily_quote( $value ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Pure-policy class must not depend on WordPress.
		$value = strip_tags( (string) $value );
		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
		$lines = explode( "\n", $value );
		$clean = array();

		foreach ( $lines as $line ) {
			$line = trim( (string) preg_replace( '/[ \t]+/u', ' ', $line ) );
			if ( '' !== $line ) {
				$clean[] = $line;
			}
		}

		return implode( "\n", $clean );
	}

	/**
	 * Character length of a normalized #KalimatHariIni text.
	 *
	 * @param string $value Raw or normalized quote text.
	 * @return int
	 */
	public static function daily_quote_length( $value ) {
		return self::length( self::normalize_daily_quote( $value ) );
	}

	/**
	 * Validate a #KalimatHariIni submission.
	 *
	 * @param string $value Raw quote text.
	 * @return string Error message, or empty string when valid.
	 */
	public static function validate_daily_quote( $value ) {
		$normalized = self::normalize_daily_quote( $value );

		if ( '' === $normalized ) {
			return 'Tulis kalimat terlebih dahulu sebelum menerbitkan #KalimatHariIni.';
		}

		$line_count = substr_count( $normalized, "\n" ) + 1;
		if ( $line_count > self::DAILY_QUOTE_MAX_LINES ) {
			return sprintf( '#KalimatHariIni maksimal %d baris agar tetap ringkas dibaca.', self::DAILY_QUOTE_MAX_LINES );
		}

		if ( self::length( $normalized ) > self::DAILY_QUOTE_MAX_LENGTH ) {
			return sprintf( '#KalimatHariIni maksimal %d karakter.', self::DAILY_QUOTE_MAX_LENGTH );
		}

		return '';
	}

	/**
	 * Quick-reason presets offered to Senators when taking down a #KalimatHariIni.
	 *
	 * @return string[]
	 */
	public static function daily_quote_removal_presets() {
		return array(
			'Spam atau promosi tidak relevan',
			'Mengandung SARA atau ujaran kebencian',
			'Mengandung kata kasar atau tidak pantas',
			'Tidak sesuai norma komunitas Penalis',
		);
	}

	/**
	 * Normalize a Senator's takedown reason for #KalimatHariIni.
	 *
	 * Strips markup and collapses whitespace into single spaces so the reason
	 * renders cleanly inside the dashboard notification banner.
	 *
	 * @param string $value Raw reason text.
	 * @return string
	 */
	public static function normalize_daily_quote_removal_reason( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$plain = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $value ) : strip_tags( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Fallback for pure policy unit tests without WordPress.
		$clean = preg_replace( '/\s+/u', ' ', $plain );

		return trim( is_string( $clean ) ? $clean : $plain );
	}

	/**
	 * Validate a Senator's takedown reason for #KalimatHariIni.
	 *
	 * @param string $value Raw reason text.
	 * @return string Error message, or empty string when valid.
	 */
	public static function validate_daily_quote_removal_reason( $value ) {
		$normalized = self::normalize_daily_quote_removal_reason( $value );

		if ( '' === $normalized ) {
			return 'Alasan penghapusan wajib diisi agar penulis mengetahui penyebabnya.';
		}

		if ( self::length( $normalized ) > self::DAILY_QUOTE_REASON_MAX_LENGTH ) {
			return sprintf( 'Alasan penghapusan maksimal %d karakter.', self::DAILY_QUOTE_REASON_MAX_LENGTH );
		}

		return '';
	}

	/**
	 * Whether a stored #KalimatHariIni record is currently active.
	 *
	 * @param mixed    $record Stored meta value.
	 * @param int|null $now    Current Unix timestamp.
	 * @return bool
	 */
	public static function is_daily_quote_active( $record, $now = null ) {
		if ( ! is_array( $record ) ) {
			return false;
		}

		$now        = null === $now ? time() : (int) $now;
		$text       = isset( $record['text'] ) ? self::normalize_daily_quote( (string) $record['text'] ) : '';
		$created_at = isset( $record['created_at'] ) ? (int) $record['created_at'] : 0;
		$expires_at = isset( $record['expires_at'] ) ? (int) $record['expires_at'] : 0;

		return '' !== $text && $created_at > 0 && $expires_at > $now;
	}

	/**
	 * Compute the rolling 24-hour publish quota for #KalimatHariIni.
	 *
	 * Deleting an active quote does not restore this budget: a writer may
	 * publish at most DAILY_QUOTE_DAILY_LIMIT times within any rolling 24-hour
	 * window.
	 *
	 * @param mixed    $timestamps Raw stored publish timestamps.
	 * @param int|null $now        Current Unix timestamp.
	 * @return array{timestamps:int[],used:int,limit:int,remaining:int,reset_at:int}
	 */
	public static function daily_quote_rate_state( $timestamps, $now = null ) {
		$now    = null === $now ? time() : (int) $now;
		$cutoff = $now - self::DAILY_QUOTE_TTL;
		$pruned = array();

		if ( is_array( $timestamps ) ) {
			foreach ( $timestamps as $ts ) {
				$ts = (int) $ts;
				if ( $ts > $cutoff && $ts <= $now ) {
					$pruned[] = $ts;
				}
			}
		}

		sort( $pruned, SORT_NUMERIC );

		$used      = count( $pruned );
		$limit     = self::DAILY_QUOTE_DAILY_LIMIT;
		$remaining = max( 0, $limit - $used );
		$reset_at  = ( $used >= $limit && isset( $pruned[0] ) ) ? ( $pruned[0] + self::DAILY_QUOTE_TTL ) : 0;

		return array(
			'timestamps' => $pruned,
			'used'       => $used,
			'limit'      => $limit,
			'remaining'  => $remaining,
			'reset_at'   => $reset_at,
		);
	}

	/**
	 * Normalize the sort key for the public #KalimatHariIni directory.
	 *
	 * @param mixed $sort Requested sort mode.
	 * @return string
	 */
	public static function normalize_daily_quotes_sort( $sort ) {
		$allowed = array( 'newest', 'oldest', 'random' );

		if ( is_string( $sort ) && in_array( $sort, $allowed, true ) ) {
			return $sort;
		}

		return 'newest';
	}

	/**
	 * Sort normalized public #KalimatHariIni records.
	 *
	 * @param array<int,array<string,mixed>> $quotes Quote records.
	 * @param string                         $sort   Normalized sort mode.
	 * @return array<int,array<string,mixed>>
	 */
	public static function sort_daily_quotes( $quotes, $sort ) {
		$mode   = self::normalize_daily_quotes_sort( $sort );
		$quotes = array_values( $quotes );

		if ( 'random' === $mode ) {
			if ( count( $quotes ) > 1 ) {
				shuffle( $quotes );
			}
			return $quotes;
		}

		usort(
			$quotes,
			static function ( $a, $b ) use ( $mode ) {
				$created_a = isset( $a['createdAt'] ) ? (int) $a['createdAt'] : 0;
				$created_b = isset( $b['createdAt'] ) ? (int) $b['createdAt'] : 0;
				$id_a      = isset( $a['authorId'] ) ? (int) $a['authorId'] : 0;
				$id_b      = isset( $b['authorId'] ) ? (int) $b['authorId'] : 0;

				if ( 'oldest' === $mode ) {
					if ( $created_a !== $created_b ) {
						return $created_a <=> $created_b;
					}
					return $id_a <=> $id_b;
				}

				// Default: newest.
				if ( $created_a !== $created_b ) {
					return $created_b <=> $created_a;
				}
				return $id_a <=> $id_b;
			}
		);

		return $quotes;
	}
}
