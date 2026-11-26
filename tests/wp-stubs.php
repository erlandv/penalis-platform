<?php
/**
 * Minimal WordPress function shims for repository-contained unit tests.
 *
 * This file is NOT loaded by the plugin. It exists only so policy and
 * status-transition logic can be exercised with plain PHP, without a
 * WordPress installation.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error implementation.
	 */
	class WP_Error {
		/** @var string */
		private $code;
		/** @var string */
		private $message;
		/** @var array<string,mixed> */
		private $data;

		/**
		 * Constructor.
		 *
		 * @param string              $code    Code.
		 * @param string              $message Message.
		 * @param array<string,mixed> $data    Data.
		 */
		public function __construct( $code = '', $message = '', $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		/**
		 * Error code.
		 *
		 * @return string
		 */
		public function get_error_code() {
			return $this->code;
		}

		/**
		 * Error message.
		 *
		 * @return string
		 */
		public function get_error_message() {
			return $this->message;
		}

		/**
		 * Error data.
		 *
		 * @return array<string,mixed>
		 */
		public function get_error_data() {
			return $this->data;
		}

		/**
		 * Add an error, mirroring core's WP_Error::add().
		 *
		 * @param string              $code    Code.
		 * @param string              $message Message.
		 * @param array<string,mixed> $data    Data.
		 * @return void
		 */
		public function add( $code, $message, $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Whether a value is a WP_Error.
	 *
	 * @param mixed $thing Value.
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	/**
	 * Minimal WP_User test double.
	 */
	class WP_User {
		/** @var int */
		public $ID;
		/** @var string */
		public $user_email;
		/** @var string */
		public $user_login;
		/** @var string */
		public $user_nicename;
		/** @var string */
		public $display_name;
		/** @var string */
		public $user_pass;
		/** @var string */
		public $user_registered = '2025-01-01 00:00:00';
		/** @var string[] */
		public $roles;
		/** @var array<string,array<string,mixed>> */
		public $meta = array();

		/**
		 * Constructor.
		 *
		 * @param int      $id    User ID.
		 * @param string[] $roles Roles.
		 */
		public function __construct( $id, $roles = array( 'subscriber' ) ) {
			$this->ID           = (int) $id;
			$this->roles        = $roles;
			$this->user_email   = 'user' . $id . '@example.com';
			$this->user_login    = 'user' . $id;
			$this->user_nicename = 'user' . $id;
			$this->display_name  = 'User ' . $id;
			$this->user_pass     = 'hash';
		}

		/**
		 * Set role.
		 *
		 * @param string $role Role.
		 * @return void
		 */
		public function set_role( $role ) {
			$this->roles = array( $role );
			$GLOBALS['penalis_test_role_changes'][] = array( $this->ID, $role );
		}

		/**
		 * Whether the user has a capability (role-name based stub).
		 *
		 * @param string $cap Capability.
		 * @return bool
		 */
		public function has_cap( $cap ) {
			$map = array(
				'penalis_writer' => array( 'read', 'edit_posts', 'publish_posts' ),
				'subscriber'     => array( 'read' ),
				'administrator'  => array( 'manage_options', 'edit_posts', 'publish_posts', 'list_users', 'edit_others_posts' ),
				'editor'         => array( 'edit_posts', 'publish_posts', 'edit_others_posts' ),
				'author'         => array( 'edit_posts', 'publish_posts' ),
				'contributor'    => array( 'read', 'edit_posts' ),
			);

			foreach ( $this->roles as $role ) {
				if ( isset( $map[ $role ] ) && in_array( $cap, $map[ $role ], true ) ) {
					return true;
				}
			}

			return false;
		}
	}
}

$GLOBALS['penalis_test_users']        = array();
$GLOBALS['penalis_test_role_changes'] = array();

if ( ! function_exists( 'get_userdata' ) ) {
	/**
	 * Get a user double.
	 *
	 * @param int $user_id User ID.
	 * @return WP_User|false
	 */
	function get_userdata( $user_id ) {
		return isset( $GLOBALS['penalis_test_users'][ (int) $user_id ] )
			? $GLOBALS['penalis_test_users'][ (int) $user_id ]
			: false;
	}
}

if ( ! function_exists( 'get_user_meta' ) ) {
	/**
	 * Read user meta.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Key.
	 * @param bool   $single  Single.
	 * @return mixed
	 */
	function get_user_meta( $user_id, $key, $single = false ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! isset( $user->meta[ $key ] ) ) {
			return $single ? '' : array();
		}

		$values = (array) $user->meta[ $key ];

		return $single ? reset( $values ) : $values;
	}
}

if ( ! function_exists( 'add_user_meta' ) ) {
	/**
	 * Append user meta, mirroring core's multi-row behaviour.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Key.
	 * @param mixed  $value   Value.
	 * @return int|false
	 */
	function add_user_meta( $user_id, $key, $value ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		$values   = isset( $user->meta[ $key ] ) ? (array) $user->meta[ $key ] : array();
		$values[] = $value;
		$user->meta[ $key ] = $values;

		return count( $values );
	}
}

if ( ! function_exists( 'update_user_meta' ) ) {
	/**
	 * Write user meta.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $key        Key.
	 * @param mixed  $value      Value.
	 * @param mixed  $prev_value Optional row to replace.
	 * @return bool
	 */
	function update_user_meta( $user_id, $key, $value, $prev_value = '' ) {
		if (
			isset( $GLOBALS['penalis_test_fail_user_meta_keys'] ) &&
			is_array( $GLOBALS['penalis_test_fail_user_meta_keys'] ) &&
			in_array( (string) $key, $GLOBALS['penalis_test_fail_user_meta_keys'], true )
		) {
			return false;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		if ( isset( $GLOBALS['penalis_test_db_events'] ) && is_array( $GLOBALS['penalis_test_db_events'] ) ) {
			$GLOBALS['penalis_test_db_events'][] = 'meta:' . $key;
		}

		if ( '' === $prev_value ) {
			// Core replaces every row for the key when no previous value is given.
			$user->meta[ $key ] = array( $value );
			return true;
		}

		$values = isset( $user->meta[ $key ] ) ? (array) $user->meta[ $key ] : array();

		foreach ( $values as $index => $row ) {
			if ( $row === $prev_value ) {
				$values[ $index ] = $value;
				$user->meta[ $key ] = $values;
				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'delete_user_meta' ) ) {
	/**
	 * Delete user meta.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Key.
	 * @param mixed  $value   Optional row to delete.
	 * @return bool
	 */
	function delete_user_meta( $user_id, $key, $value = '' ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! isset( $user->meta[ $key ] ) ) {
			return false;
		}

		if ( '' === $value ) {
			unset( $user->meta[ $key ] );
			return true;
		}

		$values = array_values(
			array_filter(
				(array) $user->meta[ $key ],
				static function ( $row ) use ( $value ) {
					return $row !== $value;
				}
			)
		);

		if ( array() === $values ) {
			unset( $user->meta[ $key ] );
		} else {
			$user->meta[ $key ] = $values;
		}

		return true;
	}
}

if ( ! function_exists( 'metadata_exists' ) ) {
	/**
	 * Whether user meta exists.
	 *
	 * @param string $type    Type.
	 * @param int    $user_id User ID.
	 * @param string $key     Key.
	 * @return bool
	 */
	function metadata_exists( $type, $user_id, $key ) {
		$user = get_userdata( $user_id );
		return $user && array_key_exists( $key, $user->meta );
	}
}

if ( ! function_exists( 'get_users' ) ) {
	/**
	 * Find users by meta, role, or capability.
	 *
	 * @param array $args Args.
	 * @return int[]
	 */
	function get_users( $args ) {
		$key        = isset( $args['meta_key'] ) ? $args['meta_key'] : '';
		$value      = isset( $args['meta_value'] ) ? $args['meta_value'] : '';
		$meta_query = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
		$role       = isset( $args['role'] ) ? (string) $args['role'] : '';
		$capability = isset( $args['capability'] ) ? (string) $args['capability'] : '';
		$out        = array();

		foreach ( $GLOBALS['penalis_test_users'] as $user ) {
			if ( '' !== $role && ! in_array( $role, (array) $user->roles, true ) ) {
				continue;
			}

			if ( '' !== $capability && ! $user->has_cap( $capability ) ) {
				continue;
			}

			if ( '' !== $key && ( ! isset( $user->meta[ $key ] ) || ! in_array( $value, (array) $user->meta[ $key ], true ) ) ) {
				continue;
			}

			$meta_matched = true;
			foreach ( $meta_query as $clause ) {
				if ( ! is_array( $clause ) || empty( $clause['key'] ) ) {
					continue;
				}
				$m_key     = (string) $clause['key'];
				$compare   = isset( $clause['compare'] ) ? strtoupper( (string) $clause['compare'] ) : '=';
				$has_meta  = isset( $user->meta[ $m_key ] ) && ! empty( $user->meta[ $m_key ] );
				$first_val = $has_meta ? reset( $user->meta[ $m_key ] ) : '';

				if ( 'EXISTS' === $compare ) {
					if ( ! $has_meta ) {
						$meta_matched = false;
						break;
					}
					continue;
				}

				if ( '>' === $compare ) {
					$expected = isset( $clause['value'] ) ? $clause['value'] : 0;
					if ( ! $has_meta || (int) $first_val <= (int) $expected ) {
						$meta_matched = false;
						break;
					}
					continue;
				}

				$expected = isset( $clause['value'] ) ? $clause['value'] : '';
				if ( ! $has_meta || (string) $first_val !== (string) $expected ) {
					$meta_matched = false;
					break;
				}
			}

			if ( ! $meta_matched ) {
				continue;
			}

			$out[] = $user->ID;
		}

		return $out;
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * Query argument stub.
	 *
	 * @param array|string $args     Arguments or key.
	 * @param mixed        ...$extra Additional arguments ($url or $value, $url).
	 * @return string
	 */
	function add_query_arg( $args, ...$extra ) {
		if ( is_array( $args ) ) {
			$url       = isset( $extra[0] ) ? (string) $extra[0] : '';
			$query_arr = $args;
		} else {
			$query_arr = array( (string) $args => isset( $extra[0] ) ? $extra[0] : '' );
			$url       = isset( $extra[1] ) ? (string) $extra[1] : '';
		}

		$parts = explode( '?', (string) $url, 2 );
		$base  = $parts[0];
		$query = array();
		if ( isset( $parts[1] ) ) {
			parse_str( $parts[1], $query );
		}
		foreach ( $query_arr as $k => $v ) {
			$query[ $k ] = $v;
		}
		$qs = http_build_query( $query );

		return $base . ( '' !== $qs ? '?' . $qs : '' );
	}
}

if ( ! function_exists( 'remove_query_arg' ) ) {
	/**
	 * Remove query arg stub.
	 *
	 * @param string|array $key Key or keys to remove.
	 * @param string       $url URL.
	 * @return string
	 */
	function remove_query_arg( $key, $url = '' ) {
		$parts = explode( '?', (string) $url, 2 );
		if ( count( $parts ) < 2 ) {
			return $url;
		}
		parse_str( $parts[1], $query );
		$keys = (array) $key;
		foreach ( $keys as $k ) {
			unset( $query[ $k ] );
		}
		$qs = http_build_query( $query );

		return $parts[0] . ( '' !== $qs ? '?' . $qs : '' );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	/**
	 * Home URL double.
	 *
	 * Tests may set `$GLOBALS['penalis_test_home_url']` to exercise non-default
	 * schemes and ports (for example `https://example.com:8443`).
	 *
	 * @param string $path Path.
	 * @return string
	 */
	function home_url( $path = '' ) {
		$home = isset( $GLOBALS['penalis_test_home_url'] ) ? (string) $GLOBALS['penalis_test_home_url'] : 'https://example.com';

		return rtrim( $home, '/' ) . $path;
	}
}

if ( ! function_exists( 'is_ssl' ) ) {
	/**
	 * SSL request double.
	 *
	 * Defaults to true so `home_url()` without a scheme resolves to https.
	 *
	 * @return bool
	 */
	function is_ssl() {
		return isset( $GLOBALS['penalis_test_is_ssl'] ) ? (bool) $GLOBALS['penalis_test_is_ssl'] : true;
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	/**
	 * Admin URL double.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	function admin_url( $path = '' ) {
		return 'https://example.com/wp-admin/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	/**
	 * Site name double.
	 *
	 * @param string $show Field.
	 * @return string
	 */
	function get_bloginfo( $show = '' ) {
		return 'name' === $show ? 'Penalis' : '';
	}
}

if ( ! function_exists( 'wp_specialchars_decode' ) ) {
	/**
	 * Entity decode double.
	 *
	 * @param string $value   Value.
	 * @param int    $quote_style Quote style.
	 * @return string
	 */
	function wp_specialchars_decode( $value, $quote_style = ENT_NOQUOTES ) {
		return htmlspecialchars_decode( (string) $value, $quote_style );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * Remove slashes.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'validate_username' ) ) {
	/**
	 * Validate a login.
	 *
	 * @param string $username Login.
	 * @return bool
	 */
	function validate_username( $username ) {
		return '' !== (string) $username && sanitize_user( $username, true ) === $username;
	}
}

if ( ! function_exists( 'wp_check_password' ) ) {
	/**
	 * Check a password against the test hash convention.
	 *
	 * @param string $password Password.
	 * @param string $hash     Hash.
	 * @param int    $user_id  User ID.
	 * @return bool
	 */
	function wp_check_password( $password, $hash, $user_id = 0 ) {
		return 'hash:' . (string) $password === (string) $hash;
	}
}

if ( ! function_exists( 'user_can' ) ) {
	/**
	 * Capability check double.
	 *
	 * @param int|WP_User $user_id User ID or user object.
	 * @param string      $cap     Capability.
	 * @return bool
	 */
	function user_can( $user_id, $cap ) {
		$user = is_object( $user_id ) ? $user_id : get_userdata( (int) $user_id );

		return $user && $user->has_cap( $cap );
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	/**
	 * Logged-in state double.
	 *
	 * @return bool
	 */
	function is_user_logged_in() {
		return get_current_user_id() > 0;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Current user double.
	 *
	 * @return int
	 */
	function get_current_user_id() {
		return isset( $GLOBALS['penalis_test_current_user'] ) ? (int) $GLOBALS['penalis_test_current_user'] : 0;
	}
}

if ( ! function_exists( 'clean_user_cache' ) ) {
	/**
	 * Record cache invalidation.
	 *
	 * @param mixed $user User or ID.
	 * @return void
	 */
	function clean_user_cache( $user ) {
		$GLOBALS['penalis_test_cleaned_users'][] = $user instanceof WP_User ? $user->ID : (int) $user;
	}
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	/**
	 * Record cache deletion.
	 *
	 * @param string $key   Key.
	 * @param string $group Group.
	 * @return bool
	 */
	function wp_cache_delete( $key, $group = '' ) {
		$GLOBALS['penalis_test_cache_deletes'][] = $group . ':' . $key;
		return true;
	}
}

if ( ! function_exists( 'wp_clear_auth_cookie' ) ) {
	/**
	 * Record auth cookie clearing.
	 *
	 * @return void
	 */
	function wp_clear_auth_cookie() {
		$GLOBALS['penalis_test_auth_cookie_cleared'] = true;
	}
}

if ( ! function_exists( 'wp_mail' ) ) {
	/**
	 * Capture mail.
	 *
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param string $message Message.
	 * @param array  $headers Headers.
	 * @return bool
	 */
	function wp_mail( $to, $subject, $message, $headers = array() ) {
		if ( ! empty( $GLOBALS['penalis_test_mail_fails'] ) ) {
			return false;
		}

		$GLOBALS['penalis_test_mail'][] = array( $to, $subject, $message, (array) $headers );
		return true;
	}
}

if ( ! class_exists( 'WP_Session_Tokens' ) ) {
	/**
	 * Session token double.
	 */
	class WP_Session_Tokens {
		/**
		 * Instance.
		 *
		 * @param int $user_id User ID.
		 * @return self
		 */
		public static function get_instance( $user_id ) {
			return new self();
		}

		/**
		 * Record session destruction.
		 *
		 * @return void
		 */
		public function destroy_all() {
			$GLOBALS['penalis_test_sessions_destroyed'] = true;
		}
	}
}

if ( ! class_exists( 'Penalis_Test_WPDB' ) ) {
	/**
	 * Minimal wpdb double for the username-change lock, lookup and update.
	 *
	 * It models the three queries the feature issues and nothing else, so a
	 * change to those queries shows up as a failing assertion instead of
	 * silently passing against an over-permissive fake.
	 */
	class Penalis_Test_WPDB {
		/** @var string */
		public $users = 'wp_users';
		/** @var string */
		public $usermeta = 'wp_usermeta';
		/** @var string */
		public $posts = 'wp_posts';

		/**
		 * Prepare a simple %s / %d query.
		 *
		 * @param string $query Query.
		 * @param mixed  ...$args Arguments.
		 * @return string
		 */
		public function prepare( $query, ...$args ) {
			$query = str_replace( array( '%s', '%d' ), "'%s'", $query );
			return vsprintf( $query, $args );
		}

		/**
		 * Answer the lock, availability and verification queries.
		 *
		 * @param string $query Query.
		 * @return string|null
		 */
		public function get_var( $query ) {
			if ( false !== strpos( $query, 'GET_LOCK' ) || false !== strpos( $query, 'RELEASE_LOCK' ) ) {
				if ( isset( $GLOBALS['penalis_test_db_events'] ) && is_array( $GLOBALS['penalis_test_db_events'] ) ) {
					$GLOBALS['penalis_test_db_events'][] = false !== strpos( $query, 'GET_LOCK' ) ? 'lock' : 'unlock';
				}

				if ( false !== strpos( $query, 'GET_LOCK' ) && isset( $GLOBALS['penalis_test_lock_queries'] ) && is_array( $GLOBALS['penalis_test_lock_queries'] ) ) {
					$GLOBALS['penalis_test_lock_queries'][] = $query;
				}

				if ( false !== strpos( $query, 'GET_LOCK' ) && isset( $GLOBALS['penalis_test_on_lock'] ) && is_callable( $GLOBALS['penalis_test_on_lock'] ) ) {
					// Simulates another request writing between the caller's
					// initial read and the moment the lock is granted.
					call_user_func( $GLOBALS['penalis_test_on_lock'] );
				}

				return isset( $GLOBALS['penalis_test_lock_available'] ) && ! $GLOBALS['penalis_test_lock_available'] ? null : '1';
			}

			// Availability check: a configured duplicate ID simulates a login
			// already owned by another account.
			if ( false !== strpos( $query, 'SELECT ID FROM' ) ) {
				if ( isset( $GLOBALS['penalis_test_duplicate_login'] ) ) {
					return $GLOBALS['penalis_test_duplicate_login'];
				}

				$args = $this->quoted_args( $query );
				$name = isset( $args[0] ) ? $args[0] : '';
				$owner = isset( $args[2] ) ? (int) $args[2] : 0;

				foreach ( $GLOBALS['penalis_test_users'] as $user ) {
					if ( (int) $user->ID === $owner ) {
						continue;
					}
					if ( $user->user_login === $name || $user->user_nicename === $name ) {
						return (string) $user->ID;
					}
				}

				return null;
			}

			// Post-write verification: report the login currently stored.
			if ( false !== strpos( $query, 'SELECT user_login FROM' ) ) {
				$user = get_userdata( (int) $GLOBALS['penalis_test_verified_user'] );
				return $user ? $user->user_login : null;
			}

			return null;
		}

		/**
		 * Answer a reservation lookup.
		 *
		 * @param string $query Query.
		 * @return string[]
		 */
		public function get_col( $query ) {
			if ( false === strpos( $query, 'SELECT user_id FROM' ) ) {
				return array();
			}

			$args  = $this->quoted_args( $query );
			$key   = isset( $args[0] ) ? $args[0] : '';
			$value = isset( $args[1] ) ? $args[1] : '';
			$owner = isset( $args[2] ) ? (int) $args[2] : 0;
			$out   = array();

			foreach ( $GLOBALS['penalis_test_users'] as $user ) {
				if ( (int) $user->ID === $owner || ! isset( $user->meta[ $key ] ) ) {
					continue;
				}
				if ( in_array( $value, (array) $user->meta[ $key ], true ) ) {
					$out[] = (string) $user->ID;
				}
			}

			return $out;
		}

		/**
		 * Extract the single-quoted literals from a prepared query.
		 *
		 * @param string $query Query.
		 * @return string[]
		 */
		private function quoted_args( $query ) {
			preg_match_all( "/'([^']*)'/", $query, $matches );
			return $matches[1];
		}

		/**
		 * Apply a users-table update.
		 *
		 * @param string               $table Table.
		 * @param array<string,string> $data  Data.
		 * @param array<string,mixed>  $where Where.
		 * @return int|false
		 */
		public function update( $table, $data, $where ) {
			if ( isset( $GLOBALS['penalis_test_update_fails'] ) && $GLOBALS['penalis_test_update_fails'] ) {
				return false;
			}

			$user = get_userdata( (int) $where['ID'] );

			if ( ! $user || $user->user_login !== $where['user_login'] ) {
				return 0;
			}

			foreach ( $data as $key => $value ) {
				$user->$key = $value;
			}

			$GLOBALS['penalis_test_verified_user'] = $user->ID;
			$GLOBALS['penalis_test_updated_login'] = $data['user_login'];

			return 1;
		}

		/**
		 * Return the table prefix for the current blog.
		 *
		 * @return string
		 */
		public function get_blog_prefix() {
			return 'wp_';
		}
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Sanitize a text field.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	function sanitize_text_field( $value ) {
		return trim( strip_tags( (string) $value ) );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Sanitize a key.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	function sanitize_key( $value ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) );
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * Absolute integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	/**
	 * Sanitize an email.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	function sanitize_email( $value ) {
		return filter_var( (string) $value, FILTER_SANITIZE_EMAIL );
	}
}

if ( ! function_exists( 'sanitize_user' ) ) {
	/**
	 * Sanitize a username.
	 *
	 * Mirrors core's strict mode: only ASCII letters, digits, `_`, `.`, `-`,
	 * `@` and space survive, and the result is trimmed. The `$strict` flag is
	 * accepted because the plugin passes it, but the character set is the same
	 * either way for the values these tests use.
	 *
	 * @param string $value  Value.
	 * @param bool   $strict Strict mode.
	 * @return string
	 */
	function sanitize_user( $value, $strict = false ) {
		$value = preg_replace( '/[^a-zA-Z0-9._@ -]/', '', (string) $value );
		return trim( (string) $value );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Strip tags.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	function wp_strip_all_tags( $value ) {
		return strip_tags( (string) $value );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Parse a URL.
	 *
	 * @param string $url       URL.
	 * @param int    $component Component.
	 * @return mixed
	 */
	function wp_parse_url( $url, $component = -1 ) {
		return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Escape HTML text.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	function esc_html( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * Escape HTML attribute value.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	function esc_attr( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Translate and escape.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	function esc_html__( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	/**
	 * Translate and escape, echo.
	 *
	 * @param string $value Value.
	 * @return void
	 */
	function esc_html_e( $value ) {
		echo htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	/**
	 * Format number per locale stub.
	 *
	 * @param float|int $number   Number to format.
	 * @param int       $decimals Decimals.
	 * @return string
	 */
	function number_format_i18n( $number, $decimals = 0 ) {
		return number_format( (float) $number, (int) $decimals, '.', ',' );
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Dispatch registered callbacks, like WordPress `do_action()`.
	 *
	 * The stub used to be a no-op, which forced production code into a dual
	 * dispatch path (direct call plus hook). Dispatching for real keeps the
	 * unit tests aligned with the single hook path used in production.
	 *
	 * @param string $hook Hook name.
	 * @param mixed  ...$args Arguments.
	 * @return void
	 */
	function do_action( $hook, ...$args ) {
		$by_priority = $GLOBALS['penalis_test_actions'][ $hook ] ?? array();

		if ( array() === $by_priority ) {
			return;
		}

		ksort( $by_priority );

		foreach ( $by_priority as $callbacks ) {
			foreach ( $callbacks as $entry ) {
				if ( is_array( $entry ) && isset( $entry['callback'] ) ) {
					$callback     = $entry['callback'];
					$accepted_args = isset( $entry['accepted_args'] ) ? (int) $entry['accepted_args'] : 1;
				} else {
					$callback      = $entry;
					$accepted_args = 1;
				}

				call_user_func_array( $callback, array_slice( $args, 0, max( 0, $accepted_args ) ) );
			}
		}
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Register a hook callback in the test registry.
	 *
	 * @param string   $hook          Hook.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Accepted arguments.
	 * @return void
	 */
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['penalis_test_actions'][ $hook ][ (int) $priority ][] = array(
			'callback'      => $callback,
			'accepted_args' => (int) $accepted_args,
		);
	}
}

if ( ! function_exists( 'remove_action' ) ) {
	/**
	 * Remove a hook callback from the test registry.
	 *
	 * @param string   $hook     Hook.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @return bool
	 */
	function remove_action( $hook, $callback, $priority = 10 ) {
		if ( empty( $GLOBALS['penalis_test_actions'][ $hook ][ $priority ] ) ) {
			return false;
		}

		foreach ( $GLOBALS['penalis_test_actions'][ $hook ][ $priority ] as $index => $registered ) {
			$registered_callback = is_array( $registered ) && isset( $registered['callback'] ) ? $registered['callback'] : $registered;

			if ( $registered_callback === $callback ) {
				unset( $GLOBALS['penalis_test_actions'][ $hook ][ $priority ][ $index ] );
				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'wp_password_change_notification' ) ) {
	/**
	 * Record the core admin password-change notification.
	 *
	 * @param WP_User $user User.
	 * @return void
	 */
	function wp_password_change_notification( $user ) {
		$GLOBALS['penalis_test_password_notifications'][] = (int) $user->ID;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Register a filter callback for the test run.
	 *
	 * Callbacks are stored per hook and priority so a test can prove a filter
	 * is wired to the hook the plugin actually uses. Nothing is registered by
	 * default, which keeps `apply_filters()` a passthrough everywhere else.
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Accepted arguments.
	 * @return bool
	 */
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		if ( ! isset( $GLOBALS['penalis_test_filters'] ) ) {
			$GLOBALS['penalis_test_filters'] = array();
		}

		$GLOBALS['penalis_test_filters'][ $hook ][ (int) $priority ][] = array( $callback, (int) $accepted_args );

		return true;
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	/**
	 * Remove a previously registered test filter callback.
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @return bool
	 */
	function remove_filter( $hook, $callback, $priority = 10 ) {
		$priority = (int) $priority;
		if ( empty( $GLOBALS['penalis_test_filters'][ $hook ][ $priority ] ) ) {
			return false;
		}

		$removed = false;
		$kept    = array();
		foreach ( $GLOBALS['penalis_test_filters'][ $hook ][ $priority ] as $entry ) {
			if ( ! $removed && $entry[0] === $callback ) {
				$removed = true;
				continue;
			}
			$kept[] = $entry;
		}

		if ( empty( $kept ) ) {
			unset( $GLOBALS['penalis_test_filters'][ $hook ][ $priority ] );
			if ( empty( $GLOBALS['penalis_test_filters'][ $hook ] ) ) {
				unset( $GLOBALS['penalis_test_filters'][ $hook ] );
			}
		} else {
			$GLOBALS['penalis_test_filters'][ $hook ][ $priority ] = $kept;
		}

		return $removed;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Apply the callbacks registered for a hook.
	 *
	 * With no callbacks registered the value is returned unchanged, so this
	 * behaves exactly like a passthrough for every hook a test does not touch.
	 *
	 * @param string $hook    Hook.
	 * @param mixed  $value   Value.
	 * @param mixed  ...$args Extra arguments for callbacks that accept them.
	 * @return mixed
	 */
	function apply_filters( $hook, $value, ...$args ) {
		$by_priority = $GLOBALS['penalis_test_filters'][ $hook ] ?? array();

		if ( array() === $by_priority ) {
			return $value;
		}

		ksort( $by_priority );

		foreach ( $by_priority as $callbacks ) {
			foreach ( $callbacks as $entry ) {
				$value = call_user_func_array(
					$entry[0],
					array_merge( array( $value ), array_slice( $args, 0, max( 0, $entry[1] - 1 ) ) )
				);
			}
		}

		return $value;
	}
}

if ( ! function_exists( 'get_password_reset_key' ) ) {
	/**
	 * Password reset key double.
	 *
	 * Records the user it was issued for so a test can prove the reset request
	 * resolved the right account, and returns a fixed key.
	 *
	 * @param WP_User $user User.
	 * @return string|WP_Error
	 */
	function get_password_reset_key( $user ) {
		$GLOBALS['penalis_test_reset_key_users'][] = (int) $user->ID;

		return 'test-reset-key';
	}
}

if ( ! function_exists( 'check_password_reset_key' ) ) {
	/**
	 * Password reset key check double.
	 *
	 * @param string $key   Key.
	 * @param string $login Login.
	 * @return WP_User|WP_Error
	 */
	function check_password_reset_key( $key, $login ) {
		$user = get_user_by( 'login', $login );

		if ( ! $user || 'test-reset-key' !== $key ) {
			return new WP_Error( 'invalid_key', 'Invalid key.' );
		}

		return $user;
	}
}

if ( ! function_exists( 'reset_password' ) ) {
	/**
	 * Password reset double.
	 *
	 * @param WP_User $user     User.
	 * @param string  $password New password.
	 * @return void
	 */
	function reset_password( $user, $password ) {
		$user->user_pass = 'hash:' . $password;
		$GLOBALS['penalis_test_password_resets'][] = (int) $user->ID;
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * URL escaping stub.
	 *
	 * The real function normalises and escapes the URL. Tests only care that
	 * the module script tag keeps the enqueued source, so it is passed through.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url( $url ) {
		return $url;
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * Raw URL sanitization stub.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url_raw( $url ) {
		return (string) $url;
	}
}

if ( ! function_exists( 'count_user_posts' ) ) {
	/**
	 * Count published posts authored by a user stub.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $post_type Post type.
	 * @return string
	 */
	function count_user_posts( $user_id, $post_type = 'post' ) {
		$count = 0;
		if ( isset( $GLOBALS['penalis_test_posts'] ) && is_array( $GLOBALS['penalis_test_posts'] ) ) {
			foreach ( $GLOBALS['penalis_test_posts'] as $post ) {
				if (
					(int) $post->post_author === (int) $user_id &&
					(string) $post->post_type === (string) $post_type &&
					'publish' === (string) $post->post_status
				) {
					$count++;
				}
			}
		}

		return (string) $count;
	}
}

if ( ! function_exists( 'wp_salt' ) ) {
	/**
	 * Deterministic salt double for HMAC tests.
	 *
	 * @param string $scheme Salt scheme.
	 * @return string
	 */
	function wp_salt( $scheme = 'auth' ) {
		return 'penalis-test-salt-' . (string) $scheme;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Option stub.
	 *
	 * @param string $name    Name.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	function get_option( $name, $default = false ) {
		return isset( $GLOBALS['penalis_test_options'][ $name ] ) ? $GLOBALS['penalis_test_options'][ $name ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Update option stub.
	 *
	 * @param string $name  Name.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	function update_option( $name, $value ) {
		$GLOBALS['penalis_test_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'wp_timezone' ) ) {
	/**
	 * Site timezone stub mirroring WordPress 5.3+ behaviour.
	 *
	 * Prefers the IANA `timezone_string` option and falls back to the fixed
	 * `gmt_offset` option, exactly like core.
	 *
	 * @return DateTimeZone
	 */
	function wp_timezone() {
		$timezone_string = (string) get_option( 'timezone_string' );

		if ( '' !== $timezone_string ) {
			try {
				return new DateTimeZone( $timezone_string );
			} catch ( Exception $exception ) {
				unset( $exception );
			}
		}

		$offset   = (float) get_option( 'gmt_offset', 0 );
		$seconds  = (int) round( $offset * HOUR_IN_SECONDS );
		$sign     = $seconds < 0 ? '-' : '+';
		$absolute = abs( $seconds );
		$hours    = intdiv( $absolute, HOUR_IN_SECONDS );
		$minutes  = intdiv( $absolute % HOUR_IN_SECONDS, MINUTE_IN_SECONDS );

		return new DateTimeZone( sprintf( '%s%02d:%02d', $sign, $hours, $minutes ) );
	}
}

if ( ! function_exists( 'wp_date' ) ) {
	/**
	 * Date formatting stub mirroring WordPress core.
	 *
	 * Formats a Unix timestamp in the supplied timezone (site timezone when
	 * omitted). Locale-aware month names are not simulated; the tests assert
	 * numeric formats so the output stays deterministic.
	 *
	 * @param string            $format   Date format.
	 * @param int|null          $timestamp Unix timestamp.
	 * @param DateTimeZone|null $timezone  Timezone.
	 * @return string|false
	 */
	function wp_date( $format, $timestamp = null, $timezone = null ) {
		$timestamp = null === $timestamp ? time() : (int) $timestamp;
		$timezone  = null === $timezone ? wp_timezone() : $timezone;
		$date      = new DateTimeImmutable( '@' . $timestamp );

		return $date->setTimezone( $timezone )->format( $format );
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * Delete option stub.
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	function delete_option( $name ) {
		unset( $GLOBALS['penalis_test_options'][ $name ] );
		return true;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * Set transient stub.
	 *
	 * @param string $name  Name.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	function set_transient( $name, $value ) {
		$GLOBALS['penalis_test_transients'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * Get transient stub.
	 *
	 * @param string $name Name.
	 * @return mixed
	 */
	function get_transient( $name ) {
		return isset( $GLOBALS['penalis_test_transients'][ $name ] ) ? $GLOBALS['penalis_test_transients'][ $name ] : false;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	/**
	 * Delete transient stub.
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	function delete_transient( $name ) {
		unset( $GLOBALS['penalis_test_transients'][ $name ] );
		return true;
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * Minimal post stub used by the editorial workflow tests.
	 */
	class WP_Post {
		/** @var int */
		public $ID = 0;
		/** @var string */
		public $post_type = 'post';
		/** @var int */
		public $post_author = 0;
		/** @var string */
		public $post_status = 'draft';
		/** @var string */
		public $post_title = '';
		/** @var string */
		public $post_content = '';
		/** @var string */
		public $post_excerpt = '';
		/** @var string */
		public $post_date_gmt = '2026-01-01 00:00:00';
		/** @var string */
		public $post_modified_gmt = '2026-01-01 00:00:00';
		/** @var string */
		public $post_mime_type = '';
		/** @var array<string,mixed> */
		public $meta = array();
	}
}

if ( ! function_exists( 'get_post' ) ) {
	/**
	 * Post lookup double backed by a test registry.
	 *
	 * @param int $post_id Post ID.
	 * @return WP_Post|null
	 */
	function get_post( $post_id = null ) {
		if ( null === $post_id ) {
			return isset( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof WP_Post
				? $GLOBALS['post']
				: null;
		}

		return isset( $GLOBALS['penalis_test_posts'][ (int) $post_id ] )
			? $GLOBALS['penalis_test_posts'][ (int) $post_id ]
			: null;
	}
}

if ( ! function_exists( 'get_the_category' ) ) {
	/**
	 * Post categories stub.
	 *
	 * @param int|false $post_id Post ID.
	 * @return array<int,WP_Term>
	 */
	function get_the_category( $post_id = false ) {
		$id = false === $post_id ? get_the_ID() : (int) $post_id;
		return isset( $GLOBALS['penalis_test_post_categories'][ $id ] ) && is_array( $GLOBALS['penalis_test_post_categories'][ $id ] )
			? $GLOBALS['penalis_test_post_categories'][ $id ]
			: array();
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	/**
	 * Read post meta.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Key.
	 * @param bool   $single  Single.
	 * @return mixed
	 */
	function get_post_meta( $post_id, $key = '', $single = false ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return $single ? '' : array();
		}

		if ( '' === $key ) {
			return $post->meta;
		}

		if ( ! isset( $post->meta[ $key ] ) ) {
			return $single ? '' : array();
		}

		$values = (array) $post->meta[ $key ];

		return $single ? reset( $values ) : $values;
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	/**
	 * Write post meta.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Key.
	 * @param mixed  $value   Value.
	 * @return bool
	 */
	function update_post_meta( $post_id, $key, $value ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return false;
		}

		$post->meta[ $key ] = array( $value );

		return true;
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	/**
	 * Delete post meta.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Key.
	 * @return bool
	 */
	function delete_post_meta( $post_id, $key ) {
		$post = get_post( $post_id );

		if ( ! $post || ! isset( $post->meta[ $key ] ) ) {
			return false;
		}

		unset( $post->meta[ $key ] );

		return true;
	}
}

if ( ! function_exists( 'wp_update_post' ) ) {
	/**
	 * Update a post double.
	 *
	 * @param array $data  Post data.
	 * @param bool  $error Whether to return a WP_Error.
	 * @return int|WP_Error
	 */
	function wp_update_post( $data, $error = false ) {
		if ( ! empty( $GLOBALS['penalis_test_post_update_fails'] ) ) {
			return new WP_Error( 'penalis_test_update_failed', 'Update failed.' );
		}

		$post = get_post( isset( $data['ID'] ) ? (int) $data['ID'] : 0 );

		if ( ! $post ) {
			return new WP_Error( 'penalis_test_missing_post', 'Post not found.' );
		}

		foreach ( $data as $key => $value ) {
			if ( 'ID' !== $key ) {
				$post->$key = $value;
			}
		}

		$GLOBALS['penalis_test_post_updates'][] = $data;

		return (int) $post->ID;
	}
}

if ( ! function_exists( 'wp_insert_post' ) ) {
	/**
	 * Insert a post double.
	 *
	 * @param array $data  Post data.
	 * @param bool  $error Whether to return a WP_Error.
	 * @return int|WP_Error
	 */
	function wp_insert_post( $data, $error = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub signature.
		$next_id = 10000;
		if ( isset( $GLOBALS['penalis_test_posts'] ) && is_array( $GLOBALS['penalis_test_posts'] ) && ! empty( $GLOBALS['penalis_test_posts'] ) ) {
			$next_id = max( array_map( 'intval', array_keys( $GLOBALS['penalis_test_posts'] ) ) ) + 1;
		}

		$post = new WP_Post(
			$next_id,
			isset( $data['post_author'] ) ? (int) $data['post_author'] : 0,
			isset( $data['post_status'] ) ? (string) $data['post_status'] : 'draft',
			isset( $data['post_title'] ) ? (string) $data['post_title'] : ''
		);
		$post->post_content = isset( $data['post_content'] ) ? (string) $data['post_content'] : '';
		$post->post_type    = isset( $data['post_type'] ) ? (string) $data['post_type'] : 'post';
		$post->post_name    = isset( $data['post_name'] ) ? (string) $data['post_name'] : '';

		if ( isset( $data['meta_input'] ) && is_array( $data['meta_input'] ) ) {
			foreach ( $data['meta_input'] as $meta_key => $meta_val ) {
				$post->meta[ (string) $meta_key ] = $meta_val;
			}
		}

		$GLOBALS['penalis_test_posts'][ $next_id ] = $post;

		return $next_id;
	}
}

if ( ! function_exists( 'wp_untrash_post' ) ) {
	/**
	 * Restore a trashed post double to its stored pre-trash status.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	function wp_untrash_post( $post_id ) {
		$post = get_post( (int) $post_id );

		if ( ! $post ) {
			return false;
		}

		$status = get_post_meta( (int) $post_id, '_wp_trash_meta_status', true );
		$post->post_status = '' !== $status ? (string) $status : 'publish';
		delete_post_meta( (int) $post_id, '_wp_trash_meta_status' );

		return true;
	}
}

if ( ! class_exists( 'WP_Comment' ) ) {
	/**
	 * Minimal comment stub for avatar identifier resolution.
	 */
	class WP_Comment {
		/** @var int */
		public $comment_ID = 0;
		/** @var int */
		public $user_id = 0;
	}
}

if ( ! function_exists( 'is_email' ) ) {
	/**
	 * Email check stub.
	 *
	 * @param mixed $email Email.
	 * @return bool
	 */
	function is_email( $email ) {
		return is_string( $email ) && false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
	}
}

if ( ! function_exists( 'get_user_by' ) ) {
	/**
	 * User lookup stub backed by the test user registry.
	 *
	 * @param string $field Field.
	 * @param mixed  $value Value.
	 * @return WP_User|false
	 */
	function get_user_by( $field, $value ) {
		$users = isset( $GLOBALS['penalis_test_users'] ) ? $GLOBALS['penalis_test_users'] : array();

		foreach ( $users as $user ) {
			if ( 'id' === $field && (int) $user->ID === (int) $value ) {
				return $user;
			}

			if ( 'email' === $field && $user->user_email === $value ) {
				return $user;
			}

			if ( 'login' === $field && $user->user_login === $value ) {
				return $user;
			}

			if ( 'slug' === $field && $user->user_nicename === $value ) {
				return $user;
			}
		}

		return false;
	}
}

$GLOBALS['penalis_test_options']    = array();
$GLOBALS['penalis_test_transients'] = array();

if ( ! function_exists( 'email_exists' ) ) {
	/**
	 * Email lookup stub.
	 *
	 * @param string $email Email.
	 * @return int|false
	 */
	function email_exists( $email ) {
		$user = get_user_by( 'email', $email );

		return $user ? (int) $user->ID : false;
	}
}

if ( ! function_exists( 'username_exists' ) ) {
	/**
	 * Login lookup stub.
	 *
	 * @param string $login Login.
	 * @return int|false
	 */
	function username_exists( $login ) {
		$user = get_user_by( 'login', $login );

		return $user ? (int) $user->ID : false;
	}
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	/**
	 * Current user double.
	 *
	 * Returns a real WP_User instance so access-control checks that inspect
	 * `roles` behave the same way they would against WordPress.
	 *
	 * @return WP_User
	 */
	function wp_get_current_user() {
		$user_id = get_current_user_id();

		if ( $user_id > 0 ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				return $user;
			}

			return new WP_User( $user_id, array( 'subscriber' ) );
		}

		return new WP_User( 0, array() );
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Current-user capability double.
	 *
	 * Meta capabilities are delegated to the access-control mapping so a test
	 * exercises the same filter chain WordPress would apply.
	 *
	 * @param string $cap  Capability.
	 * @param mixed  ...$args Capability arguments.
	 * @return bool
	 */
	function current_user_can( $cap, ...$args ) {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return false;
		}

		$caps = user_can( $user_id, $cap ) ? array( $cap ) : array( 'do_not_allow' );

		if ( class_exists( 'Penalis_Access_Control' ) ) {
			$caps = Penalis_Access_Control::map_meta_cap( $caps, $cap, $user_id, $args );
		}

		return ! in_array( 'do_not_allow', $caps, true );
	}
}

if ( ! function_exists( 'rest_authorization_required_code' ) ) {
	/**
	 * REST authorization status code double.
	 *
	 * @return int
	 */
	function rest_authorization_required_code() {
		return is_user_logged_in() ? 403 : 401;
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Minimal REST request double.
	 */
	class WP_REST_Request {
		/** @var string */
		private $route = '';
		/** @var string */
		private $method = 'GET';
		/** @var array<string,mixed> */
		private $params = array();
		/** @var array<string,mixed> */
		private $file_params = array();

		/**
		 * Constructor.
		 *
		 * @param string               $method HTTP method.
		 * @param string               $route  Route.
		 * @param array<string,mixed>  $params Parameters.
		 */
		public function __construct( $method = 'GET', $route = '', $params = array() ) {
			$this->method = strtoupper( (string) $method );
			$this->route  = (string) $route;
			$this->params = (array) $params;
		}

		/**
		 * Uploaded files map.
		 *
		 * @return array<string,mixed>
		 */
		public function get_file_params() {
			return $this->file_params;
		}

		/**
		 * Set uploaded files map for tests.
		 *
		 * @param array<string,mixed> $files Files array.
		 * @return void
		 */
		public function set_file_params( $files ) {
			$this->file_params = (array) $files;
		}

		/**
		 * Request route.
		 *
		 * @return string
		 */
		public function get_route() {
			return $this->route;
		}

		/**
		 * Request method.
		 *
		 * @return string
		 */
		public function get_method() {
			return $this->method;
		}

		/**
		 * Read a parameter.
		 *
		 * @param string $key Parameter name.
		 * @return mixed
		 */
		public function get_param( $key ) {
			return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null;
		}

		/**
		 * Write a parameter.
		 *
		 * @param string $key   Parameter name.
		 * @param mixed  $value Value.
		 * @return void
		 */
		public function set_param( $key, $value ) {
			$this->params[ $key ] = $value;
		}
	}
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
	/**
	 * REST server method constants.
	 */
	class WP_REST_Server {
		public const READABLE  = 'GET';
		public const CREATABLE = 'POST';
		public const EDITABLE  = 'POST, PUT, PATCH';
		public const DELETABLE = 'DELETE';
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Minimal REST response double.
	 */
	class WP_REST_Response {
		/** @var mixed */
		private $data;
		/** @var int */
		private $status;
		/** @var array<string,string> */
		private $headers = array();

		/**
		 * Constructor.
		 *
		 * @param mixed $data   Response data.
		 * @param int   $status HTTP status code.
		 */
		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = (int) $status;
		}

		/**
		 * Response data.
		 *
		 * @return mixed
		 */
		public function get_data() {
			return $this->data;
		}

		/**
		 * HTTP status code.
		 *
		 * @return int
		 */
		public function get_status() {
			return $this->status;
		}

		/**
		 * Set a response header.
		 *
		 * @param string $key   Header name.
		 * @param string $value Header value.
		 * @return void
		 */
		public function header( $key, $value ) {
			$this->headers[ (string) $key ] = (string) $value;
		}
	}
}

if ( ! function_exists( 'rest_ensure_response' ) ) {
	/**
	 * Wrap a value in WP_REST_Response unless it is already a response or WP_Error.
	 *
	 * @param mixed $response Response data.
	 * @return WP_REST_Response|WP_Error
	 */
	function rest_ensure_response( $response ) {
		if ( is_wp_error( $response ) || $response instanceof WP_REST_Response ) {
			return $response;
		}

		return new WP_REST_Response( $response );
	}
}

if ( ! function_exists( 'wp_get_post_terms' ) ) {
	/**
	 * Post terms stub.
	 *
	 * @param int   $post_id    Post ID.
	 * @param array $taxonomies Taxonomies.
	 * @param array $args       Arguments.
	 * @return array
	 */
	function wp_get_post_terms( $post_id, $taxonomies = array(), $args = array() ) {
		$post_id    = (int) $post_id;
		$taxonomies = (array) $taxonomies;
		$stored     = isset( $GLOBALS['penalis_test_post_terms'][ $post_id ] ) && is_array( $GLOBALS['penalis_test_post_terms'][ $post_id ] )
			? $GLOBALS['penalis_test_post_terms'][ $post_id ]
			: array();
		$results    = array();

		foreach ( $taxonomies as $taxonomy ) {
			if ( isset( $stored[ $taxonomy ] ) && is_array( $stored[ $taxonomy ] ) ) {
				foreach ( $stored[ $taxonomy ] as $term ) {
					$results[] = $term;
				}
			}
		}

		return $results;
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	/**
	 * Permalink stub.
	 *
	 * @param WP_Post|int $post Post or ID.
	 * @return string
	 */
	function get_permalink( $post ) {
		$id = $post instanceof WP_Post ? (int) $post->ID : (int) $post;
		return 'https://example.com/?p=' . $id;
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	/**
	 * Whether the stub request is a wp-admin screen.
	 *
	 * @return bool
	 */
	function is_admin() {
		return ! empty( $GLOBALS['penalis_test_is_admin'] );
	}
}

if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
	/**
	 * Thumbnail ID stub.
	 *
	 * @param WP_Post|int $post Post or ID.
	 * @return int
	 */
	function get_post_thumbnail_id( $post ) {
		$id = $post instanceof WP_Post ? (int) $post->ID : (int) $post;
		if ( isset( $GLOBALS['penalis_test_post_meta'][ $id ]['_thumbnail_id'] ) ) {
			return (int) $GLOBALS['penalis_test_post_meta'][ $id ]['_thumbnail_id'];
		}
		return 0;
	}
}

if ( ! function_exists( 'get_the_post_thumbnail_url' ) ) {
	/**
	 * Thumbnail URL stub.
	 *
	 * @param WP_Post|int $post Post or ID.
	 * @param string      $size Image size.
	 * @return string
	 */
	function get_the_post_thumbnail_url( $post, $size = 'thumbnail' ) {
		return '';
	}
}

if ( ! function_exists( 'wp_trim_words' ) ) {
	/**
	 * Trim words stub.
	 *
	 * @param string $text      Text.
	 * @param int    $num_words Max words.
	 * @return string
	 */
	function wp_trim_words( $text, $num_words = 55 ) {
		$words = preg_split( '/\s+/', trim( (string) $text ) );
		if ( ! is_array( $words ) || count( $words ) <= $num_words ) {
			return trim( (string) $text );
		}
		return implode( ' ', array_slice( $words, 0, $num_words ) ) . '…';
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	/**
	 * Sanitize textarea stub.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	function sanitize_textarea_field( $value ) {
		return trim( strip_tags( (string) $value ) );
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	/**
	 * Post-content sanitiser stub.
	 *
	 * The tests only need the value to survive unchanged; HTML filtering is
	 * WordPress core behaviour and is not under test here.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	function wp_kses_post( $value ) {
		return (string) $value;
	}
}

if ( ! function_exists( 'wp_update_user' ) ) {
	/**
	 * Update user stub.
	 *
	 * @param array<string,mixed> $userdata User data.
	 * @return int|WP_Error
	 */
	function wp_update_user( $userdata ) {
		$user_id = isset( $userdata['ID'] ) ? (int) $userdata['ID'] : 0;
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'invalid_user_id', 'Invalid user ID.' );
		}
		if ( isset( $userdata['user_email'] ) ) {
			$user->user_email = (string) $userdata['user_email'];
		}
		if ( isset( $userdata['display_name'] ) ) {
			$user->display_name = (string) $userdata['display_name'];
		}
		if ( isset( $userdata['first_name'] ) ) {
			update_user_meta( $user_id, 'first_name', (string) $userdata['first_name'] );
		}
		if ( isset( $userdata['last_name'] ) ) {
			update_user_meta( $user_id, 'last_name', (string) $userdata['last_name'] );
		}
		if ( isset( $userdata['description'] ) ) {
			update_user_meta( $user_id, 'description', (string) $userdata['description'] );
		}
		return $user_id;
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	/**
	 * Minimal WP_Query stub.
	 */
	class WP_Query {
		/** @var array<string,mixed> */
		public $query_vars = array();
		/** @var int */
		public $found_posts = 0;
		/** @var array */
		public $posts = array();
		/** @var int */
		public $max_num_pages = 0;

		/**
		 * Constructor.
		 *
		 * @param array<string,mixed> $args Query arguments.
		 */
		public function __construct( $args = array() ) {
			$this->query_vars = is_array( $args ) ? $args : array();

			$all_posts = isset( $GLOBALS['penalis_test_posts'] ) && is_array( $GLOBALS['penalis_test_posts'] )
				? $GLOBALS['penalis_test_posts']
				: array();

			if ( empty( $all_posts ) ) {
				return;
			}

			$post_type = isset( $args['post_type'] ) ? (string) $args['post_type'] : 'post';
			$author    = isset( $args['author'] ) ? (int) $args['author'] : 0;
			$statuses  = isset( $args['post_status'] ) ? (array) $args['post_status'] : array();
			$matched   = array();

			foreach ( $all_posts as $post ) {
				if ( ! $post instanceof WP_Post ) {
					continue;
				}

				if ( '' !== $post_type && $post->post_type !== $post_type ) {
					continue;
				}

				if ( $author > 0 && (int) $post->post_author !== $author ) {
					continue;
				}

				if ( ! empty( $statuses ) && ! in_array( (string) $post->post_status, $statuses, true ) ) {
					continue;
				}

				if ( isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) && ! self::matches_meta_query( $post, $args['meta_query'] ) ) {
					continue;
				}

				$matched[] = $post;
			}

			$effective = ! empty( $args['penalis_effective_order'] );
			$orderby   = isset( $args['orderby'] ) && is_string( $args['orderby'] ) ? $args['orderby'] : '';
			$direction = isset( $args['order'] ) && 'ASC' === strtoupper( (string) $args['order'] ) ? 1 : -1;

			if ( $effective ) {
				usort(
					$matched,
					static function ( $a, $b ) {
						$a_key = ( 'publish' === (string) $a->post_status && '0000-00-00 00:00:00' !== (string) $a->post_date_gmt )
							? (string) $a->post_date_gmt
							: (string) $a->post_modified_gmt;
						$b_key = ( 'publish' === (string) $b->post_status && '0000-00-00 00:00:00' !== (string) $b->post_date_gmt )
							? (string) $b->post_date_gmt
							: (string) $b->post_modified_gmt;

						if ( $a_key !== $b_key ) {
							return strcmp( $b_key, $a_key );
						}

						return (int) $b->ID <=> (int) $a->ID;
					}
				);
			} elseif ( 'date' === $orderby || 'modified' === $orderby ) {
				$field = 'date' === $orderby ? 'post_date_gmt' : 'post_modified_gmt';
				usort(
					$matched,
					static function ( $a, $b ) use ( $field, $direction ) {
						$cmp = strcmp( (string) $a->{$field}, (string) $b->{$field} );
						return 0 === $cmp ? 0 : ( $cmp * $direction );
					}
				);
			}

			$this->found_posts = count( $matched );

			$per_page = isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : 10;
			$paged    = isset( $args['paged'] ) ? max( 1, (int) $args['paged'] ) : 1;

			if ( $per_page > 0 ) {
				$this->max_num_pages = (int) ceil( $this->found_posts / $per_page );
				$matched             = array_slice( $matched, ( $paged - 1 ) * $per_page, $per_page );
			} else {
				$this->max_num_pages = $this->found_posts > 0 ? 1 : 0;
			}

			if ( isset( $args['fields'] ) && 'ids' === $args['fields'] ) {
				$this->posts = array_map(
					static function ( $post ) {
						return (int) $post->ID;
					},
					$matched
				);
			} else {
				$this->posts = array_values( $matched );
			}
		}

		/**
		 * Evaluate a simple meta_query against a WP_Post double.
		 *
		 * @param WP_Post             $post       Post object.
		 * @param array<int|string,mixed> $meta_query Meta query array.
		 * @return bool
		 */
		private static function matches_meta_query( $post, $meta_query ) {
			$relation = isset( $meta_query['relation'] ) && 'OR' === strtoupper( (string) $meta_query['relation'] ) ? 'OR' : 'AND';
			$clauses  = array();

			foreach ( $meta_query as $key => $clause ) {
				if ( 'relation' === $key || ! is_array( $clause ) || ! isset( $clause['key'] ) ) {
					continue;
				}
				$clauses[] = $clause;
			}

			if ( empty( $clauses ) ) {
				return true;
			}

			foreach ( $clauses as $clause ) {
				$meta_key = (string) $clause['key'];
				$compare  = isset( $clause['compare'] ) ? strtoupper( (string) $clause['compare'] ) : '=';
				$exists   = isset( $post->meta[ $meta_key ] ) && ! empty( $post->meta[ $meta_key ] );
				$actual   = $exists ? (string) reset( $post->meta[ $meta_key ] ) : '';

				if ( 'NOT EXISTS' === $compare ) {
					$clause_match = ! $exists;
				} elseif ( 'EXISTS' === $compare ) {
					$clause_match = $exists;
				} else {
					$expected     = isset( $clause['value'] ) ? (string) $clause['value'] : '';
					$clause_match = $exists && $actual === $expected;
				}

				if ( 'OR' === $relation && $clause_match ) {
					return true;
				}

				if ( 'AND' === $relation && ! $clause_match ) {
					return false;
				}
			}

			return 'AND' === $relation;
		}
	}
}

if ( ! function_exists( 'pvc_get_post_views' ) ) {
	/**
	 * Post Views Counter pvc_get_post_views() stub.
	 *
	 * Mirrors the real plugin signature and behaviour: accepts a single post ID
	 * or an array of post IDs and returns the total view count across them.
	 *
	 * @param int|int[]           $post_id Post ID or array of post IDs.
	 * @param string              $period  Count period.
	 * @param array<string,mixed> $args    Optional arguments.
	 * @return int
	 */
	function pvc_get_post_views( $post_id = 0, $period = 'total', $args = array() ) {
		unset( $period, $args );

		$views_map = isset( $GLOBALS['penalis_test_post_views'] ) && is_array( $GLOBALS['penalis_test_post_views'] )
			? $GLOBALS['penalis_test_post_views']
			: array();

		if ( is_array( $post_id ) ) {
			$total   = 0;
			$seen_id = array();
			foreach ( $post_id as $id ) {
				$id = (int) $id;
				if ( $id <= 0 || isset( $seen_id[ $id ] ) ) {
					continue;
				}
				$seen_id[ $id ] = true;
				if ( isset( $views_map[ $id ] ) ) {
					$total += (int) $views_map[ $id ];
				}
			}
			return $total;
		}

		$id = (int) $post_id;

		return isset( $views_map[ $id ] ) ? (int) $views_map[ $id ] : 0;
	}
}

if ( ! function_exists( 'get_author_posts_url' ) ) {
	/**
	 * Author posts URL stub.
	 *
	 * @param int $author_id Author ID.
	 * @return string
	 */
	function get_author_posts_url( $author_id ) {
		return 'https://example.com/author/user' . (int) $author_id . '/';
	}
}

if ( ! function_exists( 'get_avatar_url' ) ) {
	/**
	 * Avatar URL stub.
	 *
	 * @param mixed $id_or_email User ID or email.
	 * @param array $args        Arguments.
	 * @return string
	 */
	function get_avatar_url( $id_or_email, $args = array() ) {
		unset( $id_or_email, $args );
		return 'https://example.com/avatar.jpg';
	}
}

if ( ! function_exists( 'wp_doing_ajax' ) ) {
	/**
	 * AJAX check stub.
	 *
	 * @return bool
	 */
	function wp_doing_ajax() {
		return ! empty( $GLOBALS['penalis_test_doing_ajax'] );
	}
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	/**
	 * Safe redirect stub.
	 *
	 * @param string $location Redirect target.
	 * @param int    $status   HTTP status code.
	 * @return bool
	 */
	function wp_safe_redirect( $location, $status = 302 ) {
		$GLOBALS['penalis_test_redirect'] = array(
			'location' => $location,
			'status'   => $status,
		);
		return true;
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	/**
	 * Strip trailing slashes.
	 *
	 * @param string $value Path or URL.
	 * @return string
	 */
	function untrailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' );
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	/**
	 * Uploads directory stub.
	 *
	 * @return array<string,mixed>
	 */
	function wp_upload_dir() {
		if ( isset( $GLOBALS['penalis_test_upload_dir'] ) && is_array( $GLOBALS['penalis_test_upload_dir'] ) ) {
			return $GLOBALS['penalis_test_upload_dir'];
		}

		$base = sys_get_temp_dir() . '/penalis-test-uploads';

		return array(
			'basedir' => $base,
			'baseurl' => 'https://example.com/wp-content/uploads',
			'error'   => false,
		);
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	/**
	 * Recursive mkdir stub.
	 *
	 * @param string $target Directory path.
	 * @return bool
	 */
	function wp_mkdir_p( $target ) {
		if ( is_dir( $target ) ) {
			return true;
		}

		return mkdir( $target, 0777, true );
	}
}

if ( ! function_exists( 'wp_delete_file' ) ) {
	/**
	 * Delete file stub.
	 *
	 * @param string $file File path.
	 * @return void
	 */
	function wp_delete_file( $file ) {
		if ( is_file( $file ) ) {
			unlink( $file );
		}
	}
}

if ( ! function_exists( 'wp_max_upload_size' ) ) {
	/**
	 * Max upload size stub.
	 *
	 * @return int
	 */
	function wp_max_upload_size() {
		return isset( $GLOBALS['penalis_test_max_upload_size'] )
			? (int) $GLOBALS['penalis_test_max_upload_size']
			: 10485760;
	}
}

if ( ! function_exists( 'wp_attachment_is_image' ) ) {
	/**
	 * Whether a stub attachment is an image.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	function wp_attachment_is_image( $attachment_id ) {
		$post = get_post( (int) $attachment_id );
		return $post && 'attachment' === $post->post_type && 0 === strpos( (string) $post->post_mime_type, 'image/' );
	}
}

if ( ! function_exists( 'wp_get_attachment_image_src' ) ) {
	/**
	 * Attachment image source stub.
	 *
	 * @param int          $attachment_id Attachment ID.
	 * @param string|int[] $size          Size.
	 * @return array<int,mixed>|false
	 */
	function wp_get_attachment_image_src( $attachment_id, $size = 'thumbnail' ) {
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return false;
		}

		$edge = is_array( $size ) && isset( $size[0] ) ? (int) $size[0] : 96;

		return array(
			wp_get_attachment_url( $attachment_id ),
			$edge,
			$edge,
			false,
		);
	}
}

if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	/**
	 * Attachment URL stub.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string|false
	 */
	function wp_get_attachment_url( $attachment_id ) {
		$post = get_post( (int) $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return false;
		}

		$attached_file = (string) get_post_meta( (int) $attachment_id, '_wp_attached_file', true );
		if ( '' !== $attached_file ) {
			return 'https://example.com/wp-content/uploads/' . ltrim( $attached_file, '/' );
		}

		return 'https://example.com/wp-content/uploads/attachment-' . (int) $attachment_id . '.jpg';
	}
}

if ( ! function_exists( 'wp_delete_attachment' ) ) {
	/**
	 * Attachment delete stub.
	 *
	 * @param int  $post_id      Attachment ID.
	 * @param bool $force_delete Force permanent delete.
	 * @return WP_Post|false
	 */
	function wp_delete_attachment( $post_id, $force_delete = false ) {
		unset( $force_delete );
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );

		if ( ! $post || 'attachment' !== $post->post_type ) {
			return false;
		}

		unset( $GLOBALS['penalis_test_posts'][ $post_id ] );
		$GLOBALS['penalis_test_deleted_attachments'][] = $post_id;

		return $post;
	}
}

if ( ! class_exists( 'Penalis_Test_Image_Editor' ) ) {
	/**
	 * Minimal WP_Image_Editor double for standalone avatar upload tests.
	 */
	class Penalis_Test_Image_Editor {
		/** @var string */
		private $source;
		/** @var int */
		public $quality = 82;
		/** @var array<int,array<string,mixed>> */
		public $resizes = array();

		/**
		 * Constructor.
		 *
		 * @param string $source Source image path.
		 */
		public function __construct( $source ) {
			$this->source = $source;
		}

		/**
		 * Record a resize call.
		 *
		 * @param int  $max_w Width.
		 * @param int  $max_h Height.
		 * @param bool $crop  Crop flag.
		 * @return true
		 */
		public function resize( $max_w, $max_h, $crop = false ) {
			$this->resizes[] = array(
				'width'  => (int) $max_w,
				'height' => (int) $max_h,
				'crop'   => (bool) $crop,
			);
			return true;
		}

		/**
		 * Set JPEG quality.
		 *
		 * @param int $quality Quality.
		 * @return true
		 */
		public function set_quality( $quality ) {
			$this->quality = (int) $quality;
			return true;
		}

		/**
		 * Persist re-encoded image bytes to destination.
		 *
		 * @param string $dest Destination path.
		 * @param string $mime Target MIME type.
		 * @return array<string,mixed>|WP_Error
		 */
		public function save( $dest, $mime = null ) {
			if ( ! empty( $GLOBALS['penalis_test_image_editor_fail_save'] ) ) {
				return new WP_Error( 'save_failed', 'Disk full.' );
			}

			$bytes = file_get_contents( $this->source );
			if ( false === $bytes || false === file_put_contents( $dest, $bytes ) ) {
				return new WP_Error( 'save_failed', 'Cannot write destination.' );
			}

			return array(
				'path'      => $dest,
				'file'      => basename( $dest ),
				'width'     => 500,
				'height'    => 500,
				'mime-type' => $mime ? $mime : 'image/jpeg',
			);
		}
	}
}

if ( ! function_exists( 'wp_get_image_editor' ) ) {
	/**
	 * Image editor factory stub.
	 *
	 * @param string $path Source image path.
	 * @return Penalis_Test_Image_Editor|WP_Error
	 */
	function wp_get_image_editor( $path ) {
		if ( ! empty( $GLOBALS['penalis_test_image_editor_error'] ) ) {
			return new WP_Error( 'image_no_editor', 'No editor available.' );
		}

		$editor                               = new Penalis_Test_Image_Editor( $path );
		$GLOBALS['penalis_test_last_editor']  = $editor;
		return $editor;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * JSON encode stub.
	 *
	 * @param mixed $data  Value to encode.
	 * @param int   $flags JSON flags.
	 * @return string|false
	 */
	function wp_json_encode( $data, $flags = 0 ) {
		return json_encode( $data, (int) $flags );
	}
}

if ( ! function_exists( 'shortcode_atts' ) ) {
	/**
	 * Merge user attributes with known defaults.
	 *
	 * @param array<string,mixed> $pairs Default attributes.
	 * @param array<string,mixed> $atts  User attributes.
	 * @return array<string,mixed>
	 */
	function shortcode_atts( $pairs, $atts ) {
		$atts = (array) $atts;
		$out  = array();
		foreach ( $pairs as $name => $default ) {
			$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default;
		}
		return $out;
	}
}

if ( ! function_exists( 'get_the_ID' ) ) {
	/**
	 * Current loop post ID stub.
	 *
	 * @return int
	 */
	function get_the_ID() {
		return isset( $GLOBALS['penalis_test_current_post_id'] ) ? (int) $GLOBALS['penalis_test_current_post_id'] : 0;
	}
}

if ( ! function_exists( 'is_singular' ) ) {
	/**
	 * Singular post check stub.
	 *
	 * @param string $post_types Post type.
	 * @return bool
	 */
	function is_singular( $post_types = '' ) {
		$current = isset( $GLOBALS['penalis_test_is_singular'] ) ? (string) $GLOBALS['penalis_test_is_singular'] : '';
		return '' === $post_types ? '' !== $current : $current === (string) $post_types;
	}
}

if ( ! function_exists( 'in_the_loop' ) ) {
	/**
	 * Loop state stub.
	 *
	 * @return bool
	 */
	function in_the_loop() {
		return ! empty( $GLOBALS['penalis_test_in_the_loop'] );
	}
}

if ( ! function_exists( 'is_main_query' ) ) {
	/**
	 * Main query check stub.
	 *
	 * @return bool
	 */
	function is_main_query() {
		return ! empty( $GLOBALS['penalis_test_is_main_query'] );
	}
}

if ( ! function_exists( 'is_feed' ) ) {
	/**
	 * Feed check stub.
	 *
	 * @return bool
	 */
	function is_feed() {
		return ! empty( $GLOBALS['penalis_test_is_feed'] );
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	/**
	 * Enqueue style stub.
	 *
	 * @param string $handle Handle.
	 * @return void
	 */
	function wp_enqueue_style( $handle ) {
		$GLOBALS['penalis_test_enqueued_styles'][] = (string) $handle;
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	/**
	 * Enqueue script stub.
	 *
	 * @param string $handle Handle.
	 * @return void
	 */
	function wp_enqueue_script( $handle ) {
		$GLOBALS['penalis_test_enqueued_scripts'][] = (string) $handle;
	}
}

if ( ! function_exists( 'has_shortcode' ) ) {
	/**
	 * Minimal shortcode presence check stub.
	 *
	 * @param string $content Content.
	 * @param string $tag     Shortcode tag.
	 * @return bool
	 */
	function has_shortcode( $content, $tag ) {
		return false !== strpos( (string) $content, '[' . (string) $tag );
	}
}

if ( ! function_exists( 'wp_localize_script' ) ) {
	/**
	 * Localize script stub.
	 *
	 * @param string              $handle      Handle.
	 * @param string              $object_name JS object name.
	 * @param array<string,mixed> $l10n        Data.
	 * @return bool
	 */
	function wp_localize_script( $handle, $object_name, $l10n ) {
		$GLOBALS['penalis_test_localized'][ (string) $handle ][ (string) $object_name ] = $l10n;
		return true;
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	/**
	 * Minimal REST URL stub.
	 *
	 * @param string $path Optional route path.
	 * @return string
	 */
	function rest_url( $path = '' ) {
		return 'https://example.com/wp-json/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'shortcode_exists' ) ) {
	/**
	 * Minimal shortcode existence stub.
	 *
	 * @param string $tag Shortcode tag.
	 * @return bool
	 */
	function shortcode_exists( $tag ) {
		return ! empty( $GLOBALS['penalis_test_shortcodes'][ (string) $tag ] );
	}
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
	/**
	 * Minimal nonce field stub.
	 *
	 * @param int|string $action  Action name.
	 * @param string     $name    Field name.
	 * @param bool       $referer Whether to include referer field.
	 * @param bool       $display Whether to echo the field.
	 * @return string
	 */
	function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) {
		unset( $referer );
		$html = '<input type="hidden" name="' . esc_attr( (string) $name ) . '" value="' . esc_attr( 'nonce_' . (string) $action ) . '" />';
		if ( $display ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		return $html;
	}
}
