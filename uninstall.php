<?php
/**
 * Optional, opt-in uninstall routine.
 *
 * By default the plugin preserves all users, posts, metadata, the writer role,
 * and the dashboard page. Data removal only happens when the site owner has
 * explicitly opted in via the constant below or the plugin setting.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$penalis_opt_in = defined( 'PENALIS_PLATFORM_REMOVE_DATA' ) && PENALIS_PLATFORM_REMOVE_DATA;
if ( ! $penalis_opt_in ) {
	$penalis_opt_in = '1' === get_option( 'penalis_remove_data_on_uninstall', '0' );
}

if ( ! $penalis_opt_in ) {
	return;
}

// History, notifications, avatar file references, and Senator markers may belong
// to accounts without standard Penalis status metadata (administrators or legacy
// contributors).
delete_metadata( 'user', 0, '_penalis_author_history', '', true );
delete_metadata( 'user', 0, '_penalis_author_history_reserved', '', true );
delete_metadata( 'user', 0, '_penalis_notifications', '', true );
delete_metadata( 'user', 0, '_penalis_is_senator', '', true );
delete_metadata( 'user', 0, '_penalis_avatar_file', '', true );
delete_metadata( 'user', 0, '_penalis_legacy_avatar_id', '', true );
delete_metadata( 'user', 0, '_penalis_daily_quote', '', true );
delete_metadata( 'user', 0, '_penalis_daily_quote_expires', '', true );
delete_metadata( 'user', 0, '_penalis_daily_quote_publishes', '', true );

// Remove derived standalone avatar files when the site owner opted into data removal.
$penalis_uploads = wp_upload_dir();
if ( empty( $penalis_uploads['error'] ) && ! empty( $penalis_uploads['basedir'] ) ) {
	$penalis_avatar_dir = untrailingslashit( (string) $penalis_uploads['basedir'] ) . '/penalis-avatars';
	if ( is_dir( $penalis_avatar_dir ) ) {
		$penalis_avatar_entries = @scandir( $penalis_avatar_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort opt-in cleanup.
		if ( is_array( $penalis_avatar_entries ) ) {
			foreach ( $penalis_avatar_entries as $penalis_avatar_entry ) {
				if ( '.' === $penalis_avatar_entry || '..' === $penalis_avatar_entry ) {
					continue;
				}
				if (
					'index.php' === $penalis_avatar_entry ||
					'.htaccess' === $penalis_avatar_entry ||
					1 === preg_match( '/^u[0-9]+-[a-f0-9]{12}\.jpg$/', $penalis_avatar_entry )
				) {
					$penalis_avatar_path = $penalis_avatar_dir . '/' . $penalis_avatar_entry;
					if ( is_file( $penalis_avatar_path ) ) {
						wp_delete_file( $penalis_avatar_path );
					}
				}
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort opt-in cleanup of empty directory.
		@rmdir( $penalis_avatar_dir );
	}
}

// Remove V2 editorial metadata from posts (posts themselves are never deleted).
$penalis_post_meta_keys = array(
	'_penalis_editorial_state',
	'_penalis_review_feedback',
	'_penalis_submitted_at',
	'_penalis_submitted_by',
	'_penalis_reviewed_at',
	'_penalis_reviewed_by',
	'_penalis_review_cycle',
	'_penalis_review_history',
	'_penalis_like_count',
	'_penalis_dislike_count',
	'_penalis_toc_disabled',
);
foreach ( $penalis_post_meta_keys as $penalis_post_meta_key ) {
	delete_metadata( 'post', 0, $penalis_post_meta_key, '', true );
}

// Remove the plugin role.
remove_role( 'penalis_writer' );

// Read plugin-owned page flags before removing plugin options.
$penalis_page_id        = (int) get_option( 'penalis_dashboard_page_id', 0 );
$penalis_page_owned     = '1' === get_option( 'penalis_dashboard_page_created_by_plugin', '0' );
$penalis_quotes_page_id = (int) get_option( 'penalis_daily_quotes_page_id', 0 );
$penalis_quotes_owned   = '1' === get_option( 'penalis_daily_quotes_page_created_by_plugin', '0' );

// Delete plugin options.
$penalis_options = array(
	'penalis_platform_version',
	'penalis_dashboard_page_id',
	'penalis_registration_enabled',
	'penalis_remove_data_on_uninstall',
	'penalis_dashboard_page_created_by_plugin',
	'penalis_daily_quotes_page_id',
	'penalis_daily_quotes_page_created_by_plugin',
	'penalis_dashboard_captcha_enabled',
	'penalis_dashboard_captcha_audit',
	'penalis_registration_audit',
	'penalis_remove_data_audit',
	// Customized email copy is site content, not operational state.
	'penalis_email_templates',
	// The delivery log stores recipients' addresses, so it must not outlive the
	// data the administrator asked to remove.
	'penalis_email_log',
	// Throttle overrides, the trusted proxy list, and who last changed them.
	'penalis_rate_limits',
	'penalis_trusted_proxies',
	'penalis_rate_limits_audit',
	// Site-wide default featured image fallback for published posts.
	'penalis_default_featured_image_id',
	'penalis_default_featured_image_audit',
	// Penalis-owned backup of default avatar and legacy avatar migration flag.
	'penalis_default_avatar_id',
	'penalis_legacy_avatar_migrated',
	// Built-in post views counter settings and migration flags.
	'penalis_views_schema_version',
	'penalis_views_pvc_migrated',
	'penalis_views_dedup_minutes',
	'penalis_views_exclude_staff',
	'penalis_views_audit',
	// Built-in Table of Contents settings.
	'penalis_toc_settings',
	'penalis_toc_audit',
);
foreach ( $penalis_options as $penalis_option ) {
	delete_option( $penalis_option );
}

// Drop Penalis-owned post views table when opt-in data removal is enabled.
global $wpdb;
if ( isset( $wpdb ) && is_object( $wpdb ) && ! empty( $wpdb->prefix ) ) {
	$penalis_views_table = $wpdb->prefix . 'penalis_post_views';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Opt-in uninstall cleanup of plugin-owned table.
	$wpdb->query( "DROP TABLE IF EXISTS {$penalis_views_table}" );
}

// Delete transients used for operational state.
delete_transient( 'penalis_activation_notice' );
delete_transient( 'penalis_daily_quotes_v1' );

// Remove the dashboard page only if this plugin created it.
if ( $penalis_page_id > 0 && $penalis_page_owned ) {
	$penalis_page = get_post( $penalis_page_id );
	if ( $penalis_page && false !== strpos( (string) $penalis_page->post_content, '[penalis_dashboard]' ) ) {
		wp_delete_post( $penalis_page_id, true );
	}
}

// Remove the #KalimatHariIni page only if this plugin created it.
if ( $penalis_quotes_page_id > 0 && $penalis_quotes_owned ) {
	$penalis_quotes_page = get_post( $penalis_quotes_page_id );
	if ( $penalis_quotes_page && false !== strpos( (string) $penalis_quotes_page->post_content, '[penalis_kalimat_hari_ini]' ) ) {
		wp_delete_post( $penalis_quotes_page_id, true );
	}
}

// Remove account metadata in bounded batches. User accounts and their posts
// are intentionally never deleted.
$penalis_meta_keys = array(
	'_penalis_account_status',
	'_penalis_access_enabled',
	'_penalis_email_verified',
	'_penalis_registered_at',
	'_penalis_approved_at',
	'_penalis_approved_by',
	'_penalis_status_log',
	'_penalis_display_name_mode',
	'_penalis_instagram',
	'_penalis_otp_registration',
	'_penalis_otp_legacy',
	'_penalis_otp_email_change',
	'_penalis_email_change_new_email',
	'_penalis_last_email_failed_at',
	'_penalis_last_email_failed_context',
	'_penalis_notifications',
);

$penalis_offset = 0;
do {
	$penalis_users = get_users(
		array(
			'fields'     => 'ID',
			'number'     => 100,
			'offset'     => $penalis_offset,
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded opt-in cleanup.
				array(
					'key'     => '_penalis_account_status',
					'compare' => 'EXISTS',
				),
			),
		)
	);

	foreach ( $penalis_users as $penalis_user_id ) {
		foreach ( $penalis_meta_keys as $penalis_meta_key ) {
			delete_user_meta( (int) $penalis_user_id, $penalis_meta_key );
		}
	}

	$penalis_offset    += 100;
	$penalis_user_count = count( $penalis_users );
} while ( 100 === $penalis_user_count );
