<?php
/**
 * Strict media usage inspection to protect referenced attachments from deletion.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects whether a Media Library attachment is currently referenced by any
 * active post (featured image or inline HTML) or avatar setting.
 *
 * Under Penalis's strict unused-only deletion policy, writers may only delete
 * attachments that are not referenced by any published, pending, draft,
 * scheduled, or private post, nor by a legacy/site-default avatar.
 */
final class Penalis_Media_Usage {

	/**
	 * Post statuses that protect referenced media from deletion.
	 */
	public const ACTIVE_POST_STATUSES = array( 'publish', 'pending', 'draft', 'future', 'private' );

	/**
	 * Extract the relative file stem (without extension or `-scaled` suffix)
	 * used to locate references to an attachment inside `post_content`.
	 *
	 * Example: `2026/11/senja-scaled.jpg` -> `2026/11/senja`.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $attached_file Optional `_wp_attached_file` meta value.
	 * @param string $url           Optional full attachment URL.
	 * @return string Empty string when no reliable stem can be derived.
	 */
	public static function content_search_needle( $attachment_id, $attached_file = '', $url = '' ) {
		$path = trim( (string) $attached_file );

		if ( '' === $path ) {
			$path = trim( (string) get_post_meta( (int) $attachment_id, '_wp_attached_file', true ) );
		}

		if ( '' === $path ) {
			$source_url = '' !== (string) $url ? (string) $url : (string) wp_get_attachment_url( (int) $attachment_id );
			if ( '' !== $source_url ) {
				$parsed = wp_parse_url( $source_url, PHP_URL_PATH );
				if ( is_string( $parsed ) && '' !== $parsed ) {
					$pos = strpos( $parsed, '/uploads/' );
					if ( false !== $pos ) {
						$path = substr( $parsed, $pos + strlen( '/uploads/' ) );
					} else {
						$path = ltrim( $parsed, '/' );
					}
				}
			}
		}

		if ( '' === $path ) {
			return '';
		}

		$path = ltrim( str_replace( '\\', '/', $path ), '/' );
		$stem = preg_replace( '/\.[a-z0-9]{2,5}$/i', '', $path );
		if ( ! is_string( $stem ) || '' === $stem ) {
			return '';
		}

		$stem = preg_replace( '/-scaled$/i', '', $stem );

		return is_string( $stem ) ? $stem : '';
	}

	/**
	 * Build an exact regex matching references to an attachment inside HTML content.
	 *
	 * Matches:
	 * - the original file (`stem.ext`) and `-scaled` variant (`stem-scaled.ext`),
	 * - any WordPress intermediate size (`stem-300x200.ext`, `stem-1024x683.webp`),
	 * - classic/block editor class marker (`wp-image-{id}`).
	 *
	 * Boundaries prevent prefix collisions (e.g. `2026/11/foto` will not match
	 * `2026/11/foto-2.jpg` or `2026/11/foto-liburan.jpg`).
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $attached_file Optional `_wp_attached_file` value.
	 * @param string $url           Optional attachment URL.
	 * @return string Regex pattern delimited with `~`.
	 */
	public static function content_match_pattern( $attachment_id, $attached_file = '', $url = '' ) {
		$attachment_id = (int) $attachment_id;
		$parts         = array();

		$needle = self::content_search_needle( $attachment_id, $attached_file, $url );
		if ( '' !== $needle ) {
			$parts[] = preg_quote( $needle, '~' ) . '(?:-scaled|-[0-9]+x[0-9]+)?\.[a-z0-9]{2,5}(?=["\'\s?#>)\]]|$)';
		}

		if ( $attachment_id > 0 ) {
			$parts[] = '\bwp-image-' . $attachment_id . '\b';
		}

		if ( empty( $parts ) ) {
			return '';
		}

		return '~(?:' . implode( '|', $parts ) . ')~i';
	}

	/**
	 * Whether a post content string references the given attachment.
	 *
	 * @param string $content       Post HTML content.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $attached_file Optional `_wp_attached_file` value.
	 * @param string $url           Optional attachment URL.
	 * @return bool
	 */
	public static function content_references_attachment( $content, $attachment_id, $attached_file = '', $url = '' ) {
		$content = (string) $content;
		if ( '' === $content ) {
			return false;
		}

		$pattern = self::content_match_pattern( $attachment_id, $attached_file, $url );
		if ( '' === $pattern ) {
			return false;
		}

		return 1 === preg_match( $pattern, $content );
	}

