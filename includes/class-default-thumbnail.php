<?php
/**
 * Built-in default featured image provider for published posts.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides a site-wide fallback featured image for published `post` articles
 * that do not have an explicit `_thumbnail_id` stored in post meta.
 *
 * Designed specifically for Penalis's curated workflow:
 * - Applies only to `post` records with `post_status === 'publish'` (e.g.
 *   legacy posts published before Penalis Platform existed).
 * - Never injects a fallback into drafts, pending reviews, revisions, or
 *   non-`post` types, so the writer editor, autosave, media ownership checks,
 *   and mandatory-thumbnail submission validation always see the real DB state.
 * - Never mutates WordPress's `post_meta` object cache, avoiding persistent
 *   cache poisoning across admin and REST requests.
 */
final class Penalis_Default_Thumbnail {

	/**
	 * Option storing the default featured image attachment ID.
	 */
	public const OPTION_ID = 'penalis_default_featured_image_id';

	/**
	 * Option recording who last changed the default featured image and when.
	 */
	public const OPTION_AUDIT = 'penalis_default_featured_image_audit';

	/**
	 * Re-entrancy guard when reading the underlying `_thumbnail_id` meta value.
	 *
	 * @var bool
	 */
	private static $resolving_meta = false;

	/**
	 * Register WordPress hooks for the fallback thumbnail provider.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'get_post_metadata', array( __CLASS__, 'filter_post_metadata' ), 10, 4 );
		add_filter( 'post_thumbnail_id', array( __CLASS__, 'filter_post_thumbnail_id' ), 10, 2 );
		add_filter( 'post_thumbnail_html', array( __CLASS__, 'filter_post_thumbnail_html' ), 20, 5 );
	}

	/**
	 * Return the raw configured attachment ID without validating existence.
	 *
	 * Used by the settings screen to detect when a previously saved attachment
	 * has been deleted from the Media Library.
	 *
	 * @return int
	 */
	public static function get_configured_id() {
		return max( 0, (int) get_option( self::OPTION_ID, 0 ) );
	}

	/**
	 * Return the active default featured image attachment ID, or 0 when unset
	 * or no longer pointing to a valid image attachment.
	 *
	 * @return int
	 */
	public static function get_id() {
		$attachment_id = self::get_configured_id();

		if ( $attachment_id <= 0 ) {
			return 0;
		}

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return 0;
		}

		return $attachment_id;
	}

	/**
	 * Whether the current request is a non-AJAX wp-admin screen.
	 *
	 * Admin screens (such as Edit Post and the Posts list table) must see the
	 * true database state rather than a synthetic fallback thumbnail.
	 *
	 * @return bool
	 */
	public static function is_admin_screen_request() {
		return function_exists( 'is_admin' )
			&& is_admin()
			&& ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX );
	}

	/**
	 * Whether a post is eligible to receive the default featured image fallback.
	 *
	 * Strictly restricted to published `post` entries outside non-AJAX wp-admin
	 * screens. Drafts, pending submissions, rejected articles, pages, and other
	 * post types never receive the fallback.
	 *
	 * @param WP_Post|int|null $post Post object or ID.
	 * @return bool
	 */
	public static function should_apply_to_post( $post ) {
		if ( self::is_admin_screen_request() ) {
			return false;
		}

		$post_obj = $post instanceof WP_Post ? $post : get_post( (int) $post );

		if ( ! ( $post_obj instanceof WP_Post ) ) {
			return false;
		}

		return 'post' === $post_obj->post_type && 'publish' === $post_obj->post_status;
	}

	/**
	 * Whether the post already has an explicit `_thumbnail_id` stored in post meta.
	 *
	 * Bypasses this class's own `get_post_metadata` filter via a re-entrancy guard.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function has_explicit_thumbnail( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return false;
		}

		self::$resolving_meta = true;
		try {
			$existing = get_post_meta( $post_id, '_thumbnail_id', true );
		} finally {
			self::$resolving_meta = false;
		}

		return (int) $existing > 0;
	}

	/**
	 * Supply the default featured image when `_thumbnail_id` is read directly
	 * via `get_post_meta()` on an eligible published post.
	 *
	 * Returning an array with a single element matches WordPress core's
	 * `get_metadata_raw()` contract for both `$single = true` and `$single = false`
	 * without mutating the `post_meta` object cache.
	 *
	 * @param mixed  $value     Short-circuit value (null to proceed).
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Requested meta key.
	 * @param bool   $single    Whether a single value was requested.
	 * @return mixed
	 */
	public static function filter_post_metadata( $value, $object_id, $meta_key, $single ) {
		unset( $single );

		if ( null !== $value || self::$resolving_meta || '_thumbnail_id' !== $meta_key ) {
			return $value;
		}

		$post_id = (int) $object_id;
		if ( $post_id <= 0 || ! self::should_apply_to_post( $post_id ) ) {
			return $value;
		}

		$default_id = self::get_id();
		if ( $default_id <= 0 ) {
			return $value;
		}

		if ( self::has_explicit_thumbnail( $post_id ) ) {
			return $value;
		}

		return array( (string) $default_id );
	}

	/**
	 * Supply the default featured image for `get_post_thumbnail_id()` and every
	 * WordPress helper built on top of it (`has_post_thumbnail()`,
	 * `get_the_post_thumbnail()`, `get_the_post_thumbnail_url()`).
	 *
	 * @param int|false        $thumbnail_id Resolved thumbnail ID.
	 * @param WP_Post|int|null $post         Post object or ID.
	 * @return int|false
	 */
	public static function filter_post_thumbnail_id( $thumbnail_id, $post ) {
		if ( (int) $thumbnail_id > 0 ) {
			return $thumbnail_id;
		}

		if ( ! self::should_apply_to_post( $post ) ) {
			return $thumbnail_id;
		}

		$default_id = self::get_id();

		return $default_id > 0 ? $default_id : $thumbnail_id;
	}

	/**
	 * Add a distinguishing CSS class when a post thumbnail was supplied by this
	 * fallback provider so themes can target fallback images if needed.
	 *
	 * @param string       $html              Post thumbnail HTML.
	 * @param int          $post_id           Post ID.
	 * @param int          $post_thumbnail_id Post thumbnail attachment ID.
	 * @param string|int[] $size              Requested image size.
	 * @param string|array $attr              Query string or array of attributes.
	 * @return string
	 */
	public static function filter_post_thumbnail_html( $html, $post_id, $post_thumbnail_id, $size, $attr ) {
		unset( $size, $attr );

		$html = (string) $html;
		if ( '' === $html ) {
			return $html;
		}

		$default_id = self::get_id();
		if ( $default_id <= 0 || (int) $post_thumbnail_id !== $default_id ) {
			return $html;
		}

		$post_id = (int) $post_id;
		if ( ! self::should_apply_to_post( $post_id ) || self::has_explicit_thumbnail( $post_id ) ) {
			return $html;
		}

		if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
			$processor = new WP_HTML_Tag_Processor( $html );
			if ( $processor->next_tag( 'img' ) ) {
				$processor->add_class( 'penalis-default-featured-image' );
				return $processor->get_updated_html();
			}
		}

		return $html;
	}
}
