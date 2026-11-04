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
				'administrator'  => array( 'manage_options', 'edit_posts', 'publish_posts', 'list_users' ),
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
	 * @param int    $user_id User ID.
	 * @param string $cap     Capability.
	 * @return bool
	 */
	function user_can( $user_id, $cap ) {
		$user = get_userdata( $user_id );
		return $user && in_array( 'administrator', $user->roles, true );
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
	function wp_mail( $to, $subject, $message ) {
		$GLOBALS['penalis_test_mail'][] = array( $to, $subject, $message );
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
	 * Minimal post stub for avatar identifier resolution.
	 */
	class WP_Post {
		/** @var int */
		public $ID = 0;
		/** @var string */
		public $post_type = 'post';
		/** @var int */
		public $post_author = 0;
		/** @var string */
		public $post_mime_type = '';
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
