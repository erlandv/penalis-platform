<?php
/**
 * Plugin Name:       Penalis Platform
 * Plugin URI:        https://github.com/erlandv/penalis-platform
 * Description:       Curated publishing workflow and React writing dashboard for the Penalis literary community on WordPress.
 * Version:           1.4.0
 * Requires at least: 6.0
 * Requires PHP:      8.2
 * Author:            Penalis
 * Author URI:        https://penalis.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Minimum PHP version guard — fail gracefully without fatal errors.
if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'Penalis Platform requires PHP 8.2 or higher. Please upgrade PHP.', 'penalis-platform' )
				. '</p></div>';
		}
	);
	return;
}

define( 'PENALIS_PLATFORM_VERSION', '1.4.0' );
define( 'PENALIS_PLATFORM_FILE', __FILE__ );
define( 'PENALIS_PLATFORM_DIR', plugin_dir_path( __FILE__ ) );
define( 'PENALIS_PLATFORM_URL', plugin_dir_url( __FILE__ ) );
define( 'PENALIS_PLATFORM_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoload plugin classes using the Penalis_ prefix.
 *
 * This keeps the plugin self-contained: production deployments do not need
 * Composer's autoloader or a vendor directory.
 *
 * @param string $class_name Class name.
 * @return void
 */
function penalis_platform_autoload( $class_name ) {
	if ( 0 !== strpos( $class_name, 'Penalis_' ) ) {
		return;
	}

	$slug = strtolower( str_replace( '_', '-', substr( $class_name, strlen( 'Penalis_' ) ) ) );
	$file = PENALIS_PLATFORM_DIR . 'includes/class-' . $slug . '.php';

	if ( is_readable( $file ) ) {
		require_once $file;
	}
}
spl_autoload_register( 'penalis_platform_autoload' );

require_once PENALIS_PLATFORM_DIR . 'includes/class-activator.php';
require_once PENALIS_PLATFORM_DIR . 'includes/class-deactivator.php';

register_activation_hook( __FILE__, array( 'Penalis_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Penalis_Deactivator', 'deactivate' ) );

/**
 * Boot the plugin.
 *
 * @return void
 */
function penalis_platform_bootstrap() {
	Penalis_Plugin::instance()->run();
}
add_action( 'plugins_loaded', 'penalis_platform_bootstrap' );
