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
				'Gambar tersebut tidak tersedia di pustaka media.',
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
	 * Register the built-in avatar provider.
	 *
	 * One User Avatar stays authoritative while it is active: registering a
	 * second filter would make the rendered avatar depend on hook order. The
	 * built-in provider only takes over once that plugin is gone.
	 *
	 * @return void
	 */
	public static function register_provider() {
		if ( self::is_supported() ) {
			return;
		}

		add_filter( 'pre_get_avatar_data', array( __CLASS__, 'filter_avatar_data' ), 10, 2 );
	}

	/**
	 * Whether this plugin currently renders avatars.
	 *
	 * @return bool
	 */
	public static function is_provider() {
		return ! self::is_supported();
	}

	/**
	 * Supply avatar data from the stored attachment.
	 *
	 * WordPress calls this filter for both get_avatar() and get_avatar_url(),
	 * and re-invokes it with a doubled size for the 2x srcset entry, so only the
	 * requested size has to be resolved here.
	 *
	 * Identifiers that resolve to no user (guest commenters, Gravatar hashes)
	 * still receive the site default photo, matching the previous provider.
	 * Gravatar accounts are not consulted while a default photo is configured,
	 * which keeps rendering deterministic and avoids an outbound request.
	 *
	 * @param array<string,mixed> $args        Avatar arguments.
	 * @param mixed               $id_or_email User identifier.
	 * @return array<string,mixed>
	 */
	public static function filter_avatar_data( $args, $id_or_email ) {
		if ( ! empty( $args['force_default'] ) ) {
			return $args;
		}

		$user_id = self::resolve_user_id( $id_or_email );

		$size = isset( $args['size'] ) ? (int) $args['size'] : 96;
		$size = $size > 0 ? $size : 96;

		$attachment_id = $user_id > 0 ? self::get_id( $user_id ) : 0;

		if ( $attachment_id > 0 && wp_attachment_is_image( $attachment_id ) ) {
			$image = self::image_src( $attachment_id, $size );

			if ( $image ) {
				$args['url']          = $image[0];
				$args['found_avatar'] = true;
				$args['width']        = (int) $image[1];
				$args['height']       = (int) $image[2];

				if ( isset( $args['alt'] ) && '' === (string) $args['alt'] ) {
					$user = get_userdata( $user_id );

					if ( $user ) {
						$args['alt'] = $user->display_name;
					}
				}

				return $args;
			}
		}

		return self::apply_default_avatar( $args, $size );
	}

	/**
	 * Use the site default photo for users without one.
	 *
	 * Mirrors One User Avatar: the custom default only applies while the core
	 * `avatar_default` option selects it, so an administrator can still choose
	 * Gravatar or Mystery Person.
	 *
	 * @param array<string,mixed> $args Avatar arguments.
	 * @param int                 $size Requested size.
	 * @return array<string,mixed>
	 */
	private static function apply_default_avatar( $args, $size ) {
		$attachment_id = self::default_avatar_id();

		if ( $attachment_id <= 0 ) {
			return $args;
		}

		$image = self::image_src( $attachment_id, $size );

		if ( ! $image ) {
			return $args;
		}

		$args['url']          = $image[0];
		$args['found_avatar'] = false; // Keep the avatar-default class.
		$args['width']        = (int) $image[1];
		$args['height']       = (int) $image[2];

		return $args;
	}

	/**
	 * Attachment image source for a square size, falling back to the original.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $size          Requested square size.
	 * @return array<int,mixed>|false
	 */
	private static function image_src( $attachment_id, $size ) {
		$image = wp_get_attachment_image_src( $attachment_id, array( $size, $size ) );

		if ( ! $image ) {
			$image = wp_get_attachment_image_src( $attachment_id, 'full' );
		}

		return $image;
	}

	/**
	 * Default photo attachment ID, when the site has selected one.
	 *
	 * @return int
	 */
	public static function default_avatar_id() {
		if ( 'wp_user_avatar' !== (string) get_option( 'avatar_default' ) ) {
			return 0;
		}

		$attachment_id = (int) get_option( 'avatar_default_wp_user_avatar', 0 );

		return $attachment_id > 0 && wp_attachment_is_image( $attachment_id ) ? $attachment_id : 0;
	}

	/**
	 * Resolve a user ID from the shapes core passes to avatar filters.
	 *
	 * @param mixed $id_or_email User ID, email, WP_User, WP_Post, or WP_Comment.
	 * @return int
	 */
	public static function resolve_user_id( $id_or_email ) {
		if ( $id_or_email instanceof WP_User ) {
			return (int) $id_or_email->ID;
		}

		if ( $id_or_email instanceof WP_Post ) {
			return (int) $id_or_email->post_author;
		}

		if ( $id_or_email instanceof WP_Comment ) {
			return (int) $id_or_email->user_id;
		}

		if ( is_numeric( $id_or_email ) ) {
			return (int) $id_or_email;
		}

		if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$user = get_user_by( 'email', $id_or_email );

			return $user ? (int) $user->ID : 0;
		}

		return 0;
	}
}
