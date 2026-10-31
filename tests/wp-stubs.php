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
			$this->user_login   = 'user' . $id;
			$this->display_name = 'User ' . $id;
			$this->user_pass    = 'hash';
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
		if ( ! $user ) {
			return $single ? '' : array();
		}

		if ( ! isset( $user->meta[ $key ] ) ) {
			return $single ? '' : array();
		}

		return $single ? $user->meta[ $key ] : array( $user->meta[ $key ] );
	}
}

if ( ! function_exists( 'update_user_meta' ) ) {
	/**
	 * Write user meta.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Key.
	 * @param mixed  $value   Value.
	 * @return bool
	 */
	function update_user_meta( $user_id, $key, $value ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		$user->meta[ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_user_meta' ) ) {
	/**
	 * Delete user meta.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Key.
	 * @return bool
	 */
	function delete_user_meta( $user_id, $key ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		unset( $user->meta[ $key ] );
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
			if ( '' === $key || ( isset( $user->meta[ $key ] ) && $user->meta[ $key ] === $value ) ) {
				$out[] = $user->ID;
			}
		}

		return $out;
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
	 * @param string $value Value.
	 * @return string
	 */
	function sanitize_user( $value ) {
		return preg_replace( '/[^a-zA-Z0-9._-]/', '', (string) $value );
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
		}

		return false;
	}
}

$GLOBALS['penalis_test_options']    = array();
$GLOBALS['penalis_test_transients'] = array();
