<?php
/**
 * #KalimatHariIni ephemeral daily writer quotes.
 *
 * Allows active writers to publish a single short quote (max 200 characters)
 * that stays visible for 24 hours on their public author archive and on the
 * public `/kalimat-hari-ini/` showcase page (`[penalis_kalimat_hari_ini]`).
 *
 * No history is kept: once a quote expires or is deleted, only a rolling
 * 24-hour timestamp log remains to enforce the 3 publishes per 24 hours quota.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages #KalimatHariIni persistence, expiry, public feed, and shortcode page.
 */
final class Penalis_Daily_Quotes {

	public const SHORTCODE         = 'penalis_kalimat_hari_ini';
	public const PAGE_SLUG         = 'kalimat-hari-ini';
	public const OPTION_PAGE_ID    = 'penalis_daily_quotes_page_id';
	public const OPTION_PAGE_OWNED = 'penalis_daily_quotes_page_created_by_plugin';
	public const META_QUOTE        = '_penalis_daily_quote';
	public const META_EXPIRES      = '_penalis_daily_quote_expires';
	public const META_PUBLISH_LOG  = '_penalis_daily_quote_publishes';
	public const CACHE_KEY         = 'penalis_daily_quotes_v1';
	public const CACHE_TTL         = 120;
	public const ASSET_HANDLE      = 'penalis-daily-quotes';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_assets' ) );
		add_filter( 'template_include', array( __CLASS__, 'maybe_use_template' ) );
		add_filter( 'script_loader_tag', array( __CLASS__, 'module_script_tag' ), 10, 3 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );

