<?php
/**
 * Roles and capabilities.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the dedicated writer role.
 */
final class Penalis_Roles {

	public const ROLE = 'penalis_writer';

	/**
	 * Capabilities granted to the writer role.
	 *
	 * Deliberately excludes edit_others_posts, delete_others_posts,
	 * manage_categories, moderate_comments, manage_options, and every
	 * administrative capability.
	 *
	 * @return array<string,bool>
	 */
	public static function capabilities() {
		return array(
			'read'                   => true,
			'upload_files'           => true,
			'edit_posts'             => true,
			'publish_posts'          => true,
			'edit_published_posts'   => true,
			'delete_posts'           => true,
			'delete_published_posts' => true,
			// Explicitly denied for clarity and future-proofing.
			'edit_others_posts'      => false,
			'delete_others_posts'    => false,
			'edit_private_posts'     => false,
			'delete_private_posts'   => false,
			'read_private_posts'     => false,
			'manage_categories'      => false,
			'moderate_comments'      => false,
			'manage_options'         => false,
			'list_users'             => false,
			'promote_users'          => false,
			'edit_users'             => false,
			'delete_users'           => false,
			'create_users'           => false,
			'install_plugins'        => false,
			'activate_plugins'       => false,
			'edit_plugins'           => false,
			'install_themes'         => false,
			'switch_themes'          => false,
			'edit_themes'            => false,
			'unfiltered_html'        => false,
			'edit_files'             => false,
			'import'                 => false,
			'export'                 => false,
		);
	}

	/**
	 * Add or update the role.
	 *
	 * Uses add_role for new installs and add_cap/remove_cap for existing role
	 * definitions so plugin updates can correct capabilities without deleting
	 * the role (which would strip it from existing users).
	 *
	 * @return void
	 */
	public static function register() {
		$caps = self::capabilities();

		$role = get_role( self::ROLE );

		if ( ! $role ) {
			add_role( self::ROLE, 'Penalis Writer', $caps );
			return;
		}

		foreach ( $caps as $cap => $grant ) {
			if ( $grant ) {
				$role->add_cap( $cap );
			} else {
				$role->remove_cap( $cap );
			}
		}
	}

	/**
	 * Remove the role. Only called by opt-in uninstall.
	 *
	 * @return void
	 */
	public static function remove() {
		remove_role( self::ROLE );
	}

	/**
	 * Give an approved account the writer role.
	 *
	 * Only promotes subscribers and existing writer-role accounts. Users with
	 * a higher role (administrator, editor, author) are never demoted or
	 * modified by this method.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function assign_writer( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return;
		}

		$roles = (array) $user->roles;

		if ( in_array( self::ROLE, $roles, true ) ) {
			return;
		}

		if ( array( 'subscriber' ) === array_values( $roles ) || empty( $roles ) ) {
			$user->set_role( self::ROLE );
		}
	}

	/**
	 * Remove writer capabilities from an account that is no longer active.
	 *
	 * Only demotes accounts that currently hold the writer role. Higher roles
	 * are left untouched.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function revoke_writer( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return;
		}

		if ( in_array( self::ROLE, (array) $user->roles, true ) ) {
			$user->set_role( 'subscriber' );
		}
	}
}
