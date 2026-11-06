<?php
/**
 * Main plugin container.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads components and registers hooks.
 */
final class Penalis_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Penalis_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether components have been initialized.
	 *
	 * @var bool
	 */
	private $initialized = false;

	/**
	 * Get the singleton instance.
	 *
	 * @return Penalis_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {}

	/**
	 * Initialize components and hooks.
	 *
	 * @return void
	 */
	public function run() {
		if ( $this->initialized ) {
			return;
		}
		$this->initialized = true;

		$this->maybe_upgrade();

		Penalis_Access_Control::init();
		Penalis_Editorial_Notifications::init();
		Penalis_Rest_Api::init();
		Penalis_Dashboard::init();
		Penalis_Author_History::init();
		Penalis_Avatar::register_provider();

		if ( is_admin() ) {
			Penalis_Admin::init();
			Penalis_Editorial_Admin::init();
		}

		add_action( 'admin_notices', array( $this, 'activation_notice' ) );
	}

	/**
	 * Run lightweight version checks. No destructive migrations.
	 *
	 * @return void
	 */
	private function maybe_upgrade() {
		$stored = get_option( 'penalis_platform_version', '' );

		if ( PENALIS_PLATFORM_VERSION === $stored ) {
			return;
		}

		// Ensure the role definition matches the current capability policy.
		Penalis_Roles::register();

		if ( '' === $stored ) {
			add_option( 'penalis_platform_version', PENALIS_PLATFORM_VERSION, '', false );
		} else {
			update_option( 'penalis_platform_version', PENALIS_PLATFORM_VERSION, false );
		}

		// Create the dashboard page if the plugin was updated by replacing
		// files without a fresh activation. This is additive and never
		// modifies an existing page. It runs on init because post types are not
		// registered yet at plugins_loaded.
		add_action( 'init', array( 'Penalis_Activator', 'ensure_dashboard_page' ), 20 );
	}

	/**
	 * Show an activation notice to administrators.
	 *
	 * @return void
	 */
	public function activation_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! get_transient( 'penalis_activation_notice' ) ) {
			return;
		}

		delete_transient( 'penalis_activation_notice' );

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			'Penalis Platform aktif. Tinjau halaman dasbor dan pendaftaran penulis di Pengguna → Penulis Penalis.'
		);
	}
}
