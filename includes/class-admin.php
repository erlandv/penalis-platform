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
 * Adds a Penalis Writers screen under Users.
 */
final class Penalis_Admin {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_penalis_account_action', array( __CLASS__, 'handle_account_action' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_filter( 'manage_users_columns', array( __CLASS__, 'add_status_column' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'render_status_column' ), 10, 3 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_styles' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_scripts' ) );
		add_action( 'admin_post_penalis_save_default_avatar', array( __CLASS__, 'save_default_avatar' ) );
	}

	/**
	 * Register the menu.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_users_page(
			'Penulis Penalis',
			'Penulis Penalis',
			'list_users',
			'penalis-writers',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the admin stylesheet.
	 *
	 * The status pill is also used by the Users list column, so the stylesheet
	 * loads on both screens. Everything else is scoped to `.penalis-writers` so
	 * no other admin screen inherits the layout rules.
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public static function admin_styles( $hook ) {
		if ( ! in_array( $hook, array( 'users.php', 'users_page_penalis-writers' ), true ) ) {
			return;
		}

		wp_register_style( 'penalis-admin', false, array(), PENALIS_PLATFORM_VERSION );
		wp_enqueue_style( 'penalis-admin' );
		wp_add_inline_style( 'penalis-admin', self::admin_css() );
	}

	/**
	 * Stylesheet contents.
	 *
	 * Kept in one place so the status pill, the table, and the panels stay
	 * visually consistent. Colours come from the WordPress admin palette.
	 *
	 * @return string
	 */
	private static function admin_css() {
		return <<<'CSS'
.penalis-status{display:inline-flex;align-items:center;gap:6px;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:600;letter-spacing:.02em;line-height:1.7;white-space:nowrap}
.penalis-status__dot{width:6px;height:6px;border-radius:50%;background:currentColor;flex:0 0 6px}
.penalis-status--active{background:#edfaef;color:#007017}
.penalis-status--pending_verification,.penalis-status--pending_approval{background:#fcf9e8;color:#8a6116}
.penalis-status--rejected,.penalis-status--suspended{background:#fcf0f1;color:#8a2424}
.penalis-status--legacy{background:#f0f0f1;color:#50575e}
.penalis-writers .penalis-intro{margin:2px 0 18px;max-width:72ch;color:#50575e;font-size:13px}
.penalis-writers .subsubsub{float:none;clear:both;margin:0 0 14px;font-size:13px}
.penalis-writers .subsubsub li:not(:last-child)::after{content:"|";margin:0 6px;color:#c3c4c7}
.penalis-writers .penalis-toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:8px;clear:both;margin:0 0 12px;padding:10px 12px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;box-shadow:0 1px 1px rgba(0,0,0,.04)}
.penalis-writers .penalis-toolbar input[type="search"]{flex:1 1 240px;max-width:340px;margin:0}
.penalis-writers .penalis-toolbar .button{margin:0}
.penalis-writers .penalis-toolbar__count{margin-left:auto;color:#646970;font-size:12px}
.penalis-writers .penalis-table{margin:0}
.penalis-writers .penalis-table-wrap{overflow-x:auto}
.penalis-writers .penalis-table thead th{padding:10px;font-weight:600;color:#1d2327}
.penalis-writers .penalis-table tbody td{padding:14px 10px;vertical-align:top}
.penalis-writers .penalis-col-writer{width:22%}
.penalis-writers .penalis-col-email{width:18%}
.penalis-writers .penalis-col-status{width:17%}
.penalis-writers .penalis-col-date{width:12%}
.penalis-writers .penalis-col-actions{width:31%}
.penalis-writers .penalis-writer{display:flex;align-items:center;gap:10px}
.penalis-writers .penalis-writer__avatar{display:block;width:36px;height:36px;border-radius:50%;flex:0 0 36px;background:#f0f0f1}
.penalis-writers .penalis-writer__name{display:block;color:#1d2327;font-size:13px;line-height:1.4}
.penalis-writers .penalis-writer__login{display:block;color:#646970;font-size:12px;line-height:1.4;word-break:break-word}
.penalis-writers .penalis-flags{display:flex;flex-wrap:wrap;gap:4px;margin-top:8px}
.penalis-writers .penalis-flag{display:inline-block;padding:1px 7px;border-radius:3px;background:#f0f0f1;color:#50575e;font-size:11px;line-height:1.7}
.penalis-writers .penalis-flag--warn{background:#fcf0f1;color:#8a2424}
.penalis-writers .penalis-flag--ok{background:#edfaef;color:#007017}
.penalis-writers .penalis-date{display:block;color:#1d2327;font-size:13px;line-height:1.4}
.penalis-writers .penalis-muted{display:block;color:#646970;font-size:12px;line-height:1.4}
.penalis-writers .penalis-actions{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 8px}
.penalis-writers .penalis-actions form{margin:0}
.penalis-writers .penalis-actions .button{margin:0}
.penalis-writers .penalis-button--danger{color:#b32d2e;border-color:#b32d2e}
.penalis-writers .button.penalis-button--danger:hover{background:#b32d2e;border-color:#b32d2e;color:#fff}
.penalis-writers .button.penalis-button--danger:focus{border-color:#b32d2e;color:#b32d2e;box-shadow:0 0 0 1px #b32d2e}
.penalis-writers .penalis-hint{margin:0;color:#646970;font-size:12px;line-height:1.5}
.penalis-writers .penalis-history{margin-top:4px;font-size:12px;color:#50575e}
.penalis-writers .penalis-history summary{cursor:pointer;color:#2271b1;font-weight:500;padding:2px 0}
.penalis-writers .penalis-history summary:hover{color:#135e96}
.penalis-writers .penalis-history summary:focus{outline:2px solid #2271b1;outline-offset:1px}
.penalis-writers .penalis-history__list{margin:8px 0 0;padding:0 0 0 10px;list-style:none;border-left:2px solid #f0f0f1}
.penalis-writers .penalis-history__item{padding:3px 0;line-height:1.5}
.penalis-writers .penalis-history__time{display:block;color:#8c8f94;font-size:11px}
.penalis-writers .penalis-history__flow{display:block;color:#1d2327}
.penalis-writers .penalis-history__reason{display:block;color:#646970}
.penalis-writers .penalis-empty__inner{padding:36px 16px;text-align:center}
.penalis-writers .penalis-empty__title{margin:0 0 4px;font-size:14px;font-weight:600;color:#1d2327}
.penalis-writers .penalis-empty__text{margin:0;color:#646970}
.penalis-writers .penalis-panel{max-width:760px;margin:24px 0 0;padding:16px 20px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;box-shadow:0 1px 1px rgba(0,0,0,.04)}
.penalis-writers .penalis-panel h2{margin:0 0 4px;font-size:14px}
.penalis-writers .penalis-panel__body{display:flex;flex-wrap:wrap;align-items:center;gap:16px;margin-top:14px}
.penalis-writers .penalis-panel__preview{width:72px;height:72px;border-radius:50%;object-fit:cover;background:#f0f0f1;flex:0 0 72px}
.penalis-writers .penalis-panel__controls{display:flex;flex-wrap:wrap;align-items:center;gap:8px}
.penalis-writers .penalis-panel__controls .button{margin:0}
@media screen and (max-width:782px){
.penalis-writers .penalis-table{min-width:720px}
.penalis-writers .penalis-toolbar__count{margin-left:0}
}
CSS;
	}

	/**
	 * Enqueue the media picker used for the default profile photo.
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public static function admin_scripts( $hook ) {
		if ( 'users_page_penalis-writers' !== $hook ) {
			return;
		}

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

		$redirect = admin_url( 'users.php?page=penalis-writers' );
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
			Penalis_Policy::STATUS_PENDING_APPROVAL     => 'Menunggu persetujuan',
			Penalis_Policy::STATUS_PENDING_VERIFICATION => 'Menunggu verifikasi',
			Penalis_Policy::STATUS_ACTIVE               => 'Aktif',
			Penalis_Policy::STATUS_REJECTED             => 'Ditolak',
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
				Tinjau pendaftaran penulis di sini. Menyetujui akun akan mengaktifkan akses dasbor dan
				peran penulis; menolak atau menangguhkan akan mencabutnya. Setiap perubahan tercatat
				pada riwayat akun.
			</p>

			<ul class="subsubsub">
				<?php foreach ( $tabs as $value => $label ) : ?>
					<?php
					$tab_url    = add_query_arg(
						array(
							'page'   => 'penalis-writers',
							'status' => $value,
						),
						admin_url( 'users.php' )
					);
					$is_current = $status === $value;
					if ( '' !== $search ) {
						$tab_url = add_query_arg( 's', $search, $tab_url );
					}
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
				<input type="hidden" name="page" value="penalis-writers">
				<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
				<label for="penalis-search" class="screen-reader-text">Cari penulis</label>
				<input
					type="search"
					id="penalis-search"
					name="s"
					value="<?php echo esc_attr( $search ); ?>"
					placeholder="Cari nama, nama pengguna, atau email"
				>
				<button type="submit" class="button">Cari</button>
				<?php
				$clear_url = add_query_arg(
					array(
						'page'   => 'penalis-writers',
						'status' => $status,
					),
					admin_url( 'users.php' )
				);
				?>
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
							<th scope="col" class="penalis-col-status">Status</th>
							<th scope="col" class="penalis-col-date">Terdaftar</th>
							<th scope="col" class="penalis-col-actions">Tindakan</th>
						</tr>
					</thead>
					<tbody>
					<?php if ( empty( $users ) ) : ?>
						<tr>
							<td colspan="5">
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
							$can_approve    = Penalis_Policy::can_transition( $user_status, Penalis_Policy::STATUS_ACTIVE );
							$can_reject     = Penalis_Policy::can_transition( $user_status, Penalis_Policy::STATUS_REJECTED );
							$can_suspend    = Penalis_Policy::can_transition( $user_status, Penalis_Policy::STATUS_SUSPENDED );
							$can_reactivate = Penalis_Policy::can_transition( $user_status, Penalis_Policy::STATUS_ACTIVE );
							$can_reopen     = Penalis_Policy::can_transition( $user_status, Penalis_Policy::STATUS_PENDING_APPROVAL );
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
									<span class="penalis-status penalis-status--<?php echo esc_attr( '' === $user_status ? 'legacy' : $user_status ); ?>">
										<span class="penalis-status__dot" aria-hidden="true"></span>
										<?php echo esc_html( '' === $user_status ? 'Akun lama' : Penalis_Policy::status_label( $user_status ) ); ?>
									</span>
									<?php if ( $access || $needs_password || $mail_failed ) : ?>
										<div class="penalis-flags">
											<?php if ( $access ) : ?>
												<span class="penalis-flag penalis-flag--ok">Akses dasbor aktif</span>
											<?php endif; ?>
											<?php if ( $needs_password ) : ?>
												<span class="penalis-flag">Belum punya kata sandi</span>
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
												<?php self::action_button( $user_id, 'legacy_send_activation', 'Kirim tautan aktivasi' ); ?>
												<?php self::action_button( $user_id, 'legacy_vouch', 'Aktifkan langsung', true ); ?>
											<?php endif; ?>

											<?php if ( Penalis_Policy::STATUS_PENDING_VERIFICATION === $user_status ) : ?>
												<?php self::action_button( $user_id, 'resend_verification', 'Kirim ulang verifikasi' ); ?>
											<?php endif; ?>

											<?php if ( $can_approve && Penalis_Policy::STATUS_PENDING_APPROVAL === $user_status ) : ?>
												<?php self::action_button( $user_id, 'approve', 'Setujui', false, true ); ?>
											<?php endif; ?>

											<?php if ( $can_reactivate && Penalis_Policy::STATUS_SUSPENDED === $user_status ) : ?>
												<?php self::action_button( $user_id, 'reactivate', 'Aktifkan kembali', false, true ); ?>
											<?php endif; ?>

											<?php if ( $can_reopen && Penalis_Policy::STATUS_REJECTED === $user_status ) : ?>
												<?php self::action_button( $user_id, 'reopen', 'Buka kembali' ); ?>
											<?php endif; ?>

											<?php if ( $can_reject ) : ?>
												<?php self::action_button( $user_id, 'reject', 'Tolak', true ); ?>
											<?php endif; ?>

											<?php if ( $can_suspend ) : ?>
												<?php self::action_button( $user_id, 'suspend', 'Tangguhkan', true ); ?>
											<?php endif; ?>
										</div>

										<?php if ( $is_legacy_view ) : ?>
											<p class="penalis-hint">
												Akun lama belum punya kata sandi. "Aktifkan langsung" melewati
												verifikasi email, tetapi tetap perlu persetujuan.
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
	 * The full labels are written for writers ("Menunggu persetujuan admin");
	 * the log needs something that fits on one line.
	 *
	 * @param string $status Status value.
	 * @return string
	 */
	private static function status_short_label( $status ) {
		$labels = array(
			''                                          => 'Baru',
			Penalis_Policy::STATUS_PENDING_VERIFICATION => 'Menunggu verifikasi',
			Penalis_Policy::STATUS_PENDING_APPROVAL     => 'Menunggu persetujuan',
			Penalis_Policy::STATUS_ACTIVE               => 'Aktif',
			Penalis_Policy::STATUS_REJECTED             => 'Ditolak',
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
			'admin_approved'        => 'Disetujui admin',
			'admin_reactivated'     => 'Diaktifkan kembali admin',
			'admin_rejected'        => 'Ditolak admin',
			'admin_reopened'        => 'Dibuka kembali admin',
			'admin_suspended'       => 'Ditangguhkan admin',
			'admin_vouched'         => 'Diaktifkan langsung admin',
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
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="penalis_account_action">
			<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user_id ); ?>">
			<input type="hidden" name="penalis_action" value="<?php echo esc_attr( $action ); ?>">
			<?php wp_nonce_field( 'penalis_account_' . $action . '_' . $user_id ); ?>
			<button type="submit" class="<?php echo esc_attr( $classes ); ?>">
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
			'approve',
			'reject',
			'suspend',
			'reactivate',
			'reopen',
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

		// Never allow an administrator to be suspended or rejected here.
		if ( user_can( $user, 'manage_options' ) ) {
			self::redirect( 'protected_account' );
		}

		$actor = get_current_user_id();

		switch ( $action ) {
			case 'approve':
				$result = Penalis_Account_Status::transition( $user_id, Penalis_Policy::STATUS_ACTIVE, 'admin_approved', $actor );
				break;

			case 'reactivate':
				$result = Penalis_Account_Status::transition( $user_id, Penalis_Policy::STATUS_ACTIVE, 'admin_reactivated', $actor );
				break;

			case 'reject':
				$result = Penalis_Account_Status::transition( $user_id, Penalis_Policy::STATUS_REJECTED, 'admin_rejected', $actor );
				break;

			case 'reopen':
				$result = Penalis_Account_Status::transition( $user_id, Penalis_Policy::STATUS_PENDING_APPROVAL, 'admin_reopened', $actor );
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
					Penalis_Policy::STATUS_PENDING_APPROVAL,
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

		if ( in_array( $action, array( 'approve', 'reactivate', 'reject', 'suspend' ), true ) ) {
			Penalis_Emails::send_status_notification( $user_id, $new_status );
		}

		self::redirect( 'success', 'done' );
	}

	/**
	 * Send an activation link for a legacy account.
	 *
	 * @param int $user_id User ID.
	 * @return true|WP_Error
	 */
	private static function legacy_send_activation( $user_id ) {
		if ( ! Penalis_Account_Status::is_legacy_account( $user_id ) ) {
			return new WP_Error( 'penalis_not_legacy', 'Akun ini bukan akun kontributor lama.', array( 'status' => 409 ) );
		}

		$token = Penalis_Account_Status::issue_verification_token( $user_id );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$sent = Penalis_Emails::send_legacy_activation( $user_id, $token );

		if ( ! $sent ) {
			return new WP_Error( 'penalis_email_failed', 'Email aktivasi tidak dapat dikirim.', array( 'status' => 500 ) );
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

		$token = Penalis_Account_Status::issue_verification_token( $user_id );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$sent = Penalis_Emails::send_verification( $user_id, $token );

		if ( ! $sent ) {
			return new WP_Error( 'penalis_email_failed', 'Email verifikasi tidak dapat dikirim.', array( 'status' => 500 ) );
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
		$args = array(
			'page' => 'penalis-writers',
		);

		if ( 'success' === $type ) {
			$args['penalis_message'] = $value;
		} else {
			$args['penalis_error'] = $value;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'users.php' ) ) );
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
			'penalis_not_legacy'         => 'Akun ini bukan akun kontributor lama yang dapat diaktifkan.',
			'penalis_role_conflict'      => 'Akun ini memiliki peran yang tidak dapat disetujui melalui alur kerja penulis.',
			'avatar_saved'               => 'Foto profil bawaan telah diperbarui.',
			'avatar_invalid'             => 'Pilih gambar yang valid untuk foto profil bawaan.',
		);

		return isset( $messages[ $key ] ) ? $messages[ $key ] : 'Permintaan telah diselesaikan.';
	}

	/**
	 * Show a notice when writer applications are waiting.
	 *
	 * @return void
	 */
	public static function admin_notices() {
		if ( ! current_user_can( 'list_users' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'users', 'users_page_penalis-writers' ), true ) ) {
			return;
		}

		if ( 'users_page_penalis-writers' === $screen->id ) {
			return;
		}

		$count = get_transient( 'penalis_pending_approval_count' );

		if ( false === $count ) {
			$count = Penalis_Account_Status::count_by_status( Penalis_Policy::STATUS_PENDING_APPROVAL );
			set_transient( 'penalis_pending_approval_count', (int) $count, 5 * MINUTE_IN_SECONDS );
		}

		$count = (int) $count;

		if ( $count <= 0 ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					'%d pendaftaran penulis menunggu tinjauan.',
					$count
				)
			),
			esc_url( admin_url( 'users.php?page=penalis-writers&status=pending_approval' ) ),
			'Tinjau pendaftaran'
		);
	}
}
