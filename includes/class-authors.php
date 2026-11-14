<?php
/**
 * Public authors directory integration.
 *
 * Provides the [penalis_authors] shortcode for any WordPress page.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the public authors directory shortcode, assets, and query.
 */
final class Penalis_Authors {

	public const SHORTCODE       = 'penalis_authors';
	public const BADGE_SHORTCODE = 'penalis_verified_badge';
	public const CACHE_KEY       = 'penalis_public_authors_v2';
	public const CACHE_TTL       = 600;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_shortcode' ) );
		add_shortcode( self::BADGE_SHORTCODE, array( __CLASS__, 'render_badge_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_assets' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_profile_verified_badge' ) );
		add_filter( 'template_include', array( __CLASS__, 'maybe_use_authors_template' ) );
		add_filter( 'script_loader_tag', array( __CLASS__, 'module_script_tag' ), 10, 3 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );

		// Invalidate cached directory when posts, roles, or profile data change.
		add_action( 'save_post_post', array( __CLASS__, 'invalidate_cache' ) );
		add_action( 'deleted_post', array( __CLASS__, 'invalidate_cache' ) );
		add_action( 'set_user_role', array( __CLASS__, 'invalidate_cache' ) );
		add_action( 'profile_update', array( __CLASS__, 'invalidate_cache' ) );
		add_action( 'penalis_account_status_changed', array( __CLASS__, 'invalidate_cache' ) );
		add_action( 'updated_user_meta', array( __CLASS__, 'maybe_invalidate_cache_on_meta' ), 10, 3 );
	}

	/**
	 * Whether the current request renders the public authors directory.
	 *
	 * @return bool
	 */
	public static function is_authors_request() {
		return is_page() && has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), self::SHORTCODE );
	}

	/**
	 * Add a body class to the public authors directory.
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public static function body_class( $classes ) {
		if ( self::is_authors_request() ) {
			$classes[] = 'penalis-authors-page';
		}

		return $classes;
	}

	/**
	 * Load the minimal theme-wrapped template for the authors directory.
	 *
	 * @param string $template Template path.
	 * @return string
	 */
	public static function maybe_use_authors_template( $template ) {
		if ( ! self::is_authors_request() ) {
			return $template;
		}

		/**
		 * Allow a site to opt out of the bundled authors template.
		 *
		 * @param bool $use_template Whether to use the bundled template.
		 */
		if ( ! apply_filters( 'penalis_use_authors_template', true ) ) {
			return $template;
		}

		return Penalis_Dashboard::resolve_bundled_template(
			$template,
			'penalis-authors.php',
			'authors.php'
		);
	}

	/**
	 * Enqueue the public authors directory assets only when needed.
	 *
	 * @return void
	 */
	public static function maybe_enqueue_assets() {
		if ( ! self::is_authors_request() ) {
			return;
		}

		if ( ! Penalis_Dashboard::enqueue_vite_entry( 'src/authors/index.tsx', 'penalis-authors' ) ) {
			return;
		}

		wp_localize_script(
			'penalis-authors',
			'penalisAuthors',
			array(
				'restUrl'  => esc_url_raw( rest_url( Penalis_Rest_Api::NAMESPACE_V1 ) ),
				'siteName' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'perPage'  => Penalis_Policy::AUTHORS_PER_PAGE,
			)
		);
	}

	/**
	 * Load the authors directory bundle as an ES module.
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Script handle.
	 * @param string $src    Script source URL.
	 * @return string
	 */
	public static function module_script_tag( $tag, $handle, $src ) {
		return Penalis_Dashboard::format_module_script_tag( $tag, $handle, $src, 'penalis-authors' );
	}

	/**
	 * Render the authors directory mount point.
	 *
	 * @return string
	 */
	public static function render_shortcode() {
		return Penalis_Dashboard::render_spa_mount(
			'penalis-authors-root',
			'Aset direktori penulis Penalis tidak ditemukan.',
			'Halaman Kontributor Penalis memerlukan JavaScript.'
		);
	}

	/**
	 * Query, filter, sort, and paginate public authors.
	 *
	 * @param array<string,mixed> $args Query arguments (search, sort, verified, page, per_page).
	 * @return array<string,mixed>
	 */
	public static function query_authors( $args = array() ) {
		$search   = isset( $args['search'] ) ? trim( sanitize_text_field( (string) $args['search'] ) ) : '';
		$sort     = Penalis_Policy::normalize_authors_sort( isset( $args['sort'] ) ? $args['sort'] : 'most_posts' );
		$verified = ! empty( $args['verified'] );
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : Penalis_Policy::AUTHORS_PER_PAGE;
		$per_page = max( 1, min( Penalis_Policy::AUTHORS_MAX_PER_PAGE, $per_page ) );

		$all_authors = self::get_all_eligible_authors();

		$total_all      = count( $all_authors );
		$total_verified = 0;

		foreach ( $all_authors as $author ) {
			if ( ! empty( $author['isVerified'] ) ) {
				++$total_verified;
			}
		}

		$filtered = array();

		foreach ( $all_authors as $author ) {
			if ( $verified && empty( $author['isVerified'] ) ) {
				continue;
			}

			if ( '' !== $search ) {
				$haystack = $author['displayName'] . ' ' . $author['username'];
				if ( false === stripos( $haystack, $search ) ) {
					continue;
				}
			}

			$filtered[] = $author;
		}

		$sorted = Penalis_Policy::sort_public_authors( $filtered, $sort );
		$total  = count( $sorted );
		$offset = ( $page - 1 ) * $per_page;
		$items  = array_slice( $sorted, $offset, $per_page );

		return array(
			'items'         => array_values( $items ),
			'total'         => $total,
			'totalAll'      => $total_all,
			'totalVerified' => $total_verified,
			'page'          => $page,
			'perPage'       => $per_page,
			'hasMore'       => ( $offset + count( $items ) ) < $total,
		);
	}

	/**
	 * Fetch and cache all eligible public authors.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function get_all_eligible_authors() {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single aggregate query cached via transient.
		$rows = $wpdb->get_results(
			"SELECT post_author, COUNT(*) AS post_count
			FROM {$wpdb->posts}
			WHERE post_type = 'post' AND post_status = 'publish'
			GROUP BY post_author
			HAVING post_count >= 1",
			ARRAY_A
		);

		if ( empty( $rows ) || ! is_array( $rows ) ) {
			set_transient( self::CACHE_KEY, array(), self::CACHE_TTL );
			return array();
		}

		$counts_by_user = array();
		foreach ( $rows as $row ) {
			$uid = isset( $row['post_author'] ) ? (int) $row['post_author'] : 0;
			$cnt = isset( $row['post_count'] ) ? (int) $row['post_count'] : 0;
			if ( $uid > 0 && $cnt >= 1 ) {
				$counts_by_user[ $uid ] = $cnt;
			}
		}

		if ( empty( $counts_by_user ) ) {
			set_transient( self::CACHE_KEY, array(), self::CACHE_TTL );
			return array();
		}

		$user_query = new WP_User_Query(
			array(
				'include'  => array_keys( $counts_by_user ),
				'role__in' => Penalis_Policy::public_author_roles(),
				'number'   => -1,
			)
		);

		$users   = $user_query->get_results();
		$authors = array();

		foreach ( $users as $user ) {
			if ( ! $user instanceof WP_User ) {
				continue;
			}

			$user_id    = (int) $user->ID;
			$post_count = isset( $counts_by_user[ $user_id ] ) ? (int) $counts_by_user[ $user_id ] : 0;
			$status     = Penalis_Account_Status::get_status( $user_id );
			$roles      = (array) $user->roles;

			if ( ! Penalis_Policy::is_public_author_eligible( $roles, $status, $post_count ) ) {
				continue;
			}

			$authors[] = array(
				'id'          => $user_id,
				'displayName' => (string) $user->display_name,
				'username'    => (string) $user->user_nicename,
				'avatarUrl'   => (string) Penalis_Avatar::get_url( $user_id ),
				'authorUrl'   => esc_url_raw( get_author_posts_url( $user_id, $user->user_nicename ) ),
				'postCount'   => $post_count,
				'isVerified'  => Penalis_Policy::is_verified_author( $roles, $status, Penalis_Account_Status::is_access_enabled( $user_id ) ),
			);
		}

		set_transient( self::CACHE_KEY, $authors, self::CACHE_TTL );

		return $authors;
	}

	/**
	 * Invalidate the cached authors directory.
	 *
	 * @return void
	 */
	public static function invalidate_cache() {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Invalidate cache when relevant user meta changes.
	 *
	 * @param int    $meta_id  Meta ID.
	 * @param int    $user_id  User ID.
	 * @param string $meta_key Meta key.
	 * @return void
	 */
	public static function maybe_invalidate_cache_on_meta( $meta_id, $user_id, $meta_key ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
		$watched = array(
			Penalis_Account_Status::META_STATUS,
			Penalis_Account_Status::META_ACCESS,
			'_penalis_avatar_id',
		);

		if ( in_array( (string) $meta_key, $watched, true ) ) {
			self::invalidate_cache();
		}
	}

	/**
	 * Whether a WordPress user qualifies for the verified active writer badge.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_user_verified( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return false;
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User ) {
			return false;
		}

		return Penalis_Policy::is_verified_author(
			(array) $user->roles,
			Penalis_Account_Status::get_status( $user_id ),
			Penalis_Account_Status::is_access_enabled( $user_id )
		);
	}

	/**
	 * Return the inline HTML for the verified active writer badge.
	 *
	 * @return string
	 */
	public static function verified_badge_html() {
		return '<span class="penalis-verified-badge-inline" title="Penulis Aktif terverifikasi" aria-label="Penulis Aktif terverifikasi" style="display:inline-flex;align-items:center;vertical-align:middle;position:relative;top:-0.08em;margin-left:0.32em;color:#1d9bf0;line-height:1;">'
			. '<svg width="0.9em" height="0.9em" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">'
			. '<path d="M22.25 12c0-1.43-.88-2.67-2.19-3.34.46-1.39.2-2.9-.81-3.91s-2.52-1.27-3.91-.81c-.66-1.31-1.91-2.19-3.34-2.19s-2.67.88-3.33 2.19c-1.4-.46-2.91-.2-3.92.81s-1.26 2.52-.8 3.91c-1.31.67-2.2 1.91-2.2 3.34s.89 2.67 2.2 3.34c-.46 1.39-.21 2.9.8 3.91s2.52 1.26 3.91.81c.67 1.31 1.91 2.19 3.34 2.19s2.68-.88 3.34-2.19c1.39.45 2.9.2 3.91-.81s1.27-2.52.81-3.91c1.31-.67 2.19-1.91 2.19-3.34zm-11.71 4.2L6.8 12.46l1.41-1.42 2.26 2.26 4.8-5.23 1.47 1.36-6.2 6.77z" />'
			. '</svg>'
			. '</span>';
	}

	/**
	 * Resolve the author user ID from the current WordPress query context.
	 *
	 * @param string|string[] $singular_post_types Optional post type filter for `is_singular()`.
	 * @return int
	 */
	private static function resolve_context_author_id( $singular_post_types = '' ) {
		if ( is_author() ) {
			return (int) get_queried_object_id();
		}

		if ( is_singular( $singular_post_types ) ) {
			return (int) get_post_field( 'post_author', get_queried_object_id() );
		}

		return 0;
	}

	/**
	 * Render the [penalis_verified_badge] shortcode.
	 *
	 * @param array<string,mixed> $atts Shortcode attributes.
	 * @return string
	 */
	public static function render_badge_shortcode( $atts = array() ) {
		$atts    = shortcode_atts( array( 'user_id' => 0 ), (array) $atts, self::BADGE_SHORTCODE );
		$user_id = (int) $atts['user_id'];

		if ( $user_id <= 0 ) {
			$user_id = self::resolve_context_author_id();
		}

		if ( ! self::is_user_verified( $user_id ) ) {
			return '';
		}

		return self::verified_badge_html();
	}

	/**
	 * Attach the verified badge on single post author boxes and public author
	 * profile headings when the author is a verified active writer.
	 *
	 * @return void
	 */
	public static function render_profile_verified_badge() {
		$author_id = self::resolve_context_author_id( 'post' );

		if ( ! self::is_user_verified( $author_id ) ) {
			return;
		}

		$badge_json = wp_json_encode( self::verified_badge_html() );
		if ( ! is_string( $badge_json ) ) {
			return;
		}
		?>
		<script>
		(function(){
			var badgeHtml = <?php echo $badge_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-encoded static badge markup. ?>;
			var selectors = [
				'.article-author .author-name a.url',
				'h1.elementor-heading-title'
			];
			for (var i = 0; i < selectors.length; i++) {
				var nodes = document.querySelectorAll(selectors[i]);
				for (var j = 0; j < nodes.length; j++) {
					var el = nodes[j];
					if (el && !el.querySelector('.penalis-verified-badge-inline')) {
						el.insertAdjacentHTML('beforeend', badgeHtml);
					}
				}
			}
		})();
		</script>
		<?php
	}
}