	/**
	 * Authoritative site-wide usage inspection for a single attachment.
	 *
	 * Used before executing any deletion to guarantee no live, pending, or draft
	 * content (or avatar) loses a referenced image.
	 *
	 * @param int $attachment_id  Attachment ID.
	 * @param int $viewer_user_id Optional requesting user ID; only posts authored
	 *                            by this user have their titles included in `usedIn`.
	 * @return array{inUse:bool,canDelete:bool,reason:string,usageCount:int,usedIn:array<int,array{id:int,title:string,status:string}>}
	 */
	public static function inspect( $attachment_id, $viewer_user_id = 0 ) {
		$attachment_id  = (int) $attachment_id;
		$viewer_user_id = (int) $viewer_user_id;

		if ( $attachment_id <= 0 ) {
			return self::empty_summary();
		}

		$default_avatar = (int) get_option( 'avatar_default_wp_user_avatar', 0 ) === $attachment_id;
		$legacy_avatar  = self::is_used_as_legacy_avatar( $attachment_id );
		$posts          = self::find_referencing_posts_sitewide( $attachment_id );

		return self::build_summary( $posts, $legacy_avatar, $default_avatar, $viewer_user_id );
	}

	/**
	 * Convenience predicate for capability and access-control guards.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function is_in_use( $attachment_id ) {
		$result = self::inspect( $attachment_id );

		return $result['inUse'];
	}

	/**
	 * Batch usage summary for a page of media items owned by a writer.
	 *
	 * Avoids N+1 queries on `GET /penalis/v1/media` by checking featured images,
	 * legacy avatars, and the writer's active posts in bulk.
	 *
	 * @param int[] $attachment_ids Attachment IDs on the current page.
	 * @param int   $user_id        Current writer ID.
	 * @return array<int,array{inUse:bool,canDelete:bool,reason:string,usageCount:int,usedIn:array<int,array{id:int,title:string,status:string}>}>
	 */
	public static function summarize_for_user( $attachment_ids, $user_id ) {
		$user_id = (int) $user_id;
		$ids     = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', (array) $attachment_ids ),
					static function ( $id ) {
						return $id > 0;
					}
				)
			)
		);

		$summaries = array();
		if ( empty( $ids ) ) {
			return $summaries;
		}

		$default_avatar_id = (int) get_option( 'avatar_default_wp_user_avatar', 0 );
		$legacy_avatar_map = self::batch_legacy_avatar_ids( $ids );
		$thumbnail_map     = self::batch_thumbnail_posts( $ids );
		$writer_posts      = self::fetch_active_posts_for_author( $user_id );

		foreach ( $ids as $id ) {
			$posts = isset( $thumbnail_map[ $id ] ) ? $thumbnail_map[ $id ] : array();

			$attached_file = (string) get_post_meta( $id, '_wp_attached_file', true );
			$url           = (string) wp_get_attachment_url( $id );
			$pattern       = self::content_match_pattern( $id, $attached_file, $url );

			if ( '' !== $pattern ) {
				foreach ( $writer_posts as $post ) {
					$post_id = (int) $post['id'];
					if ( isset( $posts[ $post_id ] ) ) {
						continue;
					}
					if ( 1 === preg_match( $pattern, (string) $post['content'] ) ) {
						$posts[ $post_id ] = $post;
					}
				}
			}

			$summaries[ $id ] = self::build_summary(
				$posts,
				! empty( $legacy_avatar_map[ $id ] ),
				$default_avatar_id === $id,
				$user_id
			);
		}

		return $summaries;
	}

	/**
	 * Empty (unused) usage summary.
	 *
	 * @return array{inUse:bool,canDelete:bool,reason:string,usageCount:int,usedIn:array<int,array{id:int,title:string,status:string}>}
	 */
	public static function empty_summary() {
		return array(
			'inUse'      => false,
			'canDelete'  => true,
			'reason'     => '',
			'usageCount' => 0,
			'usedIn'     => array(),
		);
	}

	/**
	 * Build a normalized, privacy-safe usage summary.
	 *
	 * @param array<int,array{id:int,title:string,status:string,author:int}> $posts          Referencing posts keyed by ID.
	 * @param bool                                                           $legacy_avatar  Whether used as a legacy user avatar.
	 * @param bool                                                           $default_avatar Whether used as the site default avatar.
	 * @param int                                                            $viewer_user_id Viewer ID (0 omits titles).
	 * @return array{inUse:bool,canDelete:bool,reason:string,usageCount:int,usedIn:array<int,array{id:int,title:string,status:string}>}
	 */
	private static function build_summary( $posts, $legacy_avatar, $default_avatar, $viewer_user_id ) {
		$own_posts   = array();
		$other_posts = 0;

		foreach ( $posts as $post ) {
			if ( $viewer_user_id > 0 && (int) $post['author'] === $viewer_user_id ) {
				$own_posts[] = array(
					'id'     => (int) $post['id'],
					'title'  => '' !== trim( (string) $post['title'] ) ? (string) $post['title'] : 'Tanpa judul',
					'status' => (string) $post['status'],
				);
			} else {
				++$other_posts;
			}
		}

		$total_posts = count( $own_posts ) + $other_posts;
		$in_use      = $total_posts > 0 || $legacy_avatar || $default_avatar;

		if ( ! $in_use ) {
			return self::empty_summary();
		}

		$reason = '';
		if ( count( $own_posts ) === 1 && 0 === $other_posts ) {
			$reason = sprintf(
				'Gambar ini sedang dipakai pada karya "%s". Lepas gambar dari karya terlebih dahulu sebelum menghapus.',
				$own_posts[0]['title']
			);
		} elseif ( count( $own_posts ) > 1 && 0 === $other_posts ) {
			$reason = sprintf(
				'Gambar ini sedang dipakai pada %d karya kamu. Lepas gambar dari semua karya terlebih dahulu sebelum menghapus.',
				count( $own_posts )
			);
		} elseif ( $total_posts > 0 ) {
			$reason = 'Gambar ini sedang digunakan pada konten di situs dan tidak dapat dihapus.';
		} elseif ( $default_avatar ) {
			$reason = 'Gambar ini sedang dipakai sebagai foto profil bawaan situs.';
		} else {
			$reason = 'Gambar ini sedang dipakai sebagai foto profil dan tidak dapat dihapus.';
		}

		return array(
			'inUse'      => true,
			'canDelete'  => false,
			'reason'     => $reason,
			'usageCount' => $total_posts,
			'usedIn'     => $own_posts,
		);
	}

	/**
	 * Whether real WordPress `$wpdb` SQL queries are available.
	 *
	 * @return bool
	 */
	private static function has_wpdb_tables() {
		global $wpdb;

		return isset( $wpdb )
			&& is_object( $wpdb )
			&& method_exists( $wpdb, 'get_results' )
			&& ! empty( $wpdb->posts )
			&& ! empty( $wpdb->postmeta )
			&& ! empty( $wpdb->usermeta );
	}

	/**
	 * Whether any user references this attachment in the legacy avatar user meta.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private static function is_used_as_legacy_avatar( $attachment_id ) {
		$map = self::batch_legacy_avatar_ids( array( $attachment_id ) );

		return ! empty( $map[ $attachment_id ] );
	}

	/**
	 * Batch lookup of attachment IDs referenced as legacy user avatars.
	 *
	 * @param int[] $attachment_ids Attachment IDs.
	 * @return array<int,bool>
	 */
	private static function batch_legacy_avatar_ids( $attachment_ids ) {
		global $wpdb;

		$found    = array();
		$meta_key = Penalis_Avatar::meta_key();

		if ( self::has_wpdb_tables() ) {
			$placeholders = implode( ',', array_fill( 0, count( $attachment_ids ), '%s' ) );
			$params       = array_merge( array( $meta_key ), array_map( 'strval', $attachment_ids ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DISTINCT meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value IN ({$placeholders})",
					$params
				)
			);
			// phpcs:enable

			if ( is_array( $rows ) ) {
				foreach ( $rows as $row ) {
					$id = isset( $row->meta_value ) ? (int) $row->meta_value : 0;
					if ( $id > 0 ) {
						$found[ $id ] = true;
					}
				}
			}

			return $found;
		}

		// Plain-PHP test stub fallback.
		$id_lookup = array_fill_keys( $attachment_ids, true );
		$users     = get_users( array( 'fields' => 'ID' ) );
		foreach ( $users as $uid ) {
			$avatar_id = (int) get_user_meta( (int) $uid, $meta_key, true );
			if ( $avatar_id > 0 && isset( $id_lookup[ $avatar_id ] ) ) {
				$found[ $avatar_id ] = true;
			}
		}

		return $found;
	}

	/**
	 * Batch lookup of active posts using any of the given attachments as `_thumbnail_id`.
	 *
	 * @param int[] $attachment_ids Attachment IDs.
	 * @return array<int,array<int,array{id:int,title:string,status:string,author:int}>>
	 */
	private static function batch_thumbnail_posts( $attachment_ids ) {
		global $wpdb;

		$map = array();

		if ( self::has_wpdb_tables() ) {
			$id_placeholders     = implode( ',', array_fill( 0, count( $attachment_ids ), '%s' ) );
			$status_placeholders = implode( ',', array_fill( 0, count( self::ACTIVE_POST_STATUSES ), '%s' ) );
			$params              = array_merge(
				array_map( 'strval', $attachment_ids ),
				self::ACTIVE_POST_STATUSES
			);

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT pm.meta_value AS attachment_id, p.ID, p.post_title, p.post_status, p.post_author
					FROM {$wpdb->postmeta} pm
					INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					WHERE pm.meta_key = '_thumbnail_id'
						AND pm.meta_value IN ({$id_placeholders})
						AND p.post_type NOT IN ('revision', 'attachment')
						AND p.post_status IN ({$status_placeholders})",
					$params
				)
			);
			// phpcs:enable

			if ( is_array( $rows ) ) {
				foreach ( $rows as $row ) {
					$att_id  = (int) $row->attachment_id;
					$post_id = (int) $row->ID;
					if ( $att_id <= 0 || $post_id <= 0 ) {
						continue;
					}
					$map[ $att_id ][ $post_id ] = array(
						'id'     => $post_id,
						'title'  => (string) $row->post_title,
						'status' => (string) $row->post_status,
						'author' => (int) $row->post_author,
					);
				}
			}

			return $map;
		}

		// Plain-PHP test stub fallback.
		$id_lookup = array_fill_keys( $attachment_ids, true );
		foreach ( self::stub_active_posts() as $post ) {
			$thumb_id = (int) get_post_meta( $post->ID, '_thumbnail_id', true );
			if ( $thumb_id > 0 && isset( $id_lookup[ $thumb_id ] ) ) {
				$map[ $thumb_id ][ (int) $post->ID ] = array(
					'id'     => (int) $post->ID,
					'title'  => (string) $post->post_title,
					'status' => (string) $post->post_status,
					'author' => (int) $post->post_author,
				);
			}
		}

		return $map;
	}

	/**
	 * Fetch active posts authored by a specific writer for batch `post_content` matching.
	 *
	 * @param int $user_id Author user ID.
	 * @return array<int,array{id:int,title:string,status:string,author:int,content:string}>
	 */
	private static function fetch_active_posts_for_author( $user_id ) {
		global $wpdb;

		$posts = array();
		if ( $user_id <= 0 ) {
			return $posts;
		}

		if ( self::has_wpdb_tables() ) {
			$status_placeholders = implode( ',', array_fill( 0, count( self::ACTIVE_POST_STATUSES ), '%s' ) );
			$params              = array_merge( array( $user_id ), self::ACTIVE_POST_STATUSES );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_title, post_status, post_author, post_content
					FROM {$wpdb->posts}
					WHERE post_author = %d
						AND post_type NOT IN ('revision', 'attachment')
						AND post_status IN ({$status_placeholders})",
					$params
				)
			);
			// phpcs:enable

			if ( is_array( $rows ) ) {
				foreach ( $rows as $row ) {
					$posts[] = array(
						'id'      => (int) $row->ID,
						'title'   => (string) $row->post_title,
						'status'  => (string) $row->post_status,
						'author'  => (int) $row->post_author,
						'content' => (string) $row->post_content,
					);
				}
			}

			return $posts;
		}

		// Plain-PHP test stub fallback.
		foreach ( self::stub_active_posts() as $post ) {
			if ( (int) $post->post_author !== $user_id ) {
				continue;
			}
			$posts[] = array(
				'id'      => (int) $post->ID,
				'title'   => (string) $post->post_title,
				'status'  => (string) $post->post_status,
				'author'  => (int) $post->post_author,
				'content' => (string) $post->post_content,
			);
		}

		return $posts;
	}

	/**
	 * Find every active post across the entire site that references an attachment
	 * via `_thumbnail_id` or inside `post_content`.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<int,array{id:int,title:string,status:string,author:int}>
	 */
	private static function find_referencing_posts_sitewide( $attachment_id ) {
		global $wpdb;

		$thumb_map = self::batch_thumbnail_posts( array( $attachment_id ) );
		$posts     = isset( $thumb_map[ $attachment_id ] ) ? $thumb_map[ $attachment_id ] : array();

		$attached_file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$url           = (string) wp_get_attachment_url( $attachment_id );
		$needle        = self::content_search_needle( $attachment_id, $attached_file, $url );
		$pattern       = self::content_match_pattern( $attachment_id, $attached_file, $url );

		if ( '' === $pattern ) {
			return $posts;
		}

		if ( self::has_wpdb_tables() ) {
			$status_placeholders = implode( ',', array_fill( 0, count( self::ACTIVE_POST_STATUSES ), '%s' ) );
			$like_clauses        = array();
			$like_params         = array();

			if ( '' !== $needle ) {
				$like_clauses[] = 'post_content LIKE %s';
				$like_params[]  = '%' . $wpdb->esc_like( $needle ) . '%';
			}

			$like_clauses[] = 'post_content LIKE %s';
			$like_params[]  = '%' . $wpdb->esc_like( 'wp-image-' . $attachment_id ) . '%';

			$like_sql = implode( ' OR ', $like_clauses );
			$params   = array_merge( self::ACTIVE_POST_STATUSES, $like_params );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_title, post_status, post_author, post_content
					FROM {$wpdb->posts}
					WHERE post_type NOT IN ('revision', 'attachment')
						AND post_status IN ({$status_placeholders})
						AND ({$like_sql})",
					$params
				)
			);
			// phpcs:enable

			if ( is_array( $rows ) ) {
				foreach ( $rows as $row ) {
					$post_id = (int) $row->ID;
					if ( isset( $posts[ $post_id ] ) ) {
						continue;
					}
					if ( 1 === preg_match( $pattern, (string) $row->post_content ) ) {
						$posts[ $post_id ] = array(
							'id'     => $post_id,
							'title'  => (string) $row->post_title,
							'status' => (string) $row->post_status,
							'author' => (int) $row->post_author,
						);
					}
				}
			}

			return $posts;
		}

		// Plain-PHP test stub fallback.
		foreach ( self::stub_active_posts() as $post ) {
			$post_id = (int) $post->ID;
			if ( isset( $posts[ $post_id ] ) ) {
				continue;
			}
			if ( 1 === preg_match( $pattern, (string) $post->post_content ) ) {
				$posts[ $post_id ] = array(
					'id'     => $post_id,
					'title'  => (string) $post->post_title,
					'status' => (string) $post->post_status,
					'author' => (int) $post->post_author,
				);
			}
		}

		return $posts;
	}

	/**
	 * Active non-revision/non-attachment posts from the plain-PHP test store.
	 *
	 * @return WP_Post[]
	 */
	private static function stub_active_posts() {
		$all = isset( $GLOBALS['penalis_test_posts'] ) && is_array( $GLOBALS['penalis_test_posts'] )
			? $GLOBALS['penalis_test_posts']
			: array();

		$active = array();
		foreach ( $all as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			if ( in_array( (string) $post->post_type, array( 'revision', 'attachment' ), true ) ) {
				continue;
			}
			if ( ! in_array( (string) $post->post_status, self::ACTIVE_POST_STATUSES, true ) ) {
				continue;
			}
			$active[] = $post;
		}

		return $active;
	}
}
