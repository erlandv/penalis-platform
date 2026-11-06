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
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
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
	 * Find users by meta.
	 *
	 * @param array $args Args.
	 * @return int[]
	 */
	function get_users( $args ) {
		$key   = isset( $args['meta_key'] ) ? $args['meta_key'] : '';
		$value = isset( $args['meta_value'] ) ? $args['meta_value'] : '';
		$out   = array();

		foreach ( $GLOBALS['penalis_test_users'] as $user ) {
			if ( '' === $key || ( isset( $user->meta[ $key ] ) && in_array( $value, (array) $user->meta[ $key ], true ) ) ) {
				$out[] = $user->ID;
			}
		}

		return $out;
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * Query argument stub.
	 *
	 * @param array  $args Arguments.
	 * @param string $url  URL.
	 * @return string
	 */
	function add_query_arg( $args, $url = '' ) {
		return $url . '?' . http_build_query( $args );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	/**
	 * Home URL double.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	function home_url( $path = '' ) {
		return 'https://example.com' . $path;
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
	 * @return bool
	 */
	function wp_mail( $to, $subject, $message, $headers = array() ) {
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

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Action no-op.
	 *
	 * @return void
	 */
	function do_action() {}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Filter passthrough.
	 *
	 * @param string $hook  Hook.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	function apply_filters( $hook, $value ) {
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
	function get_post( $post_id ) {
		return isset( $GLOBALS['penalis_test_posts'][ (int) $post_id ] )
			? $GLOBALS['penalis_test_posts'][ (int) $post_id ]
			: null;
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
		return array();
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

if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
	/**
	 * Thumbnail ID stub.
	 *
	 * @param WP_Post|int $post Post or ID.
	 * @return int
	 */
	function get_post_thumbnail_id( $post ) {
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
