<?php
/**
 * Public author archive template and profile data integration.
 *
 * Replaces third-party theme-builder author archives with a bundled,
 * theme-wrapped profile and works showcase when enabled.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers template routing, assets, and view helpers for author archives.
 */
final class Penalis_Author_Archive {

	public const ASSET_HANDLE = 'penalis-author-archive';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'template_include', array( __CLASS__, 'maybe_use_template' ), 99 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_assets' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
	}

	/**
	 * Whether the bundled author archive template is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		/**
		 * Allow a site to opt out of the bundled author archive template.
		 *
		 * @param bool $use_template Whether to use the bundled author archive template.
		 */
		return (bool) apply_filters( 'penalis_use_author_archive_template', true );
	}

	/**
	 * Whether the current request is a public author archive handled by Penalis.
	 *
	 * @return bool
	 */
	public static function is_archive_request() {
		return is_author() && self::is_enabled();
	}

	/**
	 * Route author archive requests to the bundled template (with theme override).
	 *
	 * @param string $template Resolved template path.
	 * @return string
	 */
	public static function maybe_use_template( $template ) {
		if ( ! self::is_archive_request() ) {
			return $template;
		}

		return Penalis_Dashboard::resolve_bundled_template(
			$template,
			'penalis-author-archive.php',
			'author-archive.php'
		);
	}

	/**
	 * Add a body class on Penalis author archive pages.
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public static function body_class( $classes ) {
		if ( self::is_archive_request() ) {
			$classes[] = 'penalis-author-archive-page';
		}

		return $classes;
	}

	/**
	 * Enqueue author archive styles and share/copy script only on author archives.
	 *
	 * @return void
	 */
	public static function maybe_enqueue_assets() {
		if ( ! self::is_archive_request() ) {
			return;
		}

		$css = Penalis_Admin::resolve_asset_meta( 'assets/css/author-archive.css' );
		$js  = Penalis_Admin::resolve_asset_meta( 'assets/js/author-archive.js' );

		wp_enqueue_style( self::ASSET_HANDLE, $css['url'], array(), $css['version'] );
		wp_enqueue_script( self::ASSET_HANDLE, $js['url'], array(), $js['version'], true );
	}

	/**
	 * Resolve the queried author user for the current archive request.
	 *
	 * @return WP_User|null
	 */
	public static function resolve_queried_author() {
		$queried = get_queried_object();
		if ( $queried instanceof WP_User ) {
			return $queried;
		}

		$author_id = (int) get_queried_object_id();
		if ( $author_id <= 0 ) {
			return null;
		}

		$user = get_userdata( $author_id );

		return $user instanceof WP_User ? $user : null;
	}

	/**
	 * Resolve the public contributors directory URL.
	 *
	 * @return string
	 */
	public static function directory_url() {
		$default = home_url( '/author/' );

		if ( function_exists( 'get_page_by_path' ) ) {
			$page = get_page_by_path( 'author' );
			if ( $page instanceof WP_Post && 'publish' === $page->post_status ) {
				$permalink = get_permalink( $page );
				if ( is_string( $permalink ) && '' !== $permalink ) {
					$default = $permalink;
				}
			}
		}

		/**
		 * Filter the back-link URL pointing to the contributors directory.
		 *
		 * @param string $default Directory URL.
		 */
		return (string) apply_filters( 'penalis_authors_directory_url', $default );
	}

	/**
	 * Format a profile URL into a clean display label without scheme or trailing slash.
	 *
	 * @param string $url Full profile URL.
	 * @return string
	 */
	public static function format_profile_url_label( $url ) {
		$clean = rtrim( trim( (string) $url ), '/' );
		$clean = (string) preg_replace( '#^https?://#i', '', $clean );

		return $clean;
	}

	/**
	 * Format an integer counter using Indonesian dot thousand separators.
	 *
	 * @param int $value Numeric counter.
	 * @return string
	 */
	public static function format_number( $value ) {
		return number_format( max( 0, (int) $value ), 0, ',', '.' );
	}

	/**
	 * Return lifetime views for a single published post when Post Views Counter is active.
	 *
	 * @param int $post_id Post ID.
	 * @return int|null Null when Post Views Counter is unavailable.
	 */
	public static function get_post_views( $post_id ) {
		if ( ! function_exists( 'pvc_get_post_views' ) ) {
			return null;
		}

		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return 0;
		}

		return max( 0, (int) pvc_get_post_views( $post_id ) );
	}

	/**
	 * Return total lifetime views across all published posts owned by an author.
	 *
	 * @param int $user_id User ID.
	 * @return int|null Null when Post Views Counter is unavailable.
	 */
	public static function get_author_total_views( $user_id ) {
		if ( ! function_exists( 'pvc_get_post_views' ) ) {
			return null;
		}

		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return 0;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single lightweight ID fetch per archive render.
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID
				FROM {$wpdb->posts}
				WHERE post_type = 'post' AND post_status = 'publish' AND post_author = %d",
				$user_id
			)
		);

		if ( empty( $post_ids ) || ! is_array( $post_ids ) ) {
			return 0;
		}

		$ids = array_values(
			array_filter(
				array_map( 'intval', $post_ids ),
				static function ( $post_id ) {
					return $post_id > 0;
				}
			)
		);

		if ( empty( $ids ) ) {
			return 0;
		}

		return max( 0, (int) pvc_get_post_views( $ids ) );
	}

	/**
	 * Resolve the primary display category for a post card.
	 *
	 * Prefers a non-Uncategorized category when multiple categories are assigned.
	 *
	 * @param int $post_id Post ID.
	 * @return WP_Term|null
	 */
	public static function get_primary_category( $post_id ) {
		$categories = get_the_category( (int) $post_id );
		if ( empty( $categories ) || ! is_array( $categories ) ) {
			return null;
		}

		foreach ( $categories as $category ) {
			if ( $category instanceof WP_Term && 'uncategorized' !== $category->slug ) {
				return $category;
			}
		}

		return $categories[0] instanceof WP_Term ? $categories[0] : null;
	}

	/**
	 * Build normalized profile view data for an author archive page.
	 *
	 * @param WP_User $author Queried author user.
	 * @return array<string,mixed>
	 */
	public static function get_profile_data( WP_User $author ) {
		$user_id     = (int) $author->ID;
		$profile_url = (string) get_author_posts_url( $user_id, $author->user_nicename );
		$is_owner    = is_user_logged_in() && get_current_user_id() === $user_id;
		$can_write   = $is_owner && Penalis_Account_Status::can_write( $user_id );
		$post_count  = (int) count_user_posts( $user_id, 'post', true );
		$joined      = Penalis_Datetime::format_indonesian_month_year_gmt( (string) $author->user_registered );

		return array(
			'id'                => $user_id,
			'display_name'      => (string) $author->display_name,
			'username'          => (string) $author->user_nicename,
			'avatar_url'        => (string) Penalis_Avatar::get_url( $user_id ),
			'profile_url'       => $profile_url,
			'profile_url_label' => self::format_profile_url_label( $profile_url ),
			'bio'               => trim( (string) get_user_meta( $user_id, 'description', true ) ),
			'is_verified'       => Penalis_Authors::is_user_verified( $user_id ),
			'is_senator'        => Penalis_Authors::is_user_senator( $user_id ),
			'post_count'        => max( 0, $post_count ),
			'total_views'       => self::get_author_total_views( $user_id ),
			'joined_label'      => $joined,
			'is_owner'          => $is_owner,
			'can_write'         => $can_write,
			'edit_profile_url'  => Penalis_Dashboard::dashboard_url( array( 'view' => 'profile' ) ),
			'new_article_url'   => Penalis_Dashboard::dashboard_url( array( 'view' => 'editor' ) ),
			'directory_url'     => self::directory_url(),
		);
	}
}
