<?php
/**
 * Built-in post views counter with Post Views Counter migration and Atlas theme bridge.
 *
 * Replaces the external `post-views-counter` plugin with a lean lifetime-total
 * table (`{prefix}penalis_post_views`), cache-safe JS beacon counting, bot
 * filtering, configurable cookie deduplication, staff exclusion, `orderby=post_views`
 * support for the Atlas theme, and administrator override controls.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages published post view counts, migration from PVC, and admin overrides.
 */
final class Penalis_Views {

	public const TABLE_SUFFIX          = 'penalis_post_views';
	public const PVC_TABLE_SUFFIX      = 'post_views';
	public const SCHEMA_VERSION        = '1';
	public const OPTION_SCHEMA_VERSION = 'penalis_views_schema_version';
	public const OPTION_MIGRATED       = 'penalis_views_pvc_migrated';
	public const OPTION_DEDUP_MINUTES  = 'penalis_views_dedup_minutes';
	public const OPTION_EXCLUDE_STAFF  = 'penalis_views_exclude_staff';
	public const OPTION_AUDIT          = 'penalis_views_audit';
	public const DEFAULT_DEDUP_MINUTES = 30;
	public const MAX_DEDUP_MINUTES     = 1440;
	public const COOKIE_PREFIX         = 'penalis_viewed_';
	public const SCRIPT_HANDLE         = 'penalis-post-views';
	public const EDITOR_SCRIPT_HANDLE  = 'penalis-admin-views-editor';
	public const QUICK_EDIT_HANDLE     = 'penalis-admin-views-quick-edit';
	public const ADMIN_COLUMN_KEY      = 'penalis_views';
	public const META_BOX_NONCE        = 'penalis_save_views_meta';

	/**
	 * Register lifecycle hooks.
	 *
	 * Atlas compatibility shim is deferred to `plugins_loaded` priority 20 so
	 * every active plugin has already loaded; when real Post Views Counter is
	 * still active, Penalis stays out of its way until PVC is deactivated.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_bootstrap_runtime' ), 20 );
		add_action( 'init', array( __CLASS__, 'maybe_install_and_migrate' ), 16 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'delete_post', array( __CLASS__, 'handle_delete_post' ) );
	}

	/**
	 * Whether the external Post Views Counter plugin itself is currently active.
	 *
	 * Returns false when only Penalis's own compatibility shim is loaded.
	 *
	 * @return bool
	 */
	public static function is_pvc_active() {
		return class_exists( 'Post_Views_Counter' ) && ! defined( 'PENALIS_PVC_COMPAT_SHIM' );
	}

