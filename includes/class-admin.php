<?php
/**
 * Administrator approval screens.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the unified Penalis Platform menu, the overview dashboard, and the
 * Penalis Writers management screen.
 */
final class Penalis_Admin {

	public const MENU_SLUG = 'penalis-platform';
	public const PAGE_SLUG = 'penalis-writers';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 10 );
		add_action( 'admin_menu', array( __CLASS__, 'register_writers_submenu' ), 12 );
		add_action( 'admin_post_penalis_account_action', array( __CLASS__, 'handle_account_action' ) );
		add_filter( 'manage_users_columns', array( __CLASS__, 'add_status_column' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'render_status_column' ), 10, 3 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_styles' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_scripts' ) );
		add_action( 'admin_post_penalis_save_default_avatar', array( __CLASS__, 'save_default_avatar' ) );
	}

	/**
	 * Register the top-level Penalis Platform menu and its Ringkasan submenu.
	 *
	 * @return void
	 */
	public static function register_menu() {
		$pending_reviews = class_exists( 'Penalis_Editorial_Admin' ) ? Penalis_Editorial_Admin::pending_review_count() : 0;

		$menu_title = 'Penalis';
		if ( $pending_reviews > 0 ) {
			$menu_title .= sprintf(
				' <span class="awaiting-mod update-plugins count-%1$d"><span class="pending-count" aria-hidden="true">%2$s</span></span>',
				$pending_reviews,
				number_format_i18n( $pending_reviews )
			);
		}

		add_menu_page(
			'Penalis Platform',
			$menu_title,
			'list_users',
			self::MENU_SLUG,
			array( __CLASS__, 'render_overview_page' ),
			'dashicons-welcome-write-blog',
			58
		);

		add_submenu_page(
			self::MENU_SLUG,
			'Ringkasan — Penalis Platform',
			'Ringkasan',
			'list_users',
			self::MENU_SLUG,
			array( __CLASS__, 'render_overview_page' )
		);
	}

	/**
	 * Register the Penulis Penalis submenu under Penalis Platform.
	 *
	 * Registered at priority 12 so the submenu order is:
	 * 1. Ringkasan (priority 10)
	 * 2. Tinjauan Karya (priority 11)
	 * 3. Penulis Penalis (priority 12)
	 *
	 * @return void
	 */
	public static function register_writers_submenu() {
		add_submenu_page(
			self::MENU_SLUG,
			'Penulis Penalis — Penalis Platform',
			'Penulis Penalis',
			'list_users',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Whether the current admin request belongs to a Penalis Platform screen.
	 *
	 * Checks both the `$hook` suffix and the `page` query parameter so the
	 * check stays reliable even if a plugin or translation alters menu titles.
	 *
	 * @param string $hook Current admin page hook.
	 * @return bool
	 */
	public static function is_penalis_screen( $hook = '' ) {
		$hook  = (string) $hook;
		$slugs = array(
			self::MENU_SLUG,
			self::PAGE_SLUG,
			Penalis_Editorial_Admin::PAGE_SLUG,
		);

		foreach ( $slugs as $slug ) {
			if ( false !== strpos( $hook, $slug ) ) {
				return true;
			}
		}

		if ( isset( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$page = sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( in_array( $page, $slugs, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Enqueue the shared wp-admin stylesheet (`assets/css/admin.css`).
	 *
	 * The status pill is also used by the Users list column, so the stylesheet
	 * loads on `users.php` as well as all Penalis Platform screens.
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public static function admin_styles( $hook ) {
		if ( 'users.php' !== $hook && ! self::is_penalis_screen( $hook ) ) {
			return;
		}

		self::enqueue_stylesheet();
	}

	/**
	 * Register and enqueue `assets/css/admin.css`.
	 *
	 * @return void
	 */
	public static function enqueue_stylesheet() {
		$rel_path = 'assets/css/admin.css';
		$abs_path = defined( 'PENALIS_PLATFORM_DIR' ) ? PENALIS_PLATFORM_DIR . $rel_path : '';
		$url      = defined( 'PENALIS_PLATFORM_URL' ) ? PENALIS_PLATFORM_URL . $rel_path : '';
		$version  = ( '' !== $abs_path && file_exists( $abs_path ) )
			? (string) filemtime( $abs_path )
			: ( defined( 'PENALIS_PLATFORM_VERSION' ) ? PENALIS_PLATFORM_VERSION : '1.0.0' );

		wp_enqueue_style( 'penalis-admin', $url, array(), $version );
	}

	/**
	 * Register and enqueue `assets/js/admin-review.js` for confirmation modals.
	 *
	 * @return void
	 */
	public static function enqueue_confirmation_script() {
		if ( ! function_exists( 'wp_enqueue_script' ) ) {
			return;
		}

		$rel_path = 'assets/js/admin-review.js';
		$abs_path = defined( 'PENALIS_PLATFORM_DIR' ) ? PENALIS_PLATFORM_DIR . $rel_path : '';
		$url      = defined( 'PENALIS_PLATFORM_URL' ) ? PENALIS_PLATFORM_URL . $rel_path : '';
		$version  = ( '' !== $abs_path && file_exists( $abs_path ) )
			? (string) filemtime( $abs_path )
			: ( defined( 'PENALIS_PLATFORM_VERSION' ) ? PENALIS_PLATFORM_VERSION : '1.0.0' );

		wp_enqueue_script( 'penalis-admin-review', $url, array(), $version, true );
	}

	/**
	 * Enqueue the media picker and confirmation dialog on the writers screen.
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public static function admin_scripts( $hook ) {
		$page      = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_writer = false !== strpos( (string) $hook, self::PAGE_SLUG ) || self::PAGE_SLUG === $page;

		if ( ! $is_writer ) {
			return;
		}

		self::enqueue_confirmation_script();
		wp_enqueue_media();

		wp_register_script( 'penalis-admin-avatar', '', array( 'jquery' ), PENALIS_PLATFORM_VERSION, true );
		wp_enqueue_script( 'penalis-admin-avatar' );

		$script = <<<'JS'
jQuery( function ( $ ) {
	var frame;

	$( '#penalis-default-avatar-choose' ).on( 'click', function ( event ) {
		event.preventDefault();

		if ( ! frame ) {
			frame = wp.media( {
				title: 'Foto profil bawaan',
				button: { text: 'Gunakan gambar ini' },
				library: { type: 'image' },
				multiple: false
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				$( '#penalis-default-avatar-id' ).val( attachment.id );
				$( '#penalis-default-avatar-preview' ).attr( 'src', attachment.url ).show();
			} );
		}

		frame.open();
	} );
} );
JS;

		wp_add_inline_script( 'penalis-admin-avatar', $script );
	}

	/**
	 * Save the default profile photo.
	 *
	 * @return void
	 */
	public static function save_default_avatar() {
		if ( ! current_user_can( 'list_users' ) ) {
			wp_die( 'Kamu tidak memiliki izin untuk mengubah pengaturan ini.' );
		}

		check_admin_referer( 'penalis_default_avatar' );

		$redirect = self::writers_url();
		$clear    = isset( $_POST['penalis_clear_default_avatar'] );
		$avatar   = isset( $_POST['penalis_default_avatar_id'] ) ? absint( wp_unslash( $_POST['penalis_default_avatar_id'] ) ) : 0;

		if ( ! $clear && ( $avatar <= 0 || ! wp_attachment_is_image( $avatar ) ) ) {
			wp_safe_redirect( add_query_arg( 'penalis_error', 'avatar_invalid', $redirect ) );
			exit;
		}

		if ( $clear ) {
			update_option( 'avatar_default_wp_user_avatar', '' );
			update_option( 'avatar_default', 'mystery' );
		} else {
			update_option( 'avatar_default_wp_user_avatar', $avatar );
			update_option( 'avatar_default', 'wp_user_avatar' );
		}

		wp_safe_redirect( add_query_arg( 'penalis_message', 'avatar_saved', $redirect ) );
		exit;
	}

	/**
	 * Render the unified Penalis Platform overview page.
	 *
	 * @return void
	 */
	public static function render_overview_page() {
		if ( ! current_user_can( 'list_users' ) ) {
			wp_die( 'Kamu tidak memiliki izin untuk melihat halaman ini.' );
		}

		$user_counts    = self::status_counts();
		$article_counts = class_exists( 'Penalis_Editorial_Admin' ) ? Penalis_Editorial_Admin::state_counts() : array();
		$pending_posts  = class_exists( 'Penalis_Editorial_Admin' ) ? Penalis_Editorial_Admin::recent_pending_submissions( 5 ) : array();
		$recent_users   = self::recent_registered_writers( 5 );

		$pending_review_count    = isset( $article_counts[ Penalis_Editorial_Policy::STATE_PENDING_REVIEW ] ) ? (int) $article_counts[ Penalis_Editorial_Policy::STATE_PENDING_REVIEW ] : 0;
		$changes_requested_count = isset( $article_counts[ Penalis_Editorial_Policy::STATE_CHANGES_REQUESTED ] ) ? (int) $article_counts[ Penalis_Editorial_Policy::STATE_CHANGES_REQUESTED ] : 0;
		$rejected_article_count  = isset( $article_counts[ Penalis_Editorial_Policy::STATE_REJECTED ] ) ? (int) $article_counts[ Penalis_Editorial_Policy::STATE_REJECTED ] : 0;
		$published_article_count = isset( $article_counts[ Penalis_Editorial_Policy::STATE_PUBLISHED ] ) ? (int) $article_counts[ Penalis_Editorial_Policy::STATE_PUBLISHED ] : 0;

		$active_writer_count        = isset( $user_counts[ Penalis_Policy::STATUS_ACTIVE ] ) ? (int) $user_counts[ Penalis_Policy::STATUS_ACTIVE ] : 0;
		$pending_verification_count = isset( $user_counts[ Penalis_Policy::STATUS_PENDING_VERIFICATION ] ) ? (int) $user_counts[ Penalis_Policy::STATUS_PENDING_VERIFICATION ] : 0;
		$suspended_writer_count     = isset( $user_counts[ Penalis_Policy::STATUS_SUSPENDED ] ) ? (int) $user_counts[ Penalis_Policy::STATUS_SUSPENDED ] : 0;
		$legacy_writer_count        = isset( $user_counts['legacy'] ) ? (int) $user_counts['legacy'] : 0;
		?>
		<div class="wrap penalis-overview">
			<h1 class="wp-heading-inline">Penalis Platform</h1>
			<a href="<?php echo esc_url( Penalis_Editorial_Admin::list_url() ); ?>" class="page-title-action">Tinjauan Karya</a>
			<a href="<?php echo esc_url( self::writers_url() ); ?>" class="page-title-action">Penulis Penalis</a>
			<a href="<?php echo esc_url( Penalis_Dashboard::dashboard_url() ); ?>" class="page-title-action" target="_blank" rel="noopener noreferrer">Buka Dasbor Penulis &nearr;</a>
			<hr class="wp-header-end">

			<p class="penalis-intro">
				Pusat kurasi komunitas Penalis. Pantau antrean karya tulis yang menunggu tinjauan dan
				kelola akun penulis komunitas di satu tempat.
			</p>

			<div class="penalis-overview__section">
				<h2 class="penalis-overview__section-title">Antrean Karya Tulis</h2>
				<div class="penalis-overview__stats">
					<a
						href="<?php echo esc_url( Penalis_Editorial_Admin::list_url( Penalis_Editorial_Policy::STATE_PENDING_REVIEW ) ); ?>"
						class="penalis-overview__stat <?php echo $pending_review_count > 0 ? 'penalis-overview__stat--attention' : ''; ?>"
					>
						<span class="penalis-overview__stat-label">Menunggu tinjauan</span>
						<span class="penalis-overview__stat-value"><?php echo esc_html( number_format_i18n( $pending_review_count ) ); ?></span>
						<span class="penalis-overview__stat-hint">Tinjau pengajuan &rarr;</span>
					</a>

					<a
						href="<?php echo esc_url( Penalis_Editorial_Admin::list_url( Penalis_Editorial_Policy::STATE_CHANGES_REQUESTED ) ); ?>"
						class="penalis-overview__stat"
					>
						<span class="penalis-overview__stat-label">Perlu revisi</span>
						<span class="penalis-overview__stat-value"><?php echo esc_html( number_format_i18n( $changes_requested_count ) ); ?></span>
						<span class="penalis-overview__stat-hint">Lihat daftar &rarr;</span>
					</a>

					<a
						href="<?php echo esc_url( Penalis_Editorial_Admin::list_url( Penalis_Editorial_Policy::STATE_REJECTED ) ); ?>"
						class="penalis-overview__stat"
					>
						<span class="penalis-overview__stat-label">Ditolak</span>
						<span class="penalis-overview__stat-value"><?php echo esc_html( number_format_i18n( $rejected_article_count ) ); ?></span>
						<span class="penalis-overview__stat-hint">Lihat daftar &rarr;</span>
					</a>

					<a
						href="<?php echo esc_url( Penalis_Editorial_Admin::list_url( Penalis_Editorial_Policy::STATE_PUBLISHED ) ); ?>"
						class="penalis-overview__stat"
					>
						<span class="penalis-overview__stat-label">Terbit</span>
						<span class="penalis-overview__stat-value"><?php echo esc_html( number_format_i18n( $published_article_count ) ); ?></span>
						<span class="penalis-overview__stat-hint">Lihat artikel terbit &rarr;</span>
					</a>
				</div>
			</div>

			<div class="penalis-overview__section">
				<h2 class="penalis-overview__section-title">Status Akun Penulis</h2>
				<div class="penalis-overview__stats">
					<a
						href="<?php echo esc_url( self::writers_url( array( 'status' => Penalis_Policy::STATUS_ACTIVE ) ) ); ?>"
						class="penalis-overview__stat"
					>
						<span class="penalis-overview__stat-label">Penulis aktif</span>
						<span class="penalis-overview__stat-value"><?php echo esc_html( number_format_i18n( $active_writer_count ) ); ?></span>
						<span class="penalis-overview__stat-hint">Kelola penulis &rarr;</span>
					</a>

					<a
						href="<?php echo esc_url( self::writers_url( array( 'status' => Penalis_Policy::STATUS_PENDING_VERIFICATION ) ) ); ?>"
						class="penalis-overview__stat"
					>
						<span class="penalis-overview__stat-label">Menunggu verifikasi email</span>
						<span class="penalis-overview__stat-value"><?php echo esc_html( number_format_i18n( $pending_verification_count ) ); ?></span>
						<span class="penalis-overview__stat-hint">Lihat akun &rarr;</span>
					</a>

					<a
						href="<?php echo esc_url( self::writers_url( array( 'status' => Penalis_Policy::STATUS_SUSPENDED ) ) ); ?>"
						class="penalis-overview__stat"
					>
						<span class="penalis-overview__stat-label">Akun ditangguhkan</span>
						<span class="penalis-overview__stat-value"><?php echo esc_html( number_format_i18n( $suspended_writer_count ) ); ?></span>
						<span class="penalis-overview__stat-hint">Lihat akun &rarr;</span>
					</a>

					<a
						href="<?php echo esc_url( self::writers_url( array( 'status' => 'legacy' ) ) ); ?>"
						class="penalis-overview__stat"
					>
						<span class="penalis-overview__stat-label">Akun lama (belum aktif)</span>
						<span class="penalis-overview__stat-value"><?php echo esc_html( number_format_i18n( $legacy_writer_count ) ); ?></span>
						<span class="penalis-overview__stat-hint">Lihat akun lama &rarr;</span>
					</a>
				</div>
			</div>

			<div class="penalis-overview__panels">
				<div class="penalis-overview__card">
					<div class="penalis-overview__card-header">
						<h2>Karya Tulis Menunggu Tinjauan</h2>
						<a class="penalis-overview__card-link" href="<?php echo esc_url( Penalis_Editorial_Admin::list_url() ); ?>">
							Lihat semua &rarr;
						</a>
					</div>
					<?php if ( empty( $pending_posts ) ) : ?>
						<p class="penalis-overview__empty">Tidak ada karya tulis yang menunggu tinjauan saat ini.</p>
					<?php else : ?>
						<ul class="penalis-overview__list">
							<?php foreach ( $pending_posts as $post ) : ?>
								<?php
								$author     = get_userdata( (int) $post->post_author );
								$submitted  = Penalis_Editorial_Workflow::get_submitted_at( $post );
								$cycle      = max( 1, Penalis_Editorial_Workflow::get_review_cycle( $post ) );
								$detail_url = Penalis_Editorial_Admin::detail_url( (int) $post->ID );
								?>
								<li class="penalis-overview__item">
									<div class="penalis-overview__item-main">
										<a class="penalis-overview__item-title" href="<?php echo esc_url( $detail_url ); ?>">
											<?php echo esc_html( '' !== $post->post_title ? $post->post_title : '(tanpa judul)' ); ?>
										</a>
										<span class="penalis-overview__item-meta">
											<?php
											echo esc_html(
												sprintf(
													'%1$s · Siklus #%2$d · %3$s',
													$author ? $author->display_name : 'Penulis tidak ditemukan',
													$cycle,
													$submitted > 0 ? date_i18n( 'j M Y H:i', $submitted ) : mysql2date( 'j M Y H:i', $post->post_modified )
												)
											);
											?>
										</span>
									</div>
									<a class="button button-small button-primary" href="<?php echo esc_url( $detail_url ); ?>">
										Tinjau
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>

				<div class="penalis-overview__card">
					<div class="penalis-overview__card-header">
						<h2>Penulis Terdaftar Terbaru</h2>
						<a class="penalis-overview__card-link" href="<?php echo esc_url( self::writers_url() ); ?>">
							Kelola semua penulis &rarr;
						</a>
					</div>
					<?php if ( empty( $recent_users ) ) : ?>
						<p class="penalis-overview__empty">Belum ada penulis terdaftar yang dikelola.</p>
					<?php else : ?>
						<ul class="penalis-overview__list">
							<?php foreach ( $recent_users as $user ) : ?>
								<?php
								$user_id         = (int) $user->ID;
								$user_status     = Penalis_Account_Status::get_status( $user_id );
								$instagram       = Penalis_Account_Status::get_instagram( $user_id );
								$manage_user_url = self::writers_url(
									array(
										's' => $user->user_login,
									)
								);
								?>
								<li class="penalis-overview__item">
									<div class="penalis-overview__user">
										<?php
										$avatar = function_exists( 'get_avatar' ) ? get_avatar( $user_id, 34, '', '', array( 'class' => 'penalis-overview__avatar' ) ) : '';
										if ( $avatar ) {
											echo wp_kses_post( $avatar );
										} else {
											echo '<span class="penalis-overview__avatar" aria-hidden="true"></span>';
										}
										?>
										<div class="penalis-overview__item-main">
											<a class="penalis-overview__item-title" href="<?php echo esc_url( $manage_user_url ); ?>">
												<?php echo esc_html( $user->display_name ); ?>
											</a>
											<span class="penalis-overview__item-meta">
												<?php
												$meta_parts = array(
													$user->user_login,
													$user->user_email,
												);
												if ( '' !== $instagram ) {
													$meta_parts[] = '@' . $instagram;
												}
												$meta_parts[] = mysql2date( get_option( 'date_format' ), $user->user_registered );
												echo esc_html( implode( ' · ', $meta_parts ) );
												?>
											</span>
										</div>
									</div>
									<span class="penalis-status penalis-status--<?php echo esc_attr( '' === $user_status ? 'legacy' : $user_status ); ?>">
										<span class="penalis-status__dot" aria-hidden="true"></span>
										<?php echo esc_html( '' === $user_status ? 'Akun lama' : Penalis_Policy::status_label( $user_status ) ); ?>
									</span>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Add a status column to the Users list.
	 *
	 * @param array<string,string> $columns Columns.
	 * @return array<string,string>
	 */
	public static function add_status_column( $columns ) {
		$columns['penalis_status'] = 'Status Penalis';
		return $columns;
	}

	/**
	 * Render the status column.
	 *
	 * @param string $output      Output.
	 * @param string $column_name Column.
	 * @param int    $user_id     User ID.
	 * @return string
	 */
	public static function render_status_column( $output, $column_name, $user_id ) {
		if ( 'penalis_status' !== $column_name ) {
			return $output;
		}

		$status = Penalis_Account_Status::get_status( $user_id );

		if ( '' === $status ) {
			return '<span class="penalis-status penalis-status--legacy"><span class="penalis-status__dot" aria-hidden="true"></span>Akun lama</span>';
		}

		return sprintf(
			'<span class="penalis-status penalis-status--%1$s"><span class="penalis-status__dot" aria-hidden="true"></span>%2$s</span>',
			esc_attr( $status ),
			esc_html( Penalis_Policy::status_label( $status ) )
		);
	}

	/**
	 * Render the management page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'list_users' ) ) {
			wp_die( 'Kamu tidak memiliki izin untuk melihat halaman ini.' );
		}

		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// An unknown filter value would silently fall back to the default query
		// while leaving no tab highlighted, so normalise it up front.
		if ( 'legacy' !== $status && ! Penalis_Policy::is_valid_status( $status ) ) {
			$status = '';
		}

		// The legacy tab lists contributor profiles that predate this plugin.
		// They have no Penalis metadata, so they are queried by role instead.
		$is_legacy_view = 'legacy' === $status;

		$per_page = 25;
		$args     = array(
			'number'  => $per_page,
			'offset'  => ( $paged - 1 ) * $per_page,
			'fields'  => 'all',
			'orderby' => 'registered',
			'order'   => 'DESC',
		);

		if ( $is_legacy_view ) {
			$args['role']       = 'contributor';
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'AND',
				array(
					'key'     => Penalis_Account_Status::META_STATUS,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => Penalis_Account_Status::META_ACCESS,
					'compare' => 'NOT EXISTS',
				),
			);
		} elseif ( '' !== $status && Penalis_Policy::is_valid_status( $status ) ) {
			$args['meta_key']   = Penalis_Account_Status::META_STATUS; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$args['meta_value'] = $status; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		} else {
			// "All managed" excludes legacy accounts, which intentionally have
			// no Penalis status metadata.
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => Penalis_Account_Status::META_STATUS,
					'compare' => 'EXISTS',
				),
			);
		}

		if ( '' !== $search ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}

		$query = new WP_User_Query( $args );
		$users = $query->get_results();
		$total = (int) $query->get_total();

		$default_avatar_id  = (int) get_option( 'avatar_default_wp_user_avatar', 0 );
		$default_avatar_url = $default_avatar_id > 0 ? (string) wp_get_attachment_image_url( $default_avatar_id, 'thumbnail' ) : '';

		$tabs = array(
			''                                          => 'Semua yang dikelola',
			Penalis_Policy::STATUS_ACTIVE               => 'Aktif',
			Penalis_Policy::STATUS_PENDING_VERIFICATION => 'Menunggu verifikasi',
			Penalis_Policy::STATUS_SUSPENDED            => 'Ditangguhkan',
			'legacy'                                    => 'Akun lama (belum diaktifkan)',
		);

		$counts = self::status_counts();

		?>
		<div class="wrap penalis-writers">
			<h1 class="wp-heading-inline">Penulis Penalis</h1>
			<a href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>" class="page-title-action">Semua pengguna</a>
			<hr class="wp-header-end">

			<?php if ( isset( $_GET['penalis_message'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( self::message_for( sanitize_key( wp_unslash( $_GET['penalis_message'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['penalis_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( self::message_for( sanitize_key( wp_unslash( $_GET['penalis_error'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p></div>
			<?php endif; ?>

			<p class="penalis-intro">
				Kelola akun penulis komunitas Penalis di sini. Pendaftar yang telah memverifikasi alamat
				email otomatis menjadi penulis aktif; kamu dapat mengirim ulang email verifikasi,
				mengaktifkan akun kontributor lama, atau menangguhkan akun jika ditemukan pelanggaran
				atau indikasi spam.
			</p>

			<ul class="subsubsub">
				<?php foreach ( $tabs as $value => $label ) : ?>
					<?php
					$tab_args   = array( 'status' => $value );
					$is_current = $status === $value;
					if ( '' !== $search ) {
						$tab_args['s'] = $search;
					}
					$tab_url = self::writers_url( $tab_args );
					?>
					<li>
						<a
							href="<?php echo esc_url( $tab_url ); ?>"
							class="<?php echo esc_attr( $is_current ? 'current' : '' ); ?>"
							<?php echo $is_current ? 'aria-current="page"' : ''; ?>
						>
							<?php echo esc_html( $label ); ?>
							<span class="count">(<?php echo esc_html( (string) ( $counts[ $value ] ?? 0 ) ); ?>)</span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>

			<form method="get" class="penalis-toolbar">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
				<label for="penalis-search" class="screen-reader-text">Cari penulis</label>
				<input
					type="search"
					id="penalis-search"
					name="s"
					value="<?php echo esc_attr( $search ); ?>"
					placeholder="Cari nama, username, atau email"
				>
				<button type="submit" class="button">Cari</button>
				<?php $clear_url = self::writers_url( array( 'status' => $status ) ); ?>
				<?php if ( '' !== $search ) : ?>
					<a class="button button-link" href="<?php echo esc_url( $clear_url ); ?>">Hapus pencarian</a>
				<?php endif; ?>
				<span class="penalis-toolbar__count">
					<?php echo esc_html( number_format_i18n( $total ) ); ?> akun
				</span>
			</form>

			<div class="penalis-table-wrap">
				<table class="wp-list-table widefat fixed striped penalis-table">
					<thead>
						<tr>
							<th scope="col" class="penalis-col-writer">Penulis</th>
							<th scope="col" class="penalis-col-email">Email</th>
							<th scope="col" class="penalis-col-instagram">Instagram</th>
							<th scope="col" class="penalis-col-status">Status</th>
							<th scope="col" class="penalis-col-date">Terdaftar</th>
							<th scope="col" class="penalis-col-actions">Tindakan</th>
						</tr>
					</thead>
					<tbody>
					<?php if ( empty( $users ) ) : ?>
						<tr>
							<td colspan="6">
								<div class="penalis-empty__inner">
									<p class="penalis-empty__title">
										<?php echo '' !== $search ? 'Tidak ada akun yang cocok' : 'Belum ada akun di tampilan ini'; ?>
									</p>
									<p class="penalis-empty__text">
										<?php if ( '' !== $search ) : ?>
											Coba kata kunci lain, atau <a href="<?php echo esc_url( $clear_url ); ?>">hapus pencarian</a>.
										<?php elseif ( $is_legacy_view ) : ?>
											Semua akun kontributor lama sudah diaktifkan.
										<?php else : ?>
											Pilih tab lain untuk melihat akun dengan status berbeda.
										<?php endif; ?>
									</p>
								</div>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $users as $user ) : ?>
							<?php
							$user_id        = (int) $user->ID;
							$user_status    = Penalis_Account_Status::get_status( $user_id );
							$access         = Penalis_Account_Status::is_access_enabled( $user_id );
							$instagram      = Penalis_Account_Status::get_instagram( $user_id );
							$can_suspend    = Penalis_Policy::can_transition( $user_status, Penalis_Policy::STATUS_SUSPENDED );
							$can_reactivate = Penalis_Policy::can_transition( $user_status, Penalis_Policy::STATUS_ACTIVE );
							$needs_password = Penalis_Account_Status::needs_password( $user_id );
							$mail_failed    = Penalis_Account_Status::has_recent_email_failure( $user_id );
							$is_protected   = user_can( $user, 'manage_options' );
							?>
							<tr>
								<td>
									<div class="penalis-writer">
										<?php
										// get_avatar() returns false when avatars are
										// disabled site-wide; keep the row aligned anyway.
										$avatar = get_avatar( $user_id, 36, '', '', array( 'class' => 'penalis-writer__avatar' ) );
										if ( $avatar ) {
											echo wp_kses_post( $avatar );
										} else {
											echo '<span class="penalis-writer__avatar" aria-hidden="true"></span>';
										}
										?>
										<div>
											<strong class="penalis-writer__name"><?php echo esc_html( $user->display_name ); ?></strong>
											<span class="penalis-writer__login"><?php echo esc_html( $user->user_login ); ?></span>
										</div>
									</div>
								</td>
								<td>
									<a href="<?php echo esc_url( 'mailto:' . $user->user_email ); ?>"><?php echo esc_html( $user->user_email ); ?></a>
								</td>
								<td>
									<?php if ( '' !== $instagram ) : ?>
										<a
											class="penalis-writer__instagram"
											href="<?php echo esc_url( 'https://www.instagram.com/' . rawurlencode( $instagram ) . '/' ); ?>"
											target="_blank"
											rel="noopener noreferrer"
										>
											@<?php echo esc_html( $instagram ); ?>
										</a>
									<?php else : ?>
										<span class="penalis-muted">&mdash;</span>
									<?php endif; ?>
								</td>
								<td>
									<span class="penalis-status penalis-status--<?php echo esc_attr( '' === $user_status ? 'legacy' : $user_status ); ?>">
										<span class="penalis-status__dot" aria-hidden="true"></span>
										<?php echo esc_html( '' === $user_status ? 'Akun lama' : Penalis_Policy::status_label( $user_status ) ); ?>
									</span>
									<?php if ( $access || $needs_password || $mail_failed ) : ?>
										<div class="penalis-flags">
											<?php if ( $access ) : ?>
												<span class="penalis-flag penalis-flag--ok">Akses dashboard aktif</span>
											<?php endif; ?>
											<?php if ( $needs_password ) : ?>
												<span class="penalis-flag">Belum punya password</span>
											<?php endif; ?>
											<?php if ( $mail_failed ) : ?>
												<span class="penalis-flag penalis-flag--warn">Email terakhir gagal</span>
											<?php endif; ?>
										</div>
									<?php endif; ?>
								</td>
								<td>
									<span class="penalis-date"><?php echo esc_html( mysql2date( get_option( 'date_format' ), $user->user_registered ) ); ?></span>
									<span class="penalis-muted"><?php echo esc_html( mysql2date( get_option( 'time_format' ), $user->user_registered ) ); ?></span>
								</td>
								<td>
									<?php if ( $is_protected ) : ?>
										<p class="penalis-hint">Akun administrator tidak dapat diubah dari layar ini.</p>
									<?php else : ?>
										<div class="penalis-actions">
											<?php if ( $is_legacy_view ) : ?>
												<?php self::action_button( $user_id, 'legacy_send_activation', 'Kirim kode aktivasi' ); ?>
												<?php self::action_button( $user_id, 'legacy_vouch', 'Aktifkan langsung', false, true ); ?>
											<?php endif; ?>

											<?php if ( Penalis_Policy::STATUS_PENDING_VERIFICATION === $user_status ) : ?>
												<?php self::action_button( $user_id, 'resend_verification', 'Kirim ulang kode verifikasi' ); ?>
											<?php endif; ?>

											<?php if ( $can_reactivate && Penalis_Policy::STATUS_SUSPENDED === $user_status ) : ?>
												<?php self::action_button( $user_id, 'reactivate', 'Aktifkan kembali', false, true ); ?>
											<?php endif; ?>

											<?php if ( $can_suspend ) : ?>
												<?php self::action_button( $user_id, 'suspend', 'Tangguhkan', true ); ?>
											<?php endif; ?>
										</div>

										<?php if ( $is_legacy_view ) : ?>
											<p class="penalis-hint">
												Akun lama belum punya password. &ldquo;Aktifkan langsung&rdquo; melewati
												verifikasi email, mengaktifkan akun penulis, dan mengirim link atur password.
											</p>
										<?php endif; ?>

										<?php
										$log = Penalis_Account_Status::get_log( $user_id );
										if ( ! empty( $log ) ) :
											$entries = array_slice( array_reverse( $log ), 0, 3 );
											?>
											<details class="penalis-history">
												<summary>Riwayat (<?php echo esc_html( (string) count( $log ) ); ?>)</summary>
												<ul class="penalis-history__list">
													<?php foreach ( $entries as $entry ) : ?>
														<li class="penalis-history__item">
															<span class="penalis-history__time"><?php echo esc_html( date_i18n( 'j M Y H:i', (int) $entry['time'] ) ); ?></span>
															<span class="penalis-history__flow">
																<?php
																echo esc_html(
																	sprintf(
																		'%1$s → %2$s',
																		self::status_short_label( (string) $entry['from'] ),
																		self::status_short_label( (string) $entry['to'] )
																	)
																);
																?>
															</span>
															<span class="penalis-history__reason"><?php echo esc_html( self::reason_label( (string) $entry['reason'] ) ); ?></span>
														</li>
													<?php endforeach; ?>
												</ul>
											</details>
										<?php endif; ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
				</table>
			</div>

			<?php
			$pages = (int) ceil( $total / $per_page );
			if ( $pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'current'   => $paged,
							'total'     => $pages,
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
						)
					)
				);
				echo '</div></div>';
			}
			?>

			<div class="penalis-panel">
				<h2>Foto profil bawaan</h2>
				<p class="description">
					Dipakai untuk penulis yang belum memilih foto sendiri. Gambar ini disimpan sebagai
					foto profil bawaan situs.
					<?php if ( Penalis_Avatar::is_supported() ) : ?>
						Plugin One User Avatar aktif dan mengelola pengaturan yang sama.
					<?php endif; ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="penalis_save_default_avatar" />
					<?php wp_nonce_field( 'penalis_default_avatar' ); ?>
					<input type="hidden" name="penalis_default_avatar_id" id="penalis-default-avatar-id" value="<?php echo esc_attr( (string) $default_avatar_id ); ?>" />
					<div class="penalis-panel__body">
						<img
							id="penalis-default-avatar-preview"
							class="penalis-panel__preview"
							src="<?php echo esc_url( $default_avatar_url ); ?>"
							alt=""
							<?php echo '' === $default_avatar_url ? 'style="display:none;"' : ''; ?>
						/>
						<div class="penalis-panel__controls">
							<button type="button" class="button" id="penalis-default-avatar-choose">Pilih gambar</button>
							<button type="submit" class="button button-primary">Simpan</button>
							<?php if ( $default_avatar_id > 0 ) : ?>
								<button type="submit" class="button penalis-button--danger" name="penalis_clear_default_avatar" value="1">Hapus foto bawaan</button>
							<?php endif; ?>
						</div>
					</div>
				</form>
			</div>

			<div id="penalis-writer-confirm-modal" class="penalis-confirm-modal" hidden>
				<div class="penalis-confirm-modal__backdrop" data-penalis-writer-confirm-close></div>
				<div
					class="penalis-confirm-modal__dialog"
					role="dialog"
					aria-modal="true"
					aria-labelledby="penalis-writer-confirm-title"
					aria-describedby="penalis-writer-confirm-desc"
				>
					<div class="penalis-confirm-modal__head">
						<span id="penalis-writer-confirm-badge" class="penalis-badge">Konfirmasi</span>
						<h4 id="penalis-writer-confirm-title" class="penalis-confirm-modal__title">Konfirmasi Tindakan Akun</h4>
					</div>
					<p id="penalis-writer-confirm-desc" class="penalis-confirm-modal__desc"></p>
					<div id="penalis-writer-confirm-account" class="penalis-confirm-modal__feedback">
						<span class="penalis-confirm-modal__feedback-label">Akun penulis:</span>
						<div id="penalis-writer-confirm-account-text" class="penalis-confirm-modal__feedback-text"></div>
					</div>
					<div class="penalis-confirm-modal__actions">
						<button type="button" id="penalis-writer-confirm-cancel" class="button">
							Batal
						</button>
						<button type="button" id="penalis-writer-confirm-submit" class="button button-primary">
							Ya, Lanjutkan
						</button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Count accounts per status for the filter tabs.
	 *
	 * Six small queries on an admin screen is cheaper than loading every user
	 * just to count them, and the numbers stay correct without a cache.
	 *
	 * @return array<string,int>
	 */
	private static function status_counts() {
		$counts = array( '' => 0 );

		foreach ( Penalis_Policy::statuses() as $status ) {
			$counts[ $status ] = Penalis_Account_Status::count_by_status( $status );
			$counts['']       += $counts[ $status ];
		}

		$legacy = new WP_User_Query(
			array(
				'fields'      => 'ID',
				'number'      => 1,
				'count_total' => true,
				'role'        => 'contributor',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'     => Penalis_Account_Status::META_STATUS,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => Penalis_Account_Status::META_ACCESS,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$counts['legacy'] = (int) $legacy->get_total();

		return $counts;
	}

	/**
	 * Short status label for the audit log.
	 *
	 * The full labels are written for writers; the log needs something that fits
	 * on one line.
	 *
	 * @param string $status Status value.
	 * @return string
	 */
	private static function status_short_label( $status ) {
		$labels = array(
			''                                          => 'Baru',
			Penalis_Policy::STATUS_PENDING_VERIFICATION => 'Menunggu verifikasi',
			Penalis_Policy::STATUS_ACTIVE               => 'Aktif',
			Penalis_Policy::STATUS_SUSPENDED            => 'Ditangguhkan',
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * Human label for an audit log reason code.
	 *
	 * @param string $reason Reason code.
	 * @return string
	 */
	private static function reason_label( $reason ) {
		$labels = array(
			'registration'          => 'Pendaftaran mandiri',
			'email_verified'        => 'Email diverifikasi',
			'legacy_self_activated' => 'Aktivasi mandiri akun lama',
			'legacy_converted'      => 'Konversi akun lama',
			'admin_vouched'         => 'Diaktifkan langsung admin',
			'admin_reactivated'     => 'Diaktifkan kembali admin',
			'admin_suspended'       => 'Ditangguhkan admin',
		);

		return isset( $labels[ $reason ] ) ? $labels[ $reason ] : ( '' === $reason ? 'Tanpa keterangan' : $reason );
	}

	/**
	 * Render a nonce-protected action button.
	 *
	 * @param int    $user_id User ID.
	 * @param string $action  Action.
	 * @param string $label   Label.
	 * @param bool   $danger  Whether the action is destructive.
	 * @param bool   $primary Whether the action is the recommended next step.
	 * @return void
	 */
	private static function action_button( $user_id, $action, $label, $danger = false, $primary = false ) {
		$classes = 'button';

		if ( $danger ) {
			$classes .= ' penalis-button--danger';
		} elseif ( $primary ) {
			$classes .= ' button-primary';
		} else {
			$classes .= ' button-secondary';
		}

		$user         = get_userdata( (int) $user_id );
		$display_name = $user ? (string) $user->display_name : '';
		$user_login   = $user ? (string) $user->user_login : '';
		$user_email   = $user ? (string) $user->user_email : '';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="penalis_account_action">
			<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user_id ); ?>">
			<input type="hidden" name="penalis_action" value="<?php echo esc_attr( $action ); ?>">
			<?php wp_nonce_field( 'penalis_account_' . $action . '_' . $user_id ); ?>
			<button
				type="submit"
				class="<?php echo esc_attr( $classes ); ?>"
				data-penalis-writer-action="<?php echo esc_attr( $action ); ?>"
				data-penalis-writer-name="<?php echo esc_attr( $display_name ); ?>"
				data-penalis-writer-login="<?php echo esc_attr( $user_login ); ?>"
				data-penalis-writer-email="<?php echo esc_attr( $user_email ); ?>"
			>
				<?php echo esc_html( $label ); ?>
			</button>
		</form>
		<?php
	}

	/**
	 * Handle administrator actions.
	 *
	 * @return void
	 */
	public static function handle_account_action() {
		if ( ! current_user_can( 'list_users' ) ) {
			wp_die( 'Kamu tidak memiliki izin untuk melakukan tindakan ini.' );
		}

		$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		$action  = isset( $_POST['penalis_action'] ) ? sanitize_key( wp_unslash( $_POST['penalis_action'] ) ) : '';

		check_admin_referer( 'penalis_account_' . $action . '_' . $user_id );

		if ( $user_id <= 0 ) {
			self::redirect( 'invalid_user' );
		}

		$allowed_actions = array(
			'suspend',
			'reactivate',
			'resend_verification',
			'legacy_send_activation',
			'legacy_vouch',
		);
		if ( ! in_array( $action, $allowed_actions, true ) ) {
			self::redirect( 'invalid_action' );
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			self::redirect( 'invalid_user' );
		}

		// Never allow an administrator to be suspended here.
		if ( user_can( $user, 'manage_options' ) ) {
			self::redirect( 'protected_account' );
		}

		$actor = get_current_user_id();

		switch ( $action ) {
			case 'reactivate':
				$result = Penalis_Account_Status::transition( $user_id, Penalis_Policy::STATUS_ACTIVE, 'admin_reactivated', $actor );
				break;

			case 'suspend':
				$result = Penalis_Account_Status::transition( $user_id, Penalis_Policy::STATUS_SUSPENDED, 'admin_suspended', $actor );
				break;

			case 'resend_verification':
				$result = self::resend_verification( $user_id );
				break;

			case 'legacy_send_activation':
				$result = self::legacy_send_activation( $user_id );
				break;

			case 'legacy_vouch':
				$result = Penalis_Account_Status::convert_legacy(
					$user_id,
					Penalis_Policy::STATUS_ACTIVE,
					'admin_vouched',
					$actor,
					true
				);

				// The account still has no password. Send a reset link so the
				// owner can set one; without it they could not sign in at all.
				if ( ! is_wp_error( $result ) ) {
					$key = get_password_reset_key( $user );
					if ( ! is_wp_error( $key ) ) {
						Penalis_Emails::send_password_reset( $user_id, $key );
					}
				}
				break;

			default:
				$result = new WP_Error( 'penalis_invalid_action', 'Tindakan tidak valid.', array( 'status' => 400 ) );
		}

		if ( is_wp_error( $result ) ) {
			self::redirect( 'error', $result->get_error_code() );
		}

		$new_status = Penalis_Account_Status::get_status( $user_id );

		if ( in_array( $action, array( 'reactivate', 'suspend' ), true ) ) {
			Penalis_Emails::send_status_notification( $user_id, $new_status );
		}

		self::redirect( 'success', 'done' );
	}

	/**
	 * Send an activation code for a legacy account.
	 *
	 * @param int $user_id User ID.
	 * @return true|WP_Error
	 */
	private static function legacy_send_activation( $user_id ) {
		if ( ! Penalis_Account_Status::is_legacy_account( $user_id ) ) {
			return new WP_Error( 'penalis_not_legacy', 'Akun ini bukan akun kontributor lama.', array( 'status' => 409 ) );
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return new WP_Error( 'penalis_user_not_found', 'Akun tidak ditemukan.', array( 'status' => 404 ) );
		}

		Penalis_Otp::unlock( $user_id );
		$challenge = Penalis_Otp::issue( 'legacy', $user_id, $user->user_email, false );

		if ( is_wp_error( $challenge ) ) {
			return $challenge;
		}

		return true;
	}

	/**
	 * Resend a verification email for an administrator.
	 *
	 * @param int $user_id User ID.
	 * @return true|WP_Error
	 */
	private static function resend_verification( $user_id ) {
		$status = Penalis_Account_Status::get_status( $user_id );

		if ( Penalis_Policy::STATUS_PENDING_VERIFICATION !== $status ) {
			return new WP_Error( 'penalis_invalid_transition', 'Akun ini tidak sedang menunggu verifikasi.', array( 'status' => 409 ) );
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return new WP_Error( 'penalis_user_not_found', 'Akun tidak ditemukan.', array( 'status' => 404 ) );
		}

		Penalis_Otp::unlock( $user_id );
		$challenge = Penalis_Otp::issue( 'registration', $user_id, $user->user_email, false );

		if ( is_wp_error( $challenge ) ) {
			return $challenge;
		}

		return true;
	}

	/**
	 * Redirect back to the management page.
	 *
	 * @param string $type  Message type.
	 * @param string $value Message key.
	 * @return void
	 */
	private static function redirect( $type, $value = '1' ) {
		$args = array();

		if ( 'success' === $type ) {
			$args['penalis_message'] = $value;
		} else {
			$args['penalis_error'] = $value;
		}

		wp_safe_redirect( self::writers_url( $args ) );
		exit;
	}

	/**
	 * Map message keys to human text.
	 *
	 * @param string $key Message key.
	 * @return string
	 */
	private static function message_for( $key ) {
		$messages = array(
			'done'                       => 'Status akun telah diperbarui.',
			'error'                      => 'Status akun tidak dapat diperbarui.',
			'invalid_user'               => 'Akun yang dipilih tidak ditemukan.',
			'invalid_action'             => 'Tindakan tersebut tidak diizinkan.',
			'protected_account'          => 'Akun administrator tidak dapat diubah di sini.',
			'penalis_invalid_transition' => 'Perubahan status tersebut tidak diizinkan untuk akun ini.',
			'penalis_email_failed'       => 'Email verifikasi tidak dapat dikirim. Status akun tidak diubah.',
			'penalis_rate_limited'       => 'Terlalu banyak permintaan kode. Silakan coba lagi nanti.',
			'penalis_not_legacy'         => 'Akun ini bukan akun kontributor lama yang dapat diaktifkan.',
			'penalis_role_conflict'      => 'Akun ini memiliki peran yang tidak dapat disetujui melalui alur kerja penulis.',
			'avatar_saved'               => 'Foto profil bawaan telah diperbarui.',
			'avatar_invalid'             => 'Pilih gambar yang valid untuk foto profil bawaan.',
		);

		return isset( $messages[ $key ] ) ? $messages[ $key ] : 'Permintaan telah diselesaikan.';
	}

	/**
	 * URL of the unified Penalis Platform overview page.
	 *
	 * @return string
	 */
	public static function overview_url() {
		return add_query_arg(
			array(
				'page' => self::MENU_SLUG,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * URL of the Penalis Writers management page.
	 *
	 * @param array<string,mixed> $args Optional query arguments.
	 * @return string
	 */
	public static function writers_url( $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::PAGE_SLUG,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Recent managed writer accounts ordered by registration date.
	 *
	 * @param int $limit Max items.
	 * @return WP_User[]
	 */
	private static function recent_registered_writers( $limit = 5 ) {
		$query = new WP_User_Query(
			array(
				'number'     => max( 1, (int) $limit ),
				'fields'     => 'all',
				'orderby'    => 'registered',
				'order'      => 'DESC',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => Penalis_Account_Status::META_STATUS,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$users = $query->get_results();

		return is_array( $users ) ? $users : array();
	}
}
