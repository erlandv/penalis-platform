<?php
/**
 * Shareable article card integration for single posts, shortcodes, and archives.
 *
 * Provides:
 * - Auto-appended share callout at the bottom of published single posts (`the_content`).
 * - `[penalis_share_article]` shortcode for manual placement in themes or page builders.
 * - Shared payload builder and clean excerpt formatter reused by author archives and REST.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers single-post article share bar, shortcode, and share-card payload helpers.
 */
final class Penalis_Article_Share {

	public const SHORTCODE     = 'penalis_share_article';
	public const STYLE_HANDLE  = 'penalis-article-share';
	public const SCRIPT_HANDLE = 'penalis-article-share-card';

	/**
	 * Post IDs where `[penalis_share_article]` already rendered on this request.
	 *
	 * Prevents `append_single_post_bar()` from rendering a duplicate block when
	 * a theme or post manually places the shortcode.
	 *
	 * @var array<int,bool>
	 */
	private static $shortcode_rendered = array();

	/**
	 * Post IDs whose embedded JSON card payload has already been printed.
	 *
	 * @var array<int,bool>
	 */
	private static $payload_rendered = array();

	/**
	 * Post IDs whose auto-appended share bar has already been rendered.
	 *
	 * @var array<int,bool>
	 */
	private static $bar_rendered = array();

