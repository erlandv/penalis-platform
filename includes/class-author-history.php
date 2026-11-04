<?php
/**
 * Historical author identifiers and reservations.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps historical identifiers reserved without changing existing accounts.
 */
final class Penalis_Author_History {
	/**
	 * User meta key. Rows are never pruned automatically.
	 *
	 * @var string
	 */
	const META_KEY = '_penalis_author_history';

	/**
	 * Register native validation and early canonical redirects.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'registration_errors', array( __CLASS__, 'validate_registration' ), 10, 2 );
		add_action( 'user_profile_update_errors', array( __CLASS__, 'validate_profile' ), 10, 3 );
		add_action( 'template_redirect', array( __CLASS__, 'redirect' ), 1 );
	}

	/**
	 * Find conflicts using database collation, not PHP string comparisons.
	 *
	 * @param string $name Candidate identifier.
	 * @param int    $owner Reclaiming owner, or zero for registration.
	 * @return bool
	 */
	public static function unavailable( $name, $owner = 0 ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Authoritative collation-aware namespace check; never use cached user lookups.
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE (user_login = %s OR user_nicename = %s) AND ID <> %d LIMIT 1", $name, $name, $owner ) );
		if ( $current ) {
			return true;
		}

		// Slugs are separate meta rows so SQL compares the slug itself under the
		// database collation; serialized LIKE matching is not a uniqueness check.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Reservation lookup must use the same collation as current identifiers.
		$holders = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s AND user_id <> %d", self::META_KEY . '_reserved', $name, $owner ) );
		return ! empty( $holders );
	}

