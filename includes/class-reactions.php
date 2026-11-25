<?php
/**
 * Built-in post reactions (Like & Dislike) with legacy Posts Like Dislike compatibility.
 *
 * Replaces the external `posts-like-dislike` plugin using a cache-safe REST
 * endpoint, per-IP rate limiting, atomic MySQL advisory locks, and seamless
 * non-destructive fallback to existing `pld_like_count` / `pld_dislike_count`
 * post meta and `pld_{post_id}` visitor cookies.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages published post reactions (like / dislike) and frontend controls.
 */
final class Penalis_Reactions {

	public const META_LIKE            = '_penalis_like_count';
	public const META_DISLIKE         = '_penalis_dislike_count';
	public const LEGACY_META_LIKE     = 'pld_like_count';
	public const LEGACY_META_DISLIKE  = 'pld_dislike_count';
	public const COOKIE_PREFIX        = 'penalis_react_';
	public const LEGACY_COOKIE_PREFIX = 'pld_';
	public const COOKIE_NONE          = 'none';
	public const COOKIE_TTL           = 31536000;
	public const TYPE_LIKE            = 'like';
	public const TYPE_DISLIKE         = 'dislike';
	public const SHORTCODE            = 'penalis_reactions';
	public const LEGACY_SHORTCODE     = 'posts_like_dislike';
	public const SCRIPT_HANDLE        = 'penalis-post-reactions';
	public const EDITOR_SCRIPT_HANDLE = 'penalis-admin-reactions-editor';
	public const QUICK_EDIT_HANDLE    = 'penalis-admin-reactions-quick-edit';
	public const ADMIN_COLUMN_KEY     = 'penalis_reactions';
	public const META_BOX_NONCE       = 'penalis_save_reactions_meta';

	/**
	 * Whether reaction assets have been enqueued during this request.
	 *
	 * @var bool
	 */
	private static $assets_enqueued = false;

	/**
	 * Register hooks, REST routes, and shortcodes.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_shortcode' ) );
		add_action( 'init', array( __CLASS__, 'maybe_register_legacy_shortcode' ), 20 );

		if ( is_admin() ) {
			add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_editor_assets' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_quick_edit_assets' ) );
			add_action( 'add_meta_boxes_post', array( __CLASS__, 'register_classic_meta_box' ) );
			add_action( 'save_post_post', array( __CLASS__, 'save_classic_meta_box' ), 10, 2 );
			add_filter( 'manage_post_posts_columns', array( __CLASS__, 'register_posts_column' ) );
			add_action( 'manage_post_posts_custom_column', array( __CLASS__, 'render_posts_column' ), 10, 2 );
			add_action( 'quick_edit_custom_box', array( __CLASS__, 'render_quick_edit_fields' ), 10, 2 );
		}
	}

	/**
	 * Whether the current user may manually override post reaction counts in wp-admin.
	 *
	 * Restricted to administrators (`manage_options`).
	 *
	 * @return bool
	 */
	public static function can_admin_edit() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Reset per-request state (used by unit tests).
	 *
	 * @return void
	 */
	public static function reset_state() {
		self::$assets_enqueued = false;
	}

	/**
	 * Register `[posts_like_dislike]` as a compatibility alias when the legacy
	 * Posts Like Dislike plugin is no longer active.
	 *
	 * @return void
	 */
	public static function maybe_register_legacy_shortcode() {
		if ( ! shortcode_exists( self::LEGACY_SHORTCODE ) ) {
			add_shortcode( self::LEGACY_SHORTCODE, array( __CLASS__, 'render_shortcode' ) );
		}
	}

	/**
	 * Register public REST endpoints for reading and toggling post reactions.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			Penalis_Rest_Api::NAMESPACE_V1,
			'/posts/(?P<id>\d+)/reaction',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'handle_get_reaction' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'id' => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'handle_post_reaction' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'id'   => array(
							'type'     => 'integer',
							'required' => true,
						),
						'type' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'handle_admin_update_reaction' ),
					'permission_callback' => array( __CLASS__, 'can_admin_edit' ),
					'args'                => array(
						'id'      => array(
							'type'     => 'integer',
							'required' => true,
						),
						'like'    => array(
							'type'     => 'integer',
							'required' => true,
						),
						'dislike' => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Whether a post is eligible for public reactions.
	 *
	 * Only published `post` records accept reactions. Drafts, pending reviews,
	 * trashed posts, and pages are excluded.
	 *
	 * @param WP_Post|int|null $post Post object or ID.
	 * @return WP_Post|null Eligible post object, or null otherwise.
	 */
	public static function resolve_eligible_post( $post ) {
		$post_obj = $post instanceof WP_Post ? $post : get_post( (int) $post );

		if ( ! ( $post_obj instanceof WP_Post ) ) {
			return null;
		}

		if ( 'post' !== $post_obj->post_type || 'publish' !== $post_obj->post_status ) {
			return null;
		}

		return $post_obj;
	}

