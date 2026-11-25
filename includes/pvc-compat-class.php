<?php
/**
 * Minimal `Post_Views_Counter` class shim for the Atlas theme.
 *
 * Loaded only on `plugins_loaded` (priority 20) when the real Post Views Counter
 * plugin is NOT active so `TH90_POSTVIEWS_IS_ACTIVE` in the Atlas theme stays
 * `true` without modifying parent theme files.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Post_Views_Counter' ) ) {
	if ( ! defined( 'PENALIS_PVC_COMPAT_SHIM' ) ) {
		define( 'PENALIS_PVC_COMPAT_SHIM', true );
	}

	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Intentional compatibility shim for Atlas theme.
	/**
	 * Minimal presence shim for themes checking `class_exists( 'Post_Views_Counter' )`.
	 */
	class Post_Views_Counter {
	}
	// phpcs:enable
}
