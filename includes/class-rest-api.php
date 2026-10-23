<?php
/**
 * REST API controller.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles Penalis REST routes.
 */
final class Penalis_Rest_Api {

	public const NAMESPACE_V1 = 'penalis/v1';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'add_cache_headers' ), 10, 3 );
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/registration',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_registration' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'display_name'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'user_login'      => array(
						'type'     => 'string',
						'required' => true,
					),
					'user_email'      => array(
						'type'     => 'string',
						'required' => true,
					),
					'password'        => array(
						'type'     => 'string',
						'required' => true,
					),
					'penalis_consent' => array(
						'type'     => 'string',
						'required' => true,
					),
					'penalis_website' => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/verification/confirm',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_verification' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/verification/resend',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_resend' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'user_email' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/account',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_account' ),
				'permission_callback' => array( __CLASS__, 'require_logged_in' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/account/profile',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'update_profile' ),
				// Pending accounts may maintain safe profile fields; the handler
				// only accepts display name, bio, and website.
				'permission_callback' => array( __CLASS__, 'require_logged_in' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/account/password',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'update_password' ),
				'permission_callback' => array( __CLASS__, 'require_logged_in' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/account/avatar',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'set_avatar' ),
					'permission_callback' => array( __CLASS__, 'require_logged_in' ),
					'args'                => array(
						'attachment_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'remove_avatar' ),
					'permission_callback' => array( __CLASS__, 'require_logged_in' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/articles',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_articles' ),
				'permission_callback' => array( __CLASS__, 'require_active_writer' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/articles/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_article' ),
				'permission_callback' => array( __CLASS__, 'require_active_writer' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/articles/(?P<id>\d+)/publish',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'publish_article' ),
				'permission_callback' => array( __CLASS__, 'require_active_writer' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/articles/(?P<id>\d+)/unpublish',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'unpublish_article' ),
				'permission_callback' => array( __CLASS__, 'require_active_writer' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/media',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_media' ),
				'permission_callback' => array( __CLASS__, 'require_active_writer' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/tags',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_tag' ),
				'permission_callback' => array( __CLASS__, 'require_active_writer' ),
			)
		);
	}

	/**
	 * Require an authenticated user.
	 *
	 * Pending accounts may read their account state. Rejected and suspended
	 * accounts cannot use the dashboard API.
	 *
	 * @return true|WP_Error
	 */
	public static function require_logged_in() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'penalis_not_authenticated',
				'You must be signed in.',
				array( 'status' => 401 )
			);
		}

		$user_id = get_current_user_id();

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( ! Penalis_Account_Status::can_view_dashboard( $user_id ) ) {
			return new WP_Error(
				'penalis_account_inactive',
				'This account cannot access the writing dashboard.',
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Require an active writer with write capability.
	 *
	 * @return true|WP_Error
	 */
	public static function require_active_writer() {
		$check = self::require_logged_in();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$user_id = get_current_user_id();

		if ( ! Penalis_Account_Status::can_write( $user_id ) ) {
			return new WP_Error(
				'penalis_account_inactive',
				'Your account is not currently allowed to create or manage articles.',
				array( 'status' => 403 )
			);
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'penalis_forbidden',
				'You are not allowed to perform this action.',
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}   /**
		 * Handle registration.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
	public static function handle_registration( $request ) {
		$data = array(
			'display_name'    => $request->get_param( 'display_name' ),
			'user_login'      => $request->get_param( 'user_login' ),
			'user_email'      => $request->get_param( 'user_email' ),
			'password'        => $request->get_param( 'password' ),
			'penalis_consent' => $request->get_param( 'penalis_consent' ),
			'penalis_website' => $request->get_param( 'penalis_website' ),
		);

		$result = Penalis_Registration::register( $data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 201 );
	}

	/**
	 * Confirm verification.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_verification( $request ) {
		$result = Penalis_Registration::verify( (string) $request->get_param( 'token' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Resend verification email.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_resend( $request ) {
		$result = Penalis_Registration::resend_verification( (string) $request->get_param( 'user_email' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Return the current account.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_account() {
		$user_id = get_current_user_id();
		$user    = get_userdata( $user_id );

		if ( ! $user ) {
			return new WP_Error( 'penalis_not_authenticated', 'You must be signed in.', array( 'status' => 401 ) );
		}

		$status    = Penalis_Account_Status::get_status( $user_id );
		$can_write = Penalis_Account_Status::can_write( $user_id );
		$counts    = self::article_counts( $user_id );
		$is_admin  = current_user_can( 'manage_options' );

		if ( '' !== $status ) {
			$status_label = Penalis_Policy::status_label( $status );
		} elseif ( $is_admin ) {
			$status_label = 'Administrator';
		} else {
			$status_label = 'Legacy account';
		}

		return new WP_REST_Response(
			array(
				'id'              => (int) $user_id,
				'username'        => $user->user_login,
				'displayName'     => $user->display_name,
				'firstName'       => (string) get_user_meta( $user_id, 'first_name', true ),
				'lastName'        => (string) get_user_meta( $user_id, 'last_name', true ),
				'displayNameMode' => Penalis_Account_Status::get_display_name_mode( $user_id ),
				'avatarId'        => Penalis_Avatar::get_id( $user_id ),
				'avatarUrl'       => Penalis_Avatar::get_url( $user_id ),
				'email'           => $user->user_email,
				'description'     => (string) get_user_meta( $user_id, 'description', true ),
				'website'         => $user->user_url,
				'status'          => $status,
				'statusLabel'     => $status_label,
				'isAdministrator' => $is_admin,
				'canWrite'        => $can_write,
				'canView'         => Penalis_Account_Status::can_view_dashboard( $user_id ),
				'emailVerified'   => '1' === (string) get_user_meta( $user_id, Penalis_Account_Status::META_VERIFIED, true ),
				'registeredAt'    => (int) get_user_meta( $user_id, Penalis_Account_Status::META_REGISTERED_AT, true ),
				'approvedAt'      => (int) get_user_meta( $user_id, Penalis_Account_Status::META_APPROVED_AT, true ),
				'counts'          => $counts,
				'registrationOn'  => Penalis_Registration::is_registration_enabled(),
			),
			200
		);
	}

	/**
	 * Update safe profile fields.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_profile( $request ) {
		$user_id = get_current_user_id();
		$input   = array();

		$fields = array( 'display_name', 'display_name_mode', 'first_name', 'last_name', 'description', 'user_url' );

		foreach ( $fields as $field ) {
			$value = $request->get_param( $field );
			if ( null !== $value ) {
				$input[ $field ] = $value;
			}
		}

		$mode       = isset( $input['display_name_mode'] ) ? sanitize_key( (string) $input['display_name_mode'] ) : '';
		$validation = $input;
		$mode_field = array(
			'first' => 'first_name',
			'last'  => 'last_name',
		);

		// A partial update may switch the public-name mode without resending the
		// name fields, so validate that mode against the stored values.
		if ( isset( $mode_field[ $mode ] ) ) {
			$required = $mode_field[ $mode ];

			if ( ! isset( $validation[ $required ] ) ) {
				$validation[ $required ] = (string) get_user_meta( $user_id, $required, true );
			}
		}

		$errors = Penalis_Policy::validate_profile( $validation );
		if ( ! empty( $errors ) ) {
			return new WP_Error(
				'penalis_profile_invalid',
				'Please correct the highlighted fields.',
				array(
					'status' => 400,
					'fields' => $errors,
				)
			);
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'penalis_not_authenticated', 'You must be signed in.', array( 'status' => 401 ) );
		}

		$update = array( 'ID' => $user_id );

		if ( isset( $input['first_name'] ) ) {
			$update['first_name'] = Penalis_Policy::normalize_person_name( sanitize_text_field( (string) $input['first_name'] ) );
		}

		if ( isset( $input['last_name'] ) ) {
			$update['last_name'] = Penalis_Policy::normalize_person_name( sanitize_text_field( (string) $input['last_name'] ) );
		}

		if ( isset( $input['description'] ) ) {
			$update['description'] = sanitize_textarea_field( $input['description'] );
		}

		if ( isset( $input['user_url'] ) ) {
			$update['user_url'] = esc_url_raw( trim( (string) $input['user_url'] ) );
		}

		if ( Penalis_Policy::is_valid_display_mode( $mode ) ) {
			if ( 'custom' === $mode ) {
				$update['display_name'] = sanitize_text_field( (string) ( $input['display_name'] ?? '' ) );
			} else {
				// Resolve against the incoming names, falling back to stored ones.
				$first = $update['first_name'] ?? (string) get_user_meta( $user_id, 'first_name', true );
				$last  = $update['last_name'] ?? (string) get_user_meta( $user_id, 'last_name', true );

				$update['display_name'] = Penalis_Policy::resolve_display_name( $mode, $first, $last, $user->user_login );
			}
		} elseif ( isset( $input['display_name'] ) ) {
			// Backwards compatibility for clients that only send display_name.
			$update['display_name'] = sanitize_text_field( (string) $input['display_name'] );
			$mode                   = 'custom';
		}

		$result = wp_update_user( $update );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'penalis_profile_update_failed',
				'Your profile could not be updated. Please try again.',
				array( 'status' => 400 )
			);
		}

		if ( Penalis_Policy::is_valid_display_mode( $mode ) ) {
			update_user_meta( $user_id, Penalis_Account_Status::META_DISPLAY_NAME_MODE, $mode );
		}

		return self::get_account();
	}

	/**
	 * Assign a profile photo from the writer's media library.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function set_avatar( $request ) {
		$result = Penalis_Avatar::set( get_current_user_id(), (int) $request['attachment_id'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::get_account();
	}

	/**
	 * Remove the current profile photo.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function remove_avatar() {
		Penalis_Avatar::clear( get_current_user_id() );

		return self::get_account();
	}

	/**
	 * Change the current user's password.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_password( $request ) {
		$user_id = get_current_user_id();

		if ( ! Penalis_Rate_Limiter::is_allowed( 'password_change', 5, HOUR_IN_SECONDS, (string) $user_id ) ) {
			return new WP_Error(
				'penalis_rate_limited',
				'Too many attempts. Please try again later.',
				array( 'status' => 429 )
			);
		}
		Penalis_Rate_Limiter::hit( 'password_change', HOUR_IN_SECONDS, (string) $user_id );

		$current = (string) $request->get_param( 'current_password' );
		$new     = (string) $request->get_param( 'new_password' );
		$user    = get_userdata( $user_id );

		if ( ! $user || ! wp_check_password( $current, $user->user_pass, $user_id ) ) {
			return new WP_Error(
				'penalis_password_invalid',
				'Your current password is incorrect.',
				array( 'status' => 400 )
			);
		}

		$error = Penalis_Policy::validate_password( $new );
		if ( '' !== $error ) {
			return new WP_Error(
				'penalis_password_weak',
				$error,
				array( 'status' => 400 )
			);
		}

		wp_set_password( $new, $user_id );

		// Invalidate every session, including the current one, so a compromised
		// session cannot continue using the account after a password change.
		if ( class_exists( 'WP_Session_Tokens' ) ) {
			WP_Session_Tokens::get_instance( $user_id )->destroy_all();
		}

		return new WP_REST_Response(
			array(
				'message' => 'Your password has been changed. Please sign in again.',
			),
			200
		);
	}

	/**
	 * List the current writer's articles.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_articles( $request ) {
		$user_id  = get_current_user_id();
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page > 0 ? min( 50, $per_page ) : 10;

		$status = (string) $request->get_param( 'status' );
		if ( '' === $status || 'all' === $status ) {
			$post_status = array( 'publish', 'draft', 'pending', 'future' );
		} elseif ( Penalis_Policy::is_allowed_post_status( $status ) ) {
			$post_status = array( $status );
		} else {
			$post_status = array( 'publish', 'draft', 'pending', 'future' );
		}

		$query = new WP_Query(
			array(
				'post_type'           => 'post',
				'post_status'         => $post_status,
				'author'              => $user_id,
				'posts_per_page'      => $per_page,
				'paged'               => $page,
				'orderby'             => 'modified',
				'order'               => 'DESC',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => false,
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = self::prepare_article_summary( $post );
		}

		return new WP_REST_Response(
			array(
				'items'      => $items,
				'total'      => (int) $query->found_posts,
				'page'       => $page,
				'perPage'    => $per_page,
				'totalPages' => (int) $query->max_num_pages,
				'counts'     => self::article_counts( $user_id ),
			),
			200
		);
	}

	/**
	 * Return a single owned article for editing.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_article( $request ) {
		$post = self::get_owned_article( (int) $request['id'] );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$terms      = wp_get_post_terms( $post->ID, array( 'category', 'post_tag' ), array( 'fields' => 'all' ) );
		$categories = array();
		$tags       = array();

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( 'category' === $term->taxonomy ) {
					$categories[] = array(
						'id'   => (int) $term->term_id,
						'name' => $term->name,
					);
				} else {
					$tags[] = array(
						'id'   => (int) $term->term_id,
						'name' => $term->name,
					);
				}
			}
		}

		return new WP_REST_Response(
			array(
				'id'            => (int) $post->ID,
				'title'         => $post->post_title,
				'content'       => $post->post_content,
				'excerpt'       => $post->post_excerpt,
				'status'        => $post->post_status,
				'date'          => $post->post_date_gmt,
				'modified'      => $post->post_modified_gmt,
				'link'          => get_permalink( $post ),
				'featuredMedia' => (int) get_post_thumbnail_id( $post ),
				'categories'    => $categories,
				'tags'          => $tags,
			),
			200
		);
	}

	/**
	 * Publish an owned article.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function publish_article( $request ) {
		$post = self::get_owned_article( (int) $request['id'] );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( ! in_array( $post->post_status, array( 'draft', 'pending', 'future' ), true ) ) {
			return new WP_Error(
				'penalis_invalid_transition',
				'This article cannot be published from its current status.',
				array( 'status' => 409 )
			);
		}

		$result = wp_update_post(
			array(
				'ID'          => $post->ID,
				'post_status' => 'publish',
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'penalis_publish_failed',
				'The article could not be published.',
				array( 'status' => 500 )
			);
		}

		$updated = get_post( $post->ID );
		return new WP_REST_Response( self::prepare_article_summary( $updated ), 200 );
	}

	/**
	 * Return an owned article to draft.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function unpublish_article( $request ) {
		$post = self::get_owned_article( (int) $request['id'] );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( 'publish' !== $post->post_status ) {
			return new WP_Error(
				'penalis_invalid_transition',
				'Only published articles can be returned to draft.',
				array( 'status' => 409 )
			);
		}

		$result = wp_update_post(
			array(
				'ID'          => $post->ID,
				'post_status' => 'draft',
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'penalis_unpublish_failed',
				'The article could not be unpublished.',
				array( 'status' => 500 )
			);
		}

		$updated = get_post( $post->ID );
		return new WP_REST_Response( self::prepare_article_summary( $updated ), 200 );
	}

	/**
	 * List the current writer's media.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_media( $request ) {
		$user_id  = get_current_user_id();
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page > 0 ? min( 50, $per_page ) : 20;

		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'author'         => $user_id,
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $query->posts as $attachment ) {
			$items[] = self::prepare_media( $attachment );
		}

		return new WP_REST_Response(
			array(
				'items'      => $items,
				'total'      => (int) $query->found_posts,
				'page'       => $page,
				'perPage'    => $per_page,
				'totalPages' => (int) $query->max_num_pages,
			),
			200
		);
	}

	/**
	 * Create (or reuse) a tag for the current writer.
	 *
	 * Writers do not have manage_categories. This narrow endpoint allows
	 * creating a tag by name while preventing taxonomy administration.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_tag( $request ) {
		$user_id = get_current_user_id();

		if ( ! Penalis_Rate_Limiter::is_allowed( 'tag_create', 30, HOUR_IN_SECONDS, (string) $user_id ) ) {
			return new WP_Error(
				'penalis_rate_limited',
				'Too many tags created. Please try again later.',
				array( 'status' => 429 )
			);
		}

		$name        = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$name        = trim( $name );
		$name_length = function_exists( 'mb_strlen' ) ? mb_strlen( $name, 'UTF-8' ) : strlen( $name );

		if ( '' === $name || $name_length > 50 ) {
			return new WP_Error(
				'penalis_tag_invalid',
				'Please provide a tag name of 50 characters or fewer.',
				array( 'status' => 400 )
			);
		}

		Penalis_Rate_Limiter::hit( 'tag_create', HOUR_IN_SECONDS, (string) $user_id );

		$existing = get_term_by( 'name', $name, 'post_tag' );
		if ( $existing instanceof WP_Term ) {
			return new WP_REST_Response(
				array(
					'id'   => (int) $existing->term_id,
					'name' => $existing->name,
				),
				200
			);
		}

		$term = wp_insert_term( $name, 'post_tag' );

		if ( is_wp_error( $term ) ) {
			return new WP_Error(
				'penalis_tag_failed',
				'The tag could not be created.',
				array( 'status' => 400 )
			);
		}

		$created = get_term( (int) $term['term_id'], 'post_tag' );

		return new WP_REST_Response(
			array(
				'id'   => (int) $term['term_id'],
				'name' => $created instanceof WP_Term ? $created->name : $name,
			),
			201
		);
	}

	/**
	 * Fetch an article owned by the current user.
	 *
	 * @param int $post_id Post ID.
	 * @return WP_Post|WP_Error
	 */
	private static function get_owned_article( $post_id ) {
		$user_id = get_current_user_id();
		$post    = get_post( (int) $post_id );

		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error(
				'penalis_not_found',
				'Article not found.',
				array( 'status' => 404 )
			);
		}

		if ( (int) $post->post_author !== (int) $user_id ) {
			// Return 404 rather than 403 to avoid disclosing the existence of
			// another writer's article.
			return new WP_Error(
				'penalis_not_found',
				'Article not found.',
				array( 'status' => 404 )
			);
		}

		return $post;
	}

	/**
	 * Prepare a small article summary.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string,mixed>
	 */
	private static function prepare_article_summary( $post ) {
		$thumbnail = get_the_post_thumbnail_url( $post, 'thumbnail' );

		return array(
			'id'        => (int) $post->ID,
			'title'     => $post->post_title,
			'excerpt'   => wp_trim_words( wp_strip_all_tags( $post->post_excerpt ? $post->post_excerpt : $post->post_content ), 30 ),
			'status'    => $post->post_status,
			'date'      => $post->post_date_gmt,
			'modified'  => $post->post_modified_gmt,
			'link'      => 'publish' === $post->post_status ? get_permalink( $post ) : '',
			'editUrl'   => Penalis_Dashboard::dashboard_url(
				array(
					'view' => 'editor',
					'id'   => (int) $post->ID,
				)
			),
			'thumbnail' => $thumbnail ? $thumbnail : '',
		);
	}

	/**
	 * Prepare a media item.
	 *
	 * @param WP_Post $attachment Attachment post.
	 * @return array<string,mixed>
	 */
	private static function prepare_media( $attachment ) {
		$thumb = wp_get_attachment_image_src( $attachment->ID, 'thumbnail' );
		$full  = wp_get_attachment_image_src( $attachment->ID, 'full' );

		return array(
			'id'        => (int) $attachment->ID,
			'title'     => $attachment->post_title,
			'mime'      => $attachment->post_mime_type,
			'url'       => wp_get_attachment_url( $attachment->ID ),
			'thumbnail' => $thumb ? $thumb[0] : '',
			'full'      => $full ? $full[0] : '',
			'date'      => $attachment->post_date_gmt,
		);
	}

	/**
	 * Article counts for the current user.
	 *
	 * @param int $user_id User ID.
	 * @return array<string,int>
	 */
	private static function article_counts( $user_id ) {
		$counts = array(
			'all'     => 0,
			'publish' => 0,
			'draft'   => 0,
			'pending' => 0,
		);

		foreach ( array( 'publish', 'draft', 'pending' ) as $status ) {
			$query             = new WP_Query(
				array(
					'post_type'      => 'post',
					'post_status'    => $status,
					'author'         => (int) $user_id,
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => false,
				)
			);
			$counts[ $status ] = (int) $query->found_posts;
			$counts['all']    += (int) $query->found_posts;
		}

		return $counts;
	}

	/**
	 * Add private cache headers to Penalis responses.
	 *
	 * @param WP_HTTP_Response $response Response.
	 * @param WP_REST_Server   $server   Server.
	 * @param WP_REST_Request  $request  Request.
	 * @return WP_HTTP_Response
	 */
	public static function add_cache_headers( $response, $server, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
		if ( ! $response instanceof WP_REST_Response ) {
			return $response;
		}

		if ( 0 !== strpos( (string) $request->get_route(), '/' . self::NAMESPACE_V1 ) ) {
			return $response;
		}

		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, private, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'X-Robots-Tag', 'noindex, nofollow' );

		return $response;
	}
}
