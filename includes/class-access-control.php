<?php
/**
 * Authentication, capability, and REST hardening.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server-side access control.
 *
 * Enforcement is layered:
 * 1. Login gate for plugin-managed accounts and legacy contributors.
 * 2. Capability removal for non-active writers.
 * 3. REST route guards and post insert/query filtering.
 * 4. Upload restrictions.
 */
final class Penalis_Access_Control {

	/**
	 * Re-entrancy guard for the profile email restoration.
	 *
	 * @var bool
	 */
	private static $restoring_email = false;

	/**
	 * Whether a verified email change is being applied.
	 *
	 * The profile-update guard below restores the previous address for
	 * plugin-managed accounts. A verified change has already proven control of
	 * the new address, so it must be allowed through that guard.
	 *
	 * @var bool
	 */
	private static $applying_verified_email_change = false;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'authenticate', array( __CLASS__, 'block_login' ), 100, 3 );
		add_filter( 'allow_password_reset', array( __CLASS__, 'block_password_reset' ), 10, 2 );
		add_filter( 'login_errors', array( __CLASS__, 'generic_login_error' ) );
		add_filter( 'user_has_cap', array( __CLASS__, 'filter_capabilities' ), 10, 4 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'guard_rest_request' ), 10, 3 );
		add_filter( 'rest_pre_insert_post', array( __CLASS__, 'enforce_post_insert' ), 10, 2 );
		add_filter( 'rest_pre_insert_attachment', array( __CLASS__, 'enforce_attachment_insert' ), 10, 2 );
		add_filter( 'rest_post_query', array( __CLASS__, 'force_own_post_query' ), 10, 2 );
		add_filter( 'rest_media_query', array( __CLASS__, 'force_own_media_query' ), 10, 2 );
		add_filter( 'upload_mimes', array( __CLASS__, 'restrict_upload_mimes' ) );
		add_filter( 'wp_handle_upload_prefilter', array( __CLASS__, 'validate_upload' ) );
		add_action( 'set_user_role', array( __CLASS__, 'prevent_role_escalation' ), 10, 3 );
		add_action( 'profile_update', array( __CLASS__, 'prevent_profile_escalation' ), 10, 2 );
		add_filter( 'send_email_change_email', array( __CLASS__, 'suppress_core_email_change_notice' ), 10, 1 );
		add_filter( 'map_meta_cap', array( __CLASS__, 'map_meta_cap' ), 10, 4 );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'guard_writer_publish_status' ), 10, 2 );
	}

	/**
	 * Apply an email address that was already verified by its owner.
	 *
	 * This is the only sanctioned way for a plugin-managed account to change
	 * its address. It bypasses the profile-update restoration guard for the
	 * duration of the update and suppresses WordPress core's own change notice,
	 * because the plugin sends a notice with its own copy.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $new_email Verified address.
	 * @return true|WP_Error
	 */
	public static function apply_verified_email_change( $user_id, $new_email ) {
		$user_id = (int) $user_id;
		$email   = sanitize_email( (string) $new_email );

		if ( $user_id <= 0 || ! is_email( $email ) ) {
			return new WP_Error( 'penalis_email_invalid', 'Alamat email tidak valid.', array( 'status' => 400 ) );
		}

		self::$applying_verified_email_change = true;

		$result = wp_update_user(
			array(
				'ID'         => $user_id,
				'user_email' => $email,
			)
		);

		self::$applying_verified_email_change = false;

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Suppress core's email change notice during a verified change.
	 *
	 * Core would otherwise send an English notice to the old address in
	 * addition to the plugin's own Indonesian notice.
	 *
	 * @param bool $send Whether core should send the notice.
	 * @return bool
	 */
	public static function suppress_core_email_change_notice( $send ) {
		return self::$applying_verified_email_change ? false : $send;
	}

	/**
	 * Whether a user is an administrator, checked without capability filters.
	 *
	 * Calling user_can() inside user_has_cap would recurse, so roles are
	 * inspected directly here.
	 *
	 * @param WP_User|null $user User.
	 * @return bool
	 */
	public static function is_administrator( $user ) {
		return $user instanceof WP_User && in_array( 'administrator', (array) $user->roles, true );
	}

	/**
	 * Whether the user is subject to Penalis writer restrictions.
	 *
	 * Administrators are never restricted. Editors and authors are unaffected.
	 *
	 * Legacy accounts (no Penalis status) are restricted while access is
	 * disabled, so a pre-existing browser session cannot write. If an
	 * administrator explicitly enables access for a legacy account, that
	 * account returns to native WordPress behavior instead of the dashboard
	 * workflow.
	 *
	 * @param WP_User|null $user User object.
	 * @return bool
	 */
	public static function is_restricted_user( $user ) {
		if ( ! $user instanceof WP_User ) {
			return false;
		}

		if ( self::is_administrator( $user ) ) {
			return false;
		}

		$status = Penalis_Account_Status::get_status( $user->ID );

		if ( '' === $status ) {
			if ( Penalis_Account_Status::is_access_enabled( $user->ID ) ) {
				// Explicitly enabled legacy account: native WordPress rules.
				return false;
			}

			$roles = (array) $user->roles;

			return in_array( Penalis_Roles::ROLE, $roles, true )
				|| in_array( 'contributor', $roles, true );
		}

		return true;
	}

	/**
	 * Block login for restricted accounts.
	 *
	 * @param WP_User|WP_Error|null $user     User or error.
	 * @param string                $username Username.
	 * @param string                $password Password.
	 * @return WP_User|WP_Error|null
	 */
	public static function block_login( $user, $username, $password ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
		if ( ! $user instanceof WP_User ) {
			return $user;
		}

		if ( self::is_administrator( $user ) ) {
			return $user;
		}

		$status = Penalis_Account_Status::get_status( $user->ID );

		if ( '' !== $status ) {
			if ( ! Penalis_Policy::can_login( $status, Penalis_Account_Status::is_access_enabled( $user->ID ) ) ) {
				return new WP_Error(
					'penalis_login_blocked',
					'Akun ini tidak dapat masuk. Silakan hubungi admin Penalis.'
				);
			}

			return $user;
		}

		// Legacy accounts: contributor profiles were never intended for login.
		// An administrator may explicitly opt an individual account in by
		// setting the access flag, but the plugin never does so automatically.
		if ( in_array( 'contributor', (array) $user->roles, true ) ) {
			if ( Penalis_Account_Status::is_access_enabled( $user->ID ) ) {
				return $user;
			}

			return new WP_Error(
				'penalis_login_blocked',
				'Akun ini tidak dapat masuk. Silakan hubungi admin Penalis.'
			);
		}

		// A writer role without plugin metadata is not a valid writer account.
		if ( in_array( Penalis_Roles::ROLE, (array) $user->roles, true ) ) {
			return new WP_Error(
				'penalis_login_blocked',
				'Akun ini tidak dapat masuk. Silakan hubungi admin Penalis.'
			);
		}

		return $user;
	}

	/**
	 * Prevent password reset for accounts that cannot sign in.
	 *
	 * @param bool $allow   Whether reset is allowed.
	 * @param int  $user_id User ID.
	 * @return bool
	 */
	public static function block_password_reset( $allow, $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return $allow;
		}

		if ( self::is_administrator( $user ) ) {
			return $allow;
		}

		$status = Penalis_Account_Status::get_status( $user->ID );

		if ( '' !== $status ) {
			return Penalis_Policy::can_login( $status, Penalis_Account_Status::is_access_enabled( $user->ID ) );
		}

		if ( in_array( 'contributor', (array) $user->roles, true ) ) {
			return Penalis_Account_Status::is_access_enabled( $user->ID );
		}

		return $allow;
	}

	/**
	 * Replace login errors with a generic message to limit enumeration.
	 *
	 * @param string $message Error HTML.
	 * @return string
	 */
	public static function generic_login_error( $message ) {
		if ( '' === trim( (string) $message ) ) {
			return $message;
		}

		// Preserve operational errors that do not reveal account existence.
		if ( false !== stripos( (string) $message, 'cookie' ) ) {
			return $message;
		}

		return 'Nama pengguna atau kata sandi salah.';
	}

	/**
	 * Remove write capabilities from non-active writer accounts.
	 *
	 * This is defense in depth: even if a native endpoint or admin screen is
	 * reached, the capability check fails.
	 *
	 * @param array<string,bool> $allcaps All capabilities.
	 * @param string[]           $caps    Required caps.
	 * @param int                $user_id User ID.
	 * @param array              $args    Arguments.
	 * @return array<string,bool>
	 */
	public static function filter_capabilities( $allcaps, $caps, $user_id, $args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
		$user = get_userdata( (int) $user_id );
		if ( ! $user || self::is_administrator( $user ) ) {
			return $allcaps;
		}

		$status = Penalis_Account_Status::get_status( $user_id );

		if ( '' === $status ) {
			// Explicitly enabled legacy accounts keep native WordPress
			// capabilities. Dormant contributor profiles do not.
			if ( Penalis_Account_Status::is_access_enabled( $user_id ) ) {
				return $allcaps;
			}

			$roles = (array) $user->roles;
			if ( ! in_array( Penalis_Roles::ROLE, $roles, true ) && ! in_array( 'contributor', $roles, true ) ) {
				return $allcaps;
			}
		}

		if ( Penalis_Account_Status::can_write( $user_id ) ) {
			return $allcaps;
		}

		$deny = array(
			'edit_posts',
			'edit_published_posts',
			'delete_posts',
			'delete_published_posts',
			'publish_posts',
			'upload_files',
			'edit_others_posts',
			'delete_others_posts',
			'edit_private_posts',
			'delete_private_posts',
			'read_private_posts',
		);

		foreach ( $deny as $cap ) {
			$allcaps[ $cap ] = false;
		}

		return $allcaps;
	}

	/**
	 * Guard sensitive native REST routes for restricted users.
	 *
	 * @param mixed           $result  Response or null.
	 * @param WP_REST_Server  $server  Server.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function guard_rest_request( $result, $server, $request ) {
		if ( null !== $result || ! is_user_logged_in() ) {
			return $result;
		}

		$user = wp_get_current_user();
		if ( ! self::is_restricted_user( $user ) ) {
			return $result;
		}

		$route  = (string) $request->get_route();
		$method = strtoupper( (string) $request->get_method() );

		// Read-only self-service endpoints needed by the block editor and
		// profile screens. These expose only the current user's own data or
		// public block metadata.
		if ( 'GET' === $method ) {
			if ( 0 === strpos( $route, '/wp/v2/users/me' ) ) {
				return $result;
			}

			if ( 0 === strpos( $route, '/wp/v2/block-types' ) ) {
				return $result;
			}
		}

		// Blocked route prefixes for writers. These are administrative surfaces
		// that writers must never use, including via direct API calls.
		$blocked_prefixes = array(
			'/wp/v2/users',
			'/wp/v2/settings',
			'/wp/v2/plugins',
			'/wp/v2/themes',
			'/wp/v2/templates',
			'/wp/v2/template-parts',
			'/wp/v2/global-styles',
			'/wp/v2/navigation',
			'/wp/v2/menus',
			'/wp/v2/menu-items',
			'/wp/v2/menu-locations',
			'/wp/v2/widgets',
			'/wp/v2/sidebars',
			'/wp/v2/block-types',
			'/wp/v2/pattern-directory',
			'/wp/v2/font-families',
			'/wp/v2/font-faces',
		);

		foreach ( $blocked_prefixes as $prefix ) {
			if ( 0 === strpos( $route, $prefix ) ) {
				// Allow read-only access to taxonomies and block types is not
				// needed for the dashboard; deny all methods for clarity.
				return new WP_Error(
					'penalis_rest_forbidden',
					'Kamu tidak diizinkan mengakses endpoint ini.',
					array( 'status' => rest_authorization_required_code() )
				);
			}
		}

		// Writers may read comments but must not create or moderate them
		// through the REST API.
		if ( 0 === strpos( $route, '/wp/v2/comments' ) && 'GET' !== $method ) {
			return new WP_Error(
				'penalis_rest_forbidden',
				'Kamu tidak diizinkan mengakses endpoint ini.',
				array( 'status' => rest_authorization_required_code() )
			);
		}

		// Pages, media beyond own attachments, and other post types are not
		// part of the writing workflow.
		if ( preg_match( '#^/wp/v2/pages#', $route ) ) {
			return new WP_Error(
				'penalis_rest_forbidden',
				'Kamu tidak diizinkan mengakses endpoint ini.',
				array( 'status' => rest_authorization_required_code() )
			);
		}

		// Single posts and attachments are fetched by ID, which bypasses the
		// list query filters below. Refuse cross-writer reads explicitly.
		if ( 'GET' === $method ) {
			$read_guard = self::guard_single_item_read( $route, $user );
			if ( is_wp_error( $read_guard ) ) {
				return $read_guard;
			}
		}

		// Any write operation requires an active writer account, except the
		// account and verification routes that pending users may legitimately
		// use.
		if ( in_array( $method, array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
			$always_allowed_prefixes = array(
				'/penalis/v1/verification/',
				'/penalis/v1/password/',
				'/penalis/v1/account/password',
				'/penalis/v1/account/profile',
				// Pending accounts may maintain their own credentials. The
				// handlers still enforce the status rule through
				// Penalis_Policy::can_change_email() and can_change_username().
				'/penalis/v1/account/email',
				'/penalis/v1/account/username',
				'/penalis/v1/notifications/',
			);

			$is_always_allowed = false;
			foreach ( $always_allowed_prefixes as $allowed_route ) {
				if ( 0 === strpos( $route, $allowed_route ) ) {
					$is_always_allowed = true;
					break;
				}
			}

			if ( ! $is_always_allowed && ! Penalis_Account_Status::can_write( $user->ID ) ) {
				return new WP_Error(
					'penalis_account_inactive',
					'Akun kamu saat ini tidak diizinkan membuat atau mengubah konten.',
					array( 'status' => 403 )
				);
			}

			// Writers may only write through a narrow allowlist of routes. The
			// legacy publish endpoints are deliberately excluded: publication is
			// an administrator-only workflow action now, while submission and
			// resubmission for editorial review remain open to the owner.
			$allowed_write_prefixes = array(
				'/wp/v2/posts',
				'/wp/v2/media',
				'/penalis/v1/account/',
				'/penalis/v1/verification/',
				'/penalis/v1/password/',
				'/penalis/v1/notifications/',
				'/penalis/v1/tags',
			);

			$allowed = false;
			foreach ( $allowed_write_prefixes as $prefix ) {
				if ( 0 === strpos( $route, $prefix ) ) {
					$allowed = true;
					break;
				}
			}

			if ( ! $allowed && 'POST' === $method && 1 === preg_match( '#^/penalis/v1/articles/\d+/(submit|resubmit)$#', $route ) ) {
				$allowed = true;
			}

			if ( ! $allowed ) {
				return new WP_Error(
					'penalis_rest_forbidden',
					'Kamu tidak diizinkan mengakses endpoint ini.',
					array( 'status' => rest_authorization_required_code() )
				);
			}

			// Media writes are validated before the upload is handled.
			//
			// `WP_REST_Attachments_Controller::insert_attachment()` casts the
			// prepared attachment to an array without checking for WP_Error, so
			// a refusal returned from `rest_pre_insert_attachment` on create is
			// swallowed *after* the file has already been written to disk. The
			// dispatch guard refuses first, and the filter below still covers
			// the update path, where core does return the error.
			if ( 0 === strpos( $route, '/wp/v2/media' ) ) {
				$media_guard = self::guard_media_params( $request, $user );
				if ( is_wp_error( $media_guard ) ) {
					return $media_guard;
				}
			}
		}

		return $result;
	}

	/**
	 * Validate media parameters before any file is written.
	 *
	 * Mirrors the insert filter so a writer cannot assign media to another
	 * account, publish an attachment post, or attach media to an article they
	 * do not own — and cannot do any of it by having core swallow the error.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param WP_User         $user    Current restricted user.
	 * @return WP_Error|null
	 */
	private static function guard_media_params( $request, $user ) {
		$author = Penalis_Policy::resolve_author( $request->get_param( 'author' ), (int) $user->ID );

		if ( isset( $author['error'] ) ) {
			return new WP_Error( 'penalis_invalid_author', $author['error'], array( 'status' => 403 ) );
		}

		$requested_status = $request->get_param( 'status' );
		if ( null !== $requested_status && '' !== (string) $requested_status && 'inherit' !== (string) $requested_status ) {
			return new WP_Error(
				'penalis_invalid_status',
				'Status media tersebut tidak diizinkan.',
				array( 'status' => 403 )
			);
		}

		$parent_id = (int) $request->get_param( 'post' );
		if ( $parent_id > 0 ) {
			$parent = get_post( $parent_id );

			if ( ! $parent || 'post' !== $parent->post_type || (int) $parent->post_author !== (int) $user->ID ) {
				return new WP_Error(
					'penalis_invalid_media_parent',
					'Kamu hanya dapat melampirkan media ke karya tulismu sendiri.',
					array( 'status' => 403 )
				);
			}
		}

		return null;
	}

	/**
	 * Refuse cross-writer reads of single posts and attachments.
	 *
	 * The list endpoints are narrowed by the query filters below, but reading
	 * an item by ID bypasses them. Core capability mapping does reject most of
	 * these requests, and this explicit guard keeps the rule in one place: a
	 * writer may read their own items plus already published articles by other
	 * writers, and nothing else.
	 *
	 * A missing item is not answered here so core can return its own 404.
	 *
	 * @param string  $route Request route.
	 * @param WP_User $user  Current user.
	 * @return WP_Error|null
	 */
	private static function guard_single_item_read( $route, $user ) {
		if ( ! preg_match( '#^/wp/v2/(posts|media)/(\d+)(?:/|$)#', $route, $matches ) ) {
			return null;
		}

		$post = get_post( (int) $matches[2] );

		if ( ! $post ) {
			return null;
		}

		if ( (int) $post->post_author === (int) $user->ID ) {
			return null;
		}

		// Published articles are public; drafts, pending submissions and other
		// writers' media are not.
		if ( 'posts' === $matches[1] && 'publish' === $post->post_status ) {
			return null;
		}

		return new WP_Error(
			'penalis_rest_forbidden',
			'Kamu tidak diizinkan mengakses konten ini.',
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Enforce ownership, author, and status rules on post inserts.
	 *
	 * Runs for create and update through the native posts endpoint.
	 *
	 * @param stdClass        $prepared_post Prepared post object.
	 * @param WP_REST_Request $request       Request.
	 * @return stdClass|WP_Error
	 */
	public static function enforce_post_insert( $prepared_post, $request ) {
		if ( ! is_user_logged_in() ) {
			return $prepared_post;
		}

		$user = wp_get_current_user();
		if ( ! self::is_restricted_user( $user ) ) {
			return $prepared_post;
		}

		if ( ! Penalis_Account_Status::can_write( $user->ID ) ) {
			return new WP_Error(
				'penalis_account_inactive',
				'Akun kamu saat ini tidak diizinkan membuat atau mengubah karya tulis.',
				array( 'status' => 403 )
			);
		}

		$post_id = isset( $prepared_post->ID ) ? (int) $prepared_post->ID : 0;

		if ( $post_id > 0 ) {
			$existing = get_post( $post_id );

			if ( ! $existing || 'post' !== $existing->post_type ) {
				return new WP_Error(
					'penalis_invalid_post',
					'Hanya karya tulis yang dapat diubah melalui alur kerja ini.',
					array( 'status' => 403 )
				);
			}

			if ( (int) $existing->post_author !== (int) $user->ID ) {
				return new WP_Error(
					'penalis_not_owner',
					'Kamu tidak dapat mengubah karya penulis lain.',
					array( 'status' => 403 )
				);
			}

			if ( ! Penalis_Editorial_Workflow::can_writer_edit( $existing, $user->ID ) ) {
				return new WP_Error(
					'penalis_editorial_locked',
					'Karya tulis ini sedang ditinjau atau sudah terbit.',
					array( 'status' => 403 )
				);
			}
		}

		$requested_author = $request->get_param( 'author' );
		$author           = Penalis_Policy::resolve_author( $requested_author, $user->ID );

		if ( isset( $author['error'] ) ) {
			return new WP_Error( 'penalis_invalid_author', $author['error'], array( 'status' => 403 ) );
		}

		$prepared_post->post_author = (int) $author['author'];

		$requested_status = $request->get_param( 'status' );
		if ( null !== $requested_status && '' !== $requested_status ) {
			// `publish` is reserved for the administrator approval service. A
			// writer may save only a draft, including through direct REST calls.
			if ( 'publish' === $requested_status ) {
				return new WP_Error(
					'penalis_publish_forbidden',
					'Karya tulis harus disetujui admin sebelum diterbitkan.',
					array( 'status' => 403 )
				);
			}

			if ( ! Penalis_Policy::is_allowed_post_status( $requested_status ) ) {
				return new WP_Error(
					'penalis_invalid_status',
					'Status karya tulis tersebut tidak diizinkan.',
					array( 'status' => 403 )
				);
			}
		}

		// Prevent writers from making posts sticky. A false/absent value is
		// allowed so editor clients that always send the field still work.
		if ( $request->get_param( 'sticky' ) ) {
			return new WP_Error(
				'penalis_invalid_field',
				'Kolom tersebut tidak dapat diubah melalui alur kerja ini.',
				array( 'status' => 403 )
			);
		}

		// Block fields that could expose content or bypass review. Empty
		// values are allowed because editor clients may send them by default.
		$password = $request->get_param( 'password' );
		if ( null !== $password && '' !== (string) $password ) {
			return new WP_Error(
				'penalis_invalid_field',
				'Kolom tersebut tidak dapat diubah melalui alur kerja ini.',
				array( 'status' => 403 )
			);
		}

		$meta = $request->get_param( 'meta' );
		if ( ! empty( $meta ) ) {
			return new WP_Error(
				'penalis_invalid_field',
				'Kolom tersebut tidak dapat diubah melalui alur kerja ini.',
				array( 'status' => 403 )
			);
		}

		$template = $request->get_param( 'template' );
		if ( null !== $template && '' !== (string) $template ) {
			return new WP_Error(
				'penalis_invalid_field',
				'Kolom tersebut tidak dapat diubah melalui alur kerja ini.',
				array( 'status' => 403 )
			);
		}

		// Featured images must belong to the writer.
		$featured_media = (int) $request->get_param( 'featured_media' );
		if ( $featured_media > 0 ) {
			$attachment = get_post( $featured_media );
			if ( ! $attachment || 'attachment' !== $attachment->post_type || (int) $attachment->post_author !== (int) $user->ID ) {
				return new WP_Error(
					'penalis_invalid_media',
					'Kamu hanya dapat menggunakan media milik sendiri sebagai gambar unggulan.',
					array( 'status' => 403 )
				);
			}
		}

		return $prepared_post;
	}

	/**
	 * Enforce ownership rules on attachment inserts and updates.
	 *
	 * Core checks `edit_post` on the parent when an upload is created, but an
	 * update can move an existing attachment to another post without that
	 * check. Writers may only touch their own media and may only attach it to
	 * their own articles, so the rule is enforced here for both paths.
	 *
	 * @param stdClass        $prepared_attachment Prepared attachment object.
	 * @param WP_REST_Request $request             Request.
	 * @return stdClass|WP_Error
	 */
	public static function enforce_attachment_insert( $prepared_attachment, $request ) {
		if ( ! is_user_logged_in() ) {
			return $prepared_attachment;
		}

		$user = wp_get_current_user();
		if ( ! self::is_restricted_user( $user ) ) {
			return $prepared_attachment;
		}

		if ( ! Penalis_Account_Status::can_write( $user->ID ) ) {
			return new WP_Error(
				'penalis_account_inactive',
				'Akun kamu saat ini tidak diizinkan mengelola media.',
				array( 'status' => 403 )
			);
		}

		$attachment_id = isset( $prepared_attachment->ID ) ? (int) $prepared_attachment->ID : 0;

		if ( $attachment_id > 0 ) {
			$existing = get_post( $attachment_id );

			if ( ! $existing || 'attachment' !== $existing->post_type ) {
				return new WP_Error(
					'penalis_invalid_media',
					'Media tersebut tidak dapat diubah.',
					array( 'status' => 403 )
				);
			}

			if ( (int) $existing->post_author !== (int) $user->ID ) {
				// 404-style refusal: another writer's media must not be
				// confirmed to exist.
				return new WP_Error(
					'penalis_not_found',
					'Media tidak ditemukan.',
					array( 'status' => 404 )
				);
			}
		}

		$requested_author = $request->get_param( 'author' );
		$author           = Penalis_Policy::resolve_author( $requested_author, $user->ID );

		if ( isset( $author['error'] ) ) {
			return new WP_Error( 'penalis_invalid_author', $author['error'], array( 'status' => 403 ) );
		}

		$prepared_attachment->post_author = (int) $author['author'];

		// Attachments are never published on their own; `inherit` is the only
		// status the media library uses. Refuse anything else so a writer
		// cannot create a publicly listed attachment post.
		$requested_status = $request->get_param( 'status' );
		if ( null !== $requested_status && '' !== (string) $requested_status && 'inherit' !== $requested_status ) {
			return new WP_Error(
				'penalis_invalid_status',
				'Status media tersebut tidak diizinkan.',
				array( 'status' => 403 )
			);
		}

		// A writer may attach media only to their own article.
		$parent_id = (int) $request->get_param( 'post' );
		if ( $parent_id > 0 ) {
			$parent = get_post( $parent_id );

			if ( ! $parent || 'post' !== $parent->post_type || (int) $parent->post_author !== (int) $user->ID ) {
				return new WP_Error(
					'penalis_invalid_media_parent',
					'Kamu hanya dapat melampirkan media ke karya tulismu sendiri.',
					array( 'status' => 403 )
				);
			}
		}

		return $prepared_attachment;
	}

	/**
	 * Force post list queries to the current author for restricted users.
	 *
	 * @param array           $args    Query args.
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function force_own_post_query( $args, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
		if ( ! is_user_logged_in() ) {
			return $args;
		}

		$user = wp_get_current_user();
		if ( ! self::is_restricted_user( $user ) ) {
			return $args;
		}

		$args['author'] = (int) $user->ID;

		// Only the dashboard's own statuses are queryable.
		if ( isset( $args['post_status'] ) && is_array( $args['post_status'] ) ) {
			$allowed             = array( 'publish', 'draft', 'pending', 'future', 'private' );
			$args['post_status'] = array_values( array_intersect( $args['post_status'], $allowed ) );
			if ( empty( $args['post_status'] ) ) {
				$args['post_status'] = array( 'publish' );
			}
		}

		return $args;
	}

	/**
	 * Force media list queries to the current author for restricted users.
	 *
	 * @param array           $args    Query args.
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function force_own_media_query( $args, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
		if ( ! is_user_logged_in() ) {
			return $args;
		}

		$user = wp_get_current_user();
		if ( ! self::is_restricted_user( $user ) ) {
			return $args;
		}

		$args['author'] = (int) $user->ID;

		return $args;
	}

	/**
	 * Restrict upload MIME types for writers.
	 *
	 * @param array<string,string> $mimes Allowed MIME types.
	 * @return array<string,string>
	 */
	public static function restrict_upload_mimes( $mimes ) {
		if ( ! is_user_logged_in() ) {
			return $mimes;
		}

		$user = wp_get_current_user();
		if ( ! self::is_restricted_user( $user ) ) {
			return $mimes;
		}

		return array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'gif'          => 'image/gif',
			'webp'         => 'image/webp',
		);
	}

	/**
	 * Validate an uploaded file for writers.
	 *
	 * @param array $file Upload file array.
	 * @return array
	 */
	public static function validate_upload( $file ) {
		if ( ! is_user_logged_in() ) {
			return $file;
		}

		$user = wp_get_current_user();
		if ( ! self::is_restricted_user( $user ) ) {
			return $file;
		}

		$allowed = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
		$type    = isset( $file['type'] ) ? (string) $file['type'] : '';

		if ( ! in_array( $type, $allowed, true ) ) {
			$file['error'] = 'Hanya gambar JPEG, PNG, GIF, dan WebP yang dapat diunggah.';
			return $file;
		}

		// Reject files whose contents do not match an image.
		$tmp = isset( $file['tmp_name'] ) ? $file['tmp_name'] : '';
		if ( '' === $tmp || ! is_readable( $tmp ) ) {
			$file['error'] = 'File yang diunggah tidak dapat divalidasi.';
			return $file;
		}

		$info = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $info || ! isset( $info[2] ) ) {
			$file['error'] = 'File yang diunggah bukan gambar yang valid.';
			return $file;
		}

		$allowed_types = array( IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP );
		if ( ! in_array( (int) $info[2], $allowed_types, true ) ) {
			$file['error'] = 'Jenis gambar yang diunggah tidak diizinkan.';
		}

		return $file;
	}

	/**
	 * Prevent changing a user's role to administrator or writer via native
	 * user edit screens by users who are not allowed to promote.
	 *
	 * Core already enforces promote_users, but this adds an explicit guard for
	 * the plugin role and blocks privilege escalation attempts.
	 *
	 * @param int     $user_id   User ID.
	 * @param string  $new_role  New role.
	 * @param mixed[] $old_roles Old roles.
	 * @return void
	 */
	public static function prevent_role_escalation( $user_id, $new_role, $old_roles ) {
		if ( current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( 'administrator' === $new_role && ! current_user_can( 'promote_users' ) ) {
			// Revert by restoring the previous role.
			$previous = is_array( $old_roles ) && ! empty( $old_roles ) ? $old_roles[0] : 'subscriber';
			$user     = get_userdata( (int) $user_id );
			if ( $user ) {
				$user->set_role( $previous );
			}
		}
	}

	/**
	 * Prevent plugin-managed writer accounts from changing their email address
	 * through a generic profile update.
	 *
	 * Only plugin-managed/writer accounts are affected. Existing editors,
	 * authors, and administrators retain their normal profile behavior.
	 *
	 * @param int     $user_id       User ID.
	 * @param WP_User $old_user_data Old user data.
	 * @return void
	 */
	public static function prevent_profile_escalation( $user_id, $old_user_data ) {
		if ( self::$restoring_email || self::$applying_verified_email_change ) {
			return;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return;
		}

		$current_id = get_current_user_id();
		if ( $current_id !== (int) $user_id ) {
			return;
		}

		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return;
		}

		$is_writer = in_array( Penalis_Roles::ROLE, (array) $user->roles, true )
			|| Penalis_Account_Status::is_managed_account( $user_id );

		if ( ! $is_writer ) {
			return;
		}

		// Restore the email address; writers change it only through the verified
		// email-change flow in Penalis_Email_Change.
		if ( $old_user_data instanceof WP_User && $user->user_email !== $old_user_data->user_email ) {
			self::$restoring_email = true;
			wp_update_user(
				array(
					'ID'         => $user_id,
					'user_email' => $old_user_data->user_email,
				)
			);
			self::$restoring_email = false;
		}
	}

	/**
	 * Last-resort guard against a writer publishing through any write path.
	 *
	 * Capability mapping and the REST insert filter already refuse this, but
	 * `wp_insert_post()` is reachable from plugins, wp-admin screens and future
	 * code. This filter runs on every insert and update, so a writer request
	 * that somehow carries `publish`, `future` or `private` is downgraded to a
	 * draft instead of going live. An article that is already published keeps
	 * its status: this guard must never unpublish content as a side effect.
	 *
	 * Administrators, editors and other unrestricted accounts are untouched.
	 *
	 * @param array<string,mixed> $data    Slashed post data.
	 * @param array<string,mixed> $postarr Raw post array.
	 * @return array<string,mixed>
	 */
	public static function guard_writer_publish_status( $data, $postarr ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
		if ( ! is_user_logged_in() ) {
			return $data;
		}

		$user = wp_get_current_user();
		if ( ! self::is_restricted_user( $user ) ) {
			return $data;
		}

		$status = isset( $data['post_status'] ) ? (string) $data['post_status'] : '';

		if ( ! in_array( $status, array( 'publish', 'future', 'private' ), true ) ) {
			return $data;
		}

		$post_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		$current = $post_id > 0 ? get_post( $post_id ) : null;

		// Never take a live article down; the edit guard is what stops a writer
		// from touching it in the first place.
		if ( $current && 'publish' === $current->post_status ) {
			return $data;
		}

		$data['post_status'] = 'draft';

		return $data;
	}

	/**
	 * Map meta capabilities for writer-owned posts.
	 *
	 * @param string[] $caps    Primitive caps.
	 * @param string   $cap     Capability.
	 * @param int      $user_id User ID.
	 * @param array    $args    Args.
	 * @return string[]
	 */
	public static function map_meta_cap( $caps, $cap, $user_id, $args ) {
		if ( ! in_array( $cap, array( 'edit_post', 'delete_post', 'publish_post' ), true ) ) {
			return $caps;
		}

		$post_id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( $post_id <= 0 ) {
			return $caps;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return $caps;
		}

		$user = get_userdata( (int) $user_id );
		if ( ! $user || ! self::is_restricted_user( $user ) ) {
			return $caps;
		}

		// Writers only work on articles and their own media. Any other post
		// type reached through a meta capability is outside the workflow.
		if ( ! in_array( $post->post_type, array( 'post', 'attachment' ), true ) ) {
			$caps[] = 'do_not_allow';
			return $caps;
		}

		if ( (int) $post->post_author !== (int) $user_id ) {
			$caps[] = 'do_not_allow';
			return $caps;
		}

		if ( ! Penalis_Account_Status::can_write( $user_id ) ) {
			$caps[] = 'do_not_allow';
			return $caps;
		}

		// Attachments are owned media, not articles: they have the `inherit`
		// status, so the editorial state rules must not be applied to them.
		// Ownership and the write gate above are the whole rule, and no writer
		// path may publish an attachment.
		if ( 'attachment' === $post->post_type ) {
			if ( 'publish_post' === $cap ) {
				$caps[] = 'do_not_allow';
			}

			return $caps;
		}

		// Deleting is a content takedown, not an edit: a writer may still
		// remove their own article, including a published one, which is the
		// existing dashboard behaviour. Only editing and publishing are locked
		// by the editorial state.
		if ( 'delete_post' === $cap ) {
			return $caps;
		}

		if ( 'publish_post' === $cap || ! Penalis_Editorial_Workflow::can_writer_edit( $post, $user_id ) ) {
			$caps[] = 'do_not_allow';
		}

		return $caps;
	}
}
