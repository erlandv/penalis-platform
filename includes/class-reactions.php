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
			. '<button type="button" class="penalis-reaction-btn penalis-reaction-btn--like" data-reaction="like" aria-pressed="false" aria-label="Suka karya tulis ini" title="Suka">%4$s<span class="penalis-reaction-btn__label">Suka</span><span class="penalis-reaction-btn__count" data-reaction-count="like">%5$s</span></button>'
			. '<button type="button" class="penalis-reaction-btn penalis-reaction-btn--dislike" data-reaction="dislike" aria-pressed="false" aria-label="Tidak suka karya tulis ini" title="Tidak suka">%6$s<span class="penalis-reaction-btn__count" data-reaction-count="dislike">%7$s</span></button>'
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