	/**
	 * Persist one historical identifier before the users row is changed.
	 *
	 * A failed reservation may leave an extra safe reservation, but must never
	 * allow the rename to discard the old URL. No rollback deletes history.
	 *
	 * @param int    $owner Owner ID.
	 * @param string $slug Identifier.
	 * @param bool   $redirect Whether this was an actual author slug.
	 * @return bool
	 */
	public static function remember( $owner, $slug, $redirect ) {
		$rows = (array) get_user_meta( $owner, self::META_KEY, false );
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['slug'], $row['reserved'] ) ) {
				continue;
			}
			if ( $row['slug'] !== $slug || ! $row['reserved'] ) {
				continue;
			}

			// A login-only reservation can later become a real author slug.
			// Upgrade the row instead of adding a duplicate, so the redirect
			// starts working without losing the audit entry.
			if ( $redirect && empty( $row['redirect'] ) ) {
				$upgraded             = $row;
				$upgraded['redirect'] = true;
				if ( false === update_user_meta( $owner, self::META_KEY, $upgraded, $row ) ) {
					return false;
				}
			}

			return self::reserve( $owner, $slug );
		}
		if ( ! self::reserve( $owner, $slug ) ) {
			return false;
		}
		return false !== add_user_meta(
			$owner,
			self::META_KEY,
			array(
				'slug'     => $slug,
				'time'     => time(),
				'reserved' => true,
				'redirect' => (bool) $redirect,
			)
		);
	}

	/**
	 * Ensure the searchable reservation is persisted.
	 *
	 * @param int    $owner Owner ID.
	 * @param string $slug Identifier.
	 * @return bool
	 */
	private static function reserve( $owner, $slug ) {
		if ( in_array( $slug, (array) get_user_meta( $owner, self::META_KEY . '_reserved', false ), true ) ) {
			return true;
		}
		return false !== add_user_meta( $owner, self::META_KEY . '_reserved', $slug );
	}

	/**
	 * Explicit internal release; callers must hold the username advisory lock.
	 *
	 * Released rows remain as audit history, but no longer redirect or reserve.
	 *
	 * @param int    $owner Owner ID.
	 * @param string $slug Identifier.
	 * @return bool
	 */
	public static function release( $owner, $slug ) {
		foreach ( (array) get_user_meta( $owner, self::META_KEY, false ) as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['slug'], $row['reserved'] ) ) {
				continue;
			}
			if ( $row['slug'] === $slug && $row['reserved'] ) {
				$released             = $row;
				$released['reserved'] = false;
				if ( false === update_user_meta( $owner, self::META_KEY, $released, $row ) ) {
					return false;
				}
			}
		}
		delete_user_meta( $owner, self::META_KEY . '_reserved', $slug );
		return ! in_array( $slug, (array) get_user_meta( $owner, self::META_KEY . '_reserved', false ), true );
	}

	/**
	 * Reject reserved identifiers during native registration.
	 *
	 * `wp_pre_insert_user_data` cannot reject an insert: core only checks that
	 * the returned value is a non-empty array, so a WP_Error there is silently
	 * discarded. `registration_errors` is the supported place to add an error.
	 *
	 * @param WP_Error $errors Registration errors.
	 * @param string   $login  Sanitized login.
	 * @return WP_Error
	 */
	public static function validate_registration( $errors, $login ) {
		$login = (string) $login;

		if ( '' !== $login && self::unavailable( $login ) ) {
			$errors->add( 'penalis_username_unavailable', 'Username tersebut tidak dapat digunakan.' );
		}

		return $errors;
	}

	/**
	 * Reject reserved identifiers on an administrator profile update.
	 *
	 * The owner is excluded so an account can keep its own historical slug and
	 * unrelated profile edits on legacy accounts are not blocked.
	 *
	 * @param WP_Error $errors Profile errors.
	 * @param bool     $update Whether this is an update.
	 * @param object   $user   User object being saved.
	 * @return void
	 */
	public static function validate_profile( $errors, $update, $user ) {
		if ( ! is_object( $user ) ) {
			return;
		}

		$owner = isset( $user->ID ) ? (int) $user->ID : 0;
		$old   = $owner ? get_userdata( $owner ) : false;

		foreach ( array( 'user_login', 'user_nicename' ) as $field ) {
			$value = isset( $user->$field ) ? (string) $user->$field : '';

			// Only a changed identifier is checked. An unrelated profile edit
			// on a legacy account must not be blocked by a historical
			// reservation it never asked to take.
			if ( '' === $value || ( $old && isset( $old->$field ) && $old->$field === $value ) ) {
				continue;
			}

			if ( self::unavailable( $value, $owner ) ) {
				$errors->add( 'penalis_username_unavailable', 'Username tersebut tidak dapat digunakan.' );
				return;
			}
		}
	}

	/**
	 * Resolve only genuine historical slugs, never login-only reservations.
	 *
	 * @param string $slug Requested author slug.
	 * @return int Owner ID, or zero.
	 */
	public static function redirect_owner( $slug ) {
		global $wpdb;

		if ( get_user_by( 'slug', $slug ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Reservation lookup must use the same collation as current identifiers.
		$owners = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s", self::META_KEY . '_reserved', $slug ) );

		foreach ( (array) $owners as $owner ) {
			foreach ( (array) get_user_meta( $owner, self::META_KEY, false ) as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['slug'], $row['reserved'], $row['redirect'] ) ) {
					continue;
				}
				if ( $row['slug'] === $slug && $row['reserved'] && $row['redirect'] ) {
					return (int) $owner;
				}
			}
		}

		return 0;
	}

	/**
	 * Redirect through WordPress's parsed author rewrite, preserving custom bases.
	 *
	 * @return void
	 */
	public static function redirect() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';

		if ( is_admin() || ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return;
		}

		$slug  = (string) get_query_var( 'author_name' );
		$owner = '' !== $slug ? self::redirect_owner( $slug ) : 0;
		$user  = $owner ? get_userdata( $owner ) : false;
		if ( ! $user || $user->user_nicename === $slug ) {
			return;
		}
		$url = get_author_posts_url( $owner );
		if ( wp_parse_url( $url, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) && wp_safe_redirect( $url, 301, 'Penalis' ) ) {
			exit;
		}
	}
}
