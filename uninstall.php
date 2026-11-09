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

// History and notifications also belong to administrators without plugin account status.
delete_metadata( 'user', 0, '_penalis_author_history', '', true );
delete_metadata( 'user', 0, '_penalis_author_history_reserved', '', true );
delete_metadata( 'user', 0, '_penalis_notifications', '', true );

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
);
foreach ( $penalis_post_meta_keys as $penalis_post_meta_key ) {
	delete_metadata( 'post', 0, $penalis_post_meta_key, '', true );
}

// Remove the plugin role.
remove_role( 'penalis_writer' );

// Delete plugin options.
$penalis_options = array(
	'penalis_platform_version',
	'penalis_dashboard_page_id',
	'penalis_registration_enabled',
	'penalis_remove_data_on_uninstall',
	'penalis_dashboard_page_created_by_plugin',
);
foreach ( $penalis_options as $penalis_option ) {
	delete_option( $penalis_option );
}

// Delete transients used for operational state.
delete_transient( 'penalis_activation_notice' );

// Remove the dashboard page only if this plugin created it.
$penalis_page_id = (int) get_option( 'penalis_dashboard_page_id', 0 );
if ( $penalis_page_id > 0 && '1' === get_option( 'penalis_dashboard_page_created_by_plugin', '0' ) ) {
	$penalis_page = get_post( $penalis_page_id );
	if ( $penalis_page && false !== strpos( (string) $penalis_page->post_content, '[penalis_dashboard]' ) ) {
		wp_delete_post( $penalis_page_id, true );
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
