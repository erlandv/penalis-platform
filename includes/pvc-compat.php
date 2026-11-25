<?php
/**
 * Pluggable `pvc_get_post_views()` compatibility bridge for the Atlas theme.
 *
 * Loaded only on `plugins_loaded` (priority 20) when the real Post Views Counter
 * plugin is NOT active.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'pvc_get_post_views' ) ) {
	/**
	 * Pluggable drop-in replacement for `pvc_get_post_views()`.
	 *
	 * Supports a single post ID, `0` (current post in the loop), or an array of
	 * post IDs (returning their sum).
	 *
	 * @param int|int[] $post_id Post ID or list of post IDs.
	 * @param string    $period  Unused (Penalis tracks lifetime total views).
	 * @param array     $args    Unused compatibility parameter.
	 * @return int
	 */
	function pvc_get_post_views( $post_id = 0, $period = 'total', $args = array() ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Intentional drop-in compatibility function for Atlas theme.
		unset( $period, $args );

		if ( ! class_exists( 'Penalis_Views' ) ) {
			return 0;
		}

		if ( is_array( $post_id ) ) {
			return Penalis_Views::get_views_sum( $post_id );
		}

		$id = (int) $post_id;
		if ( $id <= 0 && function_exists( 'get_the_ID' ) ) {
			$id = (int) get_the_ID();
		}

		return Penalis_Views::get_views( $id );
	}
}
