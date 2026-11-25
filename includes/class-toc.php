<?php
/**
 * Built-in Table of Contents generator for published articles.
 *
 * Replaces the unmaintained `luckywp-table-of-contents` plugin natively inside
 * Penalis Platform: parses configured heading tags (`H2`–`H4`), injects clean
 * lowercase slug anchors (`#judul-bagian`), renders a collapsible editorial
 * Table of Contents box, respects per-post overrides (including legacy
 * `_lwptoc_settings` entries), and registers with Rank Math's TOC SEO check.
 *
 * Automatically yields while LuckyWP Table of Contents is still active so the
 * site never renders duplicate Table of Contents blocks during migration.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Table of Contents settings, content processor, renderer, and editor controls.
 */
final class Penalis_Toc {

	/**
	 * Option key storing the Table of Contents configuration array.
	 */
	public const OPTION_SETTINGS = 'penalis_toc_settings';

	/**
	 * Option key recording who last updated Table of Contents settings and when.
	 */
	public const OPTION_AUDIT = 'penalis_toc_audit';

	/**
	 * Post meta key for per-post disable override (`'1'` disabled, `'0'` enabled).
	 */
	public const META_DISABLED = '_penalis_toc_disabled';

	/**
	 * Legacy LuckyWP Table of Contents per-post settings meta key.
	 */
	public const LEGACY_META_KEY = '_lwptoc_settings';

	/**
	 * Classic editor meta box nonce action.
	 */
	public const META_BOX_NONCE = 'penalis_toc_meta_box';

	/**
	 * Stylesheet handle for the frontend TOC box.
	 */
	public const STYLE_HANDLE = 'penalis-toc';

	/**
	 * Script handle for the frontend TOC toggle & smooth scroll.
	 */
	public const SCRIPT_HANDLE = 'penalis-toc';

	/**
	 * Block editor sidebar panel script handle.
	 */
	public const EDITOR_SCRIPT_HANDLE = 'penalis-editor-toc';

	/**
	 * Default box title.
	 */
	public const DEFAULT_TITLE = 'Table of Contents';

	/**
	 * Default minimum heading count before auto-inserting TOC.
	 */
	public const DEFAULT_MIN_HEADINGS = 4;

	/**
	 * Allowed heading levels that can be included in TOC.
	 */
	public const ALLOWED_LEVELS = array( 2, 3, 4 );

	/**
	 * Allowed auto-insert positions.
	 */
	public const ALLOWED_POSITIONS = array( 'top', 'before_first_heading' );

	/**
	 * Placeholder token used to place the TOC box inside content safely.
	 */
	private const PLACEHOLDER = '<!--penalis-toc-placeholder-->';

	/**
	 * Whether frontend assets were enqueued in the current request.
	 *
	 * @var bool
	 */
	private static $assets_enqueued = false;

	/**
	 * Counter for generating unique DOM IDs when multiple TOCs appear on a page.
	 *
	 * @var int
	 */
	private static $instance_counter = 0;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
		add_action( 'init', array( __CLASS__, 'register_shortcodes' ), 20 );
		add_filter( 'rank_math/researches/toc_plugins', array( __CLASS__, 'register_rank_math_toc' ) );
		add_filter( 'the_content', array( __CLASS__, 'filter_content' ), 25 );

