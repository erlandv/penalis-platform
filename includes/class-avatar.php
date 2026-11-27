<?php
/**
 * Standalone profile photo storage and built-in WordPress avatar provider.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores cropped profile photos in a dedicated uploads subfolder (`penalis-avatars/`)
 * so the WordPress Media Library stays reserved for article assets.
 *
 * Legacy accounts that already have an attachment ID stored in `{prefix}user_avatar`
 * continue to resolve that attachment as a read-only fallback without migration.
 * Replacing or clearing an avatar detaches the legacy meta without ever deleting
 * Media Library attachments, while immediately removing the superseded derived file
 * inside `penalis-avatars/` so storage stays at one file per user.
 */
final class Penalis_Avatar {

	/**
	 * User meta key storing the standalone avatar filename inside `penalis-avatars/`.
	 */
	public const META_FILE = '_penalis_avatar_file';

	/**
	 * Penalis-owned backup user meta key storing a legacy Media Library attachment ID.
	 *
	 * Mirrors `{prefix}user_avatar` so uninstalling the external One User Avatar
	 * plugin (whose `uninstall.php` deletes `{prefix}user_avatar`) cannot wipe
	 * existing contributors' profile photos.
	 */
	public const META_LEGACY_ID = '_penalis_legacy_avatar_id';

	/**
	 * Legacy user meta suffix storing a Media Library attachment ID (One User Avatar).
	 */
	public const META_SUFFIX = 'user_avatar';

	/**
	 * Penalis-owned option storing the site-wide default avatar attachment ID.
	 *
	 * Mirrors `avatar_default_wp_user_avatar` so uninstalling One User Avatar
	 * cannot reset the site-wide default avatar.
	 */
	public const OPTION_DEFAULT_AVATAR_ID = 'penalis_default_avatar_id';

	/**
	 * Flag option indicating the one-time non-destructive legacy avatar backup ran.
	 */
	public const OPTION_LEGACY_MIGRATED = 'penalis_legacy_avatar_migrated';

	/**
	 * Uploads subfolder dedicated to cropped profile photos.
	 */
	public const SUBDIR = 'penalis-avatars';

	/**
	 * Target square output dimension in pixels (matches `CROP_OUTPUT_SIZE`).
	 */
	public const OUTPUT_SIZE = 256;

	/**
	 * JPEG output quality (0-100).
	 */
	public const OUTPUT_QUALITY = 85;

	/**
	 * Maximum accepted upload size in bytes (5 MiB).
	 */
	public const MAX_FILE_SIZE = 5242880;

	/**
	 * Legacy user meta key for an attachment-based avatar ID.
	 *
	 * Prefixed with the site table prefix so existing production records continue
	 * to resolve without migration.
	 *
	 * @return string
	 */
	public static function meta_key() {
		global $wpdb;

		return $wpdb->get_blog_prefix() . self::META_SUFFIX;
	}

	/**
	 * Whether a filename matches the strict server-generated standalone pattern.
	 *
	 * Only basenames matching `u{user_id}-{12-hex}.jpg` are ever read or deleted,
	 * preventing path traversal and arbitrary file operations.
	 *
	 * @param mixed $filename Candidate filename.
	 * @return bool
	 */
	public static function is_valid_filename( $filename ) {
		return is_string( $filename ) && 1 === preg_match( '/^u[0-9]+-[a-f0-9]{12}\.jpg$/', $filename );
	}

	/**
	 * Effective maximum upload size in bytes.
	 *
	 * @return int
	 */
	public static function max_file_size() {
		$configured = class_exists( 'Penalis_Config' )
			? (int) Penalis_Config::account()['avatar_max_mb'] * 1024 * 1024
			: self::MAX_FILE_SIZE;

		if ( function_exists( 'wp_max_upload_size' ) ) {
			$wp_max = (int) wp_max_upload_size();
			if ( $wp_max > 0 && $wp_max < $configured ) {
				return $wp_max;
			}
		}

		return $configured;
	}

	/**
	 * Resolve the dedicated avatar storage directory path and public URL.
	 *
	 * @return array{path:string,url:string}|WP_Error
	 */
	public static function storage_dir() {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
			return new WP_Error(
				'penalis_avatar_storage_unavailable',
				'Direktori penyimpanan foto profil tidak tersedia.',
				array( 'status' => 500 )
			);
		}

