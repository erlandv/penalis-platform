<?php
/**
 * Purpose-bound email challenges.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serializes issuance, attempts and consumption using a database advisory lock.
 */
final class Penalis_Otp {

	/**
	 * Run an operation under a site-specific lock. Never proceed unlocked.
	 *
	 * @param callable $operation Operation.
	 * @return mixed
	 */
	private static function locked( $operation ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return self::invalid();
		}
		$name = 'penalis_otp_' . md5( $wpdb->users );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Advisory locks cannot be cached.
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $name ) ) ) {
			return self::invalid();
		}
		try {
			return $operation();
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Release serialization lock.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}

	/**
	 * Generic invalid challenge error.
	 *
	 * @return WP_Error
	 */
	public static function invalid() {
		return new WP_Error( 'penalis_invalid_code', 'Kode tidak valid, sudah digunakan, atau kedaluwarsa. Minta kode baru jika diperlukan.', array( 'status' => 400 ) );
	}

	/**
	 * Public response identical for eligible and unknown accounts.
	 *
	 * @param string $id Opaque identifier.
	 * @return array
	 */
	public static function response( $id = '' ) {
		return array(
			'challenge_id' => '' !== $id ? $id : Penalis_Policy::generate_token(),
			'resend_after' => 60,
			'expires_in'   => 600,
			'message'      => 'Jika permintaan memenuhi syarat, kode verifikasi telah dikirim. Masukkan kode enam digit dari email kamu.',
		);
	}

	/**
	 * HMAC binds every security-relevant challenge field.
	 *
	 * @param array  $state Stored context.
	 * @param string $code  Code string.
	 * @return string
	 */
	private static function digest( $state, $code ) {
		return hash_hmac( 'sha256', implode( '|', array( $state['id'], $state['purpose'], $state['user_id'], $state['email'], $state['source'], $state['expires'], $code ) ), wp_salt( 'auth' ) );
	}

	/**
	 * Issue and deliver a challenge. Budgets survive replacement challenges.
	 *
	 * @param string $purpose registration, legacy, or email_change.
	 * @param int    $user_id User ID.
	 * @param string $email   Destination.
	 * @return array|WP_Error
	 */
	public static function issue( $purpose, $user_id, $email ) {
		return self::locked(
			static function () use ( $purpose, $user_id, $email ) {
				$user = get_userdata( $user_id );
				if ( ! $user || ! in_array( $purpose, array( 'registration', 'legacy', 'email_change' ), true ) ) {
					return self::invalid();
				}
				$now  = time();
				$keys = array( 'user:' . $user_id, 'email:' . strtolower( $email ), 'ip:' . Penalis_Rate_Limiter::client_ip() );
				$rows = array();
				foreach ( $keys as $subject ) {
					$key = 'penalis_otp_budget_' . hash_hmac( 'sha256', $subject, wp_salt( 'auth' ) );
					$row = get_transient( $key );
					if ( ! is_array( $row ) || $row['until'] <= $now ) {
						$row = array( 'count' => 0, 'last' => 0, 'until' => $now + 3600 );
					}
					$limit = 0 === strpos( $subject, 'ip:' ) ? 20 : 5;
					if ( $row['count'] >= $limit || ( 0 !== strpos( $subject, 'ip:' ) && $row['last'] + 60 > $now ) ) {
						return self::response();
					}
					$rows[ $key ] = $row;
				}
				foreach ( $rows as $key => $row ) {
					++$row['count'];
					$row['last'] = $now;
					if ( ! set_transient( $key, $row, $row['until'] - $now ) ) {
						return self::invalid();
					}
				}
				$id    = Penalis_Policy::generate_token();
				$code  = sprintf( '%06d', random_int( 0, 999999 ) );
				$state = array(
					'id' => $id, 'purpose' => $purpose, 'user_id' => (int) $user_id,
					'email' => $email, 'source' => $user->user_email,
					'expires' => $now + 600, 'attempts' => 0,
				);
				$state['hash'] = self::digest( $state, $code );
				$key           = '_penalis_otp_' . $purpose;
				if ( ! update_user_meta( $user_id, $key, $state ) ) {
					return self::invalid();
				}
				// Indexed locator contains no code; old locators fail the current-ID check.
				if ( ! set_transient( 'penalis_otp_' . hash( 'sha256', $id ), array( $user_id, $purpose ), 600 ) ) {
					delete_user_meta( $user_id, $key );
					return self::invalid();
				}
				if ( ! Penalis_Emails::send_otp( $user_id, $email, $code, $purpose ) ) {
					delete_user_meta( $user_id, $key );
				}
				return self::response( $id );
			}
		);
	}

	/**
	 * Verify and consume before invoking the lifecycle mutation under the lock.
	 *
	 * @param string   $purpose Purpose expected by endpoint.
	 * @param string   $id      Opaque ID.
	 * @param string   $code    Six-digit string.
	 * @param callable $apply   Mutation, receiving stored context.
	 * @return mixed
	 */
	public static function consume( $purpose, $id, $code, $apply ) {
		return self::locked(
			static function () use ( $purpose, $id, $code, $apply ) {
				if ( ! Penalis_Policy::is_token_format_valid( $id ) ) {
					return self::invalid();
				}
				$locator = get_transient( 'penalis_otp_' . hash( 'sha256', $id ) );
				if ( ! is_array( $locator ) || $purpose !== $locator[1] ) {
					return self::invalid();
				}
				$user_id = (int) $locator[0];
				wp_cache_delete( $user_id, 'user_meta' );
				$key   = '_penalis_otp_' . $purpose;
				$state = get_user_meta( $user_id, $key, true );
				$user  = get_userdata( $user_id );
				if ( ! is_array( $state ) || $state['id'] !== $id || $state['expires'] <= time() || $state['attempts'] >= 5 || ! $user || $state['source'] !== $user->user_email ) {
					return self::invalid();
				}
				++$state['attempts'];
				if ( ! update_user_meta( $user_id, $key, $state ) ) {
					return self::invalid();
				}
				if ( 1 !== preg_match( '/\A[0-9]{6}\z/', $code ) || ! hash_equals( $state['hash'], self::digest( $state, $code ) ) ) {
					return self::invalid();
				}
				if ( ! delete_user_meta( $user_id, $key ) ) {
					return self::invalid();
				}
				delete_transient( 'penalis_otp_' . hash( 'sha256', $id ) );
				return $apply( $state );
			}
		);
	}
}
