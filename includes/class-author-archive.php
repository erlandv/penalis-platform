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

	public const ASSET_HANDLE      = 'penalis-author-archive';
	public const SHARE_CARD_HANDLE = 'penalis-author-archive-card';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'template_include', array( __CLASS__, 'maybe_use_template' ), 99 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_assets' ) );
		add_filter( 'script_loader_tag', array( __CLASS__, 'module_script_tag' ), 10, 3 );
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

		$author = self::resolve_queried_author();
		if ( ! $author instanceof WP_User ) {
			return;
		}

		if ( ! Penalis_Dashboard::enqueue_vite_entry( 'src/share-card/author-archive.ts', self::SHARE_CARD_HANDLE ) ) {
			return;
		}

		$data  = self::get_profile_data( $author );
		$brand = Penalis_Dashboard::brand_assets();
		$quote = is_array( $data['daily_quote'] ) ? array(
			'text'        => (string) $data['daily_quote']['text'],
			'displayName' => (string) $data['display_name'],
			'username'    => (string) $data['username'],
			'avatarUrl'   => (string) $data['avatar_url'],
			'authorUrl'   => (string) $data['profile_url'],
			'siteUrl'     => (string) $data['daily_quotes_url'],
			'isVerified'  => ! empty( $data['is_verified'] ),
			'isSenator'   => ! empty( $data['is_senator'] ),
		) : null;

		wp_localize_script(
			self::SHARE_CARD_HANDLE,
			'penalisAuthorArchive',
			array(
				'brandLogoUrl' => $brand['logoUrl'],
				'brandIconUrl' => $brand['iconUrl'],
				'profile'      => array(
					'displayName' => (string) $data['display_name'],
					'username'    => (string) $data['username'],
					'avatarUrl'   => (string) $data['avatar_url'],
					'profileUrl'  => (string) $data['profile_url'],
					'bio'         => (string) $data['bio'],
					'postCount'   => (int) $data['post_count'],
					'totalViews'  => null === $data['total_views'] ? null : (int) $data['total_views'],
					'joinedLabel' => (string) $data['joined_label'],
					'isVerified'  => ! empty( $data['is_verified'] ),
					'isSenator'   => ! empty( $data['is_senator'] ),
				),
				'quote'        => $quote,
			)
		);
	}

	/**
	 * Load the author archive share-card bundle as an ES module.
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Script handle.
	 * @param string $src    Script source URL.
	 * @return string
	 */
	public static function module_script_tag( $tag, $handle, $src ) {
		return Penalis_Dashboard::format_module_script_tag( $tag, $handle, $src, self::SHARE_CARD_HANDLE );
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
	 * Return lifetime views for a single published post.
	 *
	 * @param int $post_id Post ID.
	 * @return int|null Null only when no views provider is loaded.
	 */
	public static function get_post_views( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return 0;
		}

		if ( class_exists( 'Penalis_Views' ) ) {
			return Penalis_Views::get_views( $post_id );
		}

		if ( function_exists( 'pvc_get_post_views' ) ) {
			return max( 0, (int) pvc_get_post_views( $post_id ) );
		}

		return null;
	}

	/**
	 * Return total lifetime views across all published posts owned by an author.
	 *
	 * @param int $user_id User ID.
	 * @return int|null Null only when no views provider is loaded.
	 */
	public static function get_author_total_views( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return 0;
		}

		if ( class_exists( 'Penalis_Views' ) ) {
			return Penalis_Views::get_author_total_views( $user_id );
		}

		return null;
	}

	/**
	 * Return lifetime likes for a single published post.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function get_post_likes( $post_id ) {
		if ( ! class_exists( 'Penalis_Reactions' ) ) {
			return 0;
		}

		return Penalis_Reactions::get_like_count( (int) $post_id );
	}

	/**
	 * Return total lifetime likes across all published posts owned by an author.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public static function get_author_total_likes( $user_id ) {
		if ( ! class_exists( 'Penalis_Reactions' ) ) {
			return 0;
		}

		return Penalis_Reactions::get_author_total_likes( (int) $user_id );
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
		$user_id            = (int) $author->ID;
		$profile_url        = (string) get_author_posts_url( $user_id, $author->user_nicename );
		$is_owner           = is_user_logged_in() && get_current_user_id() === $user_id;
		$can_write          = $is_owner && Penalis_Account_Status::can_write( $user_id );
		$post_count         = (int) count_user_posts( $user_id, 'post', true );
		$joined             = Penalis_Datetime::format_indonesian_month_year_gmt( (string) $author->user_registered );
		$bio                = Penalis_Policy::normalize_bio( (string) get_user_meta( $user_id, 'description', true ) );
		$is_bio_collapsible = '' !== $bio && ( Penalis_Policy::bio_length( $bio ) > Penalis_Policy::MAX_BIO || substr_count( $bio, "\n" ) >= 3 );
		$daily_quote        = Penalis_Daily_Quotes::get_active_for_user( $user_id );

		return array(
			'id'                   => $user_id,
			'display_name'         => (string) $author->display_name,
			'username'             => (string) $author->user_nicename,
			'avatar_url'           => (string) Penalis_Avatar::get_url( $user_id ),
			'profile_url'          => $profile_url,
			'profile_url_label'    => self::format_profile_url_label( $profile_url ),
			'bio'                  => $bio,
			'is_bio_collapsible'   => $is_bio_collapsible,
			'is_verified'          => Penalis_Authors::is_user_verified( $user_id ),
			'is_senator'           => Penalis_Authors::is_user_senator( $user_id ),
			'post_count'           => max( 0, $post_count ),
			'total_views'          => self::get_author_total_views( $user_id ),
			'total_likes'          => self::get_author_total_likes( $user_id ),
			'joined_label'         => $joined,
			'is_owner'             => $is_owner,
			'can_write'            => $can_write,
			'edit_profile_url'     => Penalis_Dashboard::dashboard_url( array( 'view' => 'profile' ) ),
			'new_article_url'      => Penalis_Dashboard::dashboard_url( array( 'view' => 'editor' ) ),
			'new_quote_url'        => Penalis_Dashboard::dashboard_url() . '#kalimat-hari-ini',
			'directory_url'        => self::directory_url(),
			'daily_quotes_url'     => Penalis_Daily_Quotes::page_url(),
			'daily_quote'          => $daily_quote,
			'daily_quote_relative' => is_array( $daily_quote )
				? Penalis_Daily_Quotes::format_relative_time( (int) $daily_quote['createdAt'] )
				: '',
		);
	}
}