		return array(
			'path' => untrailingslashit( (string) $uploads['basedir'] ) . '/' . self::SUBDIR,
			'url'  => untrailingslashit( (string) $uploads['baseurl'] ) . '/' . self::SUBDIR,
		);
	}

	/**
	 * Ensure the dedicated avatar subfolder exists and is hardened against
	 * directory listing and PHP execution.
	 *
	 * @return array{path:string,url:string}|WP_Error
	 */
	public static function ensure_storage_dir() {
		$dir = self::storage_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		if ( ! is_dir( $dir['path'] ) && ! wp_mkdir_p( $dir['path'] ) ) {
			return new WP_Error(
				'penalis_avatar_storage_unavailable',
				'Direktori penyimpanan foto profil tidak dapat dibuat.',
				array( 'status' => 500 )
			);
		}

		$index_file = $dir['path'] . '/index.php';
		if ( ! file_exists( $index_file ) && is_writable( $dir['path'] ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Checking dedicated uploads subfolder before writing guard files.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing static directory index guard inside uploads.
			file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
		}

		$htaccess_file = $dir['path'] . '/.htaccess';
		if ( ! file_exists( $htaccess_file ) && is_writable( $dir['path'] ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Checking dedicated uploads subfolder before writing guard files.
			$rules  = "Options -Indexes\n";
			$rules .= "<FilesMatch \"\\.(?:php|phtml|phar|php[3-8])$\">\n";
			$rules .= "\t<IfModule mod_authz_core.c>\n";
			$rules .= "\t\tRequire all denied\n";
			$rules .= "\t</IfModule>\n";
			$rules .= "\t<IfModule !mod_authz_core.c>\n";
			$rules .= "\t\tOrder allow,deny\n";
			$rules .= "\t\tDeny from all\n";
			$rules .= "\t</IfModule>\n";
			$rules .= "</FilesMatch>\n";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing static security guard inside uploads.
			file_put_contents( $htaccess_file, $rules );
		}

		return $dir;
	}

	/**
	 * Stored standalone avatar filename for a user, or an empty string when none.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function get_file( $user_id ) {
		$value = get_user_meta( (int) $user_id, self::META_FILE, true );

		return self::is_valid_filename( $value ) ? $value : '';
	}

	/**
	 * Absolute path to the user's standalone avatar file when it exists on disk.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function get_file_path( $user_id ) {
		$filename = self::get_file( $user_id );
		if ( '' === $filename ) {
			return '';
		}

		$dir = self::storage_dir();
		if ( is_wp_error( $dir ) ) {
			return '';
		}

		$path = $dir['path'] . '/' . $filename;

		return is_file( $path ) && is_readable( $path ) ? $path : '';
	}

	/**
	 * Public URL to the user's standalone avatar file when it exists on disk.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function get_file_url( $user_id ) {
		$filename = self::get_file( $user_id );
		if ( '' === $filename ) {
			return '';
		}

		$dir = self::storage_dir();
		if ( is_wp_error( $dir ) ) {
			return '';
		}

		$path = $dir['path'] . '/' . $filename;
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return '';
		}

		return $dir['url'] . '/' . $filename;
	}

	/**
	 * Legacy attachment-based avatar ID, if the user still uses one.
	 *
	 * Prefers the Penalis-owned `_penalis_legacy_avatar_id` backup over One User
	 * Avatar's `{prefix}user_avatar` so avatars survive OUA uninstallation.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public static function get_id( $user_id ) {
		$user_id   = (int) $user_id;
		$protected = get_user_meta( $user_id, self::META_LEGACY_ID, true );

		if ( is_numeric( $protected ) && (int) $protected > 0 ) {
			return (int) $protected;
		}

		$legacy = get_user_meta( $user_id, self::meta_key(), true );

		return is_numeric( $legacy ) && (int) $legacy > 0 ? (int) $legacy : 0;
	}

	/**
	 * Run the non-destructive legacy avatar backup once automatically.
	 *
	 * Guarded by an autoloaded flag option so steady-state requests incur zero
	 * extra database queries.
	 *
	 * @return void
	 */
	public static function maybe_migrate_legacy() {
		if ( '1' === (string) get_option( self::OPTION_LEGACY_MIGRATED, '0' ) ) {
			return;
		}

		self::migrate_legacy_avatars();
	}

	/**
	 * Copy legacy One User Avatar metadata into Penalis-owned keys without
	 * deleting or modifying any existing OUA records.
	 *
	 * Safe to run repeatedly (idempotent): users who already have a standalone
	 * `_penalis_avatar_file` or an existing `_penalis_legacy_avatar_id` are left
	 * untouched. Also mirrors the site-wide default avatar into
	 * `penalis_default_avatar_id` when not yet stored.
	 *
	 * @return array{migrated:int,alreadyProtected:int,defaultBackedUp:bool}
	 */
	public static function migrate_legacy_avatars() {
		global $wpdb;

		$legacy_key        = self::meta_key();
		$migrated          = 0;
		$already_protected = 0;
		$candidates        = array();

		if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_results' ) && ! empty( $wpdb->usermeta ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time non-destructive migration scan of legacy avatar meta.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s",
					$legacy_key
				)
			);

			if ( is_array( $rows ) ) {
				foreach ( $rows as $row ) {
					$uid = isset( $row->user_id ) ? (int) $row->user_id : 0;
					$att = isset( $row->meta_value ) && is_numeric( $row->meta_value ) ? (int) $row->meta_value : 0;
					if ( $uid > 0 && $att > 0 ) {
						$candidates[ $uid ] = $att;
					}
				}
			}
		} else {
			$users = get_users( array( 'fields' => 'ID' ) );
			foreach ( $users as $uid ) {
				$uid = (int) $uid;
				$att = get_user_meta( $uid, $legacy_key, true );
				if ( $uid > 0 && is_numeric( $att ) && (int) $att > 0 ) {
					$candidates[ $uid ] = (int) $att;
				}
			}
		}

		foreach ( $candidates as $uid => $attachment_id ) {
			if ( '' !== self::get_file( $uid ) ) {
				++$already_protected;
				continue;
			}

			$existing_backup = get_user_meta( $uid, self::META_LEGACY_ID, true );
			if ( is_numeric( $existing_backup ) && (int) $existing_backup > 0 ) {
				++$already_protected;
				continue;
			}

			update_user_meta( $uid, self::META_LEGACY_ID, $attachment_id );
			++$migrated;
		}

		$default_backed_up = false;
		$stored_default    = get_option( self::OPTION_DEFAULT_AVATAR_ID, '__unset__' );
		if ( '__unset__' === $stored_default && 'wp_user_avatar' === (string) get_option( 'avatar_default' ) ) {
			$legacy_default = (int) get_option( 'avatar_default_wp_user_avatar', 0 );
			if ( $legacy_default > 0 ) {
				update_option( self::OPTION_DEFAULT_AVATAR_ID, $legacy_default, false );
				$default_backed_up = true;
			}
		}

		update_option( self::OPTION_LEGACY_MIGRATED, '1', true );

		return array(
			'migrated'         => $migrated,
			'alreadyProtected' => $already_protected,
			'defaultBackedUp'  => $default_backed_up,
		);
	}

	/**
	 * Report whether any legacy One User Avatar records still need backup into
	 * Penalis-owned metadata before OUA is uninstalled.
	 *
	 * @return array{protectedUsers:int,pendingUsers:int,legacyUsers:int,defaultProtected:bool,defaultNeedsBackup:bool}
	 */
	public static function legacy_migration_status() {
		global $wpdb;

		$legacy_key      = self::meta_key();
		$protected_users = 0;
		$pending_users   = 0;
		$legacy_map      = array();

		if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_results' ) && ! empty( $wpdb->usermeta ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin settings diagnostic count.
			$protected_users = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s AND CAST(meta_value AS UNSIGNED) > 0",
					self::META_LEGACY_ID
				)
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin settings diagnostic scan.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s",
					$legacy_key
				)
			);
			if ( is_array( $rows ) ) {
				foreach ( $rows as $row ) {
					$uid = isset( $row->user_id ) ? (int) $row->user_id : 0;
					$att = isset( $row->meta_value ) && is_numeric( $row->meta_value ) ? (int) $row->meta_value : 0;
					if ( $uid > 0 && $att > 0 ) {
						$legacy_map[ $uid ] = $att;
					}
				}
			}
		} else {
			$users = get_users( array( 'fields' => 'ID' ) );
			foreach ( $users as $uid ) {
				$uid        = (int) $uid;
				$protected  = get_user_meta( $uid, self::META_LEGACY_ID, true );
				$legacy_att = get_user_meta( $uid, $legacy_key, true );

				if ( is_numeric( $protected ) && (int) $protected > 0 ) {
					++$protected_users;
				}
				if ( is_numeric( $legacy_att ) && (int) $legacy_att > 0 ) {
					$legacy_map[ $uid ] = (int) $legacy_att;
				}
			}
		}

		foreach ( $legacy_map as $uid => $att ) {
			unset( $att );
			$has_file   = '' !== self::get_file( $uid );
			$has_backup = is_numeric( get_user_meta( $uid, self::META_LEGACY_ID, true ) )
				&& (int) get_user_meta( $uid, self::META_LEGACY_ID, true ) > 0;
			if ( ! $has_file && ! $has_backup ) {
				++$pending_users;
			}
		}

		$stored_default       = get_option( self::OPTION_DEFAULT_AVATAR_ID, '__unset__' );
		$legacy_default       = 'wp_user_avatar' === (string) get_option( 'avatar_default' )
			? (int) get_option( 'avatar_default_wp_user_avatar', 0 )
			: 0;
		$default_protected    = '__unset__' !== $stored_default && (int) $stored_default > 0;
		$default_needs_backup = '__unset__' === $stored_default && $legacy_default > 0;

		return array(
			'protectedUsers'     => $protected_users,
			'pendingUsers'       => $pending_users,
			'legacyUsers'        => count( $legacy_map ),
			'defaultProtected'   => $default_protected,
			'defaultNeedsBackup' => $default_needs_backup,
		);
	}

	/**
	 * Whether the user currently has a custom profile photo (standalone or legacy).
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function has_avatar( $user_id ) {
		$user_id = (int) $user_id;

		if ( '' !== self::get_file_path( $user_id ) ) {
			return true;
		}

		$attachment_id = self::get_id( $user_id );

		return $attachment_id > 0 && wp_attachment_is_image( $attachment_id );
	}

	/**
	 * Public avatar URL resolved through WordPress's avatar pipeline.
	 *
	 * @param int $user_id User ID.
	 * @param int $size    Square size in pixels.
	 * @return string
	 */
	public static function get_url( $user_id, $size = 96 ) {
		return (string) get_avatar_url( (int) $user_id, array( 'size' => (int) $size ) );
	}

	/**
	 * Validate, re-encode, and store an uploaded profile photo in `penalis-avatars/`.
	 *
	 * Follows a safe write-new -> update-meta -> delete-old sequence so a failure
	 * mid-upload never leaves the user without their previous photo. Any legacy
	 * attachment meta is detached without deleting the Media Library attachment.
	 *
	 * @param int                 $user_id User ID.
	 * @param array<string,mixed> $file    Uploaded file entry (from `$_FILES`).
	 * @return string|WP_Error Stored filename on success.
	 */
	public static function upload( $user_id, $file ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return new WP_Error(
				'penalis_avatar_invalid_user',
				'Akun pengguna tidak ditemukan.',
				array( 'status' => 400 )
			);
		}

		if ( ! is_array( $file ) ) {
			return new WP_Error(
				'penalis_avatar_missing',
				'Pilih gambar untuk foto profil kamu.',
				array( 'status' => 400 )
			);
		}

		$upload_error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $upload_error ) {
			if ( UPLOAD_ERR_INI_SIZE === $upload_error || UPLOAD_ERR_FORM_SIZE === $upload_error ) {
				return new WP_Error(
					'penalis_avatar_too_large',
					'Ukuran gambar terlalu besar. Maksimal 5 MB.',
					array( 'status' => 400 )
				);
			}

			return new WP_Error(
				'penalis_avatar_upload_failed',
				'Gambar tidak dapat di-upload. Silakan coba lagi.',
				array( 'status' => 400 )
			);
		}

		$tmp = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		if ( '' === $tmp || ! is_file( $tmp ) || ! is_readable( $tmp ) ) {
			return new WP_Error(
				'penalis_avatar_unreadable',
				'File yang di-upload tidak dapat divalidasi.',
				array( 'status' => 400 )
			);
		}

		$size = (int) filesize( $tmp );
		if ( $size <= 0 ) {
			return new WP_Error(
				'penalis_avatar_empty',
				'File gambar kosong atau rusak.',
				array( 'status' => 400 )
			);
		}

		if ( $size > self::max_file_size() ) {
			return new WP_Error(
				'penalis_avatar_too_large',
				'Ukuran gambar terlalu besar. Maksimal 5 MB.',
				array( 'status' => 400 )
			);
		}

		$info = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid uploads trigger PHP notices.
		if ( false === $info || ! isset( $info[0], $info[1], $info[2] ) ) {
			return new WP_Error(
				'penalis_avatar_invalid',
				'File yang di-upload bukan gambar yang valid.',
				array( 'status' => 400 )
			);
		}

		$width  = (int) $info[0];
		$height = (int) $info[1];
		$mime   = self::mime_from_imagetype( (int) $info[2] );

		$policy_error = Penalis_Policy::validate_avatar( $mime, $width, $height, true );
		if ( '' !== $policy_error ) {
			return new WP_Error( 'penalis_avatar_invalid', $policy_error, array( 'status' => 400 ) );
		}

		$dir = self::ensure_storage_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$filename = self::generate_filename( $user_id, $dir['path'] );
		if ( is_wp_error( $filename ) ) {
			return $filename;
		}

		$dest          = $dir['path'] . '/' . $filename;
		$flattened_tmp = 'image/jpeg' !== $mime ? self::flatten_transparency_to_temp( $tmp, $mime ) : '';
		$editor_source = '' !== $flattened_tmp ? $flattened_tmp : $tmp;

		try {
			$editor = wp_get_image_editor( $editor_source );

			if ( is_wp_error( $editor ) ) {
				return new WP_Error(
					'penalis_avatar_process_failed',
					'Foto profil tidak dapat diproses. Silakan coba gambar lain.',
					array( 'status' => 400 )
				);
			}

			$account_cfg    = class_exists( 'Penalis_Config' ) ? Penalis_Config::account() : array();
			$output_size    = isset( $account_cfg['avatar_output_size'] ) ? (int) $account_cfg['avatar_output_size'] : self::OUTPUT_SIZE;
			$output_quality = isset( $account_cfg['avatar_output_quality'] ) ? (int) $account_cfg['avatar_output_quality'] : self::OUTPUT_QUALITY;

			// Normalize to a square JPEG capped at output_size. Re-encoding through
			// GD/Imagick strips EXIF metadata and any trailing payload.
			if ( $width !== $height || $width > $output_size || $height > $output_size ) {
				$target  = min( $output_size, $width, $height );
				$resized = $editor->resize( $target, $target, true );

				if ( is_wp_error( $resized ) ) {
					return new WP_Error(
						'penalis_avatar_process_failed',
						'Foto profil tidak dapat diproses. Silakan coba gambar lain.',
						array( 'status' => 400 )
					);
				}
			}

			$editor->set_quality( $output_quality );
			$saved = $editor->save( $dest, 'image/jpeg' );
		} finally {
			if ( '' !== $flattened_tmp && is_file( $flattened_tmp ) ) {
				wp_delete_file( $flattened_tmp );
			}
		}

		if ( is_wp_error( $saved ) ) {
			return new WP_Error(
				'penalis_avatar_save_failed',
				'Foto profil tidak dapat disimpan. Silakan coba lagi.',
				array( 'status' => 500 )
			);
		}

		$saved_path = isset( $saved['path'] ) ? (string) $saved['path'] : $dest;
		if ( $saved_path !== $dest && is_file( $saved_path ) ) {
			wp_delete_file( $saved_path );
			return new WP_Error(
				'penalis_avatar_save_failed',
				'Foto profil tidak dapat disimpan. Silakan coba lagi.',
				array( 'status' => 500 )
			);
		}

		if ( ! is_file( $dest ) || ! is_readable( $dest ) ) {
			return new WP_Error(
				'penalis_avatar_save_failed',
				'Foto profil tidak dapat disimpan. Silakan coba lagi.',
				array( 'status' => 500 )
			);
		}

		$previous_file = self::get_file( $user_id );
		$updated       = update_user_meta( $user_id, self::META_FILE, $filename );

		// Verify the DB write succeeded before detaching legacy meta or removing
		// the previous file. Re-reading disambiguates a real DB failure from a
		// no-op false return when the stored value is unchanged.
		if ( false === $updated && self::get_file( $user_id ) !== $filename ) {
			self::delete_standalone_file( $filename );

			return new WP_Error(
				'penalis_avatar_save_failed',
				'Foto profil tidak dapat disimpan. Silakan coba lagi.',
				array( 'status' => 500 )
			);
		}

		// Detach any legacy attachment ID without touching the Media Library attachment.
		delete_user_meta( $user_id, self::META_LEGACY_ID );
		delete_user_meta( $user_id, self::meta_key() );

		// Immediately remove the superseded derived avatar file so each user keeps one file.
		if ( '' !== $previous_file && $previous_file !== $filename ) {
			self::delete_standalone_file( $previous_file );
		}

		return $filename;
	}

	/**
	 * Remove the user's profile photo.
	 *
	 * Removes the standalone derived file inside `penalis-avatars/` immediately.
	 * Any legacy Media Library attachment is only detached (never deleted).
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function clear( $user_id ) {
		$user_id       = (int) $user_id;
		$previous_file = self::get_file( $user_id );

		delete_user_meta( $user_id, self::META_FILE );
		delete_user_meta( $user_id, self::META_LEGACY_ID );
		delete_user_meta( $user_id, self::meta_key() );

		if ( '' !== $previous_file ) {
			self::delete_standalone_file( $previous_file );
		}
	}

	/**
	 * Delete a single validated standalone avatar file from `penalis-avatars/`.
	 *
	 * @param string $filename Stored basename.
	 * @return bool
	 */
	public static function delete_standalone_file( $filename ) {
		if ( ! self::is_valid_filename( $filename ) ) {
			return false;
		}

		$dir = self::storage_dir();
		if ( is_wp_error( $dir ) ) {
			return false;
		}

		$path = $dir['path'] . '/' . $filename;
		if ( ! is_file( $path ) ) {
			return false;
		}

		wp_delete_file( $path );

		return ! file_exists( $path );
	}

	/**
	 * Register the built-in avatar provider.
	 *
	 * @return void
	 */
	public static function register_provider() {
		add_filter( 'pre_get_avatar_data', array( __CLASS__, 'filter_avatar_data' ), 10, 2 );
	}

	/**
	 * Supply avatar data from standalone storage, legacy attachment, or site default.
	 *
	 * WordPress calls this filter for both get_avatar() and get_avatar_url(),
	 * and re-invokes it with a doubled size for the 2x srcset entry.
	 *
	 * Identifiers that resolve to no user (guest commenters, Gravatar hashes)
	 * still receive the site default photo when configured, keeping rendering
	 * deterministic and avoiding outbound Gravatar requests.
	 *
	 * @param array<string,mixed> $args        Avatar arguments.
	 * @param mixed               $id_or_email User identifier.
	 * @return array<string,mixed>
	 */
	public static function filter_avatar_data( $args, $id_or_email ) {
		if ( ! empty( $args['force_default'] ) ) {
			return $args;
		}

		$user_id = self::resolve_user_id( $id_or_email );

		$size = isset( $args['size'] ) ? (int) $args['size'] : 96;
		$size = $size > 0 ? $size : 96;

		if ( $user_id > 0 ) {
			// 1. Dedicated standalone file in `penalis-avatars/`.
			$standalone_url = self::get_file_url( $user_id );
			if ( '' !== $standalone_url ) {
				$args['url']          = $standalone_url;
				$args['found_avatar'] = true;
				$args['width']        = $size;
				$args['height']       = $size;

				return self::apply_user_alt( $args, $user_id );
			}

			// 2. Read-only fallback to legacy attachment-based avatar.
			$attachment_id = self::get_id( $user_id );
			if ( $attachment_id > 0 && wp_attachment_is_image( $attachment_id ) ) {
				$image = self::image_src( $attachment_id, $size );

				if ( $image ) {
					$args['url']          = $image[0];
					$args['found_avatar'] = true;
					$args['width']        = (int) $image[1];
					$args['height']       = (int) $image[2];

					return self::apply_user_alt( $args, $user_id );
				}
			}
		}

		return self::apply_default_avatar( $args, $size );
	}

	/**
	 * Fill in the avatar alt text from the user's display name when empty.
	 *
	 * @param array<string,mixed> $args    Avatar arguments.
	 * @param int                 $user_id User ID.
	 * @return array<string,mixed>
	 */
	private static function apply_user_alt( $args, $user_id ) {
		if ( isset( $args['alt'] ) && '' === (string) $args['alt'] ) {
			$user = get_userdata( $user_id );

			if ( $user ) {
				$args['alt'] = $user->display_name;
			}
		}

		return $args;
	}

	/**
	 * Use the site default photo for users without one.
	 *
	 * Reuses the existing `avatar_default` / `avatar_default_wp_user_avatar` options
	 * so configured production defaults continue to apply without migration.
	 *
	 * @param array<string,mixed> $args Avatar arguments.
	 * @param int                 $size Requested size.
	 * @return array<string,mixed>
	 */
	private static function apply_default_avatar( $args, $size ) {
		$attachment_id = self::default_avatar_id();

		if ( $attachment_id <= 0 ) {
			return $args;
		}

		$image = self::image_src( $attachment_id, $size );

		if ( ! $image ) {
			return $args;
		}

		$args['url']          = $image[0];
		$args['found_avatar'] = false; // Keep the avatar-default class.
		$args['width']        = (int) $image[1];
		$args['height']       = (int) $image[2];

		return $args;
	}

	/**
	 * Attachment image source for a square size, falling back to the original.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $size          Requested square size.
	 * @return array<int,mixed>|false
	 */
	private static function image_src( $attachment_id, $size ) {
		$image = wp_get_attachment_image_src( $attachment_id, array( $size, $size ) );

		if ( ! $image ) {
			$image = wp_get_attachment_image_src( $attachment_id, 'full' );
		}

		return $image;
	}

	/**
	 * Default photo attachment ID, when the site has selected one.
	 *
	 * Prefers Penalis's own `penalis_default_avatar_id` option so uninstalling
	 * One User Avatar cannot wipe the site default avatar. Falls back to OUA's
	 * `avatar_default` / `avatar_default_wp_user_avatar` only when the Penalis
	 * option has never been saved or migrated yet.
	 *
	 * @return int
	 */
	public static function default_avatar_id() {
		$stored = get_option( self::OPTION_DEFAULT_AVATAR_ID, '__unset__' );

		if ( '__unset__' !== $stored ) {
			$attachment_id = (int) $stored;
			return $attachment_id > 0 && wp_attachment_is_image( $attachment_id ) ? $attachment_id : 0;
		}

		if ( 'wp_user_avatar' !== (string) get_option( 'avatar_default' ) ) {
			return 0;
		}

		$attachment_id = (int) get_option( 'avatar_default_wp_user_avatar', 0 );

		return $attachment_id > 0 && wp_attachment_is_image( $attachment_id ) ? $attachment_id : 0;
	}

	/**
	 * Resolve a user ID from the shapes core passes to avatar filters.
	 *
	 * @param mixed $id_or_email User ID, email, WP_User, WP_Post, or WP_Comment.
	 * @return int
	 */
	public static function resolve_user_id( $id_or_email ) {
		if ( $id_or_email instanceof WP_User ) {
			return (int) $id_or_email->ID;
		}

		if ( $id_or_email instanceof WP_Post ) {
			return (int) $id_or_email->post_author;
		}

		if ( $id_or_email instanceof WP_Comment ) {
			return (int) $id_or_email->user_id;
		}

		if ( is_numeric( $id_or_email ) ) {
			return (int) $id_or_email;
		}

		if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$user = get_user_by( 'email', $id_or_email );

			return $user ? (int) $user->ID : 0;
		}

		return 0;
	}

	/**
	 * Generate a unique `u{user_id}-{12-hex}.jpg` filename inside the target directory.
	 *
	 * @param int    $user_id User ID.
	 * @param string $dir     Storage directory path.
	 * @return string|WP_Error
	 */
	private static function generate_filename( $user_id, $dir ) {
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			try {
				$token = bin2hex( random_bytes( 6 ) );
			} catch ( Exception $e ) {
				return new WP_Error(
					'penalis_avatar_entropy_failed',
					'Foto profil tidak dapat diproses saat ini. Silakan coba lagi.',
					array( 'status' => 500 )
				);
			}

			$filename = 'u' . (int) $user_id . '-' . $token . '.jpg';

			if ( ! file_exists( $dir . '/' . $filename ) ) {
				return $filename;
			}
		}

		return new WP_Error(
			'penalis_avatar_name_collision',
			'Foto profil tidak dapat disimpan saat ini. Silakan coba lagi.',
			array( 'status' => 500 )
		);
	}

	/**
	 * Map a PHP `IMAGETYPE_*` constant to a canonical allowed MIME type.
	 *
	 * @param int $imagetype Constant from `getimagesize()[2]`.
	 * @return string Empty string when unsupported.
	 */
	private static function mime_from_imagetype( $imagetype ) {
		$map = array(
			IMAGETYPE_JPEG => 'image/jpeg',
			IMAGETYPE_PNG  => 'image/png',
			IMAGETYPE_GIF  => 'image/gif',
			IMAGETYPE_WEBP => 'image/webp',
		);

		return isset( $map[ $imagetype ] ) ? $map[ $imagetype ] : '';
	}

	/**
	 * Composite a non-JPEG image onto an opaque white canvas so transparent pixels
	 * do not turn black when encoded to JPEG.
	 *
	 * Returns the path to a temporary opaque PNG file, or an empty string when
	 * flattening is unavailable (in which case the caller falls back to the
	 * original source).
	 *
	 * @param string $source Source image path.
	 * @param string $mime   Verified MIME type (`image/png`, `image/gif`, `image/webp`).
	 * @return string
	 */
	private static function flatten_transparency_to_temp( $source, $mime ) {
		if ( function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagepng' ) ) {
			$src = false;
			if ( 'image/png' === $mime && function_exists( 'imagecreatefrompng' ) ) {
				$src = @imagecreatefrompng( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Corrupt image inputs trigger warnings.
			} elseif ( 'image/webp' === $mime && function_exists( 'imagecreatefromwebp' ) ) {
				$src = @imagecreatefromwebp( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Corrupt image inputs trigger warnings.
			} elseif ( 'image/gif' === $mime && function_exists( 'imagecreatefromgif' ) ) {
				$src = @imagecreatefromgif( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Corrupt image inputs trigger warnings.
			}

			if ( false !== $src ) {
				$w      = imagesx( $src );
				$h      = imagesy( $src );
				$canvas = $w > 0 && $h > 0 ? imagecreatetruecolor( $w, $h ) : false;

				if ( false !== $canvas ) {
					$white = imagecolorallocate( $canvas, 255, 255, 255 );
					imagefill( $canvas, 0, 0, $white );
					imagealphablending( $canvas, true );
					imagecopy( $canvas, $src, 0, 0, 0, 0, $w, $h );

					$temp = function_exists( 'wp_tempnam' )
						? wp_tempnam( 'penalis-avatar-flat.png' )
						: tempnam( sys_get_temp_dir(), 'penalis-avatar-flat-' );

					$ok = is_string( $temp ) && '' !== $temp && imagepng( $canvas, $temp );

					imagedestroy( $canvas );
					imagedestroy( $src );

					if ( $ok ) {
						return $temp;
					}

					if ( is_string( $temp ) && '' !== $temp && is_file( $temp ) ) {
						wp_delete_file( $temp );
					}

					return '';
				}

				imagedestroy( $src );
			}
		}

		if ( class_exists( 'Imagick' ) && defined( 'Imagick::ALPHACHANNEL_REMOVE' ) ) {
			try {
				$imagick = new Imagick( $source );
				$imagick->setImageBackgroundColor( '#ffffff' );
				$imagick->setImageAlphaChannel( Imagick::ALPHACHANNEL_REMOVE );
				$flattened = $imagick->mergeImageLayers( Imagick::LAYERMETHOD_FLATTEN );
				$imagick->clear();

				$temp = function_exists( 'wp_tempnam' )
					? wp_tempnam( 'penalis-avatar-flat.png' )
					: tempnam( sys_get_temp_dir(), 'penalis-avatar-flat-' );

				if ( is_string( $temp ) && '' !== $temp ) {
					$flattened->setImageFormat( 'png' );
					$written = $flattened->writeImage( $temp );
					$flattened->clear();

					if ( $written ) {
						return $temp;
					}

					if ( is_file( $temp ) ) {
						wp_delete_file( $temp );
					}
				} else {
					$flattened->clear();
				}
			} catch ( Exception $e ) {
				return '';
			}
		}

		return '';
	}
}
