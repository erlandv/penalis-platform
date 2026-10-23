<?php
/**
 * Avatar integration with One User Avatar.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the avatar managed by the One User Avatar plugin.
 *
 * One User Avatar stores the avatar attachment ID in a prefixed user meta key
 * (`{prefix}user_avatar`) and filters the core avatar functions. Reusing that
 * storage lets writers manage their photo from the dashboard without adding a
 * second avatar provider or migrating existing images. Nothing here registers
 * its own avatar filter: the provider stays authoritative.
 */
final class Penalis_Avatar {

	/**
	 * User meta suffix used by One User Avatar.
	 */
	public const META_SUFFIX = 'user_avatar';

	/**
	 * Post meta One User Avatar uses to mark an attachment as an avatar.
	 */
	public const META_ATTACHMENT_MARKER = '_wp_attachment_wp_user_avatar';

	/**
	 * Whether the external avatar provider is active.
	 *
	 * @return bool
	 */
	public static function is_supported() {
		return function_exists( 'get_wp_user_avatar' );
	}

	/**
	 * User meta key for the avatar attachment ID.
	 *
	 * One User Avatar prefixes the key with the site table prefix, so it is not
	 * safe to hardcode `wp_user_avatar`.
	 *
	 * @return string
	 */
	public static function meta_key() {
		global $wpdb;

		return $wpdb->get_blog_prefix() . self::META_SUFFIX;
	}

	/**
	 * Current avatar attachment ID.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public static function get_id( $user_id ) {
		$value = get_user_meta( (int) $user_id, self::meta_key(), true );

		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Public avatar URL, resolved by whichever provider is active.
	 *
	 * @param int $user_id User ID.
	 * @param int $size    Square size in pixels.
	 * @return string
	 */
	public static function get_url( $user_id, $size = 96 ) {
		return (string) get_avatar_url( (int) $user_id, array( 'size' => (int) $size ) );
	}

	/**
	 * Validate an attachment for use as the user's avatar.
	 *
	 * @param int $user_id       User ID.
	 * @param int $attachment_id Attachment ID.
	 * @return true|WP_Error
	 */
	public static function validate( $user_id, $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$attachment    = get_post( $attachment_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error(
				'penalis_avatar_invalid',
				'That image is not available in the media library.',
				array( 'status' => 400 )
			);
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		$width    = isset( $metadata['width'] ) ? (int) $metadata['width'] : 0;
		$height   = isset( $metadata['height'] ) ? (int) $metadata['height'] : 0;

		// A writer may keep an avatar an administrator assigned to them, but may
		// not grab another user's upload.
		$permitted = (int) $attachment->post_author === (int) $user_id || self::get_id( $user_id ) === $attachment_id;

		$error = Penalis_Policy::validate_avatar(
			(string) $attachment->post_mime_type,
			$width,
			$height,
			$permitted
		);

		if ( '' !== $error ) {
			return new WP_Error( 'penalis_avatar_invalid', $error, array( 'status' => 400 ) );
		}

		return true;
	}

	/**
	 * Assign an attachment as the user's avatar.
	 *
	 * @param int $user_id       User ID.
	 * @param int $attachment_id Attachment ID.
	 * @return true|WP_Error
	 */
	public static function set( $user_id, $attachment_id ) {
		$user_id       = (int) $user_id;
		$attachment_id = (int) $attachment_id;

		$valid = self::validate( $user_id, $attachment_id );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		update_user_meta( $user_id, self::meta_key(), $attachment_id );

		// Keep One User Avatar's bookkeeping in sync so its own screens can find
		// and replace this attachment later.
		delete_metadata( 'post', null, self::META_ATTACHMENT_MARKER, $user_id, true );
		update_post_meta( $attachment_id, self::META_ATTACHMENT_MARKER, $user_id );

		return true;
	}

	/**
	 * Detach the user's avatar.
	 *
	 * The attachment stays in the media library; only the association is
	 * removed, so no files are deleted on the writer's behalf.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function clear( $user_id ) {
		$user_id = (int) $user_id;
		$current = self::get_id( $user_id );

		delete_user_meta( $user_id, self::meta_key() );

		if ( $current > 0 ) {
			delete_post_meta( $current, self::META_ATTACHMENT_MARKER, $user_id );
		}
	}

	/**
	 * Account payload fields for the dashboard.
	 *
	 * @param int $user_id User ID.
	 * @return array<string,mixed>
	 */
	public static function get_account_fields( $user_id ) {
		return array(
			'avatarSupported' => self::is_supported(),
			'avatarId'        => self::get_id( $user_id ),
			'avatarUrl'       => self::get_url( $user_id ),
		);
	}
}