		// Invalidate cached quotes when account status, roles, or profile data change.
		add_action( 'set_user_role', array( __CLASS__, 'invalidate_cache' ) );
		add_action( 'profile_update', array( __CLASS__, 'invalidate_cache' ) );
		add_action( 'penalis_account_status_changed', array( __CLASS__, 'invalidate_cache' ) );
		add_action( 'added_user_meta', array( __CLASS__, 'maybe_invalidate_cache_on_meta' ), 10, 3 );
		add_action( 'updated_user_meta', array( __CLASS__, 'maybe_invalidate_cache_on_meta' ), 10, 3 );
		add_action( 'deleted_user_meta', array( __CLASS__, 'maybe_invalidate_cache_on_meta' ), 10, 3 );
	}

	/**
	 * Public #KalimatHariIni page ID.
	 *
	 * @return int
	 */
	public static function page_id() {
		return (int) get_option( self::OPTION_PAGE_ID, 0 );
	}

	/**
	 * Public #KalimatHariIni page URL.
	 *
	 * @param array<string,mixed> $args Optional query arguments.
	 * @return string
	 */
	public static function page_url( $args = array() ) {
		$page_id = self::page_id();
		$url     = $page_id > 0 ? get_permalink( $page_id ) : '';

		if ( ! is_string( $url ) || '' === $url ) {
			if ( function_exists( 'get_page_by_path' ) ) {
				$page = get_page_by_path( self::PAGE_SLUG );
				if ( $page instanceof WP_Post && 'publish' === $page->post_status ) {
					$permalink = get_permalink( $page );
					if ( is_string( $permalink ) && '' !== $permalink ) {
						$url = $permalink;
					}
				}
			}
		}

		if ( ! is_string( $url ) || '' === $url ) {
			$url = home_url( '/' . self::PAGE_SLUG . '/' );
		}

		return empty( $args ) ? $url : add_query_arg( $args, $url );
	}

	/**
	 * Whether the current request renders the public #KalimatHariIni showcase.
	 *
	 * @return bool
	 */
	public static function is_quotes_request() {
		$page_id = self::page_id();

		if ( $page_id > 0 && is_page( $page_id ) ) {
			return true;
		}

		return is_page() && has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), self::SHORTCODE );
	}

	/**
	 * Add a body class on the public #KalimatHariIni page.
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public static function body_class( $classes ) {
		if ( self::is_quotes_request() ) {
			$classes[] = 'penalis-daily-quotes-page';
		}

		return $classes;
	}

	/**
	 * Load the minimal theme-wrapped template for #KalimatHariIni.
	 *
	 * @param string $template Template path.
	 * @return string
	 */
	public static function maybe_use_template( $template ) {
		if ( ! self::is_quotes_request() ) {
			return $template;
		}

		/**
		 * Allow a site to opt out of the bundled #KalimatHariIni template.
		 *
		 * @param bool $use_template Whether to use the bundled template.
		 */
		if ( ! apply_filters( 'penalis_use_daily_quotes_template', (bool) Penalis_Config::general()['use_daily_quotes_template'] ) ) {
			return $template;
		}

		return Penalis_Dashboard::resolve_bundled_template(
			$template,
			'penalis-daily-quotes.php',
			'daily-quotes.php'
		);
	}

	/**
	 * Enqueue the public #KalimatHariIni assets only when needed.
	 *
	 * @return void
	 */
	public static function maybe_enqueue_assets() {
		if ( ! self::is_quotes_request() ) {
			return;
		}

		if ( ! Penalis_Dashboard::enqueue_vite_entry( 'src/quotes/index.tsx', self::ASSET_HANDLE ) ) {
			return;
		}

		$user_id   = get_current_user_id();
		$is_staff  = $user_id > 0 && Penalis_Editorial_Workflow::can_review( $user_id );
		$can_write = $user_id > 0 && ! $is_staff && Penalis_Account_Status::can_write( $user_id );

		$brand = Penalis_Dashboard::brand_assets();

		wp_localize_script(
			self::ASSET_HANDLE,
			'penalisDailyQuotes',
			array(
				'restUrl'       => esc_url_raw( rest_url( Penalis_Rest_Api::NAMESPACE_V1 ) ),
				'siteName'      => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'perPage'       => Penalis_Policy::DAILY_QUOTE_PER_PAGE,
				'currentUserId' => $user_id,
				'canWrite'      => $can_write,
				'isStaff'       => $is_staff,
				'dashboardUrl'  => esc_url_raw( Penalis_Dashboard::dashboard_url() . '#kalimat-hari-ini' ),
				'brandLogoUrl'  => $brand['logoUrl'],
				'brandIconUrl'  => $brand['iconUrl'],
			)
		);
	}

	/**
	 * Load the #KalimatHariIni bundle as an ES module.
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Script handle.
	 * @param string $src    Script source URL.
	 * @return string
	 */
	public static function module_script_tag( $tag, $handle, $src ) {
		return Penalis_Dashboard::format_module_script_tag( $tag, $handle, $src, self::ASSET_HANDLE );
	}

	/**
	 * Render the #KalimatHariIni mount point.
	 *
	 * @return string
	 */
	public static function render_shortcode() {
		return Penalis_Dashboard::render_spa_mount(
			'penalis-quotes-root',
			'Aset halaman #KalimatHariIni tidak ditemukan.',
			'Halaman #KalimatHariIni memerlukan JavaScript.'
		);
	}

	/**
	 * Return the active #KalimatHariIni for one user, or null when absent,
	 * expired, or the account is no longer an active writer.
	 *
	 * @param int      $user_id User ID.
	 * @param int|null $now     Optional current Unix timestamp for deterministic tests.
	 * @return array{text:string,createdAt:int,expiresAt:int}|null
	 */
	public static function get_active_for_user( $user_id, $now = null ) {
		$user_id = (int) $user_id;
		$now     = null === $now ? time() : (int) $now;

		if ( $user_id <= 0 || ! Penalis_Account_Status::can_write( $user_id ) ) {
			return null;
		}

		$record = get_user_meta( $user_id, self::META_QUOTE, true );

		if ( ! Penalis_Policy::is_daily_quote_active( $record, $now ) ) {
			return null;
		}

		return array(
			'text'      => Penalis_Policy::normalize_daily_quote( (string) $record['text'] ),
			'createdAt' => (int) $record['created_at'],
			'expiresAt' => (int) $record['expires_at'],
		);
	}

	/**
	 * Return the complete dashboard state for an active writer's #KalimatHariIni.
	 *
	 * @param int      $user_id User ID.
	 * @param int|null $now     Optional current Unix timestamp.
	 * @return array<string,mixed>
	 */
	public static function get_writer_state( $user_id, $now = null ) {
		$user_id   = (int) $user_id;
		$now       = null === $now ? time() : (int) $now;
		$editorial = Penalis_Config::editorial();
		$enabled   = (bool) $editorial['quote_enabled'];
		$ttl       = Penalis_Config::quote_ttl_seconds();
		$limit     = (int) $editorial['quote_daily_limit'];
		$active    = self::get_active_for_user( $user_id, $now );
		$log       = get_user_meta( $user_id, self::META_PUBLISH_LOG, true );
		$quota     = Penalis_Policy::daily_quote_rate_state( $log, $now, $limit, $ttl );

		return array(
			'enabled'    => $enabled,
			'quote'      => $active,
			'quota'      => array(
				'used'      => $quota['used'],
				'limit'     => $quota['limit'],
				'remaining' => $quota['remaining'],
				'resetAt'   => $quota['reset_at'],
			),
			'maxLength'  => (int) $editorial['quote_max_length'],
			'maxLines'   => (int) $editorial['quote_max_lines'],
			'ttlSeconds' => $ttl,
			'canPublish' => $enabled && null === $active && $quota['remaining'] > 0,
			'publicUrl'  => esc_url_raw( self::page_url() ),
		);
	}

	/**
	 * Publish a new #KalimatHariIni for an active writer.
	 *
	 * Serialized under a per-user advisory lock so rapid concurrent requests
	 * cannot bypass the active-quote guard or the rolling publish quota.
	 *
	 * @param int      $user_id  User ID.
	 * @param string   $raw_text Raw quote text submitted by the writer.
	 * @param int|null $now      Optional current Unix timestamp.
	 * @return array<string,mixed>|WP_Error Updated writer state or WP_Error.
	 */
	public static function publish( $user_id, $raw_text, $now = null ) {
		$user_id   = (int) $user_id;
		$now       = null === $now ? time() : (int) $now;
		$editorial = Penalis_Config::editorial();

		if ( empty( $editorial['quote_enabled'] ) ) {
			return new WP_Error(
				'penalis_quote_disabled',
				'Fitur #KalimatHariIni sedang dinonaktifkan oleh administrator.',
				array( 'status' => 403 )
			);
		}

		if ( $user_id <= 0 || ! Penalis_Account_Status::can_write( $user_id ) ) {
			return new WP_Error(
				'penalis_account_inactive',
				'Hanya penulis aktif yang dapat menerbitkan #KalimatHariIni.',
				array( 'status' => 403 )
			);
		}

		$validation_error = Penalis_Policy::validate_daily_quote(
			$raw_text,
			(int) $editorial['quote_max_length'],
			(int) $editorial['quote_max_lines']
		);
		if ( '' !== $validation_error ) {
			return new WP_Error(
				'penalis_validation_error',
				$validation_error,
				array(
					'status' => 422,
					'fields' => array( 'text' => $validation_error ),
				)
			);
		}

		$normalized = Penalis_Policy::normalize_daily_quote( $raw_text );
		$lock_key   = 'penalis_daily_quote_' . $user_id;
		$ttl        = Penalis_Config::quote_ttl_seconds();
		$limit      = (int) $editorial['quote_daily_limit'];

		return Penalis_Account_Status::with_advisory_lock(
			$lock_key,
			5,
			static function () use ( $user_id, $normalized, $now, $ttl, $limit ) {
				if ( null !== self::get_active_for_user( $user_id, $now ) ) {
					return new WP_Error(
						'penalis_quote_already_active',
						'Kamu masih memiliki #KalimatHariIni yang aktif. Hapus kalimat saat ini terlebih dahulu jika ingin membuat yang baru.',
						array( 'status' => 409 )
					);
				}

				$log   = get_user_meta( $user_id, self::META_PUBLISH_LOG, true );
				$quota = Penalis_Policy::daily_quote_rate_state( $log, $now, $limit, $ttl );

				if ( $quota['remaining'] <= 0 ) {
					return new WP_Error(
						'penalis_quote_limit_reached',
						sprintf(
							'Kamu sudah mencapai batas %d kali menerbitkan #KalimatHariIni. Coba lagi nanti setelah kuota tersedia.',
							$limit
						),
						array(
							'status'  => 429,
							'resetAt' => $quota['reset_at'],
						)
					);
				}

				$expires_at = $now + $ttl;
				$record     = array(
					'text'       => $normalized,
					'created_at' => $now,
					'expires_at' => $expires_at,
				);

				$timestamps   = $quota['timestamps'];
				$timestamps[] = $now;

				update_user_meta( $user_id, self::META_QUOTE, $record );
				update_user_meta( $user_id, self::META_EXPIRES, $expires_at );
				update_user_meta( $user_id, self::META_PUBLISH_LOG, $timestamps );

				self::invalidate_cache();

				return self::get_writer_state( $user_id, $now );
			},
			static function () {
				return new WP_Error(
					'penalis_quote_busy',
					'Permintaan sedang diproses. Silakan coba beberapa saat lagi.',
					array( 'status' => 409 )
				);
			}
		);
	}

	/**
	 * Delete the active #KalimatHariIni for a user.
	 *
	 * Preserves the rolling 24-hour publish log so deleting and re-publishing
	 * stays bounded to 3 publishes per 24 hours.
	 *
	 * @param int      $user_id User ID.
	 * @param int|null $now     Optional current Unix timestamp.
	 * @return array<string,mixed>|WP_Error Updated writer state or WP_Error.
	 */
	public static function delete_for_user( $user_id, $now = null ) {
		$user_id = (int) $user_id;
		$now     = null === $now ? time() : (int) $now;

		if ( $user_id <= 0 ) {
			return new WP_Error(
				'penalis_invalid_user',
				'Penulis tidak ditemukan.',
				array( 'status' => 404 )
			);
		}

		$lock_key = 'penalis_daily_quote_' . $user_id;

		return Penalis_Account_Status::with_advisory_lock(
			$lock_key,
			5,
			static function () use ( $user_id, $now ) {
				delete_user_meta( $user_id, self::META_QUOTE );
				delete_user_meta( $user_id, self::META_EXPIRES );

				self::invalidate_cache();

				return self::get_writer_state( $user_id, $now );
			},
			static function () {
				return new WP_Error(
					'penalis_quote_busy',
					'Permintaan sedang diproses. Silakan coba beberapa saat lagi.',
					array( 'status' => 409 )
				);
			}
		);
	}

	/**
	 * Remove a writer's active #KalimatHariIni as a Senator and notify the writer
	 * in their dashboard with the takedown reason (no email).
	 *
	 * @param int      $user_id    Target writer user ID.
	 * @param string   $raw_reason Required takedown reason supplied by the Senator.
	 * @param int|null $now        Optional current Unix timestamp.
	 * @return array{deleted:bool,authorId:int,reason:string}|WP_Error
	 */
	public static function moderate_delete( $user_id, $raw_reason, $now = null ) {
		$user_id = (int) $user_id;
		$now     = null === $now ? time() : (int) $now;
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;

		if ( ! $user instanceof WP_User ) {
			return new WP_Error(
				'penalis_not_found',
				'Penulis tidak ditemukan.',
				array( 'status' => 404 )
			);
		}

		$reason_max   = (int) Penalis_Config::editorial()['quote_reason_max_length'];
		$reason_error = Penalis_Policy::validate_daily_quote_removal_reason( (string) $raw_reason, $reason_max );
		if ( '' !== $reason_error ) {
			return new WP_Error(
				'penalis_validation_error',
				$reason_error,
				array(
					'status' => 422,
					'fields' => array( 'reason' => $reason_error ),
				)
			);
		}

		$reason   = Penalis_Policy::normalize_daily_quote_removal_reason( (string) $raw_reason );
		$lock_key = 'penalis_daily_quote_' . $user_id;

		$deleted = Penalis_Account_Status::with_advisory_lock(
			$lock_key,
			5,
			static function () use ( $user_id, $now ) {
				$record = get_user_meta( $user_id, self::META_QUOTE, true );
				if ( ! Penalis_Policy::is_daily_quote_active( $record, $now ) ) {
					return new WP_Error(
						'penalis_quote_not_found',
						'#KalimatHariIni penulis ini sudah tidak aktif atau telah dihapus.',
						array( 'status' => 404 )
					);
				}

				delete_user_meta( $user_id, self::META_QUOTE );
				delete_user_meta( $user_id, self::META_EXPIRES );

				self::invalidate_cache();

				return true;
			},
			static function () {
				return new WP_Error(
					'penalis_quote_busy',
					'Permintaan sedang diproses. Silakan coba beberapa saat lagi.',
					array( 'status' => 409 )
				);
			}
		);

		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		// Record the dashboard notification after releasing the daily-quote lock
		// so the two lock families are never nested and a notification lock
		// hiccup never rolls back a completed moderation takedown.
		Penalis_Editorial_Notifications::record_quote_removed( $user_id, $reason );

		return array(
			'deleted'  => true,
			'authorId' => $user_id,
			'reason'   => $reason,
		);
	}

	/**
	 * Query, filter, sort, and paginate active public #KalimatHariIni entries.
	 *
	 * @param array<string,mixed> $args Query arguments (search, sort, page, per_page).
	 * @param int|null            $now  Optional current Unix timestamp.
	 * @return array<string,mixed>
	 */
	public static function query_quotes( $args = array(), $now = null ) {
		$now              = null === $now ? time() : (int) $now;
		$search           = isset( $args['search'] ) ? trim( sanitize_text_field( (string) $args['search'] ) ) : '';
		$sort             = Penalis_Policy::normalize_daily_quotes_sort( isset( $args['sort'] ) ? $args['sort'] : 'newest' );
		$page             = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$default_per_page = (int) Penalis_Config::editorial()['quote_per_page'];
		$per_page         = isset( $args['per_page'] ) ? (int) $args['per_page'] : $default_per_page;
		$per_page         = max( 1, min( Penalis_Policy::DAILY_QUOTE_MAX_PER_PAGE, $per_page ) );

		$all_quotes = self::get_all_active_quotes( $now );
		$total_all  = count( $all_quotes );
		$filtered   = array();

		foreach ( $all_quotes as $quote ) {
			if ( '' !== $search ) {
				$haystack = $quote['text'] . ' ' . $quote['displayName'] . ' ' . $quote['username'];
				if ( false === stripos( $haystack, $search ) ) {
					continue;
				}
			}

			$filtered[] = $quote;
		}

		$sorted = Penalis_Policy::sort_daily_quotes( $filtered, $sort );
		$total  = count( $sorted );
		$offset = ( $page - 1 ) * $per_page;
		$items  = array_slice( $sorted, $offset, $per_page );

		return array(
			'items'    => array_values( $items ),
			'total'    => $total,
			'totalAll' => $total_all,
			'page'     => $page,
			'perPage'  => $per_page,
			'hasMore'  => ( $offset + count( $items ) ) < $total,
		);
	}

	/**
	 * Fetch and cache all currently active public #KalimatHariIni entries.
	 *
	 * Even when served from the short transient cache, entries whose
	 * `expiresAt` has passed are filtered out immediately so expired quotes
	 * never linger past 24 hours.
	 *
	 * @param int $now Current Unix timestamp.
	 * @return array<int,array<string,mixed>>
	 */
	private static function get_all_active_quotes( $now ) {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return array_values(
				array_filter(
					$cached,
					static function ( $entry ) use ( $now ) {
						return is_array( $entry )
							&& isset( $entry['expiresAt'] )
							&& (int) $entry['expiresAt'] > $now;
					}
				)
			);
		}

		$user_ids = get_users(
			array(
				'role'       => Penalis_Roles::ROLE,
				'fields'     => 'ID',
				'number'     => -1,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Cached via transient.
					array(
						'key'     => self::META_EXPIRES,
						'value'   => $now,
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		$quotes = array();

		foreach ( (array) $user_ids as $raw_id ) {
			$user_id = (int) $raw_id;
			$active  = self::get_active_for_user( $user_id, $now );
			if ( null === $active ) {
				continue;
			}

			$user = get_userdata( $user_id );
			if ( ! $user instanceof WP_User ) {
				continue;
			}

			$quotes[] = array(
				'authorId'    => $user_id,
				'text'        => $active['text'],
				'createdAt'   => $active['createdAt'],
				'expiresAt'   => $active['expiresAt'],
				'displayName' => (string) $user->display_name,
				'username'    => (string) $user->user_nicename,
				'avatarUrl'   => (string) Penalis_Avatar::get_url( $user_id ),
				'authorUrl'   => esc_url_raw( get_author_posts_url( $user_id, $user->user_nicename ) ),
				'isVerified'  => Penalis_Authors::is_user_verified( $user_id ),
				'isSenator'   => Penalis_Authors::is_user_senator( $user_id ),
			);
		}

		$cache_ttl = (int) Penalis_Config::performance()['quotes_cache_ttl'];
		if ( $cache_ttl > 0 ) {
			set_transient( self::CACHE_KEY, $quotes, $cache_ttl );
		}

		return $quotes;
	}

	/**
	 * Format a short Indonesian relative label for when a quote was published
	 * (for server-rendered templates such as author-archive.php).
	 *
	 * @param int      $created_at Unix timestamp when the quote was created.
	 * @param int|null $now        Optional current Unix timestamp.
	 * @return string
	 */
	public static function format_relative_time( $created_at, $now = null ) {
		$now  = null === $now ? time() : (int) $now;
		$diff = max( 0, $now - (int) $created_at );

		if ( $diff < MINUTE_IN_SECONDS ) {
			return 'Baru saja';
		}

		if ( $diff < HOUR_IN_SECONDS ) {
			$minutes = max( 1, (int) floor( $diff / MINUTE_IN_SECONDS ) );
			return $minutes . ' menit lalu';
		}

		$hours = max( 1, (int) floor( $diff / HOUR_IN_SECONDS ) );
		return $hours . ' jam lalu';
	}

	/**
	 * Invalidate the cached public #KalimatHariIni list.
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
			self::META_QUOTE,
			self::META_EXPIRES,
			Penalis_Account_Status::META_STATUS,
			Penalis_Account_Status::META_ACCESS,
			Penalis_Account_Status::META_SENATOR,
			Penalis_Avatar::META_FILE,
			Penalis_Avatar::META_LEGACY_ID,
			Penalis_Avatar::meta_key(),
		);

		if ( in_array( (string) $meta_key, $watched, true ) ) {
			self::invalidate_cache();
		}
	}
}
