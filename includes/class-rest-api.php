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
					'display_name'          => array(
						'type'     => 'string',
						'required' => true,
					),
					'user_login'            => array(
						'type'     => 'string',
						'required' => true,
					),
					'user_email'            => array(
						'type'     => 'string',
						'required' => true,
					),
					'password'              => array(
						'type'     => 'string',
						'required' => true,
					),
					'instagram'             => array(
						'type'     => 'string',
						'required' => false,
					),
					'penalis_consent'       => array(
						'type'     => 'string',
						'required' => true,
					),
					'penalis_website'       => array(
						'type'     => 'string',
						'required' => false,
					),
					'cf_turnstile_response' => array(
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
					'challenge_id' => array(
						'type'     => 'string',
						'required' => true,
					),
					'code'         => array(
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
			'/legacy/activate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_legacy_activate_request' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'user_email'            => array(
						'type'     => 'string',
						'required' => true,
					),
					'penalis_website'       => array(
						'type'     => 'string',
						'required' => false,
					),
					'cf_turnstile_response' => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/legacy/activate/confirm',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_legacy_activate_confirm' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'challenge_id' => array(
						'type'     => 'string',
						'required' => true,
					),
					'code'         => array(
						'type'     => 'string',
						'required' => true,
					),
					'password'     => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/password/forgot',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_password_forgot' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'user_email'            => array(
						'type'     => 'string',
						'required' => true,
					),
					'penalis_website'       => array(
						'type'     => 'string',
						'required' => false,
					),
					'cf_turnstile_response' => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/password/reset',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_password_reset' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'key'                   => array(
						'type'     => 'string',
						'required' => true,
					),
					'login'                 => array(
						'type'     => 'string',
						'required' => true,
					),
					'password'              => array(
						'type'     => 'string',
						'required' => true,
					),
					'cf_turnstile_response' => array(
						'type'     => 'string',
						'required' => false,
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
				// only accepts display name, bio, and the public-name preference.
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
			'/account/username',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'change_username' ),
				'permission_callback' => array( __CLASS__, 'require_logged_in' ),
				'args'                => array(
					'new_username'     => array(
						'type'     => 'string',
						'required' => true,
					),
					'current_password' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/account/email',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'request_email_change' ),
				'permission_callback' => array( __CLASS__, 'require_logged_in' ),
				'args'                => array(
					'new_email'        => array(
						'type'     => 'string',
						'required' => true,
					),
					'current_password' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/account/email/confirm',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'confirm_email_change' ),
				// Signed-in only: the challenge is bound to the account that
				// requested it, so a stolen code cannot move another account.
				'permission_callback' => array( __CLASS__, 'require_logged_in' ),
				'args'                => array(
					'challenge_id' => array(
						'type'     => 'string',
						'required' => true,
					),
					'code'         => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/account/email/resend',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'resend_email_change' ),
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
					'permission_callback' => array( __CLASS__, 'require_active_writer_or_reviewer' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'remove_avatar' ),
					'permission_callback' => array( __CLASS__, 'require_active_writer_or_reviewer' ),
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
				'permission_callback' => array( __CLASS__, 'require_active_writer_or_reviewer' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/articles/(?P<id>\d+)/review',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_article_review' ),
				'permission_callback' => array( __CLASS__, 'require_active_writer_or_reviewer' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/review/queue',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_review_queue' ),
				'permission_callback' => array( __CLASS__, 'require_reviewer' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/articles/(?P<id>\d+)/decision',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'decide_article' ),
				'permission_callback' => array( __CLASS__, 'require_reviewer' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/articles/(?P<id>\d+)/submit',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'submit_article' ),
				'permission_callback' => array( __CLASS__, 'require_active_writer' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/articles/(?P<id>\d+)/resubmit',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'resubmit_article' ),
				'permission_callback' => array( __CLASS__, 'require_active_writer' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/articles/(?P<id>\d+)/publish',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'reject_legacy_publish' ),
				'permission_callback' => array( __CLASS__, 'require_active_writer' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/articles/(?P<id>\d+)/unpublish',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'reject_legacy_publish' ),
				'permission_callback' => array( __CLASS__, 'require_active_writer' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/notifications',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_notifications' ),
				'permission_callback' => array( __CLASS__, 'require_logged_in' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/notifications/read',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'mark_notifications_read' ),
				'permission_callback' => array( __CLASS__, 'require_logged_in' ),
				'args'                => array(
					'id' => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/notifications/preferences',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'save_notification_preferences' ),
				'permission_callback' => array( __CLASS__, 'require_logged_in' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/notifications/(?P<id>[a-zA-Z0-9_\-]+)/read',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'mark_notifications_read' ),
				'permission_callback' => array( __CLASS__, 'require_logged_in' ),
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
			'/media/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_media' ),
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

		register_rest_route(
			self::NAMESPACE_V1,
			'/authors',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_public_authors' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'search'   => array(
						'type'     => 'string',
						'required' => false,
					),
					'sort'     => array(
						'type'     => 'string',
						'required' => false,
					),
					'verified' => array(
						'type'     => 'boolean',
						'required' => false,
					),
					'senator'  => array(
						'type'     => 'boolean',
						'required' => false,
					),
					'page'     => array(
						'type'     => 'integer',
						'required' => false,
						'default'  => 1,
					),
					'per_page' => array(
						'type'     => 'integer',
						'required' => false,
						'default'  => Penalis_Policy::AUTHORS_PER_PAGE,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/quotes',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'handle_public_quotes' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'search'   => array(
							'type'     => 'string',
							'required' => false,
						),
						'sort'     => array(
							'type'     => 'string',
							'required' => false,
						),
						'page'     => array(
							'type'     => 'integer',
							'required' => false,
							'default'  => 1,
						),
						'per_page' => array(
							'type'     => 'integer',
							'required' => false,
							'default'  => Penalis_Policy::DAILY_QUOTE_PER_PAGE,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'handle_publish_quote' ),
					'permission_callback' => array( __CLASS__, 'require_active_writer' ),
					'args'                => array(
						'text' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/quotes/me',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'handle_my_quote' ),
					'permission_callback' => array( __CLASS__, 'require_active_writer' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'handle_delete_my_quote' ),
					'permission_callback' => array( __CLASS__, 'require_active_writer' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/quotes/(?P<user_id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'handle_moderate_delete_quote' ),
				'permission_callback' => array( __CLASS__, 'require_reviewer' ),
				'args'                => array(
					'reason' => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);
	}

	/**
	 * Require an authenticated user.
	 *
	 * Pending accounts may read their account state. Rejected and suspended
	 * accounts cannot use the dashboard API. Reviewers (administrators and
	 * editors, both holding `edit_others_posts`) are created in wp-admin and have
	 * no Penalis writer status, so they bypass the writer status gate and see the
	 * dashboard in a read-only reviewer mode.
	 *
	 * @return true|WP_Error
	 */
	public static function require_logged_in() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'penalis_not_authenticated',
				'Kamu harus login terlebih dahulu.',
				array( 'status' => 401 )
			);
		}

		$user_id = get_current_user_id();

		if ( current_user_can( 'edit_others_posts' ) ) {
			return true;
		}

		if ( ! Penalis_Account_Status::can_view_dashboard( $user_id ) ) {
			return new WP_Error(
				'penalis_account_inactive',
				'Akun ini tidak dapat mengakses dashboard penulis.',
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
				'Akun kamu saat ini tidak diizinkan membuat atau mengelola karya tulis.',
				array( 'status' => 403 )
			);
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'penalis_forbidden',
				'Kamu tidak diizinkan melakukan tindakan ini.',
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Require an active writer or a reviewer (`edit_others_posts`).
	 *
	 * Used by read-only article detail and review endpoints so administrators and
	 * editors without writer status can inspect submissions.
	 *
	 * @return true|WP_Error
	 */
	public static function require_active_writer_or_reviewer() {
		$check = self::require_logged_in();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		if ( Penalis_Editorial_Workflow::can_review( get_current_user_id() ) ) {
			return true;
		}

		return self::require_active_writer();
	}

	/**
	 * Require a reviewer (administrator or editor).
	 *
	 * Reviewers do not need writer capabilities: an administrator without the
	 * writer role must still be able to clear the queue.
	 *
	 * @return true|WP_Error
	 */
	public static function require_reviewer() {
		$check = self::require_logged_in();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		if ( ! Penalis_Editorial_Workflow::can_review( get_current_user_id() ) ) {
			return new WP_Error(
				'penalis_review_forbidden',
				'Kamu tidak memiliki izin untuk me-review karya tulis.',
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle registration.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_registration( $request ) {
		$turnstile = Penalis_Turnstile::verify(
			(string) $request->get_param( 'cf_turnstile_response' ),
			'register',
			'penalis-register'
		);

		if ( is_wp_error( $turnstile ) ) {
			return $turnstile;
		}

		$data = array(
			'display_name'    => $request->get_param( 'display_name' ),
			'user_login'      => $request->get_param( 'user_login' ),
			'user_email'      => $request->get_param( 'user_email' ),
			'password'        => $request->get_param( 'password' ),
			'instagram'       => $request->get_param( 'instagram' ),
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
		$result = Penalis_Registration::verify(
			(string) $request->get_param( 'challenge_id' ),
			(string) $request->get_param( 'code' )
		);

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
	 * Request a legacy account activation code.
	 *
	 * Always answers generically so the endpoint cannot be used to discover
	 * which addresses belong to legacy accounts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_legacy_activate_request( $request ) {
		$turnstile = Penalis_Turnstile::verify(
			(string) $request->get_param( 'cf_turnstile_response' ),
			'activate',
			'penalis-activate'
		);

		if ( is_wp_error( $turnstile ) ) {
			return $turnstile;
		}

		$result = Penalis_Legacy_Activation::request(
			array(
				'user_email'      => $request->get_param( 'user_email' ),
				'penalis_website' => $request->get_param( 'penalis_website' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Consume an activation token and set the first password.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_legacy_activate_confirm( $request ) {
		$result = Penalis_Legacy_Activation::activate(
			array(
				'challenge_id' => $request->get_param( 'challenge_id' ),
				'code'         => $request->get_param( 'code' ),
				'password'     => $request->get_param( 'password' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Request a password reset link.
	 *
	 * Accepts an email address only: usernames are public on author archives, so
	 * allowing them would turn a public profile into a reset handle. Always
	 * answers generically so the endpoint cannot be used to discover which
	 * addresses are registered.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_password_forgot( $request ) {
		$turnstile = Penalis_Turnstile::verify(
			(string) $request->get_param( 'cf_turnstile_response' ),
			'forgot',
			'penalis-forgot'
		);

		if ( is_wp_error( $turnstile ) ) {
			return $turnstile;
		}

		$result = Penalis_Password_Reset::request(
			array(
				'user_email'      => $request->get_param( 'user_email' ),
				'penalis_website' => $request->get_param( 'penalis_website' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Consume a reset key and set a new password.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_password_reset( $request ) {
		$turnstile = Penalis_Turnstile::verify(
			(string) $request->get_param( 'cf_turnstile_response' ),
			'reset',
			'penalis-reset'
		);

		if ( is_wp_error( $turnstile ) ) {
			return $turnstile;
		}

		$result = Penalis_Password_Reset::reset(
			array(
				'key'      => $request->get_param( 'key' ),
				'login'    => $request->get_param( 'login' ),
				'password' => $request->get_param( 'password' ),
			)
		);

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
			return new WP_Error( 'penalis_not_authenticated', 'Kamu harus login terlebih dahulu.', array( 'status' => 401 ) );
		}

		$status      = Penalis_Account_Status::get_status( $user_id );
		$can_write   = Penalis_Account_Status::can_write( $user_id );
		$counts      = self::article_counts( $user_id );
		$is_admin    = current_user_can( 'manage_options' );
		$is_reviewer = current_user_can( 'edit_others_posts' );

		// The candidate address is only a display hint; the OTP challenge is
		// the authority and expires independently.
		$pending_email = Penalis_Account_Status::get_pending_email( $user_id );

		if ( '' !== $status ) {
			$status_label = Penalis_Policy::status_label( $status );
		} elseif ( $is_admin ) {
			$status_label = 'Administrator';
		} elseif ( $is_reviewer ) {
			$status_label = 'Reviewer';
		} else {
			$status_label = 'Akun lama';
		}

		return new WP_REST_Response(
			array(
				'id'                      => (int) $user_id,
				'username'                => $user->user_login,
				'displayName'             => $user->display_name,
				'firstName'               => (string) get_user_meta( $user_id, 'first_name', true ),
				'lastName'                => (string) get_user_meta( $user_id, 'last_name', true ),
				'displayNameMode'         => Penalis_Account_Status::get_display_name_mode( $user_id ),
				'avatarId'                => Penalis_Avatar::get_id( $user_id ),
				'hasAvatar'               => Penalis_Avatar::has_avatar( $user_id ),
				'avatarUrl'               => Penalis_Avatar::get_url( $user_id ),
				'email'                   => $user->user_email,
				'pendingEmail'            => $pending_email,
				'canChangeEmail'          => Penalis_Account_Status::can_change_email( $user_id ),
				'canChangeUsername'       => Penalis_Account_Status::can_change_username( $user_id ),
				'description'             => (string) get_user_meta( $user_id, 'description', true ),
				'instagram'               => Penalis_Account_Status::get_instagram( $user_id ),
				'authorUrl'               => get_author_posts_url( $user_id ),
				'status'                  => $status,
				'statusLabel'             => $status_label,
				'role'                    => Penalis_Policy::dashboard_role( $is_admin, $is_reviewer, $status, $can_write ),
				'isAdministrator'         => $is_admin,
				'isReviewer'              => $is_reviewer,
				'isVerified'              => Penalis_Authors::is_user_verified( $user_id ),
				'isSenator'               => Penalis_Authors::is_user_senator( $user_id ),
				'joinedLabel'             => Penalis_Datetime::format_indonesian_month_year_gmt( (string) $user->user_registered ),
				'canWrite'                => $can_write,
				'canView'                 => $is_reviewer || Penalis_Account_Status::can_view_dashboard( $user_id ),
				'emailVerified'           => '1' === (string) get_user_meta( $user_id, Penalis_Account_Status::META_VERIFIED, true ),
				'needsPassword'           => Penalis_Account_Status::needs_password( $user_id ),
				'registeredAt'            => (int) get_user_meta( $user_id, Penalis_Account_Status::META_REGISTERED_AT, true ),
				'approvedAt'              => (int) get_user_meta( $user_id, Penalis_Account_Status::META_APPROVED_AT, true ),
				'counts'                  => $counts,
				'unreadNotifications'     => Penalis_Editorial_Notifications::count_unread( $user_id ),
				'notificationPreferences' => Penalis_Editorial_Notifications::get_preferences( $user_id ),
				'registrationOn'          => Penalis_Registration::is_registration_enabled(),
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

		$fields = array( 'display_name', 'display_name_mode', 'first_name', 'last_name', 'description', 'instagram' );

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
				'Perbaiki kolom yang ditandai.',
				array(
					'status' => 400,
					'fields' => $errors,
				)
			);
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'penalis_not_authenticated', 'Kamu harus login terlebih dahulu.', array( 'status' => 401 ) );
		}

		$update = array( 'ID' => $user_id );

		if ( isset( $input['first_name'] ) ) {
			$update['first_name'] = Penalis_Policy::normalize_person_name( sanitize_text_field( (string) $input['first_name'] ) );
		}

		if ( isset( $input['last_name'] ) ) {
			$update['last_name'] = Penalis_Policy::normalize_person_name( sanitize_text_field( (string) $input['last_name'] ) );
		}

		if ( isset( $input['description'] ) ) {
			$update['description'] = Penalis_Policy::normalize_bio( sanitize_textarea_field( (string) $input['description'] ) );
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
				'Profil kamu tidak dapat diperbarui. Silakan coba lagi.',
				array( 'status' => 400 )
			);
		}

		if ( Penalis_Policy::is_valid_display_mode( $mode ) ) {
			update_user_meta( $user_id, Penalis_Account_Status::META_DISPLAY_NAME_MODE, $mode );
		}

		if ( array_key_exists( 'instagram', $input ) ) {
			Penalis_Account_Status::set_instagram( $user_id, (string) $input['instagram'] );
		}

		return self::get_account();
	}

	/**
	 * Change the authenticated account's login.
	 *
	 * Self-scoped: the user ID comes from the session, never from the request.
	 * The handler enforces eligibility, re-authentication, throttling and
	 * validation; the route only guarantees a signed-in caller.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function change_username( $request ) {
		$result = Penalis_Username_Change::change(
			get_current_user_id(),
			(string) $request->get_param( 'new_username' ),
			(string) $request->get_param( 'current_password' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Request an email change.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function request_email_change( $request ) {
		$result = Penalis_Email_Change::request(
			get_current_user_id(),
			(string) $request->get_param( 'new_email' ),
			(string) $request->get_param( 'current_password' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 202 );
	}

	/**
	 * Consume an email-change confirmation code.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function confirm_email_change( $request ) {
		$result = Penalis_Email_Change::confirm(
			get_current_user_id(),
			(string) $request->get_param( 'challenge_id' ),
			(string) $request->get_param( 'code' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Reissue the code for a pending email change.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function resend_email_change() {
		$result = Penalis_Email_Change::resend( get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Upload and assign a standalone profile photo.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function set_avatar( $request ) {
		$user_id = get_current_user_id();

		if ( ! Penalis_Rate_Limiter::guard( 'avatar_upload', (string) $user_id ) ) {
			return new WP_Error(
				'penalis_rate_limited',
				'Terlalu banyak percobaan mengganti foto profil. Silakan coba lagi nanti.',
				array( 'status' => 429 )
			);
		}
		Penalis_Rate_Limiter::register( 'avatar_upload', (string) $user_id );

		$files = $request->get_file_params();
		$file  = isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : null;

		if ( null === $file ) {
			return new WP_Error(
				'penalis_avatar_missing',
				'Pilih gambar untuk foto profil kamu.',
				array( 'status' => 400 )
			);
		}

		$result = Penalis_Avatar::upload( $user_id, $file );

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

		if ( ! Penalis_Rate_Limiter::guard( 'password_change', (string) $user_id ) ) {
			return new WP_Error(
				'penalis_rate_limited',
				'Terlalu banyak percobaan. Silakan coba lagi nanti.',
				array( 'status' => 429 )
			);
		}
		Penalis_Rate_Limiter::register( 'password_change', (string) $user_id );

		$current = (string) $request->get_param( 'current_password' );
		$new     = (string) $request->get_param( 'new_password' );
		$user    = get_userdata( $user_id );

		if ( ! $user || ! wp_check_password( $current, $user->user_pass, $user_id ) ) {
			return new WP_Error(
				'penalis_password_invalid',
				'Password kamu saat ini salah.',
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
				'message' => 'Password kamu berhasil diubah. Silakan login kembali.',
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
		$status   = (string) $request->get_param( 'status' );

		$query_args          = self::editorial_query_args( $user_id, $status, $page, $per_page );
		$use_effective_order = ! empty( $query_args['penalis_effective_order'] );

		if ( $use_effective_order ) {
			add_filter( 'posts_orderby', array( __CLASS__, 'filter_effective_article_orderby' ), 10, 2 );
		}

		try {
			$query = new WP_Query( $query_args );
		} finally {
			if ( $use_effective_order ) {
				remove_filter( 'posts_orderby', array( __CLASS__, 'filter_effective_article_orderby' ), 10 );
			}
		}

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
	 * Build WP_Query arguments for the writer article list by status or editorial state.
	 *
	 * Published listings order by publish date so routine wp-admin taxonomy or SEO
	 * edits never bump older published articles to the top. Mixed-status listings
	 * (`all`) flag `penalis_effective_order` so published items anchor to
	 * `post_date_gmt` while unpublished drafts and submissions stay ordered by
	 * `post_modified_gmt`.
	 *
	 * @param int    $user_id  Author ID.
	 * @param string $filter   Requested status or editorial state filter.
	 * @param int    $page     Page number.
	 * @param int    $per_page Items per page.
	 * @return array<string,mixed>
	 */
	public static function editorial_query_args( $user_id, $filter = 'all', $page = 1, $per_page = 10 ) {
		$clauses  = Penalis_Editorial_Workflow::state_query_clauses( (string) $filter );
		$statuses = isset( $clauses['post_status'] ) ? (array) $clauses['post_status'] : array();
		$args     = array(
			'post_type'           => 'post',
			'author'              => (int) $user_id,
			'posts_per_page'      => max( 1, (int) $per_page ),
			'paged'               => max( 1, (int) $page ),
			'orderby'             => 'modified',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => false,
		);

		if ( array( 'publish' ) === $statuses ) {
			$args['orderby'] = 'date';
		} elseif ( in_array( 'publish', $statuses, true ) ) {
			$args['penalis_effective_order'] = true;
		}

		return array_merge( $args, $clauses );
	}

	/**
	 * Order mixed-status writer article lists by publish date for published posts
	 * and last-modified time for unpublished drafts or submissions.
	 *
	 * @param string   $orderby SQL ORDER BY clause.
	 * @param WP_Query $query   Current query instance.
	 * @return string
	 */
	public static function filter_effective_article_orderby( $orderby, $query ) {
		if ( ! is_object( $query ) || empty( $query->query_vars['penalis_effective_order'] ) ) {
			return $orderby;
		}

		global $wpdb;
		$posts_table = ( isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->posts ) && is_string( $wpdb->posts ) && '' !== $wpdb->posts )
			? $wpdb->posts
			: 'wp_posts';

		return "CASE WHEN {$posts_table}.post_status = 'publish' AND {$posts_table}.post_date_gmt <> '0000-00-00 00:00:00' THEN {$posts_table}.post_date_gmt ELSE {$posts_table}.post_modified_gmt END DESC, {$posts_table}.ID DESC";
	}

	/**
	 * Return a single owned article (or any article for an administrator reviewer).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_article( $request ) {
		$post = self::get_readable_article( (int) $request->get_param( 'id' ) );

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
						'slug' => $term->slug,
					);
				} else {
					$tags[] = array(
						'id'   => (int) $term->term_id,
						'name' => $term->name,
					);
				}
			}
		}

		$author    = get_userdata( (int) $post->post_author );
		$thumbnail = get_the_post_thumbnail_url( $post, 'large' );
		$review    = self::prepare_article_review( $post );

		return new WP_REST_Response(
			array_merge(
				$review,
				array(
					'title'         => $post->post_title,
					'content'       => $post->post_content,
					'excerpt'       => $post->post_excerpt,
					'authorName'    => $author ? (string) $author->display_name : '',
					'authorLogin'   => $author ? (string) $author->user_login : '',
					'authorUrl'     => $author ? esc_url_raw( get_author_posts_url( $author->ID ) ) : '',
					'date'          => $post->post_date_gmt,
					'modified'      => $post->post_modified_gmt,
					'link'          => 'publish' === $post->post_status ? get_permalink( $post ) : '',
					'views'         => self::get_post_views( $post ),
					'likes'         => self::get_post_likes( $post ),
					'featuredMedia' => (int) get_post_thumbnail_id( $post ),
					'thumbnail'     => $thumbnail ? (string) $thumbnail : '',
					'categories'    => $categories,
					'tags'          => $tags,
				)
			),
			200
		);
	}

	/**
	 * Return the editorial review state and history of a single article.
	 *
	 * Readable only by the owner and administrators.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_article_review( $request ) {
		$post = self::get_readable_article( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return new WP_REST_Response( self::prepare_article_review( $post ), 200 );
	}

	/**
	 * List the articles currently waiting for a reviewer.
	 *
	 * Ordered oldest first: a review queue is worked front to back, so the
	 * article that has waited longest is the one on screen.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_review_queue( $request ) {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page > 0 ? min( 50, $per_page ) : 10;

		$args = array_merge(
			Penalis_Editorial_Workflow::state_query_clauses( Penalis_Editorial_Policy::STATE_PENDING_REVIEW ),
			array(
				'post_type'           => 'post',
				'posts_per_page'      => $per_page,
				'paged'               => $page,
				'orderby'             => 'modified',
				'order'               => 'ASC',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => false,
			)
		);

		$query = new WP_Query( $args );
		$items = array();

		foreach ( $query->posts as $post ) {
			$items[] = self::prepare_review_queue_item( $post );
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
	 * Record a reviewer decision on an article.
	 *
	 * The state machine, the per-post advisory lock, the stale-cycle guard and
	 * the reviewer capability check all live in `Penalis_Editorial_Workflow::apply()`,
	 * which the wp-admin screen calls too. This handler only validates the
	 * request shape and translates the result, so the two entry points cannot
	 * drift apart.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function decide_article( $request ) {
		$post_id  = (int) $request->get_param( 'id' );
		$action   = sanitize_key( (string) $request->get_param( 'action' ) );
		$feedback = sanitize_textarea_field( (string) $request->get_param( 'feedback' ) );
		$cycle    = (int) $request->get_param( 'expected_cycle' );

		if ( ! Penalis_Editorial_Policy::requires_review_cycle( $action ) ) {
			return new WP_Error(
				'penalis_invalid_action',
				'Aksi review tidak dikenal.',
				array( 'status' => 400 )
			);
		}

		$result = Penalis_Editorial_Workflow::apply(
			$post_id,
			$action,
			array(
				'actor_id'       => get_current_user_id(),
				'feedback'       => $feedback,
				'expected_cycle' => $cycle,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$messages = array(
			Penalis_Editorial_Policy::ACTION_APPROVE => 'Karya tulis disetujui dan sudah terbit.',
			Penalis_Editorial_Policy::ACTION_REQUEST_CHANGES => 'Catatan revisi dikirim ke penulis.',
			Penalis_Editorial_Policy::ACTION_REJECT  => 'Karya tulis ditolak dan penulis menerima pemberitahuan.',
		);

		$post = get_post( $post_id );

		return new WP_REST_Response(
			array(
				'message' => isset( $messages[ $action ] ) ? $messages[ $action ] : 'Keputusan review tersimpan.',
				'review'  => $post ? self::prepare_article_review( $post ) : array(),
			),
			200
		);
	}

	/**
	 * Prepare one row of the review queue.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string,mixed>
	 */
	private static function prepare_review_queue_item( $post ) {
		$author    = get_userdata( (int) $post->post_author );
		$submitted = Penalis_Editorial_Workflow::get_submitted_at( $post );
		$state     = Penalis_Editorial_Workflow::get_state( $post );

		return array(
			'id'                  => (int) $post->ID,
			'title'               => $post->post_title,
			'excerpt'             => wp_trim_words(
				wp_strip_all_tags( '' !== $post->post_excerpt ? $post->post_excerpt : $post->post_content ),
				40
			),
			'authorName'          => $author ? (string) $author->display_name : '',
			'authorLogin'         => $author ? (string) $author->user_login : '',
			'submittedAt'         => $submitted > 0 ? gmdate( 'Y-m-d H:i:s', $submitted ) : '',
			'reviewCycle'         => max( 1, Penalis_Editorial_Workflow::get_review_cycle( $post ) ),
			'editorialState'      => $state,
			'editorialStateLabel' => Penalis_Editorial_Policy::state_label( $state ),
		);
	}

	/**
	 * Submit an owned draft article for administrator editorial review.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function submit_article( $request ) {
		return self::apply_writer_editorial_action(
			(int) $request->get_param( 'id' ),
			Penalis_Editorial_Policy::ACTION_SUBMIT,
			'Karya tulis kamu telah di-submit dan sedang menunggu review Senator.'
		);
	}

	/**
	 * Resubmit an owned article after administrator requested changes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function resubmit_article( $request ) {
		return self::apply_writer_editorial_action(
			(int) $request->get_param( 'id' ),
			Penalis_Editorial_Policy::ACTION_RESUBMIT,
			'Revisi karya tulis kamu telah di-submit ulang untuk di-review Senator.'
		);
	}

	/**
	 * Apply a writer submission or resubmission action to an owned article.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $action  Writer workflow action (`submit` or `resubmit`).
	 * @param string $message Success message in Indonesian.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function apply_writer_editorial_action( $post_id, $action, $message ) {
		$post = self::get_owned_article( $post_id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$state = Penalis_Editorial_Workflow::get_state( $post );

		if ( Penalis_Editorial_Policy::can_transition( $state, $action ) ) {
			$completeness_error = self::validate_submission_completeness( $post );

			if ( is_wp_error( $completeness_error ) ) {
				return $completeness_error;
			}
		}

		$result = Penalis_Editorial_Workflow::apply(
			$post,
			$action,
			array(
				'actor_id' => get_current_user_id(),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$updated            = get_post( $post->ID );
		$payload            = self::prepare_article_review( $updated ? $updated : $post );
		$payload['message'] = $message;

		return new WP_REST_Response( $payload, 200 );
	}

	/**
	 * Verify that an article has all required editorial fields before submission.
	 *
	 * Drafts may be saved incomplete; only submitting or resubmitting for review
	 * requires title, content, featured image, a non-uncategorized category, and
	 * at least one tag.
	 *
	 * @param WP_Post $post Article post.
	 * @return true|WP_Error
	 */
	private static function validate_submission_completeness( $post ) {
		$field_errors = array();
		$missing      = array();

		$title   = trim( wp_strip_all_tags( (string) $post->post_title ) );
		$content = trim( wp_strip_all_tags( (string) $post->post_content ) );

		if ( '' === $title ) {
			$field_errors['title'] = 'Judul karya tulis wajib diisi.';
			$missing[]             = 'judul';
		}

		if ( '' === $content ) {
			$field_errors['content'] = 'Isi karya tulis wajib diisi.';
			$missing[]               = 'isi karya tulis';
		}

		$editorial = Penalis_Config::editorial();

		if ( ! empty( $editorial['require_thumbnail'] ) && (int) get_post_thumbnail_id( $post->ID ) <= 0 ) {
			$field_errors['featuredMedia'] = 'Pilih thumbnail karya tulis terlebih dahulu.';
			$missing[]                     = 'thumbnail';
		}

		$terms = wp_get_post_terms( $post->ID, array( 'category', 'post_tag' ), array( 'fields' => 'all' ) );
		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}

		$valid_categories = 0;
		$tag_count        = 0;

		foreach ( $terms as $term ) {
			if ( 'category' === $term->taxonomy ) {
				$slug = strtolower( trim( (string) $term->slug ) );
				$name = strtolower( trim( (string) $term->name ) );
				if ( 'uncategorized' !== $slug && 'uncategorized' !== $name ) {
					++$valid_categories;
				}
			} elseif ( 'post_tag' === $term->taxonomy ) {
				++$tag_count;
			}
		}

		if ( ! empty( $editorial['require_category'] ) && 0 === $valid_categories ) {
			$field_errors['categories'] = 'Pilih minimal satu kategori karya tulis.';
			$missing[]                  = 'kategori';
		}

		$min_tags = (int) $editorial['min_tags'];
		if ( $min_tags > 0 && $tag_count < $min_tags ) {
			$field_errors['tags'] = 1 === $min_tags
				? 'Tambahkan minimal satu tag karya tulis.'
				: sprintf( 'Tambahkan minimal %d tag karya tulis.', $min_tags );
			$missing[]            = sprintf( 'minimal %d tag', $min_tags );
		}

		if ( empty( $field_errors ) ) {
			return true;
		}

		return new WP_Error(
			'penalis_editorial_incomplete',
			'Lengkapi ' . implode( ', ', $missing ) . ' sebelum di-submit untuk di-review.',
			array(
				'status'      => 400,
				'fieldErrors' => $field_errors,
			)
		);
	}

	/**
	 * Refuse the retired writer publish/unpublish endpoints.
	 *
	 * Publication is an administrator-only workflow action in V2. The routes
	 * stay registered so an older dashboard bundle, a bookmark or a direct
	 * request receives an explicit refusal instead of a confusing 404, and so
	 * the refusal is auditable in one place. They never touch the post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Error
	 */
	public static function reject_legacy_publish( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Route callback signature.
		return new WP_Error(
			'penalis_publish_forbidden',
			'Penerbitan karya tulis dilakukan oleh Senator melalui alur review karya tulis.',
			array( 'status' => 403 )
		);
	}

	/**
	 * List the signed-in user's bounded dashboard notifications.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_notifications() {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return new WP_Error(
				'penalis_not_authenticated',
				'Kamu harus login terlebih dahulu.',
				array( 'status' => 401 )
			);
		}

		$items = Penalis_Editorial_Notifications::get_for_user( $user_id );

		return new WP_REST_Response(
			array(
				'items'       => $items,
				'unreadCount' => Penalis_Editorial_Notifications::count_unread( $user_id ),
			),
			200
		);
	}

	/**
	 * Mark one notification or all notifications as read for the signed-in user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function mark_notifications_read( $request ) {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return new WP_Error(
				'penalis_not_authenticated',
				'Kamu harus login terlebih dahulu.',
				array( 'status' => 401 )
			);
		}

		$raw_id          = $request->get_param( 'id' );
		$notification_id = null !== $raw_id ? sanitize_text_field( (string) $raw_id ) : '';
		$result          = Penalis_Editorial_Notifications::mark_read( $user_id, $notification_id );

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Save the signed-in user's per-action notification channel preferences.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_notification_preferences( $request ) {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return new WP_Error(
				'penalis_not_authenticated',
				'Kamu harus login terlebih dahulu.',
				array( 'status' => 401 )
			);
		}

		$raw = array(
			'dashboard' => $request->get_param( 'dashboard' ),
			'email'     => $request->get_param( 'email' ),
		);

		$preferences = Penalis_Editorial_Notifications::update_preferences( $user_id, $raw );

		return new WP_REST_Response(
			array(
				'preferences' => $preferences,
				'message'     => 'Preferensi notifikasi disimpan.',
			),
			200
		);
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

		$attachment_ids = array();
		foreach ( $query->posts as $attachment ) {
			$attachment_ids[] = (int) $attachment->ID;
		}

		$usage_map = Penalis_Media_Usage::summarize_for_user( $attachment_ids, $user_id );

		$items = array();
		foreach ( $query->posts as $attachment ) {
			$att_id  = (int) $attachment->ID;
			$usage   = isset( $usage_map[ $att_id ] ) ? $usage_map[ $att_id ] : Penalis_Media_Usage::empty_summary();
			$items[] = self::prepare_media( $attachment, $usage );
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
	 * Permanently delete an unused attachment owned by the current writer.
	 *
	 * Refuses with `409 penalis_media_in_use` when the attachment is referenced
	 * by any active article (published, pending, or draft) or avatar.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_media( $request ) {
		$user_id = get_current_user_id();

		if ( ! Penalis_Rate_Limiter::guard( 'media_delete', (string) $user_id ) ) {
			return new WP_Error(
				'penalis_rate_limited',
				'Terlalu banyak permintaan menghapus media. Silakan coba lagi nanti.',
				array( 'status' => 429 )
			);
		}
		Penalis_Rate_Limiter::register( 'media_delete', (string) $user_id );

		$attachment_id = (int) $request->get_param( 'id' );
		$attachment    = $attachment_id > 0 ? get_post( $attachment_id ) : null;

		if ( ! $attachment || 'attachment' !== $attachment->post_type || (int) $attachment->post_author !== $user_id ) {
			return new WP_Error(
				'penalis_not_found',
				'Gambar tidak ditemukan.',
				array( 'status' => 404 )
			);
		}

		$usage = Penalis_Media_Usage::inspect( $attachment_id, $user_id );
		if ( $usage['inUse'] ) {
			return new WP_Error(
				'penalis_media_in_use',
				$usage['reason'],
				array(
					'status' => 409,
					'usedIn' => $usage['usedIn'],
				)
			);
		}

		$deleted = wp_delete_attachment( $attachment_id, true );
		if ( false === $deleted || null === $deleted ) {
			return new WP_Error(
				'penalis_media_delete_failed',
				'Gambar tidak dapat dihapus. Silakan coba lagi.',
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'deleted' => true,
				'id'      => $attachment_id,
				'message' => 'Gambar telah dihapus.',
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

		if ( ! Penalis_Rate_Limiter::guard( 'tag_create', (string) $user_id ) ) {
			return new WP_Error(
				'penalis_rate_limited',
				'Terlalu banyak tag dibuat. Silakan coba lagi nanti.',
				array( 'status' => 429 )
			);
		}

		$name        = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$name        = trim( $name );
		$name_length = function_exists( 'mb_strlen' ) ? mb_strlen( $name, 'UTF-8' ) : strlen( $name );

		if ( '' === $name || $name_length > 50 ) {
			return new WP_Error(
				'penalis_tag_invalid',
				'Berikan nama tag maksimal 50 karakter.',
				array( 'status' => 400 )
			);
		}

		Penalis_Rate_Limiter::register( 'tag_create', (string) $user_id );

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
				'Tag tidak dapat dibuat.',
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
		return self::get_readable_article( $post_id, false );
	}

	/**
	 * Fetch an article readable in the dashboard by the owner or (optionally) a reviewer.
	 *
	 * @param int  $post_id        Post ID.
	 * @param bool $allow_reviewer Whether reviewers (`edit_others_posts`) may access non-owned articles.
	 * @return WP_Post|WP_Error
	 */
	private static function get_readable_article( $post_id, $allow_reviewer = true ) {
		$user_id = get_current_user_id();
		$post    = get_post( (int) $post_id );

		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error(
				'penalis_not_found',
				'Karya tulis tidak ditemukan.',
				array( 'status' => 404 )
			);
		}

		$is_owner = (int) $post->post_author === (int) $user_id;

		if ( ! $is_owner && ( ! $allow_reviewer || ! Penalis_Editorial_Workflow::can_review( $user_id ) ) ) {
			// Return 404 rather than 403 to avoid disclosing the existence of
			// another writer's article.
			return new WP_Error(
				'penalis_not_found',
				'Karya tulis tidak ditemukan.',
				array( 'status' => 404 )
			);
		}

		return $post;
	}

	/**
	 * Prepare shared editorial state, capability flags, and timestamps for an article.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string,mixed>
	 */
	private static function prepare_editorial_fields( $post ) {
		$user_id      = get_current_user_id();
		$is_owner     = (int) $post->post_author === (int) $user_id;
		$state        = Penalis_Editorial_Workflow::get_state( $post );
		$submitted_at = Penalis_Editorial_Workflow::get_submitted_at( $post );
		$reviewed_at  = Penalis_Editorial_Workflow::get_reviewed_at( $post );

		return array(
			'id'                  => (int) $post->ID,
			'status'              => (string) $post->post_status,
			'editorialState'      => $state,
			'editorialStateLabel' => Penalis_Editorial_Policy::state_label( $state ),
			'canEdit'             => Penalis_Editorial_Workflow::can_writer_edit( $post, $user_id ),
			'canSubmit'           => $is_owner && Penalis_Editorial_Policy::can_transition( $state, Penalis_Editorial_Policy::ACTION_SUBMIT ),
			'canResubmit'         => $is_owner && Penalis_Editorial_Policy::can_transition( $state, Penalis_Editorial_Policy::ACTION_RESUBMIT ),
			'feedback'            => Penalis_Editorial_Workflow::get_feedback( $post ),
			'reviewCycle'         => Penalis_Editorial_Workflow::get_review_cycle( $post ),
			'submittedAt'         => $submitted_at > 0 ? gmdate( 'Y-m-d H:i:s', $submitted_at ) : '',
			'reviewedAt'          => $reviewed_at > 0 ? gmdate( 'Y-m-d H:i:s', $reviewed_at ) : '',
		);
	}

	/**
	 * Prepare a small article summary.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string,mixed>
	 */
	private static function prepare_article_summary( $post ) {
		$thumbnail = get_the_post_thumbnail_url( $post, 'thumbnail' );
		$cover_raw = get_the_post_thumbnail_url( $post, 'large' );
		$category  = Penalis_Author_Archive::get_primary_category( (int) $post->ID );

		return array_merge(
			self::prepare_editorial_fields( $post ),
			array(
				'title'          => $post->post_title,
				'excerpt'        => Penalis_Article_Share::clean_excerpt( $post, 30 ),
				'date'           => $post->post_date_gmt,
				'modified'       => $post->post_modified_gmt,
				'publishedLabel' => 'publish' === $post->post_status && '' !== (string) $post->post_date_gmt
					? Penalis_Datetime::format_gmt( (string) $post->post_date_gmt, 'd/m/Y' )
					: '',
				'category'       => $category instanceof WP_Term
					? html_entity_decode( (string) $category->name, ENT_QUOTES, 'UTF-8' )
					: '',
				'link'           => 'publish' === $post->post_status ? get_permalink( $post ) : '',
				'views'          => self::get_post_views( $post ),
				'likes'          => self::get_post_likes( $post ),
				'editUrl'        => Penalis_Dashboard::dashboard_url(
					array(
						'view' => 'editor',
						'id'   => (int) $post->ID,
					)
				),
				'thumbnail'      => $thumbnail ? $thumbnail : '',
				'coverUrl'       => is_string( $cover_raw ) && '' !== $cover_raw
					? Penalis_Dashboard::ensure_same_origin_url( $cover_raw )
					: '',
			)
		);
	}

	/**
	 * Prepare the editorial review state and history payload for a single article.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string,mixed>
	 */
	private static function prepare_article_review( $post ) {
		return array_merge(
			self::prepare_editorial_fields( $post ),
			array(
				'reviewHistory' => self::prepare_review_history( $post ),
			)
		);
	}

	/**
	 * Prepare bounded review history entries in reverse chronological order.
	 *
	 * @param WP_Post $post Post.
	 * @return array<int,array<string,mixed>>
	 */
	private static function prepare_review_history( $post ) {
		$history = Penalis_Editorial_Workflow::get_history( $post );
		$items   = array();

		foreach ( array_reverse( $history ) as $entry ) {
			$action     = isset( $entry['action'] ) ? (string) $entry['action'] : '';
			$actor_id   = isset( $entry['actor_id'] ) ? (int) $entry['actor_id'] : 0;
			$actor      = $actor_id > 0 ? get_userdata( $actor_id ) : null;
			$created_at = isset( $entry['created_at'] ) ? (int) $entry['created_at'] : 0;

			if ( $actor && '' !== (string) $actor->display_name ) {
				$actor_name = (string) $actor->display_name;
			} elseif ( Penalis_Editorial_Policy::is_writer_action( $action ) ) {
				$actor_name = 'Penulis';
			} else {
				$actor_name = 'Senator Penalis';
			}

			$items[] = array(
				'action'      => $action,
				'actionLabel' => Penalis_Editorial_Policy::action_label( $action ),
				'actorName'   => $actor_name,
				'createdAt'   => $created_at > 0 ? gmdate( 'Y-m-d H:i:s', $created_at ) : '',
				'reviewCycle' => isset( $entry['review_cycle'] ) ? (int) $entry['review_cycle'] : 0,
				'feedback'    => isset( $entry['feedback'] ) ? (string) $entry['feedback'] : '',
			);
		}

		return $items;
	}

	/**
	 * Prepare a media item.
	 *
	 * @param WP_Post                  $attachment Attachment post.
	 * @param array<string,mixed>|null $usage      Optional usage summary from `Penalis_Media_Usage`.
	 * @return array<string,mixed>
	 */
	private static function prepare_media( $attachment, $usage = null ) {
		$thumb = wp_get_attachment_image_src( $attachment->ID, 'thumbnail' );
		$full  = wp_get_attachment_image_src( $attachment->ID, 'full' );
		$info  = is_array( $usage ) ? $usage : Penalis_Media_Usage::empty_summary();

		return array(
			'id'                  => (int) $attachment->ID,
			'title'               => $attachment->post_title,
			'mime'                => $attachment->post_mime_type,
			'url'                 => wp_get_attachment_url( $attachment->ID ),
			'thumbnail'           => $thumb ? $thumb[0] : '',
			'full'                => $full ? $full[0] : '',
			'date'                => $attachment->post_date_gmt,
			'inUse'               => ! empty( $info['inUse'] ),
			'canDelete'           => isset( $info['canDelete'] ) ? (bool) $info['canDelete'] : empty( $info['inUse'] ),
			'usageCount'          => isset( $info['usageCount'] ) ? (int) $info['usageCount'] : 0,
			'usedIn'              => isset( $info['usedIn'] ) && is_array( $info['usedIn'] ) ? $info['usedIn'] : array(),
			'deleteBlockedReason' => isset( $info['reason'] ) ? (string) $info['reason'] : '',
		);
	}

	/**
	 * Article counts for the current user across post statuses and V2 editorial states.
	 *
	 * @param int $user_id User ID.
	 * @return array<string,int>
	 */
	private static function article_counts( $user_id ) {
		$counts = array(
			'all'               => 0,
			'publish'           => 0,
			'draft'             => 0,
			'pending'           => 0,
			'changes_requested' => 0,
			'rejected'          => 0,
			'views'             => 0,
			'likes'             => 0,
		);

		$raw_drafts = 0;

		foreach ( array( 'publish', 'draft', 'pending' ) as $status ) {
			$found = Penalis_Editorial_Workflow::count_matching_posts(
				array(
					'post_type'   => 'post',
					'post_status' => $status,
					'author'      => (int) $user_id,
				)
			);

			if ( 'draft' === $status ) {
				$raw_drafts = $found;
			} else {
				$counts[ $status ] = $found;
			}

			$counts['all'] += $found;
		}

		foreach (
			array(
				Penalis_Editorial_Policy::STATE_CHANGES_REQUESTED,
				Penalis_Editorial_Policy::STATE_REJECTED,
			) as $editorial_state
		) {
			$counts[ $editorial_state ] = Penalis_Editorial_Workflow::count_matching_posts(
				self::editorial_query_args( $user_id, $editorial_state, 1, 1 )
			);
		}

		$counts['draft'] = max(
			0,
			$raw_drafts - $counts['changes_requested'] - $counts['rejected']
		);

		$counts['views'] = self::author_total_views( (int) $user_id );
		$counts['likes'] = class_exists( 'Penalis_Reactions' )
			? Penalis_Reactions::get_author_total_likes( (int) $user_id )
			: 0;

		return $counts;
	}

	/**
	 * Return the like count for a single published post.
	 *
	 * Unpublished articles always report 0 likes.
	 *
	 * @param WP_Post|int $post Post object or ID.
	 * @return int
	 */
	private static function get_post_likes( $post ) {
		$post_obj = $post instanceof WP_Post ? $post : get_post( (int) $post );
		if ( ! $post_obj || 'publish' !== $post_obj->post_status || ! class_exists( 'Penalis_Reactions' ) ) {
			return 0;
		}

		return Penalis_Reactions::get_like_count( (int) $post_obj->ID );
	}

	/**
	 * Return the lifetime view count for a single published post.
	 *
	 * Unpublished articles always report 0 views.
	 *
	 * @param WP_Post|int $post Post or post ID.
	 * @return int
	 */
	private static function get_post_views( $post ) {
		$post_obj = $post instanceof WP_Post ? $post : get_post( (int) $post );

		if ( ! $post_obj || (int) $post_obj->ID <= 0 || 'publish' !== $post_obj->post_status ) {
			return 0;
		}

		if ( class_exists( 'Penalis_Views' ) ) {
			return Penalis_Views::get_views( (int) $post_obj->ID );
		}

		if ( function_exists( 'pvc_get_post_views' ) ) {
			return max( 0, (int) pvc_get_post_views( (int) $post_obj->ID ) );
		}

		return 0;
	}

	/**
	 * Return the total lifetime views across all published posts owned by a user.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	private static function author_total_views( $user_id ) {
		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return 0;
		}

		if ( class_exists( 'Penalis_Views' ) ) {
			return Penalis_Views::get_author_total_views( $user_id );
		}

		return 0;
	}

	/**
	 * Return the public authors directory.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_public_authors( $request ) {
		$result = Penalis_Authors::query_authors(
			array(
				'search'   => $request->get_param( 'search' ),
				'sort'     => $request->get_param( 'sort' ),
				'verified' => $request->get_param( 'verified' ),
				'senator'  => $request->get_param( 'senator' ),
				'page'     => $request->get_param( 'page' ),
				'per_page' => $request->get_param( 'per_page' ),
			)
		);

		return rest_ensure_response( $result );
	}

	/**
	 * Return the public #KalimatHariIni showcase feed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_public_quotes( $request ) {
		$result = Penalis_Daily_Quotes::query_quotes(
			array(
				'search'   => $request->get_param( 'search' ),
				'sort'     => $request->get_param( 'sort' ),
				'page'     => $request->get_param( 'page' ),
				'per_page' => $request->get_param( 'per_page' ),
			)
		);

		return rest_ensure_response( $result );
	}

	/**
	 * Return the signed-in active writer's #KalimatHariIni state and 24-hour quota.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_my_quote() {
		return rest_ensure_response(
			Penalis_Daily_Quotes::get_writer_state( get_current_user_id() )
		);
	}

	/**
	 * Publish a new #KalimatHariIni for the signed-in active writer.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_publish_quote( $request ) {
		$result = Penalis_Daily_Quotes::publish(
			get_current_user_id(),
			(string) $request->get_param( 'text' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 201 );
	}

	/**
	 * Delete the signed-in active writer's active #KalimatHariIni.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_delete_my_quote() {
		$result = Penalis_Daily_Quotes::delete_for_user( get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Remove a writer's active #KalimatHariIni as a Senator (reviewer moderation).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_moderate_delete_quote( $request ) {
		$target_user_id = (int) $request->get_param( 'user_id' );
		$reason         = (string) $request->get_param( 'reason' );
		$result         = Penalis_Daily_Quotes::moderate_delete( $target_user_id, $reason );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
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

		$route = (string) $request->get_route();

		if ( 0 !== strpos( $route, '/' . self::NAMESPACE_V1 ) ) {
			return $response;
		}

		if ( '/' . self::NAMESPACE_V1 . '/authors' === $route && 'random' !== $request->get_param( 'sort' ) ) {
			$response->header( 'Cache-Control', 'public, max-age=120' );
			return $response;
		}

		if (
			'/' . self::NAMESPACE_V1 . '/quotes' === $route &&
			'GET' === strtoupper( (string) $request->get_method() ) &&
			! is_user_logged_in() &&
			'random' !== $request->get_param( 'sort' )
		) {
			$response->header( 'Cache-Control', 'public, max-age=30' );
			return $response;
		}

		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, private, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'X-Robots-Tag', 'noindex, nofollow' );

		return $response;
	}
}