	/**
	 * Normalize a candidate reaction type.
	 *
	 * @param mixed $type Raw reaction value.
	 * @return string `'like'`, `'dislike'`, or `''`.
	 */
	public static function normalize_type( $type ) {
		$clean = is_scalar( $type ) ? strtolower( trim( (string) $type ) ) : '';

		if ( self::TYPE_LIKE === $clean || self::TYPE_DISLIKE === $clean ) {
			return $clean;
		}

		return '';
	}

	/**
	 * Return the current like and dislike counts for a post, seamlessly falling
	 * back to legacy `pld_like_count` / `pld_dislike_count` post meta when
	 * Penalis-native meta keys have not yet been written for the post.
	 *
	 * @param int $post_id Post ID.
	 * @return array{like:int,dislike:int}
	 */
	public static function get_counts( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return array(
				'like'    => 0,
				'dislike' => 0,
			);
		}

		return array(
			'like'    => self::read_count_with_legacy( $post_id, self::META_LIKE, self::LEGACY_META_LIKE ),
			'dislike' => self::read_count_with_legacy( $post_id, self::META_DISLIKE, self::LEGACY_META_DISLIKE ),
		);
	}

	/**
	 * Return the like count for a single post (with legacy PLD fallback).
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function get_like_count( $post_id ) {
		return self::get_counts( (int) $post_id )['like'];
	}

	/**
	 * Return the total lifetime likes across all published posts owned by an author.
	 *
	 * Uses one indexed postmeta join and resolves Penalis-native `_penalis_like_count`
	 * ahead of legacy `pld_like_count` per post in PHP so duplicate legacy meta rows
	 * can never double-count and stored `0` values override legacy counts cleanly.
	 *
	 * @param int $user_id Author user ID.
	 * @return int
	 */
	public static function get_author_total_likes( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return 0;
		}

		global $wpdb;

		if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_results' ) && ! empty( $wpdb->posts ) && ! empty( $wpdb->postmeta ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Single indexed aggregate query per author archive/dashboard load.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT pm.post_id, pm.meta_key, pm.meta_value
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
					WHERE p.post_type = 'post'
						AND p.post_status = 'publish'
						AND p.post_author = %d
						AND pm.meta_key IN (%s, %s)
					ORDER BY pm.meta_id ASC",
					$user_id,
					self::META_LIKE,
					self::LEGACY_META_LIKE
				)
			);

			if ( ! is_array( $rows ) || empty( $rows ) ) {
				return 0;
			}

			$primary = array();
			$legacy  = array();

			foreach ( $rows as $row ) {
				$pid = isset( $row->post_id ) ? (int) $row->post_id : 0;
				if ( $pid <= 0 ) {
					continue;
				}
				$key = isset( $row->meta_key ) ? (string) $row->meta_key : '';
				$val = isset( $row->meta_value ) ? max( 0, (int) $row->meta_value ) : 0;

				if ( self::META_LIKE === $key && ! array_key_exists( $pid, $primary ) ) {
					$primary[ $pid ] = $val;
				} elseif ( self::LEGACY_META_LIKE === $key && ! array_key_exists( $pid, $legacy ) ) {
					$legacy[ $pid ] = $val;
				}
			}

			$total    = 0;
			$post_ids = array_unique( array_merge( array_keys( $primary ), array_keys( $legacy ) ) );
			foreach ( $post_ids as $pid ) {
				$total += array_key_exists( $pid, $primary ) ? $primary[ $pid ] : $legacy[ $pid ];
			}

			return $total;
		}

		// Plain-PHP test stub fallback.
		$total = 0;
		$posts = isset( $GLOBALS['penalis_test_posts'] ) && is_array( $GLOBALS['penalis_test_posts'] )
			? $GLOBALS['penalis_test_posts']
			: array();

		foreach ( $posts as $post ) {
			if (
				$post instanceof WP_Post
				&& 'post' === $post->post_type
				&& 'publish' === $post->post_status
				&& (int) $post->post_author === $user_id
			) {
				$total += self::get_like_count( (int) $post->ID );
			}
		}

		return $total;
	}

	/**
	 * Read a primary reaction counter meta key, falling back to its legacy PLD
	 * counterpart only when the primary meta key has never been recorded.
	 *
	 * Checking the non-single meta array distinguishes an explicit stored `0`
	 * (e.g. after undoing the last like) from a missing meta key.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $primary_key Penalis meta key.
	 * @param string $legacy_key  Legacy PLD meta key.
	 * @return int
	 */
	private static function read_count_with_legacy( $post_id, $primary_key, $legacy_key ) {
		$primary_values = get_post_meta( $post_id, $primary_key, false );

		if ( is_array( $primary_values ) && ! empty( $primary_values ) ) {
			return max( 0, (int) reset( $primary_values ) );
		}

		$legacy_raw = get_post_meta( $post_id, $legacy_key, true );

		return max( 0, (int) $legacy_raw );
	}

	/**
	 * Read a visitor's existing reaction for a post from cookies.
	 *
	 * The Penalis cookie (`penalis_react_{id}`) always takes precedence over the
	 * legacy PLD cookie (`pld_{id}`). An explicit `'none'` marker prevents a
	 * stale legacy cookie on another path from re-activating after an undo.
	 *
	 * @param int                      $post_id Post ID.
	 * @param array<string,mixed>|null $cookies Optional cookie map (defaults to `$_COOKIE`).
	 * @return string `'like'`, `'dislike'`, or `''`.
	 */
	public static function read_cookie_reaction( $post_id, $cookies = null ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return '';
		}

		if ( null === $cookies ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below via strict allowlist.
			$cookies = isset( $_COOKIE ) && is_array( $_COOKIE ) ? $_COOKIE : array();
		}

		$primary_name = self::COOKIE_PREFIX . $post_id;
		if ( isset( $cookies[ $primary_name ] ) && is_scalar( $cookies[ $primary_name ] ) ) {
			$raw = strtolower( trim( (string) $cookies[ $primary_name ] ) );
			if ( self::COOKIE_NONE === $raw ) {
				return '';
			}

			return self::normalize_type( $raw );
		}

		$legacy_name = self::LEGACY_COOKIE_PREFIX . $post_id;
		if ( isset( $cookies[ $legacy_name ] ) && is_scalar( $cookies[ $legacy_name ] ) ) {
			return self::normalize_type( (string) $cookies[ $legacy_name ] );
		}

		return '';
	}

	/**
	 * Pure state transition for a reaction click.
	 *
	 * Supports initial reaction, undo (clicking the active button again), and
	 * direct switch (like -> dislike or dislike -> like).
	 *
	 * @param string $current   Current reaction (`''`, `'like'`, `'dislike'`).
	 * @param string $requested Clicked reaction (`'like'` or `'dislike'`).
	 * @return array{next:string,likeDelta:int,dislikeDelta:int}
	 */
	public static function compute_transition( $current, $requested ) {
		$current   = self::normalize_type( $current );
		$requested = self::normalize_type( $requested );

		if ( '' === $requested ) {
			return array(
				'next'         => $current,
				'likeDelta'    => 0,
				'dislikeDelta' => 0,
			);
		}

		if ( $current === $requested ) {
			return array(
				'next'         => '',
				'likeDelta'    => self::TYPE_LIKE === $requested ? -1 : 0,
				'dislikeDelta' => self::TYPE_DISLIKE === $requested ? -1 : 0,
			);
		}

		$like_delta    = 0;
		$dislike_delta = 0;

		if ( self::TYPE_LIKE === $current ) {
			--$like_delta;
		} elseif ( self::TYPE_DISLIKE === $current ) {
			--$dislike_delta;
		}

		if ( self::TYPE_LIKE === $requested ) {
			++$like_delta;
		} elseif ( self::TYPE_DISLIKE === $requested ) {
			++$dislike_delta;
		}

		return array(
			'next'         => $requested,
			'likeDelta'    => $like_delta,
			'dislikeDelta' => $dislike_delta,
		);
	}

	/**
	 * Apply a reaction click atomically to a published post.
	 *
	 * Runs inside a per-post MySQL advisory lock so concurrent clicks cannot
	 * clobber each other, and persists both Penalis-native meta keys on first
	 * write so legacy PLD counts carry forward without loss.
	 *
	 * @param int    $post_id          Post ID.
	 * @param string $requested        Clicked reaction (`'like'` or `'dislike'`).
	 * @param string $current_reaction Visitor's current reaction from cookie.
	 * @return array{postId:int,like:int,dislike:int,reaction:string}|WP_Error
	 */
	public static function apply_reaction( $post_id, $requested, $current_reaction = '' ) {
		$post = self::resolve_eligible_post( $post_id );
		if ( ! $post ) {
			return new WP_Error(
				'penalis_not_found',
				'Karya tulis tidak ditemukan.',
				array( 'status' => 404 )
			);
		}

		$requested = self::normalize_type( $requested );
		if ( '' === $requested ) {
			return new WP_Error(
				'penalis_invalid_reaction',
				'Jenis reaksi tidak valid.',
				array( 'status' => 400 )
			);
		}

		$transition = self::compute_transition( $current_reaction, $requested );
		$target_id  = (int) $post->ID;

		return Penalis_Account_Status::with_advisory_lock(
			'penalis_react_' . $target_id,
			5,
			static function () use ( $target_id, $transition ) {
				$counts       = self::get_counts( $target_id );
				$next_like    = max( 0, $counts['like'] + $transition['likeDelta'] );
				$next_dislike = max( 0, $counts['dislike'] + $transition['dislikeDelta'] );

				update_post_meta( $target_id, self::META_LIKE, $next_like );
				update_post_meta( $target_id, self::META_DISLIKE, $next_dislike );

				return array(
					'postId'   => $target_id,
					'like'     => $next_like,
					'dislike'  => $next_dislike,
					'reaction' => $transition['next'],
				);
			},
			new WP_Error(
				'penalis_busy',
				'Permintaan sedang padat. Silakan coba lagi sebentar.',
				array( 'status' => 503 )
			),
			true
		);
	}

	/**
	 * Atomically set explicit like and dislike counts on a `post` (admin override).
	 *
	 * Runs inside the same per-post MySQL advisory lock as public clicks so manual
	 * corrections cannot race with live visitor reactions.
	 *
	 * @param int $post_id Post ID.
	 * @param int $like    Non-negative like count.
	 * @param int $dislike Non-negative dislike count.
	 * @return array{postId:int,like:int,dislike:int}|WP_Error
	 */
	public static function set_counts( $post_id, $like, $dislike ) {
		$post = get_post( (int) $post_id );
		if ( ! ( $post instanceof WP_Post ) || 'post' !== $post->post_type ) {
			return new WP_Error(
				'penalis_not_found',
				'Karya tulis tidak ditemukan.',
				array( 'status' => 404 )
			);
		}

		$target_id    = (int) $post->ID;
		$next_like    = max( 0, (int) $like );
		$next_dislike = max( 0, (int) $dislike );

		return Penalis_Account_Status::with_advisory_lock(
			'penalis_react_' . $target_id,
			5,
			static function () use ( $target_id, $next_like, $next_dislike ) {
				update_post_meta( $target_id, self::META_LIKE, $next_like );
				update_post_meta( $target_id, self::META_DISLIKE, $next_dislike );

				return array(
					'postId'  => $target_id,
					'like'    => $next_like,
					'dislike' => $next_dislike,
				);
			},
			new WP_Error(
				'penalis_busy',
				'Permintaan sedang padat. Silakan coba lagi sebentar.',
				array( 'status' => 503 )
			),
			true
		);
	}

	/**
	 * Handle `PUT /penalis/v1/posts/{id}/reaction` (administrator override).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_admin_update_reaction( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$like    = (int) $request->get_param( 'like' );
		$dislike = (int) $request->get_param( 'dislike' );

		if ( $like < 0 || $dislike < 0 ) {
			return new WP_Error(
				'penalis_invalid_reaction_count',
				'Jumlah Likes dan Dislikes harus bernilai 0 atau lebih.',
				array( 'status' => 400 )
			);
		}

		$outcome = self::set_counts( $post_id, $like, $dislike );
		if ( is_wp_error( $outcome ) ) {
			return $outcome;
		}

		return new WP_REST_Response( $outcome, 200 );
	}

	/**
	 * Enqueue Block Editor Post sidebar controls for administrators editing a `post`.
	 *
	 * @return void
	 */
	public static function enqueue_editor_assets() {
		if ( ! self::can_admin_edit() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->post_type ) {
			return;
		}

		$post_id = isset( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof WP_Post ? (int) $GLOBALS['post']->ID : 0;
		if ( $post_id <= 0 && isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only post editor context detection.
			$post_id = absint( wp_unslash( $_GET['post'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only post editor context detection.
		}

		if ( $post_id <= 0 ) {
			return;
		}

		$counts = self::get_counts( $post_id );
		$css    = Penalis_Admin::resolve_asset_meta( 'assets/css/admin.css' );
		$js     = Penalis_Admin::resolve_asset_meta( 'assets/js/admin-reactions-editor.js' );

		wp_enqueue_style( 'penalis-admin-styles', $css['url'], array(), $css['version'] );
		wp_enqueue_script(
			self::EDITOR_SCRIPT_HANDLE,
			$js['url'],
			array( 'wp-element', 'wp-plugins', 'wp-components', 'wp-api-fetch', 'wp-data', 'wp-edit-post' ),
			$js['version'],
			true
		);

		wp_localize_script(
			self::EDITOR_SCRIPT_HANDLE,
			'penalisReactionsEditor',
			array(
				'postId'   => $post_id,
				'like'     => $counts['like'],
				'dislike'  => $counts['dislike'],
				'canEdit'  => true,
				'restPath' => '/' . Penalis_Rest_Api::NAMESPACE_V1 . '/posts/' . $post_id . '/reaction',
			)
		);
	}

	/**
	 * Register a Classic Editor fallback meta box for Likes & Dislikes.
	 *
	 * Marked `__back_compat_meta_box => true` so the Block Editor uses the native
	 * Post sidebar rows (`PluginPostStatusInfo`) without duplicating inputs.
	 *
	 * @return void
	 */
	public static function register_classic_meta_box() {
		if ( ! self::can_admin_edit() ) {
			return;
		}

		add_meta_box(
			'penalis-post-reactions',
			'Likes & Dislikes',
			array( __CLASS__, 'render_classic_meta_box' ),
			'post',
			'side',
			'default',
			array(
				'__back_compat_meta_box' => true,
			)
		);
	}

	/**
	 * Render the Classic Editor meta box for manual Likes & Dislikes editing.
	 *
	 * @param WP_Post $post Post object.
	 * @return void
	 */
	public static function render_classic_meta_box( WP_Post $post ) {
		$counts = self::get_counts( (int) $post->ID );
		wp_nonce_field( self::META_BOX_NONCE, 'penalis_reactions_nonce' );
		?>
		<div class="penalis-classic-reactions">
			<input type="hidden" name="penalis_reactions_orig_like" value="<?php echo esc_attr( (string) $counts['like'] ); ?>" />
			<input type="hidden" name="penalis_reactions_orig_dislike" value="<?php echo esc_attr( (string) $counts['dislike'] ); ?>" />
			<p class="penalis-classic-reactions__field">
				<label for="penalis-reactions-like">Likes</label>
				<input type="number" id="penalis-reactions-like" name="penalis_reactions_like" min="0" step="1" value="<?php echo esc_attr( (string) $counts['like'] ); ?>" class="small-text" />
			</p>
			<p class="penalis-classic-reactions__field">
				<label for="penalis-reactions-dislike">Dislikes</label>
				<input type="number" id="penalis-reactions-dislike" name="penalis_reactions_dislike" min="0" step="1" value="<?php echo esc_attr( (string) $counts['dislike'] ); ?>" class="small-text" />
			</p>
		</div>
		<?php
	}

	/**
	 * Persist Classic Editor Likes & Dislikes overrides when changed by an admin.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public static function save_classic_meta_box( $post_id, $post ) {
		if ( ! self::can_admin_edit() || ! ( $post instanceof WP_Post ) || 'post' !== $post->post_type ) {
			return;
		}

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['penalis_reactions_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['penalis_reactions_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, self::META_BOX_NONCE ) ) {
			return;
		}

		if ( ! isset( $_POST['penalis_reactions_like'], $_POST['penalis_reactions_dislike'] ) ) {
			return;
		}

		$new_like     = absint( wp_unslash( $_POST['penalis_reactions_like'] ) );
		$new_dislike  = absint( wp_unslash( $_POST['penalis_reactions_dislike'] ) );
		$orig_like    = isset( $_POST['penalis_reactions_orig_like'] ) ? absint( wp_unslash( $_POST['penalis_reactions_orig_like'] ) ) : -1;
		$orig_dislike = isset( $_POST['penalis_reactions_orig_dislike'] ) ? absint( wp_unslash( $_POST['penalis_reactions_orig_dislike'] ) ) : -1;

		// Skip writing when the administrator left both counters untouched.
		if ( $new_like === $orig_like && $new_dislike === $orig_dislike ) {
			return;
		}

		self::set_counts( (int) $post_id, $new_like, $new_dislike );
	}

	/**
	 * Add a Likes / Dislikes column to the wp-admin Posts list table.
	 *
	 * @param array<string,string> $columns Existing columns.
	 * @return array<string,string>
	 */
	public static function register_posts_column( $columns ) {
		if ( ! self::can_admin_edit() || ! is_array( $columns ) ) {
			return $columns;
		}

		$updated = array();
		foreach ( $columns as $key => $label ) {
			$updated[ $key ] = $label;
			if ( 'date' === $key ) {
				$updated[ self::ADMIN_COLUMN_KEY ] = 'Likes / Dislikes';
			}
		}

		if ( ! isset( $updated[ self::ADMIN_COLUMN_KEY ] ) ) {
			$updated[ self::ADMIN_COLUMN_KEY ] = 'Likes / Dislikes';
		}

		return $updated;
	}

	/**
	 * Render Likes & Dislikes counts (plus Quick Edit data attributes) in the Posts column.
	 *
	 * @param string $column_name Column key.
	 * @param int    $post_id     Post ID.
	 * @return void
	 */
	public static function render_posts_column( $column_name, $post_id ) {
		if ( self::ADMIN_COLUMN_KEY !== $column_name || ! self::can_admin_edit() ) {
			return;
		}

		$counts = self::get_counts( (int) $post_id );

		printf(
			'<span class="penalis-reactions-col" data-penalis-like="%1$d" data-penalis-dislike="%2$d" title="%3$s">'
			. '<span class="penalis-reactions-col__item penalis-reactions-col__item--like">%4$s<span>%5$s</span></span>'
			. '<span class="penalis-reactions-col__sep" aria-hidden="true">/</span>'
			. '<span class="penalis-reactions-col__item penalis-reactions-col__item--dislike">%6$s<span>%7$s</span></span>'
			. '</span>',
			(int) $counts['like'],
			(int) $counts['dislike'],
			esc_attr( sprintf( '%s likes / %s dislikes', number_format_i18n( $counts['like'] ), number_format_i18n( $counts['dislike'] ) ) ),
			self::thumb_up_svg( 13 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static inline SVG.
			esc_html( number_format_i18n( $counts['like'] ) ),
			self::thumb_down_svg( 13 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static inline SVG.
			esc_html( number_format_i18n( $counts['dislike'] ) )
		);
	}

	/**
	 * Render administrator-only Likes & Dislikes inputs inside the Posts Quick Edit form.
	 *
	 * Submitted through WordPress's standard `inline-save` action and persisted by
	 * `save_classic_meta_box()` with nonce verification and change detection.
	 *
	 * @param string $column_name Column key.
	 * @param string $post_type   Post type slug.
	 * @return void
	 */
	public static function render_quick_edit_fields( $column_name, $post_type ) {
		if ( self::ADMIN_COLUMN_KEY !== $column_name || 'post' !== $post_type || ! self::can_admin_edit() ) {
			return;
		}
		?>
		<fieldset class="inline-edit-col-left penalis-quick-edit-reactions">
			<div class="inline-edit-col">
				<?php wp_nonce_field( self::META_BOX_NONCE, 'penalis_reactions_nonce' ); ?>
				<input type="hidden" name="penalis_reactions_orig_like" value="0" />
				<input type="hidden" name="penalis_reactions_orig_dislike" value="0" />
				<label class="inline-edit-group penalis-quick-edit-reactions__row">
					<span class="title">Likes</span>
					<span class="input-text-wrap">
						<input type="number" name="penalis_reactions_like" min="0" step="1" value="0" class="penalis-quick-edit-reactions__input" />
					</span>
				</label>
				<label class="inline-edit-group penalis-quick-edit-reactions__row">
					<span class="title">Dislikes</span>
					<span class="input-text-wrap">
						<input type="number" name="penalis_reactions_dislike" min="0" step="1" value="0" class="penalis-quick-edit-reactions__input" />
					</span>
				</label>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Enqueue Quick Edit hydration script and styles on `/wp-admin/edit.php` for posts.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue_quick_edit_assets( $hook ) {
		if ( 'edit.php' !== $hook || ! self::can_admin_edit() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->post_type ) {
			return;
		}

		$css = Penalis_Admin::resolve_asset_meta( 'assets/css/admin.css' );
		$js  = Penalis_Admin::resolve_asset_meta( 'assets/js/admin-reactions-quick-edit.js' );

		wp_enqueue_style( 'penalis-admin-styles', $css['url'], array(), $css['version'] );
		wp_enqueue_script(
			self::QUICK_EDIT_HANDLE,
			$js['url'],
			array( 'inline-edit-post' ),
			$js['version'],
			true
		);
	}

	/**
	 * Handle `GET /penalis/v1/posts/{id}/reaction`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_get_reaction( $request ) {
		$post = self::resolve_eligible_post( (int) $request->get_param( 'id' ) );
		if ( ! $post ) {
			return new WP_Error(
				'penalis_not_found',
				'Karya tulis tidak ditemukan.',
				array( 'status' => 404 )
			);
		}

		$post_id  = (int) $post->ID;
		$counts   = self::get_counts( $post_id );
		$response = new WP_REST_Response(
			array(
				'postId'   => $post_id,
				'like'     => $counts['like'],
				'dislike'  => $counts['dislike'],
				'reaction' => self::read_cookie_reaction( $post_id ),
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}

	/**
	 * Handle `POST /penalis/v1/posts/{id}/reaction`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_post_reaction( $request ) {
		$ip = Penalis_Rate_Limiter::client_ip();

		$guard = Penalis_Rate_Limiter::guard(
			'post_reaction_ip',
			$ip,
			'Terlalu banyak interaksi dalam waktu singkat. Coba lagi beberapa saat lagi.'
		);
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$post_id = (int) $request->get_param( 'id' );
		$type    = (string) $request->get_param( 'type' );
		$current = self::read_cookie_reaction( $post_id );

		Penalis_Rate_Limiter::register( 'post_reaction_ip', $ip );

		$outcome = self::apply_reaction( $post_id, $type, $current );
		if ( is_wp_error( $outcome ) ) {
			return $outcome;
		}

		self::send_reaction_cookie( $post_id, $outcome['reaction'] );

		$response = new WP_REST_Response( $outcome, 200 );
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}

	/**
	 * Persist the visitor's reaction state in a cache-friendly cookie.
	 *
	 * Deliberately not `HttpOnly` so the client script can highlight the active
	 * button immediately on full-page-cached articles without an extra request.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $reaction Active reaction (`'like'`, `'dislike'`, or `''`).
	 * @return void
	 */
	private static function send_reaction_cookie( $post_id, $reaction ) {
		if ( headers_sent() ) {
			return;
		}

		$secure = function_exists( 'is_ssl' ) && is_ssl();
		$value  = '' !== $reaction ? $reaction : self::COOKIE_NONE;

		setcookie(
			self::COOKIE_PREFIX . $post_id,
			$value,
			array(
				'expires'  => time() + self::COOKIE_TTL,
				'path'     => '/',
				'secure'   => $secure,
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);

		$legacy_name = self::LEGACY_COOKIE_PREFIX . $post_id;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Existence check only before expiring legacy cookie.
		if ( isset( $_COOKIE[ $legacy_name ] ) ) {
			setcookie(
				$legacy_name,
				'',
				array(
					'expires'  => time() - 3600,
					'path'     => '/',
					'secure'   => $secure,
					'httponly' => false,
					'samesite' => 'Lax',
				)
			);
		}
	}

	/**
	 * Enqueue the vanilla reaction script (and shared single-post stylesheet).
	 *
	 * @return void
	 */
	public static function enqueue_assets() {
		if ( self::$assets_enqueued ) {
			return;
		}
		self::$assets_enqueued = true;

		if ( function_exists( 'wp_style_is' ) && ! wp_style_is( Penalis_Article_Share::STYLE_HANDLE, 'enqueued' ) ) {
			$css = Penalis_Admin::resolve_asset_meta( 'assets/css/article-share.css' );
			wp_enqueue_style( Penalis_Article_Share::STYLE_HANDLE, $css['url'], array(), $css['version'] );
		}

		$js = Penalis_Admin::resolve_asset_meta( 'assets/js/post-reactions.js' );
		wp_enqueue_script( self::SCRIPT_HANDLE, $js['url'], array(), $js['version'], true );
	}

	/**
	 * Render the `[penalis_reactions]` (or legacy `[posts_like_dislike]`) shortcode.
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function render_shortcode( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'id' => 0,
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);

		$post_id = (int) $atts['id'] > 0 ? (int) $atts['id'] : (int) get_the_ID();
		$post    = self::resolve_eligible_post( $post_id );

		if ( ! $post ) {
			return '';
		}

		return self::render_controls( $post, 'inline' );
	}

	/**
	 * Render the cache-safe Like & Dislike button group for a published post.
	 *
	 * Server output intentionally omits per-visitor active state so full-page
	 * caches remain safe to share across visitors; `post-reactions.js` hydrates
	 * `aria-pressed` and `.is-active` locally from the browser cookie.
	 *
	 * @param WP_Post $post    Published post object.
	 * @param string  $variant Layout variant (`'bar'` or `'inline'`).
	 * @return string
	 */
	public static function render_controls( WP_Post $post, $variant = 'bar' ) {
		$post = self::resolve_eligible_post( $post );
		if ( ! $post ) {
			return '';
		}

		self::enqueue_assets();

		$post_id  = (int) $post->ID;
		$counts   = self::get_counts( $post_id );
		$endpoint = rest_url( Penalis_Rest_Api::NAMESPACE_V1 . '/posts/' . $post_id . '/reaction' );
		$variant  = 'inline' === $variant ? 'inline' : 'bar';

		return sprintf(
			'<div class="penalis-reactions penalis-reactions--%1$s" data-penalis-reactions data-post-id="%2$d" data-endpoint="%3$s" role="group" aria-label="Reaksi pembaca">'
			. '<button type="button" class="penalis-reaction-btn penalis-reaction-btn--like" data-reaction="like" aria-pressed="false" aria-label="Like karya tulis ini" title="Like">%4$s<span class="penalis-reaction-btn__label">Like</span><span class="penalis-reaction-btn__count" data-reaction-count="like">%5$s</span></button>'
			. '<button type="button" class="penalis-reaction-btn penalis-reaction-btn--dislike" data-reaction="dislike" aria-pressed="false" aria-label="Dislike karya tulis ini" title="Dislike">%6$s<span class="penalis-reaction-btn__count" data-reaction-count="dislike">%7$s</span></button>'
			. '<span class="penalis-reactions__status" role="status" aria-live="polite" data-reaction-status></span>'
			. '</div>',
			esc_attr( $variant ),
			$post_id,
			esc_url( $endpoint ),
			self::thumb_up_svg( 15 ),
			esc_html( number_format_i18n( $counts['like'] ) ),
			self::thumb_down_svg( 15 ),
			esc_html( number_format_i18n( $counts['dislike'] ) )
		);
	}

	/**
	 * Inline thumbs-up SVG icon.
	 *
	 * @param int $size Icon size in px.
	 * @return string
	 */
	private static function thumb_up_svg( $size = 15 ) {
		$size = max( 12, (int) $size );

		return sprintf(
			'<svg width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M7 10v12" /><path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z" /></svg>',
			$size
		);
	}

	/**
	 * Inline thumbs-down SVG icon.
	 *
	 * @param int $size Icon size in px.
	 * @return string
	 */
	private static function thumb_down_svg( $size = 15 ) {
		$size = max( 12, (int) $size );

		return sprintf(
			'<svg width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M17 14V2" /><path d="M9 18.12 10 14H4.17a2 2 0 0 1-1.92-2.56l2.33-8A2 2 0 0 1 6.5 2H20a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-2.76a2 2 0 0 0-1.79 1.11L12 22a3.13 3.13 0 0 1-3-3.88Z" /></svg>',
			$size
		);
	}
}