	/**
	 * Whether share-card assets have already been localized for this request.
	 *
	 * @var bool
	 */
	private static $assets_enqueued = false;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_shortcode' ) );
		// Run late on `the_content` (after reaction/like plugins such as PLD at 200)
		// for generic themes, and prefer the Atlas theme's post-tags hook when active.
		add_filter( 'the_content', array( __CLASS__, 'append_single_post_bar' ), 999 );
		add_action( 'th90_article_elements_below', array( __CLASS__, 'render_below_article_hook' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_assets' ) );
		add_filter( 'script_loader_tag', array( __CLASS__, 'module_script_tag' ), 10, 3 );
	}

	/**
	 * Reset per-request render state (used by unit tests).
	 *
	 * @return void
	 */
	public static function reset_state() {
		self::$shortcode_rendered = array();
		self::$payload_rendered   = array();
		self::$bar_rendered       = array();
		self::$assets_enqueued    = false;
	}

	/**
	 * Build a clean plain-text excerpt that preserves spaces across line/block breaks.
	 *
	 * WordPress's default `wp_strip_all_tags()` strips `<br>` and `</p>` without
	 * inserting whitespace, which glues consecutive poetry stanzas together.
	 *
	 * @param WP_Post $post       Post object.
	 * @param int     $word_limit Maximum words.
	 * @return string
	 */
	public static function clean_excerpt( WP_Post $post, $word_limit = 26 ) {
		$raw = '' !== trim( (string) $post->post_excerpt )
			? (string) $post->post_excerpt
			: (string) $post->post_content;

		if ( '' === trim( $raw ) ) {
			return '';
		}

		if ( function_exists( 'strip_shortcodes' ) ) {
			$raw = strip_shortcodes( $raw );
		}

		// Replace block boundaries and line breaks with a space before stripping tags.
		$spaced = (string) preg_replace( '#<(?:br\s*/?|/(?:p|div|li|blockquote|h[1-6]|tr))>#i', ' ', $raw );
		$plain  = wp_strip_all_tags( $spaced );
		$plain  = html_entity_decode( $plain, ENT_QUOTES, 'UTF-8' );
		$plain  = (string) preg_replace( '/\s+/u', ' ', $plain );
		$plain  = trim( $plain );

		if ( '' === $plain ) {
			return '';
		}

		return wp_trim_words( $plain, max( 1, (int) $word_limit ), '…' );
	}

	/**
	 * Build the normalized share-card payload for a published article.
	 *
	 * @param WP_Post $post Post object.
	 * @return array<string,mixed>
	 */
	public static function build_article_payload( WP_Post $post ) {
		$post_id    = (int) $post->ID;
		$author_id  = (int) $post->post_author;
		$author     = $author_id > 0 ? get_userdata( $author_id ) : false;
		$category   = Penalis_Author_Archive::get_primary_category( $post_id );
		$cover_raw  = function_exists( 'get_the_post_thumbnail_url' ) ? get_the_post_thumbnail_url( $post, 'large' ) : '';
		$cover_url  = is_string( $cover_raw ) && '' !== $cover_raw
			? Penalis_Dashboard::ensure_same_origin_url( $cover_raw )
			: '';
		$title_raw  = html_entity_decode( wp_strip_all_tags( (string) $post->post_title ), ENT_QUOTES, 'UTF-8' );
		$cat_name   = $category instanceof WP_Term
			? html_entity_decode( (string) $category->name, ENT_QUOTES, 'UTF-8' )
			: '';
		$published  = '' !== (string) $post->post_date_gmt
			? Penalis_Datetime::format_gmt( (string) $post->post_date_gmt, 'j M Y' )
			: '';
		$avatar_url = $author_id > 0 ? (string) Penalis_Avatar::get_url( $author_id ) : '';

		return array(
			'id'             => $post_id,
			'title'          => '' !== trim( $title_raw ) ? trim( $title_raw ) : 'Tanpa judul',
			'excerpt'        => self::clean_excerpt( $post, 26 ),
			'category'       => $cat_name,
			'coverUrl'       => $cover_url,
			'articleUrl'     => (string) get_permalink( $post ),
			'publishedLabel' => $published,
			'displayName'    => $author instanceof WP_User ? (string) $author->display_name : 'Penulis Penalis',
			'username'       => $author instanceof WP_User ? (string) $author->user_nicename : 'penalis',
			'avatarUrl'      => '' !== $avatar_url ? Penalis_Dashboard::ensure_same_origin_url( $avatar_url ) : '',
			'isVerified'     => $author_id > 0 && Penalis_Authors::is_user_verified( $author_id ),
			'isSenator'      => $author_id > 0 && Penalis_Authors::is_user_senator( $author_id ),
		);
	}

	/**
	 * Render an embedded `<script type="application/json">` payload for an article once per page.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	public static function render_embedded_payload( WP_Post $post ) {
		$post_id = (int) $post->ID;
		if ( $post_id <= 0 || isset( self::$payload_rendered[ $post_id ] ) ) {
			return '';
		}

		self::$payload_rendered[ $post_id ] = true;
		$payload                            = self::build_article_payload( $post );
		$json                               = wp_json_encode(
			$payload,
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		if ( ! is_string( $json ) || '' === $json ) {
			return '';
		}

		return sprintf(
			'<script type="application/json" data-penalis-article-card="%d">%s</script>',
			$post_id,
			$json
		);
	}

	/**
	 * Enqueue share-card assets on single posts when auto-append or shortcode is active.
	 *
	 * @return void
	 */
	public static function maybe_enqueue_assets() {
		if ( ! is_singular( 'post' ) ) {
			return;
		}

		self::enqueue_assets();
	}

	/**
	 * Enqueue the article share stylesheet and shared vanilla share-card bundle.
	 *
	 * @return void
	 */
	public static function enqueue_assets() {
		if ( self::$assets_enqueued ) {
			return;
		}
		self::$assets_enqueued = true;

		$css = Penalis_Admin::resolve_asset_meta( 'assets/css/article-share.css' );
		wp_enqueue_style( self::STYLE_HANDLE, $css['url'], array(), $css['version'] );

		// Avoid loading the share-card module twice when already enqueued by Penalis_Author_Archive.
		if ( function_exists( 'wp_script_is' ) && wp_script_is( Penalis_Author_Archive::SHARE_CARD_HANDLE, 'enqueued' ) ) {
			return;
		}

		if ( ! Penalis_Dashboard::enqueue_vite_entry( 'src/share-card/author-archive.ts', self::SCRIPT_HANDLE ) ) {
			return;
		}

		$brand = Penalis_Dashboard::brand_assets();

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'penalisArticleShare',
			array(
				'brandLogoUrl' => $brand['logoUrl'],
				'brandIconUrl' => $brand['iconUrl'],
			)
		);
	}

	/**
	 * Load the article share-card bundle as an ES module.
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Script handle.
	 * @param string $src    Script source URL.
	 * @return string
	 */
	public static function module_script_tag( $tag, $handle, $src ) {
		return Penalis_Dashboard::format_module_script_tag( $tag, $handle, $src, self::SCRIPT_HANDLE );
	}

