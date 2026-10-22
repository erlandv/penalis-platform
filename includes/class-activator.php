<?php
/**
 * Plugin activation.
 *
 * Activation is intentionally non-destructive: it registers the writer role,
 * stores the plugin version, and creates the dashboard page when one does not
 * already exist. It never migrates, deletes, or reassigns existing users,
 * posts, comments, media, or taxonomy terms.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activation routine.
 */
final class Penalis_Activator {

	/**
	 * Run on activation.
	 *
	 * @return void
	 */
	public static function activate() {
		Penalis_Roles::register();

		update_option( 'penalis_platform_version', PENALIS_PLATFORM_VERSION, false );

		if ( false === get_option( 'penalis_registration_enabled', false ) ) {
			add_option( 'penalis_registration_enabled', '1', '', false );
		}

		if ( false === get_option( 'penalis_remove_data_on_uninstall', false ) ) {
			add_option( 'penalis_remove_data_on_uninstall', '0', '', false );
		}

		self::ensure_dashboard_page();

		set_transient( 'penalis_activation_notice', '1', 60 );

		/**
		 * Fires after Penalis Platform activation.
		 *
		 * @param string $version Plugin version.
		 */
		do_action( 'penalis_platform_activated', PENALIS_PLATFORM_VERSION );
	}

	/**
	 * Create the dashboard page if it does not exist.
	 *
	 * Existing pages are never modified. If the preferred slug is taken, the
	 * plugin creates a unique slug and stores the resulting page ID.
	 *
	 * @return int Page ID, or 0 on failure.
	 */
	public static function ensure_dashboard_page() {
		$existing_id = (int) get_option( 'penalis_dashboard_page_id', 0 );

		if ( $existing_id > 0 ) {
			$existing = get_post( $existing_id );
			if ( $existing && 'page' === $existing->post_type && 'trash' !== $existing->post_status ) {
				return $existing_id;
			}
		}

		$page_id = wp_insert_post(
			array(
				'post_title'     => 'Writing Dashboard',
				'post_name'      => 'penalis-dashboard',
				'post_content'   => '[penalis_dashboard]',
				'post_status'    => 'publish',
				'post_type'      => 'page',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'post_author'    => self::acting_admin_id(),
				'meta_input'     => array(
					'_penalis_dashboard_page' => '1',
				),
			),
			true
		);

		if ( is_wp_error( $page_id ) || ! $page_id ) {
			return 0;
		}

		update_option( 'penalis_dashboard_page_id', (int) $page_id, false );
		update_option( 'penalis_dashboard_page_created_by_plugin', '1', false );

		return (int) $page_id;
	}

	/**
	 * Current administrator ID, falling back to the first administrator.
	 *
	 * @return int
	 */
	private static function acting_admin_id() {
		$current = get_current_user_id();
		if ( $current > 0 && user_can( $current, 'manage_options' ) ) {
			return $current;
		}

		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);

		return ! empty( $admins ) ? (int) $admins[0] : 0;
	}
}
