<?php
/**
 * Plugin Name:       Penalis Platform
 * Plugin URI:        https://github.com/erlandv/penalis-platform
 * Description:       A controlled user-generated content workflow and React writing dashboard for Penalis.
 * Version:           0.2.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
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

define( 'PENALIS_PLATFORM_VERSION', '0.2.1' );
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
