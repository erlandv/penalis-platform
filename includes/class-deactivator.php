<?php
/**
 * Plugin deactivation.
 *
 * Deactivation only clears short-lived operational transients. It does not
 * delete users, posts, metadata, the writer role, or the dashboard page, so
 * content and account state survive reactivation.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deactivation routine.
 */
final class Penalis_Deactivator {

	/**
	 * Run on deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		delete_transient( 'penalis_activation_notice' );

		/**
		 * Fires after Penalis Platform deactivation.
		 */
		do_action( 'penalis_platform_deactivated' );
	}
}
