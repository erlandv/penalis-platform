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
	 * Purposes a challenge may be issued for.
	 *
	 * A challenge is bound to exactly one purpose, so a registration code can
	 * never confirm an email change and vice versa.
	 */
	private const PURPOSES = array( 'registration', 'legacy', 'email_change' );

	/**
	 * Run an operation under a site-specific lock. Never proceed unlocked.
	 *
	 * @param callable $operation Operation.
	 * @return mixed
	 */
	private static function locked( $operation ) {
		global $wpdb;
		$busy = new WP_Error( 'penalis_busy', 'Server sedang sibuk. Coba sesaat lagi.', array( 'status' => 503 ) );
		if ( ! is_object( $wpdb ) ) {
			return $busy;
		}
		return Penalis_Account_Status::with_advisory_lock(
			'penalis_otp_' . md5( (string) $wpdb->users ),
			5,
			$operation,
			$busy
		);
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
	 * When no challenge was issued the identifier is random and meaningless, so
	 * the endpoint cannot be used to discover whether an account exists.
	 *
	 * @param string $id Opaque identifier.
	 * @return array
	 */
	public static function response( $id = '' ) {
		return array(
			'challenge_id' => '' !== $id ? $id : Penalis_Policy::generate_token(),
			'resend_after' => Penalis_Policy::OTP_RESEND_WAIT,
			'expires_in'   => Penalis_Policy::OTP_TTL,
			'max_attempts' => Penalis_Policy::OTP_MAX_ATTEMPTS,
			'message'      => 'Jika permintaan memenuhi syarat, kode verifikasi telah dikirim. Masukkan kode enam digit dari email kamu.',
		);
	}

	/**
	 * Read the still-valid challenge ID for a purpose.
	 *
	 * Used when a resend is throttled: the caller must keep the existing
	 * challenge instead of replacing it with a random identifier, otherwise the
	 * code the writer already received would stop working.
	 *
	 * @param string $purpose Purpose.
	 * @param int    $user_id User ID.
	 * @return string Challenge ID, or an empty string when none is valid.
	 */
	public static function current( $purpose, $user_id ) {
		if ( ! in_array( $purpose, self::PURPOSES, true ) ) {
			return '';
		}

		$state = get_user_meta( (int) $user_id, '_penalis_otp_' . $purpose, true );

		if ( ! is_array( $state ) || ! isset( $state['id'], $state['expires'], $state['attempts'] ) ) {
			return '';
		}

		if ( (int) $state['expires'] <= time() || (int) $state['attempts'] >= Penalis_Policy::OTP_MAX_ATTEMPTS ) {
			return '';
		}

		return (string) $state['id'];
	}

	/**
	 * HMAC binds every security-relevant challenge field.
	 *
	 * The code space is only one million values, so a plain hash would be
	 * brute-forceable offline. The site salt acts as a server-side pepper.
	 *
	 * @param array  $state Stored context.
	 * @param string $code  Code string.
	 * @return string
	 */
	private static function digest( $state, $code ) {
		return hash_hmac(
			'sha256',
			implode(
				'|',
				array(
					$state['id'],
					$state['purpose'],
					$state['user_id'],
					$state['email'],
					$state['source'],
					$state['expires'],
					$code,
				)
			),
			wp_salt( 'auth' )
		);
	}

	/**
	 * Delete every stored challenge for a user.
	 *
	 * Called when an account changes status so a pending code cannot be used
	 * after an administrator suspends or activates the account.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function clear( $user_id ) {
		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return;
		}

		foreach ( self::PURPOSES as $purpose ) {
			$key   = '_penalis_otp_' . $purpose;
			$state = get_user_meta( $user_id, $key, true );

			if ( is_array( $state ) && isset( $state['id'] ) ) {
				delete_transient( 'penalis_otp_' . hash( 'sha256', (string) $state['id'] ) );
			}

			delete_user_meta( $user_id, $key );
		}

		delete_user_meta( $user_id, '_penalis_otp_failures' );
	}

	/**
	 * Clear the cumulative failure counter for a user.
	 *
	 * An administrator reissuing a code is treated as a deliberate unlock; the
	 * counter must not keep an account locked after that.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function unlock( $user_id ) {
		delete_user_meta( (int) $user_id, '_penalis_otp_failures' );
	}

	/**
	 * Issue and deliver a challenge. Budgets survive replacement challenges.
	 *
	 * The challenge is created under the lock, but the email is sent after the
	 * lock is released: an SMTP call can take seconds, and holding a global
	 * advisory lock for that long would stall every other verification.
	 *
	 * @param string $purpose    registration, legacy, or email_change.
	 * @param int    $user_id    User ID.
	 * @param string $email      Destination.
	 * @param bool   $enforce_ip Whether the per-IP budget applies. Administrator
	 *                           actions pass false: a single admin resending
	 *                           codes for many writers shares one IP and must
	 *                           not exhaust a public-facing abuse budget.
	 * @return array|WP_Error
	 */
	public static function issue( $purpose, $user_id, $email, $enforce_ip = true ) {
		$prepared = self::locked(
			static function () use ( $purpose, $user_id, $email, $enforce_ip ) {
				$user_id = (int) $user_id;
				$user    = get_userdata( $user_id );
				$email   = sanitize_email( (string) $email );

				if ( ! $user || ! in_array( $purpose, self::PURPOSES, true ) || ! is_email( $email ) ) {
					return self::invalid();
				}

				// Defense in depth: a challenge is only meaningful while the
				// account is still in the state that purpose expects.
				if ( 'registration' === $purpose && Penalis_Policy::STATUS_PENDING_VERIFICATION !== Penalis_Account_Status::get_status( $user_id ) ) {
					return self::invalid();
				}

				if ( 'legacy' === $purpose && ! Penalis_Account_Status::is_legacy_account( $user_id ) ) {
					return self::invalid();
				}

				if ( (int) get_user_meta( $user_id, '_penalis_otp_failures', true ) >= Penalis_Policy::OTP_MAX_FAILURES ) {
					return self::invalid();
				}

				$now      = time();
				$subjects = array(
					array( 'user:' . $user_id, Penalis_Policy::OTP_MAX_ISSUES ),
					array( 'email:' . strtolower( $email ), Penalis_Policy::OTP_MAX_ISSUES ),
					array( 'ip:' . Penalis_Rate_Limiter::client_ip(), 20 ),
				);

				if ( ! $enforce_ip ) {
					$subjects = array_slice( $subjects, 0, 2 );
				}

				$subjects[] = array( 'day_user:' . $user_id, Penalis_Policy::OTP_DAILY_ISSUES );
				$subjects[] = array( 'day_email:' . strtolower( $email ), Penalis_Policy::OTP_DAILY_ISSUES );
				$rows       = array();

				foreach ( $subjects as $subject ) {
					$key   = 'penalis_otp_budget_' . hash_hmac( 'sha256', $subject[0], wp_salt( 'auth' ) );
					$limit = (int) $subject[1];
					$row   = get_transient( $key );

					if ( ! is_array( $row ) || ! isset( $row['count'], $row['last'], $row['until'] ) || (int) $row['until'] <= $now ) {
						$row = array(
							'count' => 0,
							'last'  => 0,
							'until' => $now + ( 0 === strpos( $subject[0], 'day_' ) ? DAY_IN_SECONDS : Penalis_Policy::OTP_WINDOW ),
						);
					}

					$is_ip = 0 === strpos( $subject[0], 'ip:' );

					if ( (int) $row['count'] >= $limit || ( ! $is_ip && (int) $row['last'] + Penalis_Policy::OTP_RESEND_WAIT > $now ) ) {
						return new WP_Error(
							'penalis_rate_limited',
							'Terlalu banyak permintaan kode. Silakan coba lagi nanti.',
							array( 'status' => 429 )
						);
					}

					$rows[ $key ] = $row;
				}

				foreach ( $rows as $key => $row ) {
					++$row['count'];
					$row['last'] = $now;

					if ( ! set_transient( $key, $row, max( 1, (int) $row['until'] - $now ) ) ) {
						return self::invalid();
					}
				}

				$id    = Penalis_Policy::generate_token();
				$max   = (int) ( 10 ** Penalis_Policy::OTP_CODE_LENGTH ) - 1;
				$code  = sprintf( '%0' . Penalis_Policy::OTP_CODE_LENGTH . 'd', random_int( 0, $max ) );
				$state = array(
					'id'       => $id,
					'purpose'  => $purpose,
					'user_id'  => $user_id,
					'email'    => $email,
					'source'   => $user->user_email,
					'expires'  => $now + Penalis_Policy::OTP_TTL,
					'attempts' => 0,
				);

				$state['hash'] = self::digest( $state, $code );
				$key           = '_penalis_otp_' . $purpose;

				// Replacing the challenge makes the previous code unusable at once.
				// Its state is retained only so a failed SMTP delivery can restore
				// it, leaving the writer with the code from the earlier email.
				$previous = get_user_meta( $user_id, $key, true );

				if ( ! update_user_meta( $user_id, $key, $state ) ) {
					return self::invalid();
				}

				// Indexed locator contains no code; old locators fail the current-ID check.
				if ( ! set_transient( 'penalis_otp_' . hash( 'sha256', $id ), array( $user_id, $purpose ), Penalis_Policy::OTP_TTL ) ) {
					self::restore( $user_id, $key, $previous );
					return self::invalid();
				}

				return array(
					'id'       => $id,
					'code'     => $code,
					'user_id'  => $user_id,
					'email'    => $email,
					'previous' => is_array( $previous ) ? $previous : array(),
				);
			}
		);

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		if ( ! Penalis_Emails::send_otp( $prepared['user_id'], $prepared['email'], $prepared['code'], $purpose ) ) {
			// The code was never delivered, so it must not stay usable. The
			// challenge it replaced is restored so an earlier email keeps
			// working.
			self::discard( $purpose, $prepared['user_id'], $prepared['id'], $prepared['previous'] );

			return new WP_Error(
				'penalis_email_failed',
				'Kode verifikasi tidak dapat dikirim. Silakan coba lagi nanti.',
				array( 'status' => 500 )
			);
		}

		// The new code is delivered, so the challenge it replaced is now dead.
		if ( ! empty( $prepared['previous']['id'] ) ) {
			delete_transient( 'penalis_otp_' . hash( 'sha256', (string) $prepared['previous']['id'] ) );
		}

		return self::response( $prepared['id'] );
	}

	/**
	 * Put a previous challenge back after a failed replacement.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $key      Meta key.
	 * @param mixed  $previous Previous state.
	 * @return void
	 */
	private static function restore( $user_id, $key, $previous ) {
		if ( is_array( $previous ) && ! empty( $previous['id'] ) ) {
			update_user_meta( (int) $user_id, $key, $previous );
			return;
		}

		delete_user_meta( (int) $user_id, $key );
	}

	/**
	 * Remove one challenge that could not be delivered.
	 *
	 * @param string $purpose  Purpose.
	 * @param int    $user_id  User ID.
	 * @param string $id       Challenge ID.
	 * @param array  $previous Challenge to restore, if any.
	 * @return void
	 */
	private static function discard( $purpose, $user_id, $id, $previous = array() ) {
		$key   = '_penalis_otp_' . $purpose;
		$state = get_user_meta( (int) $user_id, $key, true );

		if ( is_array( $state ) && isset( $state['id'] ) && $state['id'] === $id ) {
			self::restore( $user_id, $key, $previous );
		}

		delete_transient( 'penalis_otp_' . hash( 'sha256', (string) $id ) );
	}

	/**
	 * Verify and consume before invoking the lifecycle mutation under the lock.
	 *
	 * @param string   $purpose Purpose expected by endpoint.
	 * @param string   $id      Opaque ID.
	 * @param string   $code    Six-digit string.
	 * @param callable $apply   Mutation, receiving stored context.
	 * @param int      $expected_user_id Optional owner the challenge must belong to.
	 * @return mixed
	 */
	public static function consume( $purpose, $id, $code, $apply, $expected_user_id = 0 ) {
		return self::locked(
			static function () use ( $purpose, $id, $code, $apply, $expected_user_id ) {
				if ( ! in_array( $purpose, self::PURPOSES, true ) || ! Penalis_Policy::is_token_format_valid( $id ) ) {
					return self::invalid();
				}

				$locator_key = 'penalis_otp_' . hash( 'sha256', $id );
				$locator     = get_transient( $locator_key );

				if ( ! is_array( $locator ) || ! isset( $locator[0], $locator[1] ) || $purpose !== $locator[1] ) {
					return self::invalid();
				}

				$user_id = (int) $locator[0];
				if ( $expected_user_id > 0 && $user_id !== (int) $expected_user_id ) {
					return self::invalid();
				}
				wp_cache_delete( $user_id, 'user_meta' );

				$key   = '_penalis_otp_' . $purpose;
				$state = get_user_meta( $user_id, $key, true );
				$user  = get_userdata( $user_id );

				if ( ! is_array( $state ) || ! isset( $state['id'], $state['expires'], $state['attempts'], $state['hash'], $state['source'] ) ) {
					return self::invalid();
				}

				if (
					$state['id'] !== $id
					|| (int) $state['expires'] <= time()
					|| (int) $state['attempts'] >= Penalis_Policy::OTP_MAX_ATTEMPTS
					|| ! $user
					|| $state['source'] !== $user->user_email
				) {
					return self::invalid();
				}

				$failures = (int) get_user_meta( $user_id, '_penalis_otp_failures', true );
				if ( $failures >= Penalis_Policy::OTP_MAX_FAILURES ) {
					return self::invalid();
				}

				++$state['attempts'];

				if ( ! update_user_meta( $user_id, $key, $state ) ) {
					return self::invalid();
				}

				$pattern = '/\A[0-9]{' . Penalis_Policy::OTP_CODE_LENGTH . '}\z/';

				if ( 1 !== preg_match( $pattern, (string) $code ) || ! hash_equals( (string) $state['hash'], self::digest( $state, (string) $code ) ) ) {
					update_user_meta( $user_id, '_penalis_otp_failures', $failures + 1 );
					return self::invalid();
				}

				if ( ! delete_user_meta( $user_id, $key ) ) {
					return self::invalid();
				}

				delete_transient( $locator_key );
				delete_user_meta( $user_id, '_penalis_otp_failures' );

				return $apply( $state );
			}
		);
	}
}