	/**
	 * Render the `[penalis_share_article]` shortcode.
	 *
	 * Attributes:
	 * - `id`: Optional post ID (defaults to the current post in the loop).
	 * - `label`: Optional button label (defaults to `Bagikan`).
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function render_shortcode( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'id'    => 0,
				'label' => 'Bagikan',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);

		$post_id = (int) $atts['id'] > 0 ? (int) $atts['id'] : (int) get_the_ID();
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
			return '';
		}

		$label = trim( sanitize_text_field( (string) $atts['label'] ) );
		if ( '' === $label ) {
			$label = 'Bagikan';
		}

		self::$shortcode_rendered[ (int) $post->ID ] = true;
		self::enqueue_assets();

		return sprintf(
			'<span class="penalis-article-share-inline"><button type="button" class="penalis-article-share-btn" data-penalis-open-card="article" data-penalis-article-id="%1$d" aria-label="%2$s">%3$s<span>%4$s</span></button>%5$s</span>',
			(int) $post->ID,
			esc_attr( sprintf( 'Bagikan karya tulis: %s', (string) $post->post_title ) ),
			self::share_icon_svg( 15 ),
			esc_html( $label ),
			self::render_embedded_payload( $post )
		);
	}

	/**
	 * Append a clean share callout bar at the bottom of published single posts.
	 *
	 * On the Atlas theme, rendering is deferred to `th90_article_elements_below`
	 * so the bar appears cleanly below like/dislike controls and post tags.
	 * On all other themes it appends late on `the_content` (priority 999).
	 *
	 * @param string $content Post HTML content.
	 * @return string
	 */
	public static function append_single_post_bar( $content ) {
		if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() || is_feed() ) {
			return $content;
		}

		// Atlas renders like/dislike inside `the_content` and post tags right after it;
		// `render_below_article_hook()` places the bar below both.
		if ( function_exists( 'th90_opt' ) ) {
			return $content;
		}

		$post = get_post();
		if ( ! $post instanceof WP_Post ) {
			return $content;
		}

		$bar = self::render_auto_bar( $post );
		if ( '' === $bar ) {
			return $content;
		}

		return $content . "\n" . $bar;
	}

	/**
	 * Render the share callout bar right below article content and tags on Atlas.
	 *
	 * @return void
	 */
	public static function render_below_article_hook() {
		if ( ! is_singular( 'post' ) || is_feed() ) {
			return;
		}

		$post = get_post();
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$bar = self::render_auto_bar( $post );
		if ( '' !== $bar ) {
			echo $bar; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside render_auto_bar().
		}
	}

	/**
	 * Build the auto-appended share callout bar HTML if eligible for this post.
	 *
	 * Skips automatically when `[penalis_share_article]` is already used or when
	 * a site opts out via `penalis_auto_append_article_share`.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	private static function render_auto_bar( WP_Post $post ) {
		$post_id = (int) $post->ID;

		if ( 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
			return '';
		}

		if ( isset( self::$bar_rendered[ $post_id ] ) || isset( self::$shortcode_rendered[ $post_id ] ) ) {
			return '';
		}

		if ( has_shortcode( (string) $post->post_content, self::SHORTCODE ) ) {
			return '';
		}

		/**
		 * Filter whether Penalis automatically appends the share bar at the end of single posts.
		 *
		 * @param bool    $enabled Default true.
		 * @param WP_Post $post    Current post.
		 */
		if ( ! apply_filters( 'penalis_auto_append_article_share', true, $post ) ) {
			return '';
		}

		self::$bar_rendered[ $post_id ] = true;
		self::enqueue_assets();

		$reactions_html = class_exists( 'Penalis_Reactions' )
			? Penalis_Reactions::render_controls( $post, 'bar' )
			: '';

		return sprintf(
			'<aside class="penalis-article-share-bar" aria-label="Reaksi dan bagikan karya tulis"><div class="penalis-article-share-bar__copy"><strong class="penalis-article-share-bar__title">Suka karya tulis ini?</strong><p class="penalis-article-share-bar__desc">Beri apresiasi untuk penulis atau bagikan sebagai gambar siap unggah ke media sosial.</p></div><div class="penalis-article-share-bar__actions">%1$s<button type="button" class="penalis-article-share-btn penalis-article-share-btn--primary" data-penalis-open-card="article" data-penalis-article-id="%2$d" aria-label="%3$s">%4$s<span>Bagikan</span></button></div>%5$s</aside>',
			$reactions_html,
			$post_id,
			esc_attr( sprintf( 'Bagikan karya tulis: %s', (string) $post->post_title ) ),
			self::share_icon_svg( 16 ),
			self::render_embedded_payload( $post )
		);
	}

	/**
	 * Inline share-nodes SVG icon.
	 *
	 * @param int $size Icon size in px.
	 * @return string
	 */
	private static function share_icon_svg( $size = 16 ) {
		$size = max( 12, (int) $size );

		return sprintf(
			'<svg width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="18" cy="5" r="3" /><circle cx="6" cy="12" r="3" /><circle cx="18" cy="19" r="3" /><line x1="8.59" y1="13.51" x2="15.42" y2="17.49" /><line x1="15.41" y1="6.51" x2="8.59" y2="10.49" /></svg>',
			$size
		);
	}
}