	/**
	 * Bootstrap the Atlas compatibility bridge, `WP_Query` sorting, frontend
	 * beacon, and wp-admin overrides when external Post Views Counter is not active.
	 *
	 * @return void
	 */
	public static function maybe_bootstrap_runtime() {
		if ( self::is_pvc_active() ) {
			return;
		}

		$compat_class_file = PENALIS_PLATFORM_DIR . 'includes/pvc-compat-class.php';
		if ( is_readable( $compat_class_file ) ) {
			require_once $compat_class_file;
		}

		$compat_file = PENALIS_PLATFORM_DIR . 'includes/pvc-compat.php';
		if ( is_readable( $compat_file ) ) {
			require_once $compat_file;
		}

		add_filter( 'posts_clauses', array( __CLASS__, 'filter_posts_clauses' ), 20, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_assets' ) );

		if ( is_admin() ) {
			add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_editor_assets' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_quick_edit_assets' ) );
			add_action( 'add_meta_boxes_post', array( __CLASS__, 'register_classic_meta_box' ) );
			add_action( 'save_post_post', array( __CLASS__, 'save_classic_meta_box' ), 10, 2 );
			add_filter( 'manage_post_posts_columns', array( __CLASS__, 'register_posts_column' ) );
			add_action( 'manage_post_posts_custom_column', array( __CLASS__, 'render_posts_column' ), 10, 2 );
			add_filter( 'manage_edit-post_sortable_columns', array( __CLASS__, 'register_sortable_column' ) );
			add_action( 'quick_edit_custom_box', array( __CLASS__, 'render_quick_edit_fields' ), 10, 2 );
		}
	}

	/**
	 * Return the full database table name for Penalis post views.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		$prefix = ( isset( $wpdb ) && is_object( $wpdb ) && ! empty( $wpdb->prefix ) ) ? (string) $wpdb->prefix : 'wp_';

		return $prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Return the legacy Post Views Counter database table name.
	 *
	 * @return string
	 */
	public static function pvc_table_name() {
		global $wpdb;

		$prefix = ( isset( $wpdb ) && is_object( $wpdb ) && ! empty( $wpdb->prefix ) ) ? (string) $wpdb->prefix : 'wp_';

		return $prefix . self::PVC_TABLE_SUFFIX;
	}

	/**
	 * Whether real `$wpdb` SQL queries are available (false in plain-PHP stubs).
	 *
	 * @return bool
	 */
	private static function has_wpdb_tables() {
		global $wpdb;

		return isset( $wpdb )
			&& is_object( $wpdb )
			&& method_exists( $wpdb, 'get_var' )
			&& method_exists( $wpdb, 'get_results' )
			&& method_exists( $wpdb, 'query' )
			&& ! empty( $wpdb->posts );
	}

	/**
	 * Create the `{prefix}penalis_post_views` table if it does not yet exist.
	 *
	 * @return bool True when the table is ready.
	 */
	public static function ensure_table() {
		if ( ! self::has_wpdb_tables() ) {
			update_option( self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION, true );
			return true;
		}

		global $wpdb;

		$table           = self::table_name();
		$charset_collate = method_exists( $wpdb, 'get_charset_collate' ) ? (string) $wpdb->get_charset_collate() : '';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$created = $wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				post_id bigint(20) unsigned NOT NULL,
				views bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY (post_id),
				KEY views_desc (views, post_id)
			) {$charset_collate}"
		);
		// phpcs:enable

		if ( false !== $created ) {
			update_option( self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION, true );
			return true;
		}

		return false;
	}

	/**
	 * Ensure the views table exists and run the one-time automatic PVC import.
	 *
	 * Guarded by autoloaded flag options so normal requests run zero extra queries.
	 *
	 * @return void
	 */
	public static function maybe_install_and_migrate() {
		if ( self::SCHEMA_VERSION !== (string) get_option( self::OPTION_SCHEMA_VERSION, '' ) ) {
			self::ensure_table();
		}

		if ( '1' !== (string) get_option( self::OPTION_MIGRATED, '0' ) ) {
			self::migrate_from_pvc();
		}
	}

	/**
	 * Check whether the legacy Post Views Counter table exists in the database.
	 *
	 * @return bool
	 */
	public static function pvc_table_exists() {
		if ( ! self::has_wpdb_tables() ) {
			return isset( $GLOBALS['penalis_test_pvc_views'] ) && is_array( $GLOBALS['penalis_test_pvc_views'] );
		}

		global $wpdb;

		$pvc_table = self::pvc_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Checking legacy plugin table presence.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pvc_table ) );

		return (string) $found === $pvc_table;
	}

	/**
	 * Non-destructively copy lifetime total views (`type = 4, period = 'total'`)
	 * from Post Views Counter into `{prefix}penalis_post_views`.
	 *
	 * Uses `GREATEST(views, VALUES(views))` so repeated syncs never lower a
	 * count that has already incremented or been manually adjusted.
	 *
	 * @return array{tableFound:bool,importedRows:int}
	 */
	public static function migrate_from_pvc() {
		self::ensure_table();

		if ( ! self::pvc_table_exists() ) {
			update_option( self::OPTION_MIGRATED, '1', true );

			return array(
				'tableFound'   => false,
				'importedRows' => 0,
			);
		}

		if ( ! self::has_wpdb_tables() ) {
			$imported = 0;
			$source   = isset( $GLOBALS['penalis_test_pvc_views'] ) && is_array( $GLOBALS['penalis_test_pvc_views'] )
				? $GLOBALS['penalis_test_pvc_views']
				: array();

			foreach ( $source as $pid => $cnt ) {
				$pid = (int) $pid;
				$cnt = max( 0, (int) $cnt );
				if ( $pid <= 0 || $cnt <= 0 ) {
					continue;
				}
				$current = isset( $GLOBALS['penalis_test_post_views'][ $pid ] )
					? (int) $GLOBALS['penalis_test_post_views'][ $pid ]
					: 0;
				if ( $cnt > $current ) {
					$GLOBALS['penalis_test_post_views'][ $pid ] = $cnt;
					++$imported;
				}
			}

			update_option( self::OPTION_MIGRATED, '1', true );

			return array(
				'tableFound'   => true,
				'importedRows' => $imported,
			);
		}

		global $wpdb;

		$table     = self::table_name();
		$pvc_table = self::pvc_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$affected = $wpdb->query(
			"INSERT INTO {$table} (post_id, views)
			SELECT id, count
			FROM {$pvc_table}
			WHERE type = 4 AND period = 'total' AND count > 0
			ON DUPLICATE KEY UPDATE views = GREATEST(views, VALUES(views))"
		);
		// phpcs:enable

		update_option( self::OPTION_MIGRATED, '1', true );

		return array(
			'tableFound'   => true,
			'importedRows' => is_numeric( $affected ) ? max( 0, (int) $affected ) : 0,
		);
	}

	/**
	 * Report migration status between legacy Post Views Counter and Penalis Views.
	 *
	 * @return array{pvcPluginActive:bool,pvcTableExists:bool,pvcTotalPosts:int,penalisTotalPosts:int,pendingUpdates:int}
	 */
	public static function pvc_migration_status() {
		$pvc_active   = self::is_pvc_active();
		$table_exists = self::pvc_table_exists();

		if ( ! self::has_wpdb_tables() ) {
			$pvc_rows     = isset( $GLOBALS['penalis_test_pvc_views'] ) && is_array( $GLOBALS['penalis_test_pvc_views'] )
				? $GLOBALS['penalis_test_pvc_views']
				: array();
			$penalis_rows = isset( $GLOBALS['penalis_test_post_views'] ) && is_array( $GLOBALS['penalis_test_post_views'] )
				? $GLOBALS['penalis_test_post_views']
				: array();

			$pending = 0;
			foreach ( $pvc_rows as $pid => $cnt ) {
				$cur = isset( $penalis_rows[ $pid ] ) ? (int) $penalis_rows[ $pid ] : 0;
				if ( (int) $cnt > $cur ) {
					++$pending;
				}
			}

			return array(
				'pvcPluginActive'   => $pvc_active,
				'pvcTableExists'    => $table_exists,
				'pvcTotalPosts'     => count( $pvc_rows ),
				'penalisTotalPosts' => count( $penalis_rows ),
				'pendingUpdates'    => $pending,
			);
		}

		global $wpdb;

		$table = self::table_name();
		self::ensure_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$penalis_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		if ( ! $table_exists ) {
			return array(
				'pvcPluginActive'   => $pvc_active,
				'pvcTableExists'    => false,
				'pvcTotalPosts'     => 0,
				'penalisTotalPosts' => $penalis_total,
				'pendingUpdates'    => 0,
			);
		}

		$pvc_table = self::pvc_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pvc_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pvc_table} WHERE type = 4 AND period = 'total' AND count > 0" );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pending = (int) $wpdb->get_var(
			"SELECT COUNT(*)
			FROM {$pvc_table} pvc
			LEFT JOIN {$table} pv ON pv.post_id = pvc.id
			WHERE pvc.type = 4 AND pvc.period = 'total' AND pvc.count > 0
				AND pvc.count > COALESCE(pv.views, 0)"
		);
		// phpcs:enable

		return array(
			'pvcPluginActive'   => $pvc_active,
			'pvcTableExists'    => true,
			'pvcTotalPosts'     => $pvc_total,
			'penalisTotalPosts' => $penalis_total,
			'pendingUpdates'    => $pending,
		);
	}

	/**
	 * Effective deduplication window in minutes (`0` disables cookie dedup).
	 *
	 * @return int
	 */
	public static function dedup_minutes() {
		$raw = get_option( self::OPTION_DEDUP_MINUTES, null );
		if ( null === $raw || false === $raw || '' === $raw ) {
			return self::DEFAULT_DEDUP_MINUTES;
		}

		return self::sanitize_dedup_minutes( $raw );
	}

	/**
	 * Normalize a submitted deduplication window in minutes.
	 *
	 * @param mixed $value Raw input.
	 * @return int Integer in `[0, MAX_DEDUP_MINUTES]`.
	 */
	public static function sanitize_dedup_minutes( $value ) {
		if ( ! is_scalar( $value ) || ! is_numeric( trim( (string) $value ) ) ) {
			return self::DEFAULT_DEDUP_MINUTES;
		}

		$minutes = (int) $value;
		if ( $minutes < 0 ) {
			return 0;
		}

		return min( self::MAX_DEDUP_MINUTES, $minutes );
	}

	/**
	 * Whether visits from signed-in Administrators and Editors are excluded.
	 *
	 * Enabled (`true`) by default.
	 *
	 * @return bool
	 */
	public static function should_exclude_staff() {
		return '0' !== (string) get_option( self::OPTION_EXCLUDE_STAFF, '1' );
	}

	/**
	 * Whether the current signed-in visitor should be excluded from counting.
	 *
	 * @return bool
	 */
	public static function is_current_visitor_excluded() {
		if ( ! self::should_exclude_staff() ) {
			return false;
		}

		return is_user_logged_in() && current_user_can( 'edit_others_posts' );
	}

	/**
	 * Whether the current user may manually override view counts in wp-admin.
	 *
	 * Restricted to administrators (`manage_options`).
	 *
	 * @return bool
	 */
	public static function can_admin_edit() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Whether an HTTP request appears to come from an automated bot, crawler,
	 * scraper, or browser prefetch/prerender probe.
	 *
	 * @param string|null              $user_agent Optional User-Agent header (defaults to `$_SERVER`).
	 * @param array<string,mixed>|null $server     Optional server map (defaults to `$_SERVER`).
	 * @return bool
	 */
	public static function is_bot_request( $user_agent = null, $server = null ) {
		if ( null === $server ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only header inspection.
			$server = isset( $_SERVER ) && is_array( $_SERVER ) ? $_SERVER : array();
		}

		$purpose_keys = array( 'HTTP_SEC_PURPOSE', 'HTTP_PURPOSE', 'HTTP_X_PURPOSE', 'HTTP_X_MOZ' );
		foreach ( $purpose_keys as $key ) {
			if ( ! empty( $server[ $key ] ) && is_scalar( $server[ $key ] ) ) {
				$purpose = strtolower( trim( (string) $server[ $key ] ) );
				if (
					false !== strpos( $purpose, 'prefetch' )
					|| false !== strpos( $purpose, 'prerender' )
					|| false !== strpos( $purpose, 'preview' )
				) {
					return true;
				}
			}
		}

		if ( null === $user_agent ) {
			$user_agent = isset( $server['HTTP_USER_AGENT'] ) && is_scalar( $server['HTTP_USER_AGENT'] )
				? (string) $server['HTTP_USER_AGENT']
				: '';
		}

		$ua = trim( (string) $user_agent );
		if ( '' === $ua ) {
			return true;
		}

		$pattern = '/bot|crawl|spider|slurp|mediapartners|facebookexternalhit|facebot|whatsapp|telegrambot|twitterbot|linkedinbot|discordbot|pinterest|bingpreview|googleother|google-inspectiontool|gptbot|claudebot|anthropic-ai|bytespider|ahrefs|semrush|mj12bot|dotbot|petalbot|yandex|baiduspider|sogou|duckduckbot|applebot|headlesschrome|phantomjs|puppeteer|playwright|selenium|curl\/|wget\/|python-requests|python-urllib|go-http-client|libwww-perl|axios\/|node-fetch|postmanruntime|insomnia/i';

		return 1 === preg_match( $pattern, $ua );
	}

	/**
	 * Whether a post is eligible for public view counting.
	 *
	 * @param WP_Post|int|null $post Post object or ID.
	 * @return WP_Post|null
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
	 * Return lifetime views for a single post.
	 *
	 * Delegates to real Post Views Counter while PVC is still active so counts
	 * stay live during transition, then reads directly from `{prefix}penalis_post_views`.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function get_views( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return 0;
		}

		if ( self::is_pvc_active() && function_exists( 'pvc_get_post_views' ) ) {
			return max( 0, (int) pvc_get_post_views( $post_id ) );
		}

		if ( ! self::has_wpdb_tables() ) {
			return isset( $GLOBALS['penalis_test_post_views'][ $post_id ] )
				? max( 0, (int) $GLOBALS['penalis_test_post_views'][ $post_id ] )
				: 0;
		}

		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$val = $wpdb->get_var( $wpdb->prepare( "SELECT views FROM {$table} WHERE post_id = %d", $post_id ) );

		return max( 0, (int) $val );
	}

	/**
	 * Return the combined lifetime views across a list of post IDs.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return int
	 */
	public static function get_views_sum( array $post_ids ) {
		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $post_ids ),
					static function ( $id ) {
						return $id > 0;
					}
				)
			)
		);

		if ( empty( $ids ) ) {
			return 0;
		}

		if ( self::is_pvc_active() && function_exists( 'pvc_get_post_views' ) ) {
			return max( 0, (int) pvc_get_post_views( $ids ) );
		}

		if ( ! self::has_wpdb_tables() ) {
			$total = 0;
			foreach ( $ids as $id ) {
				if ( isset( $GLOBALS['penalis_test_post_views'][ $id ] ) ) {
					$total += max( 0, (int) $GLOBALS['penalis_test_post_views'][ $id ] );
				}
			}

			return $total;
		}

		global $wpdb;

		$table        = self::table_name();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$sum = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(views), 0) FROM {$table} WHERE post_id IN ({$placeholders})",
				$ids
			)
		);
		// phpcs:enable

		return max( 0, (int) $sum );
	}

	/**
	 * Return total lifetime views across all published posts authored by a user.
	 *
	 * @param int $user_id Author user ID.
	 * @return int
	 */
	public static function get_author_total_views( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return 0;
		}

		$map = self::get_authors_views_map( array( $user_id ) );

		return isset( $map[ $user_id ] ) ? (int) $map[ $user_id ] : 0;
	}

	/**
	 * Batch-resolve total lifetime views across published posts for a list of authors.
	 *
	 * Executes a single indexed SQL JOIN when powered by `penalis_post_views`.
	 *
	 * @param int[] $user_ids Author user IDs.
	 * @return array<int,int> Map of user ID => total views.
	 */
	public static function get_authors_views_map( array $user_ids ) {
		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $user_ids ),
					static function ( $id ) {
						return $id > 0;
					}
				)
			)
		);

		if ( empty( $ids ) ) {
			return array();
		}

		$result = array_fill_keys( $ids, 0 );

		if ( ! self::has_wpdb_tables() ) {
			$posts = isset( $GLOBALS['penalis_test_posts'] ) && is_array( $GLOBALS['penalis_test_posts'] )
				? $GLOBALS['penalis_test_posts']
				: array();

			foreach ( $posts as $post ) {
				if (
					$post instanceof WP_Post
					&& 'post' === $post->post_type
					&& 'publish' === $post->post_status
					&& isset( $result[ (int) $post->post_author ] )
				) {
					$result[ (int) $post->post_author ] += self::get_views( (int) $post->ID );
				}
			}

			return $result;
		}

		global $wpdb;

		if ( self::is_pvc_active() && function_exists( 'pvc_get_post_views' ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_author
					FROM {$wpdb->posts}
					WHERE post_type = 'post'
						AND post_status = 'publish'
						AND post_author IN ({$placeholders})",
					$ids
				),
				ARRAY_A
			);
			// phpcs:enable

			$by_author = array();
			if ( is_array( $rows ) ) {
				foreach ( $rows as $row ) {
					$pid = isset( $row['ID'] ) ? (int) $row['ID'] : 0;
					$aid = isset( $row['post_author'] ) ? (int) $row['post_author'] : 0;
					if ( $pid > 0 && isset( $result[ $aid ] ) ) {
						$by_author[ $aid ][] = $pid;
					}
				}
			}

			foreach ( $by_author as $aid => $post_ids ) {
				$result[ $aid ] = max( 0, (int) pvc_get_post_views( $post_ids ) );
			}

			return $result;
		}

		$table        = self::table_name();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.post_author, COALESCE(SUM(pv.views), 0) AS total_views
				FROM {$wpdb->posts} p
				INNER JOIN {$table} pv ON pv.post_id = p.ID
				WHERE p.post_type = 'post'
					AND p.post_status = 'publish'
					AND p.post_author IN ({$placeholders})
				GROUP BY p.post_author",
				$ids
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$aid = isset( $row['post_author'] ) ? (int) $row['post_author'] : 0;
				if ( isset( $result[ $aid ] ) ) {
					$result[ $aid ] = max( 0, (int) $row['total_views'] );
				}
			}
		}

		return $result;
	}

	/**
	 * Atomically increment the view counter for a published post by 1.
	 *
	 * @param int $post_id Post ID.
	 * @return int|WP_Error Updated view count, or error when post is ineligible.
	 */
	public static function increment_views( $post_id ) {
		$post = self::resolve_eligible_post( $post_id );
		if ( ! $post ) {
			return new WP_Error(
				'penalis_not_found',
				'Karya tulis tidak ditemukan.',
				array( 'status' => 404 )
			);
		}

		$target_id = (int) $post->ID;

		if ( ! self::has_wpdb_tables() ) {
			$current = isset( $GLOBALS['penalis_test_post_views'][ $target_id ] )
				? max( 0, (int) $GLOBALS['penalis_test_post_views'][ $target_id ] )
				: 0;

			$GLOBALS['penalis_test_post_views'][ $target_id ] = $current + 1;

			return $current + 1;
		}

		global $wpdb;

		$table = self::table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (post_id, views) VALUES (%d, 1) ON DUPLICATE KEY UPDATE views = views + 1",
				$target_id
			)
		);
		// phpcs:enable

		return self::get_views( $target_id );
	}

	/**
	 * Set an explicit view count on a `post` (administrator override).
	 *
	 * @param int $post_id Post ID.
	 * @param int $views   Non-negative view count.
	 * @return array{postId:int,views:int}|WP_Error
	 */
	public static function set_views( $post_id, $views ) {
		$post = get_post( (int) $post_id );
		if ( ! ( $post instanceof WP_Post ) || 'post' !== $post->post_type ) {
			return new WP_Error(
				'penalis_not_found',
				'Karya tulis tidak ditemukan.',
				array( 'status' => 404 )
			);
		}

		$target_id  = (int) $post->ID;
		$next_views = max( 0, (int) $views );

		if ( ! self::has_wpdb_tables() ) {
			$GLOBALS['penalis_test_post_views'][ $target_id ] = $next_views;
		} else {
			global $wpdb;

			$table = self::table_name();

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table} (post_id, views) VALUES (%d, %d) ON DUPLICATE KEY UPDATE views = VALUES(views)",
					$target_id,
					$next_views
				)
			);
			// phpcs:enable
		}

		if ( class_exists( 'Penalis_Authors' ) ) {
			Penalis_Authors::invalidate_cache();
		}

		return array(
			'postId' => $target_id,
			'views'  => $next_views,
		);
	}

	/**
	 * Remove a deleted post's row from `{prefix}penalis_post_views`.
	 *
	 * @param int $post_id Deleted post ID.
	 * @return void
	 */
	public static function handle_delete_post( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}

		if ( ! self::has_wpdb_tables() ) {
			unset( $GLOBALS['penalis_test_post_views'][ $post_id ] );
			return;
		}

		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE post_id = %d", $post_id ) );
	}

	/**
	 * Support `orderby => 'post_views'` on `WP_Query` for the Atlas theme and
	 * wp-admin sortable Views column.
	 *
	 * Handles string (`'post_views'`, `'post_views date'`) and array
	 * (`array( 'post_views' => 'DESC' )`) orderby specifications.
	 *
	 * @param array<string,string> $clauses SQL clauses (`fields`, `join`, `where`, `orderby`, etc.).
	 * @param WP_Query             $query   Query instance.
	 * @return array<string,string>
	 */
	public static function filter_posts_clauses( $clauses, $query ) {
		if ( ! is_array( $clauses ) || ! is_object( $query ) || ! isset( $query->query_vars['orderby'] ) ) {
			return $clauses;
		}

		$orderby_var = $query->query_vars['orderby'];
		$direction   = '';

		if ( is_string( $orderby_var ) ) {
			if ( 1 === preg_match( '/(?:^|\s)post_views(?:\s|$)/', $orderby_var ) ) {
				$raw_order = isset( $query->query_vars['order'] ) ? strtoupper( (string) $query->query_vars['order'] ) : 'DESC';
				$direction = 'ASC' === $raw_order ? 'ASC' : 'DESC';
			}
		} elseif ( is_array( $orderby_var ) && array_key_exists( 'post_views', $orderby_var ) ) {
			$raw_order = strtoupper( (string) $orderby_var['post_views'] );
			$direction = 'ASC' === $raw_order ? 'ASC' : 'DESC';
		}

		if ( '' === $direction ) {
			return $clauses;
		}

		global $wpdb;

		$table       = self::table_name();
		$posts_table = ( isset( $wpdb ) && is_object( $wpdb ) && ! empty( $wpdb->posts ) ) ? (string) $wpdb->posts : 'wp_posts';
		$alias       = 'penalis_pv';

		if ( false === strpos( (string) $clauses['join'], "AS {$alias}" ) ) {
			$clauses['join'] = trim( (string) $clauses['join'] . " LEFT JOIN {$table} AS {$alias} ON {$alias}.post_id = {$posts_table}.ID" );
		}

		$primary_order      = "COALESCE({$alias}.views, 0) {$direction}, {$posts_table}.post_date DESC";
		$clauses['orderby'] = $primary_order;

		return $clauses;
	}

	/**
	 * Register REST routes for public view counting and administrator overrides.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			Penalis_Rest_Api::NAMESPACE_V1,
			'/posts/(?P<id>\d+)/view',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'handle_post_view' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'id' => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'handle_admin_update_views' ),
					'permission_callback' => array( __CLASS__, 'can_admin_edit' ),
					'args'                => array(
						'id'    => array(
							'type'     => 'integer',
							'required' => true,
						),
						'views' => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Handle `POST /penalis/v1/posts/{id}/view`.
	 *
	 * Filters bots/prefetch probes, excludes logged-in staff when configured,
	 * respects cookie deduplication, and applies per-IP rate limiting.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_post_view( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$post    = self::resolve_eligible_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'penalis_not_found',
				'Karya tulis tidak ditemukan.',
				array( 'status' => 404 )
			);
		}

		if ( self::is_pvc_active() ) {
			return self::no_store_response(
				array(
					'postId'  => (int) $post->ID,
					'views'   => self::get_views( (int) $post->ID ),
					'counted' => false,
				)
			);
		}

		if ( self::is_bot_request() || self::is_current_visitor_excluded() ) {
			return self::no_store_response(
				array(
					'postId'  => (int) $post->ID,
					'views'   => self::get_views( (int) $post->ID ),
					'counted' => false,
				)
			);
		}

		$dedup_minutes = self::dedup_minutes();
		$cookie_name   = self::COOKIE_PREFIX . (int) $post->ID;

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Presence check only.
		if ( $dedup_minutes > 0 && isset( $_COOKIE[ $cookie_name ] ) && '' !== (string) wp_unslash( $_COOKIE[ $cookie_name ] ) ) {
			return self::no_store_response(
				array(
					'postId'  => (int) $post->ID,
					'views'   => self::get_views( (int) $post->ID ),
					'counted' => false,
				)
			);
		}

		$ip    = Penalis_Rate_Limiter::client_ip();
		$guard = Penalis_Rate_Limiter::guard(
			'post_view_ip',
			$ip,
			'Terlalu banyak permintaan dalam waktu singkat.'
		);
		if ( is_wp_error( $guard ) ) {
			return self::no_store_response(
				array(
					'postId'  => (int) $post->ID,
					'views'   => self::get_views( (int) $post->ID ),
					'counted' => false,
				)
			);
		}

		Penalis_Rate_Limiter::register( 'post_view_ip', $ip );

		$updated = self::increment_views( (int) $post->ID );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		if ( $dedup_minutes > 0 && ! headers_sent() ) {
			$secure = function_exists( 'is_ssl' ) && is_ssl();
			setcookie(
				$cookie_name,
				'1',
				array(
					'expires'  => time() + ( $dedup_minutes * MINUTE_IN_SECONDS ),
					'path'     => '/',
					'secure'   => $secure,
					'httponly' => false,
					'samesite' => 'Lax',
				)
			);
		}

		return self::no_store_response(
			array(
				'postId'  => (int) $post->ID,
				'views'   => (int) $updated,
				'counted' => true,
			)
		);
	}

	/**
	 * Handle `PUT /penalis/v1/posts/{id}/view` (administrator override).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_admin_update_views( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$views   = (int) $request->get_param( 'views' );

		if ( $views < 0 ) {
			return new WP_Error(
				'penalis_invalid_views_count',
				'Jumlah Views harus bernilai 0 atau lebih.',
				array( 'status' => 400 )
			);
		}

		$outcome = self::set_views( $post_id, $views );
		if ( is_wp_error( $outcome ) ) {
			return $outcome;
		}

		return new WP_REST_Response( $outcome, 200 );
	}

	/**
	 * Build a `200` REST response with `Cache-Control: no-store, private`.
	 *
	 * @param array<string,mixed> $payload Response payload.
	 * @return WP_REST_Response
	 */
	private static function no_store_response( array $payload ) {
		$response = new WP_REST_Response( $payload, 200 );
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}

	/**
	 * Enqueue the lightweight JS beacon on single published `post` pages.
	 *
	 * Skips automatically for logged-in staff when staff exclusion is enabled
	 * (full-page caches bypass logged-in users, so staff visits never even fire
	 * a beacon request).
	 *
	 * @return void
	 */
	public static function maybe_enqueue_assets() {
		if ( self::is_pvc_active() || ! is_singular( 'post' ) || is_preview() ) {
			return;
		}

		if ( self::is_current_visitor_excluded() ) {
			return;
		}

		$post = self::resolve_eligible_post( get_queried_object_id() );
		if ( ! $post ) {
			return;
		}

		$js = Penalis_Admin::resolve_asset_meta( 'assets/js/post-views.js' );
		wp_enqueue_script( self::SCRIPT_HANDLE, $js['url'], array(), $js['version'], true );

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'penalisPostViews',
			array(
				'postId'       => (int) $post->ID,
				'endpoint'     => rest_url( Penalis_Rest_Api::NAMESPACE_V1 . '/posts/' . (int) $post->ID . '/view' ),
				'dedupMinutes' => self::dedup_minutes(),
				'cookiePrefix' => self::COOKIE_PREFIX,
			)
		);
	}

	/**
	 * Enqueue Block Editor Post sidebar Views override control for administrators.
	 *
	 * @return void
	 */
	public static function enqueue_editor_assets() {
		if ( self::is_pvc_active() || ! self::can_admin_edit() ) {
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

		$css = Penalis_Admin::resolve_asset_meta( 'assets/css/admin.css' );
		$js  = Penalis_Admin::resolve_asset_meta( 'assets/js/admin-views-editor.js' );

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
			'penalisViewsEditor',
			array(
				'postId'   => $post_id,
				'views'    => self::get_views( $post_id ),
				'canEdit'  => true,
				'restPath' => '/' . Penalis_Rest_Api::NAMESPACE_V1 . '/posts/' . $post_id . '/view',
			)
		);
	}

	/**
	 * Register Classic Editor fallback meta box for Views.
	 *
	 * @return void
	 */
	public static function register_classic_meta_box() {
		if ( self::is_pvc_active() || ! self::can_admin_edit() ) {
			return;
		}

		add_meta_box(
			'penalis-post-views',
			'Post Views',
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
	 * Render Classic Editor meta box for Views override.
	 *
	 * @param WP_Post $post Post object.
	 * @return void
	 */
	public static function render_classic_meta_box( WP_Post $post ) {
		$views = self::get_views( (int) $post->ID );
		wp_nonce_field( self::META_BOX_NONCE, 'penalis_views_nonce' );
		?>
		<div class="penalis-classic-reactions">
			<input type="hidden" name="penalis_views_orig" value="<?php echo esc_attr( (string) $views ); ?>" />
			<p class="penalis-classic-reactions__field">
				<label for="penalis-views-count">Views</label>
				<input type="number" id="penalis-views-count" name="penalis_views_count" min="0" step="1" value="<?php echo esc_attr( (string) $views ); ?>" class="small-text" />
			</p>
		</div>
		<?php
	}

	/**
	 * Persist Classic Editor or Quick Edit Views override when changed by an admin.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public static function save_classic_meta_box( $post_id, $post ) {
		if ( self::is_pvc_active() || ! self::can_admin_edit() || ! ( $post instanceof WP_Post ) || 'post' !== $post->post_type ) {
			return;
		}

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['penalis_views_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['penalis_views_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, self::META_BOX_NONCE ) ) {
			return;
		}

		if ( ! isset( $_POST['penalis_views_count'] ) ) {
			return;
		}

		$new_views  = absint( wp_unslash( $_POST['penalis_views_count'] ) );
		$orig_views = isset( $_POST['penalis_views_orig'] ) ? absint( wp_unslash( $_POST['penalis_views_orig'] ) ) : -1;

		if ( $new_views === $orig_views ) {
			return;
		}

		self::set_views( (int) $post_id, $new_views );
	}

	/**
	 * Add a Views column to the wp-admin Posts list table.
	 *
	 * @param array<string,string> $columns Existing columns.
	 * @return array<string,string>
	 */
	public static function register_posts_column( $columns ) {
		if ( self::is_pvc_active() || ! self::can_admin_edit() || ! is_array( $columns ) ) {
			return $columns;
		}

		$updated = array();
		foreach ( $columns as $key => $label ) {
			$updated[ $key ] = $label;
			if ( 'date' === $key ) {
				$updated[ self::ADMIN_COLUMN_KEY ] = 'Views';
			}
		}

		if ( ! isset( $updated[ self::ADMIN_COLUMN_KEY ] ) ) {
			$updated[ self::ADMIN_COLUMN_KEY ] = 'Views';
		}

		return $updated;
	}

	/**
	 * Register the Views column as sortable via `orderby=post_views`.
	 *
	 * @param array<string,string> $columns Sortable columns.
	 * @return array<string,string>
	 */
	public static function register_sortable_column( $columns ) {
		if ( self::is_pvc_active() || ! self::can_admin_edit() || ! is_array( $columns ) ) {
			return $columns;
		}

		$columns[ self::ADMIN_COLUMN_KEY ] = 'post_views';

		return $columns;
	}

	/**
	 * Render the Views count and Quick Edit data attribute in the Posts column.
	 *
	 * @param string $column_name Column key.
	 * @param int    $post_id     Post ID.
	 * @return void
	 */
	public static function render_posts_column( $column_name, $post_id ) {
		if ( self::ADMIN_COLUMN_KEY !== $column_name || self::is_pvc_active() || ! self::can_admin_edit() ) {
			return;
		}

		$views = self::get_views( (int) $post_id );

		printf(
			'<span class="penalis-views-col" data-penalis-views="%1$d">%2$s</span>',
			(int) $views,
			esc_html( number_format_i18n( $views ) )
		);
	}

	/**
	 * Render the administrator-only Views input in the Posts Quick Edit panel.
	 *
	 * @param string $column_name Column key.
	 * @param string $post_type   Post type slug.
	 * @return void
	 */
	public static function render_quick_edit_fields( $column_name, $post_type ) {
		if ( self::ADMIN_COLUMN_KEY !== $column_name || 'post' !== $post_type || self::is_pvc_active() || ! self::can_admin_edit() ) {
			return;
		}
		?>
		<fieldset class="inline-edit-col-left penalis-quick-edit-reactions">
			<div class="inline-edit-col">
				<?php wp_nonce_field( self::META_BOX_NONCE, 'penalis_views_nonce' ); ?>
				<input type="hidden" name="penalis_views_orig" value="0" />
				<label class="inline-edit-group penalis-quick-edit-reactions__row">
					<span class="title">Views</span>
					<span class="input-text-wrap">
						<input type="number" name="penalis_views_count" min="0" step="1" value="0" class="penalis-quick-edit-reactions__input" />
					</span>
				</label>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Enqueue Quick Edit hydration script for Views on `/wp-admin/edit.php`.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue_quick_edit_assets( $hook ) {
		if ( 'edit.php' !== $hook || self::is_pvc_active() || ! self::can_admin_edit() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->post_type ) {
			return;
		}

		$css = Penalis_Admin::resolve_asset_meta( 'assets/css/admin.css' );
		$js  = Penalis_Admin::resolve_asset_meta( 'assets/js/admin-views-quick-edit.js' );

		wp_enqueue_style( 'penalis-admin-styles', $css['url'], array(), $css['version'] );
		wp_enqueue_script(
			self::QUICK_EDIT_HANDLE,
			$js['url'],
			array( 'inline-edit-post' ),
			$js['version'],
			true
		);
	}
}
