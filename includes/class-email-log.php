<?php
/**
 * Delivery log for transactional email.
 *
 * WordPress hands `wp_mail()` a message and returns a boolean; what it does not
 * keep is any record of what was sent, to whom, and whether it worked. When a
 * writer says "I never got the email", there is nothing to look at. This class
 * keeps the last hundred attempts so an administrator can answer that question
 * without enabling a mail plugin.
 *
 * Deliberate limits:
 * - The log lives in one option, capped at `MAX_ENTRIES`, newest first. It is
 *   operational data on a community site, not an audit trail: no schema, no
 *   migration, and clearing it is a single `delete_option()`.
 * - Two sends finishing in the same instant can drop one entry (last write
 *   wins). Accepting that is what buys the missing schema.
 * - Values of one-time secrets (`SECRET_TAGS`) are never stored. An entry that
 *   carried a secret is not resendable, because the message it produced cannot
 *   be reproduced.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores and filters recent transactional email attempts.
 */
final class Penalis_Email_Log {

	const OPTION_NAME = 'penalis_email_log';

	/** How many attempts are kept. Oldest entries fall off the end. */
	const MAX_ENTRIES = 100;

	const STATUS_SENT   = 'sent';
	const STATUS_FAILED = 'failed';

	/**
	 * Placeholders whose values must never be written to the database.
	 *
	 * A stored OTP code is a working credential until it expires, and a stored
	 * reset link is a working one until it is used. Logging either would turn a
	 * debugging aid into an account-takeover path for anyone who can read
	 * `wp_options`.
	 */
	const SECRET_TAGS = array( '{kode_otp}', '{link_reset_password}', '{link_aktivasi}' );

	/** Longest value kept per placeholder, in characters. */
	const MAX_VAR_LENGTH = 300;

	/** Longest error message kept, in characters. */
	const MAX_ERROR_LENGTH = 300;

	/**
	 * Append one attempt to the log.
	 *
	 * @param array $args {
	 *     Attempt data.
	 *
	 *     @type string $to        Recipient address.
	 *     @type string $template  Email settings registry key, when known.
	 *     @type string $subject   Rendered subject line.
	 *     @type string $status    `sent` or `failed`.
	 *     @type string $error     Failure reason from `wp_mail_failed`.
	 *     @type array  $vars      Placeholder values used to render the message.
	 *     @type int    $user_id   Related user, when known.
	 *     @type int    $post_id   Related article, when known.
	 *     @type bool   $test      Whether this was an administrator test send.
	 *     @type string $resend_of Identifier of the entry this one repeats.
	 *     @type int    $time      Unix timestamp; defaults to now.
	 * }
	 * @return array<string,mixed> The stored entry.
	 */
	public static function record( array $args ) {
		$template = isset( $args['template'] ) ? sanitize_key( (string) $args['template'] ) : '';
		$vars     = isset( $args['vars'] ) && is_array( $args['vars'] ) ? $args['vars'] : array();
		$secret   = self::has_secret( $vars );
		$test     = ! empty( $args['test'] );
		$status   = isset( $args['status'] ) ? (string) $args['status'] : '';

		if ( ! in_array( $status, array( self::STATUS_SENT, self::STATUS_FAILED ), true ) ) {
			$status = self::STATUS_FAILED;
		}

		$entry = array(
			'id'         => 'e_' . str_replace( '.', '', uniqid( '', true ) ),
			'time'       => isset( $args['time'] ) ? (int) $args['time'] : time(),
			'to'         => isset( $args['to'] ) ? sanitize_email( (string) $args['to'] ) : '',
			'template'   => $template,
			'subject'    => self::shorten( isset( $args['subject'] ) ? (string) $args['subject'] : '', 200 ),
			'status'     => $status,
			'error'      => self::shorten( isset( $args['error'] ) ? (string) $args['error'] : '', self::MAX_ERROR_LENGTH ),
			'user_id'    => isset( $args['user_id'] ) ? absint( $args['user_id'] ) : 0,
			'post_id'    => isset( $args['post_id'] ) ? absint( $args['post_id'] ) : 0,
			'test'       => $test,
			'resend_of'  => isset( $args['resend_of'] ) ? sanitize_text_field( (string) $args['resend_of'] ) : '',
			// A secret is dropped with the whole payload: a partial message
			// would render with an empty placeholder if it were ever resent.
			'vars'       => $secret ? array() : self::sanitize_vars( $vars ),
			'resendable' => ! $secret && ! $test && '' !== $template && ! empty( $vars ),
		);

		$max_entries = class_exists( 'Penalis_Config' ) ? (int) Penalis_Config::performance()['email_log_limit'] : self::MAX_ENTRIES;
		$entries     = array_slice( array_merge( array( $entry ), self::entries() ), 0, $max_entries );

		update_option( self::OPTION_NAME, $entries, false );

		return $entry;
	}

	/**
	 * All stored entries, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function entries() {
		$stored = get_option( self::OPTION_NAME, array() );

		return is_array( $stored ) ? array_values( array_filter( $stored, 'is_array' ) ) : array();
	}

	/**
	 * Find one entry by identifier.
	 *
	 * @param string $id Entry identifier.
	 * @return array<string,mixed>|null
	 */
	public static function find( $id ) {
		$needle = (string) $id;

		if ( '' === $needle ) {
			return null;
		}

		foreach ( self::entries() as $entry ) {
			if ( isset( $entry['id'] ) && $needle === (string) $entry['id'] ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Remove every entry.
	 *
	 * @return void
	 */
	public static function clear() {
		delete_option( self::OPTION_NAME );
	}

	/**
	 * Narrow entries by status and template.
	 *
	 * @param array<int,array<string,mixed>> $entries  Entries to filter.
	 * @param string                         $status   `sent`, `failed`, or an empty string for all.
	 * @param string                         $template Template key, or an empty string for all.
	 * @return array<int,array<string,mixed>>
	 */
	public static function filter( array $entries, $status = '', $template = '' ) {
		$status   = (string) $status;
		$template = (string) $template;

		return array_values(
			array_filter(
				$entries,
				static function ( $entry ) use ( $status, $template ) {
					if ( '' !== $status && ( $entry['status'] ?? '' ) !== $status ) {
						return false;
					}

					if ( '' !== $template && ( $entry['template'] ?? '' ) !== $template ) {
						return false;
					}

					return true;
				}
			)
		);
	}

	/**
	 * Whether a set of placeholders contains a value that must not be stored.
	 *
	 * @param array<string,mixed> $vars Placeholder values.
	 * @return bool
	 */
	public static function has_secret( array $vars ) {
		foreach ( self::SECRET_TAGS as $tag ) {
			if ( isset( $vars[ $tag ] ) && '' !== (string) $vars[ $tag ] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Keep placeholder values short and scalar.
	 *
	 * @param array<string,mixed> $vars Placeholder values.
	 * @return array<string,string>
	 */
	private static function sanitize_vars( array $vars ) {
		$clean = array();

		foreach ( $vars as $tag => $value ) {
			if ( count( $clean ) >= 20 ) {
				break;
			}

			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$clean[ (string) $tag ] = self::shorten( (string) $value, self::MAX_VAR_LENGTH );
		}

		return $clean;
	}

	/**
	 * Trim a string to a byte-safe length.
	 *
	 * @param string $value  Raw value.
	 * @param int    $length Maximum characters.
	 * @return string
	 */
	private static function shorten( $value, $length ) {
		$value = sanitize_text_field( $value );

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $length );
		}

		return substr( $value, 0, $length );
	}
}