		// Per-post override controls in Block Editor and Classic Editor.
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_block_editor_assets' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_classic_meta_box' ) );
		add_action( 'save_post_post', array( __CLASS__, 'save_classic_meta_box' ), 10, 2 );
	}

	/**
	 * Reset per-request state (used by unit tests).
	 *
	 * @return void
	 */
	public static function reset_state() {
		self::$assets_enqueued  = false;
		self::$instance_counter = 0;
	}

	/**
	 * Whether the legacy LuckyWP Table of Contents plugin is currently active.
	 *
	 * @return bool
	 */
	public static function is_lwptoc_active() {
		return class_exists( 'lwptocAutoloader', false )
			|| class_exists( 'luckywp\\tableOfContents\\core\\Core', false )
			|| defined( 'LWPTOC_PLUGIN_VERSION' );
	}

	/**
	 * Return default Table of Contents settings.
	 *
	 * @return array{enabled:bool,title:string,min_headings:int,levels:int[],position:string,default_collapsed:bool,smooth_scroll:bool}
	 */
	public static function defaults() {
		return array(
			'enabled'           => true,
			'title'             => self::DEFAULT_TITLE,
			'min_headings'      => self::DEFAULT_MIN_HEADINGS,
			'levels'            => array( 2, 3 ),
			'position'          => 'top',
			'default_collapsed' => true,
			'smooth_scroll'     => true,
		);
	}

	/**
	 * Sanitize and normalize a raw Table of Contents settings array.
	 *
	 * @param mixed $raw Raw settings input.
	 * @return array{enabled:bool,title:string,min_headings:int,levels:int[],position:string,default_collapsed:bool,smooth_scroll:bool}
	 */
	public static function sanitize_settings( $raw ) {
		$defaults = self::defaults();
		if ( ! is_array( $raw ) ) {
			return $defaults;
		}

		$enabled = array_key_exists( 'enabled', $raw )
			? self::to_bool( $raw['enabled'] )
			: $defaults['enabled'];

		$title_raw = isset( $raw['title'] ) ? sanitize_text_field( (string) $raw['title'] ) : '';
		$title_raw = trim( $title_raw );
		if ( '' === $title_raw ) {
			$title = $defaults['title'];
		} elseif ( function_exists( 'mb_substr' ) ) {
			$title = mb_substr( $title_raw, 0, 80, 'UTF-8' );
		} else {
			$title = substr( $title_raw, 0, 80 );
		}

		$min_headings = $defaults['min_headings'];
		if ( isset( $raw['min_headings'] ) && is_numeric( $raw['min_headings'] ) ) {
			$min_headings = max( 1, min( 20, (int) $raw['min_headings'] ) );
		}

		$levels = array();
		if ( isset( $raw['levels'] ) && is_array( $raw['levels'] ) ) {
			foreach ( $raw['levels'] as $level ) {
				$level_int = (int) $level;
				if ( in_array( $level_int, self::ALLOWED_LEVELS, true ) && ! in_array( $level_int, $levels, true ) ) {
					$levels[] = $level_int;
				}
			}
			sort( $levels );
		}
		if ( empty( $levels ) ) {
			$levels = $defaults['levels'];
		}

		$position = isset( $raw['position'] ) ? sanitize_key( (string) $raw['position'] ) : $defaults['position'];
		if ( ! in_array( $position, self::ALLOWED_POSITIONS, true ) ) {
			$position = $defaults['position'];
		}

		$default_collapsed = array_key_exists( 'default_collapsed', $raw )
			? self::to_bool( $raw['default_collapsed'] )
			: $defaults['default_collapsed'];

		$smooth_scroll = array_key_exists( 'smooth_scroll', $raw )
			? self::to_bool( $raw['smooth_scroll'] )
			: $defaults['smooth_scroll'];

		return array(
			'enabled'           => $enabled,
			'title'             => $title,
			'min_headings'      => $min_headings,
			'levels'            => $levels,
			'position'          => $position,
			'default_collapsed' => $default_collapsed,
			'smooth_scroll'     => $smooth_scroll,
		);
	}

	/**
	 * Read the normalized Table of Contents settings.
	 *
	 * @return array{enabled:bool,title:string,min_headings:int,levels:int[],position:string,default_collapsed:bool,smooth_scroll:bool}
	 */
	public static function get_settings() {
		$stored = get_option( self::OPTION_SETTINGS, null );
		if ( ! is_array( $stored ) ) {
			return self::defaults();
		}

		return self::sanitize_settings( $stored );
	}

	/**
	 * Whether automatic Table of Contents insertion is enabled globally.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = self::get_settings();

		return ! empty( $settings['enabled'] );
	}

	/**
	 * Check whether Table of Contents is disabled for a specific post.
	 *
	 * Explicit `_penalis_toc_disabled` takes precedence (`'1'` disabled, `'0'`
	 * enabled). When unset, falls back to legacy LuckyWP `_lwptoc_settings` so
	 * existing per-post opt-outs survive plugin removal without data loss.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_post_disabled( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return false;
		}

		$explicit = get_post_meta( $post_id, self::META_DISABLED, true );
		if ( '1' === (string) $explicit || 1 === $explicit || true === $explicit ) {
			return true;
		}
		if ( '0' === (string) $explicit || 0 === $explicit || false === $explicit ) {
			return false;
		}

		$legacy = get_post_meta( $post_id, self::LEGACY_META_KEY, true );
		if ( is_array( $legacy ) && array_key_exists( 'enabled', $legacy ) && false === (bool) $legacy['enabled'] ) {
			return true;
		}

		return false;
	}

	/**
	 * Count how many posts have a legacy LuckyWP TOC per-post override stored.
	 *
	 * @return array{lwptocActive:bool,legacyOverrideCount:int}
	 */
	public static function migration_status() {
		$legacy_count = 0;

		if ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) && ! empty( $GLOBALS['wpdb']->postmeta ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$legacy_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s",
					self::LEGACY_META_KEY
				)
			);
		}

		return array(
			'lwptocActive'        => self::is_lwptoc_active(),
			'legacyOverrideCount' => max( 0, $legacy_count ),
		);
	}

	/**
	 * Register `_penalis_toc_disabled` post meta for Block Editor REST persistence.
	 *
	 * @return void
	 */
	public static function register_meta() {
		if ( ! function_exists( 'register_post_meta' ) ) {
			return;
		}

		register_post_meta(
			'post',
			self::META_DISABLED,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_post_disabled_meta' ),
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					unset( $allowed, $meta_key );
					return current_user_can( 'edit_post', (int) $post_id );
				},
			)
		);
	}

	/**
	 * Sanitize the per-post `_penalis_toc_disabled` meta value.
	 *
	 * @param mixed $value Raw meta value.
	 * @return string `'1'`, `'0'`, or `''`.
	 */
	public static function sanitize_post_disabled_meta( $value ) {
		if ( true === $value || '1' === (string) $value || 1 === $value ) {
			return '1';
		}
		if ( false === $value || '0' === (string) $value || 0 === $value ) {
			return '0';
		}

		return '';
	}

	/**
	 * Register `[penalis_toc]` shortcode (and `[lwptoc]` fallback when LuckyWP is inactive).
	 *
	 * @return void
	 */
	public static function register_shortcodes() {
		if ( ! function_exists( 'add_shortcode' ) ) {
			return;
		}

		add_shortcode( 'penalis_toc', array( __CLASS__, 'render_shortcode' ) );

		if ( ! self::is_lwptoc_active() && ! shortcode_exists( 'lwptoc' ) ) {
			add_shortcode( 'lwptoc', array( __CLASS__, 'render_shortcode' ) );
		}
	}

	/**
	 * Shortcode callback that emits a placeholder replaced by `filter_content()`.
	 *
	 * @return string
	 */
	public static function render_shortcode() {
		if ( self::is_lwptoc_active() ) {
			return '';
		}

		return self::PLACEHOLDER;
	}

	/**
	 * Register Penalis Platform with Rank Math's Table of Contents SEO analysis.
	 *
	 * @param array<string,string> $plugins Registered TOC plugins map.
	 * @return array<string,string>
	 */
	public static function register_rank_math_toc( $plugins ) {
		if ( ! is_array( $plugins ) ) {
			$plugins = array();
		}

		if ( self::is_enabled() ) {
			$basename             = defined( 'PENALIS_PLATFORM_BASENAME' )
				? PENALIS_PLATFORM_BASENAME
				: 'penalis-platform/penalis-platform.php';
			$plugins[ $basename ] = 'Penalis Platform';
		}

		return $plugins;
	}

	/**
	 * Filter single-post `the_content` to inject heading anchors and the TOC box.
	 *
	 * @param string $content Rendered post content.
	 * @return string
	 */
	public static function filter_content( $content ) {
		if ( self::is_lwptoc_active() || ! is_string( $content ) || '' === trim( $content ) ) {
			return $content;
		}

		if ( is_admin() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return $content;
		}

		if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post = get_post();
		if ( ! ( $post instanceof WP_Post ) || 'post' !== $post->post_type ) {
			return $content;
		}

		$has_placeholder = false !== strpos( $content, self::PLACEHOLDER );
		$settings        = self::get_settings();

		if ( ! $has_placeholder ) {
			if ( empty( $settings['enabled'] ) || self::is_post_disabled( (int) $post->ID ) ) {
				return $content;
			}
		}

		$min_required = $has_placeholder ? 1 : (int) $settings['min_headings'];
		$result       = self::process_content(
			$content,
			array_merge(
				$settings,
				array(
					'min_headings'    => $min_required,
					'has_placeholder' => $has_placeholder,
				)
			)
		);

		if ( ! $result['rendered'] ) {
			return $has_placeholder ? str_replace( self::PLACEHOLDER, '', $content ) : $content;
		}

		self::enqueue_frontend_assets();

		return $result['content'];
	}

	/**
	 * Parse headings from HTML content, inject lowercase slug anchor IDs, and
	 * insert the rendered Table of Contents markup.
	 *
	 * Pure transformation helper (safe to call in unit tests without full WP loop).
	 *
	 * @param string              $content  Post HTML content.
	 * @param array<string,mixed> $override Optional settings overrides (`has_placeholder`, etc.).
	 * @return array{content:string,headings:array<int,array{id:string,level:int,label:string,number:string}>,rendered:bool}
	 */
	public static function process_content( $content, array $override = array() ) {
		$settings        = self::sanitize_settings( array_merge( self::get_settings(), $override ) );
		$has_placeholder = ! empty( $override['has_placeholder'] ) || false !== strpos( $content, self::PLACEHOLDER );

		$allowed_levels = $settings['levels'];
		$headings       = array();
		$used_ids       = array();
		$first_marker   = '<!--penalis-toc-first-heading-->';
		$marked_first   = false;

		$processed = preg_replace_callback(
			'/<h([1-6])(\b[^>]*)>(.*?)<\/h\1>/is',
			static function ( $matches ) use ( $allowed_levels, &$headings, &$used_ids, $first_marker, &$marked_first ) {
				$level = (int) $matches[1];
				if ( ! in_array( $level, $allowed_levels, true ) ) {
					return $matches[0];
				}

				$attrs = (string) $matches[2];
				$inner = (string) $matches[3];
				$label = self::extract_heading_label( $inner );

				if ( '' === $label ) {
					return $matches[0];
				}

				// Reuse an existing id attribute if present and non-empty; otherwise build a lowercase slug.
				$existing_id = self::extract_id_attribute( $attrs );
				if ( '' !== $existing_id ) {
					$id = self::ensure_unique_id( $existing_id, $used_ids );
					if ( $id !== $existing_id ) {
						$attrs = self::replace_id_attribute( $attrs, $id );
					}
				} else {
					$id    = self::slugify_heading( $label, $used_ids );
					$attrs = ' id="' . esc_attr( $id ) . '"' . $attrs;
				}

				$attrs = self::add_heading_class( $attrs, 'penalis-toc-heading' );

				$headings[] = array(
					'id'     => $id,
					'level'  => $level,
					'label'  => $label,
					'number' => '',
				);

				$prefix = '';
				if ( ! $marked_first ) {
					$prefix       = $first_marker;
					$marked_first = true;
				}

				return $prefix . '<h' . $level . $attrs . '>' . $inner . '</h' . $level . '>';
			},
			$content
		);

		if ( ! is_string( $processed ) || count( $headings ) < (int) $settings['min_headings'] ) {
			return array(
				'content'  => $has_placeholder ? str_replace( self::PLACEHOLDER, '', $content ) : $content,
				'headings' => array(),
				'rendered' => false,
			);
		}

		$headings = self::assign_hierarchical_numbers( $headings );
		$toc_html = self::render_toc_html( $headings, $settings );

		if ( $has_placeholder ) {
			$final = str_replace( $first_marker, '', $processed );
			$final = self::replace_once( self::PLACEHOLDER, $toc_html, $final );
			$final = str_replace( self::PLACEHOLDER, '', $final );
		} elseif ( 'before_first_heading' === $settings['position'] && false !== strpos( $processed, $first_marker ) ) {
			$final = self::replace_once( $first_marker, $toc_html, $processed );
		} else {
			$final = $toc_html . str_replace( $first_marker, '', $processed );
		}

		return array(
			'content'  => $final,
			'headings' => $headings,
			'rendered' => true,
		);
	}

	/**
	 * Convert a heading label into a unique lowercase hyphenated anchor ID (`judul-bagian`).
	 *
	 * @param string   $label    Plain heading text.
	 * @param string[] $used_ids Already-allocated IDs in this document (mutated).
	 * @return string
	 */
	public static function slugify_heading( $label, array &$used_ids ) {
		$clean = (string) $label;
		if ( function_exists( 'remove_accents' ) ) {
			$clean = remove_accents( $clean );
		}

		$clean = function_exists( 'mb_strtolower' )
			? mb_strtolower( $clean, 'UTF-8' )
			: strtolower( $clean );

		$slug = preg_replace( '/[^a-z0-9]+/', '-', $clean );
		$slug = is_string( $slug ) ? trim( $slug, '-' ) : '';

		if ( '' === $slug ) {
			$slug = 'bagian';
		}

		return self::ensure_unique_id( $slug, $used_ids );
	}

	/**
	 * Assign hierarchical decimal numbers (`1.`, `1.1.`, `2.`) to heading entries.
	 *
	 * Normalizes depths relative to the highest (smallest number) heading level
	 * present so articles starting at `H3` still number cleanly from `1.`.
	 *
	 * @param array<int,array{id:string,level:int,label:string,number:string}> $headings Headings list.
	 * @return array<int,array{id:string,level:int,label:string,number:string}>
	 */
	public static function assign_hierarchical_numbers( array $headings ) {
		if ( empty( $headings ) ) {
			return array();
		}

		$min_level = min( array_column( $headings, 'level' ) );
		$counters  = array();

		foreach ( $headings as $index => $heading ) {
			$depth = max( 0, (int) $heading['level'] - $min_level );

			// Drop deeper counters whenever heading level steps back up.
			foreach ( array_keys( $counters ) as $c_depth ) {
				if ( $c_depth > $depth ) {
					unset( $counters[ $c_depth ] );
				}
			}

			// Ensure parent depths exist even if an article skips H2 -> H4.
			for ( $d = 0; $d < $depth; $d++ ) {
				if ( ! isset( $counters[ $d ] ) ) {
					$counters[ $d ] = 1;
				}
			}

			$counters[ $depth ] = isset( $counters[ $depth ] ) ? $counters[ $depth ] + 1 : 1;

			$parts = array();
			for ( $d = 0; $d <= $depth; $d++ ) {
				$parts[] = (string) $counters[ $d ];
			}

			$headings[ $index ]['number'] = implode( '.', $parts ) . '.';
		}

		return $headings;
	}

	/**
	 * Render the accessible Table of Contents card markup.
	 *
	 * @param array<int,array{id:string,level:int,label:string,number:string}> $headings Numbered headings.
	 * @param array<string,mixed>                                              $settings Normalized TOC settings.
	 * @return string
	 */
	public static function render_toc_html( array $headings, array $settings ) {
		if ( empty( $headings ) ) {
			return '';
		}

		++self::$instance_counter;
		$list_id   = 'penalis-toc-list-' . self::$instance_counter;
		$title_id  = 'penalis-toc-title-' . self::$instance_counter;
		$collapsed = ! empty( $settings['default_collapsed'] );
		$smooth    = ! empty( $settings['smooth_scroll'] );
		$title     = isset( $settings['title'] ) && '' !== trim( (string) $settings['title'] )
			? (string) $settings['title']
			: self::DEFAULT_TITLE;

		$min_level  = min( array_column( $headings, 'level' ) );
		$list_items = '';
		foreach ( $headings as $heading ) {
			$depth       = max( 0, min( 2, (int) $heading['level'] - $min_level ) );
			$list_items .= sprintf(
				'<li class="penalis-toc__item penalis-toc__item--depth-%1$d">'
				. '<a class="penalis-toc__link" href="#%2$s">'
				. '<span class="penalis-toc__num" aria-hidden="true">%3$s</span>'
				. '<span class="penalis-toc__text">%4$s</span>'
				. '</a>'
				. '</li>',
				$depth,
				esc_attr( $heading['id'] ),
				esc_html( $heading['number'] ),
				esc_html( $heading['label'] )
			);
		}

		$toggle_label = $collapsed ? 'Tampilkan' : 'Sembunyikan';
		$hidden_attr  = $collapsed ? ' hidden' : '';
		$expanded     = $collapsed ? 'false' : 'true';
		$state_class  = $collapsed ? ' is-collapsed' : ' is-expanded';

		return sprintf(
			'<nav class="penalis-toc%1$s" aria-labelledby="%2$s" data-penalis-toc="1" data-penalis-toc-smooth="%3$s">'
			. '<div class="penalis-toc__header">'
			. '<div class="penalis-toc__heading">'
			. '<span class="penalis-toc__icon" aria-hidden="true">'
			. '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false">'
			. '<line x1="8" y1="6" x2="21" y2="6"></line>'
			. '<line x1="8" y1="12" x2="21" y2="12"></line>'
			. '<line x1="8" y1="18" x2="21" y2="18"></line>'
			. '<line x1="3" y1="6" x2="3.01" y2="6"></line>'
			. '<line x1="3" y1="12" x2="3.01" y2="12"></line>'
			. '<line x1="3" y1="18" x2="3.01" y2="18"></line>'
			. '</svg>'
			. '</span>'
			. '<span class="penalis-toc__title" id="%2$s">%4$s</span>'
			. '</div>'
			. '<button type="button" class="penalis-toc__toggle" aria-expanded="%5$s" aria-controls="%6$s" data-label-show="Tampilkan" data-label-hide="Sembunyikan">'
			. '<span class="penalis-toc__toggle-label">%7$s</span>'
			. '<svg class="penalis-toc__chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
			. '<polyline points="6 9 12 15 18 9"></polyline>'
			. '</svg>'
			. '</button>'
			. '</div>'
			. '<div class="penalis-toc__body" id="%6$s"%8$s>'
			. '<ol class="penalis-toc__list">%9$s</ol>'
			. '</div>'
			. '</nav>',
			$state_class,
			esc_attr( $title_id ),
			$smooth ? '1' : '0',
			esc_html( $title ),
			$expanded,
			esc_attr( $list_id ),
			esc_html( $toggle_label ),
			$hidden_attr,
			$list_items
		);
	}

	/**
	 * Enqueue the lightweight TOC stylesheet and toggle/scroll script once.
	 *
	 * @return void
	 */
	public static function enqueue_frontend_assets() {
		if ( self::$assets_enqueued ) {
			return;
		}
		self::$assets_enqueued = true;

		$css = Penalis_Admin::resolve_asset_meta( 'assets/css/toc.css' );
		$js  = Penalis_Admin::resolve_asset_meta( 'assets/js/toc.js' );

		wp_enqueue_style( self::STYLE_HANDLE, $css['url'], array(), $css['version'] );
		wp_enqueue_script( self::SCRIPT_HANDLE, $js['url'], array(), $js['version'], true );
	}

	/**
	 * Enqueue the Block Editor sidebar toggle for per-post TOC override.
	 *
	 * @return void
	 */
	public static function enqueue_block_editor_assets() {
		if ( self::is_lwptoc_active() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->post_type ) {
			return;
		}

		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only editor bootstrap.
		$js      = Penalis_Admin::resolve_asset_meta( 'assets/js/editor-toc.js' );

		wp_enqueue_script(
			self::EDITOR_SCRIPT_HANDLE,
			$js['url'],
			array( 'wp-plugins', 'wp-editor', 'wp-components', 'wp-data', 'wp-element' ),
			$js['version'],
			true
		);

		wp_localize_script(
			self::EDITOR_SCRIPT_HANDLE,
			'penalisEditorToc',
			array(
				'metaKey'         => self::META_DISABLED,
				'legacyDisabled'  => $post_id > 0 && self::is_post_disabled( $post_id ),
				'globallyEnabled' => self::is_enabled(),
			)
		);
	}

	/**
	 * Register the Classic Editor meta box for per-post TOC override.
	 *
	 * @return void
	 */
	public static function register_classic_meta_box() {
		if ( self::is_lwptoc_active() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		add_meta_box(
			'penalis-toc-override',
			'Table of Contents',
			array( __CLASS__, 'render_classic_meta_box' ),
			'post',
			'side',
			'default',
			array(
				'__back_compat_meta_box' => true,
			)
		);
	}

	/**
	 * Render the Classic Editor meta box for per-post TOC override.
	 *
	 * @param WP_Post $post Post object.
	 * @return void
	 */
	public static function render_classic_meta_box( WP_Post $post ) {
		$disabled = self::is_post_disabled( (int) $post->ID );
		wp_nonce_field( self::META_BOX_NONCE, 'penalis_toc_nonce' );
		?>
		<p style="margin:0;">
			<label for="penalis-toc-disable-post">
				<input type="hidden" name="penalis_toc_submitted" value="1" />
				<input
					type="checkbox"
					id="penalis-toc-disable-post"
					name="penalis_toc_disabled"
					value="1"
					<?php checked( $disabled ); ?>
				/>
				Nonaktifkan Table of Contents pada artikel ini
			</label>
		</p>
		<?php
	}

	/**
	 * Save the Classic Editor per-post TOC override.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public static function save_classic_meta_box( $post_id, $post ) {
		if ( self::is_lwptoc_active() || ! ( $post instanceof WP_Post ) || 'post' !== $post->post_type ) {
			return;
		}

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['penalis_toc_nonce'], $_POST['penalis_toc_submitted'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['penalis_toc_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, self::META_BOX_NONCE ) ) {
			return;
		}

		$disabled = isset( $_POST['penalis_toc_disabled'] ) ? '1' : '0';
		update_post_meta( $post_id, self::META_DISABLED, $disabled );
	}

	/**
	 * Extract clean single-line text from a heading's inner HTML.
	 *
	 * @param string $inner Inner HTML of heading tag.
	 * @return string
	 */
	private static function extract_heading_label( $inner ) {
		$stripped = preg_replace( '#<\s*(script|style)[^>]*>.*?</\s*\1\s*>#is', '', $inner );
		$plain    = wp_strip_all_tags( is_string( $stripped ) ? $stripped : $inner );
		$decoded  = html_entity_decode( $plain, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$spaced   = preg_replace( '/\s+/u', ' ', $decoded );

		return trim( is_string( $spaced ) ? $spaced : $decoded );
	}

	/**
	 * Extract the existing `id` attribute from a heading attribute string.
	 *
	 * @param string $attrs Raw HTML attributes string.
	 * @return string
	 */
	private static function extract_id_attribute( $attrs ) {
		if ( 1 === preg_match( '/\bid\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $attrs, $m ) ) {
			$val = '' !== $m[1] ? $m[1] : ( isset( $m[2] ) ? $m[2] : '' );
			return trim( (string) $val );
		}

		return '';
	}

	/**
	 * Replace an existing `id` attribute value inside an HTML attribute string.
	 *
	 * @param string $attrs  Raw HTML attributes string.
	 * @param string $new_id New unique ID.
	 * @return string
	 */
	private static function replace_id_attribute( $attrs, $new_id ) {
		$replaced = preg_replace(
			'/\bid\s*=\s*(?:"[^"]*"|\'[^\']*\')/i',
			'id="' . esc_attr( $new_id ) . '"',
			$attrs,
			1
		);

		return is_string( $replaced ) ? $replaced : $attrs;
	}

	/**
	 * Ensure a CSS class is present on an HTML opening tag's attribute string.
	 *
	 * @param string $attrs      Raw HTML attributes string.
	 * @param string $class_name Class name to add.
	 * @return string
	 */
	private static function add_heading_class( $attrs, $class_name ) {
		if ( 1 === preg_match( '/\bclass\s*=\s*"([^"]*)"/i', $attrs, $m ) ) {
			$classes = preg_split( '/\s+/', trim( $m[1] ) );
			if ( is_array( $classes ) && in_array( $class_name, $classes, true ) ) {
				return $attrs;
			}
			$merged = trim( $m[1] . ' ' . $class_name );
			return (string) preg_replace(
				'/\bclass\s*=\s*"[^"]*"/i',
				'class="' . esc_attr( $merged ) . '"',
				$attrs,
				1
			);
		}

		if ( 1 === preg_match( '/\bclass\s*=\s*\'([^\']*)\'/i', $attrs, $m ) ) {
			$classes = preg_split( '/\s+/', trim( $m[1] ) );
			if ( is_array( $classes ) && in_array( $class_name, $classes, true ) ) {
				return $attrs;
			}
			$merged = trim( $m[1] . ' ' . $class_name );
			return (string) preg_replace(
				'/\bclass\s*=\s*\'[^\']*\'/i',
				'class="' . esc_attr( $merged ) . '"',
				$attrs,
				1
			);
		}

		return $attrs . ' class="' . esc_attr( $class_name ) . '"';
	}

	/**
	 * Allocate a unique anchor ID by appending `-2`, `-3`, etc. on collision.
	 *
	 * @param string   $candidate Desired anchor ID.
	 * @param string[] $used_ids  Already-used IDs (mutated).
	 * @return string
	 */
	private static function ensure_unique_id( $candidate, array &$used_ids ) {
		$id      = $candidate;
		$counter = 2;

		while ( in_array( $id, $used_ids, true ) ) {
			$id = $candidate . '-' . $counter;
			++$counter;
		}

		$used_ids[] = $id;

		return $id;
	}

	/**
	 * Replace only the first occurrence of `$needle` in `$haystack`.
	 *
	 * @param string $needle      Search string.
	 * @param string $replacement Replacement string.
	 * @param string $haystack    Subject string.
	 * @return string
	 */
	private static function replace_once( $needle, $replacement, $haystack ) {
		$pos = strpos( $haystack, $needle );
		if ( false === $pos ) {
			return $haystack;
		}

		return substr_replace( $haystack, $replacement, $pos, strlen( $needle ) );
	}

	/**
	 * Interpret checkbox/boolean-like values consistently.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private static function to_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		$str = strtolower( trim( (string) $value ) );

		return in_array( $str, array( '1', 'true', 'yes', 'on' ), true );
	}
}
