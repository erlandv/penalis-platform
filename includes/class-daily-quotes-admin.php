<?php
/**
 * WordPress admin moderation screen for #KalimatHariIni.
 *
 * Registers the `Penalis → Kalimat Hari Ini` submenu accessible to
 * administrators and editors (`edit_others_posts`) so Senator staff can search,
 * paginate, and remove violating 24-hour writer quotes inside wp-admin.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the #KalimatHariIni moderation console under the Penalis menu.
 */
final class Penalis_Daily_Quotes_Admin {

	public const PAGE_SLUG = 'penalis-quotes';
	public const PER_PAGE  = 20;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 11 );
		add_action( 'admin_post_penalis_delete_quote_action', array( __CLASS__, 'handle_delete_action' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Register the Kalimat Hari Ini submenu under Penalis.
	 *
	 * @return void
	 */
	public static function register_menu() {
		$active_count = self::active_count();
		$menu_title   = Penalis_Admin::format_menu_label( 'Kalimat Hari Ini', $active_count );

		add_submenu_page(
			Penalis_Admin::MENU_SLUG,
			'Kalimat Hari Ini — Penalis Platform',
			$menu_title,
			Penalis_Admin::CAP_ACCESS,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue the shared admin stylesheet and confirmation dialog script.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( $hook ) {
		if ( ! Penalis_Admin::is_penalis_screen( $hook ) ) {
			return;
		}

		Penalis_Admin::enqueue_stylesheet();
		Penalis_Admin::enqueue_confirmation_script();
	}

	/**
	 * Count of currently active #KalimatHariIni entries.
	 *
	 * @return int
	 */
	public static function active_count() {
		$result = Penalis_Daily_Quotes::query_quotes(
			array(
				'page'     => 1,
				'per_page' => 1,
			)
		);

		return isset( $result['totalAll'] ) ? max( 0, (int) $result['totalAll'] ) : 0;
	}

	/**
	 * Build a URL to this moderation screen with optional query arguments.
	 *
	 * @param array<string,mixed> $args Optional query args.
	 * @return string
	 */
	public static function page_url( $args = array() ) {
		$base = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		return empty( $args ) ? $base : add_query_arg( $args, $base );
	}

	/**
	 * Handle a moderator delete form submission from wp-admin.
	 *
	 * @return void
	 */
	public static function handle_delete_action() {
		if ( ! Penalis_Editorial_Workflow::can_review( get_current_user_id() ) ) {
			wp_die( 'Kamu tidak memiliki izin untuk memoderasi #KalimatHariIni.' );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified via check_admin_referer() right below.
		$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		$search  = isset( $_POST['s'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['s'] ) ) ) : '';
		$sort    = isset( $_POST['sort'] ) ? sanitize_key( wp_unslash( $_POST['sort'] ) ) : 'newest';
		$paged   = isset( $_POST['paged'] ) ? max( 1, (int) $_POST['paged'] ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		check_admin_referer( 'penalis_delete_quote_' . $user_id );

		$redirect_args = array();
		if ( '' !== $search ) {
			$redirect_args['s'] = $search;
		}
		if ( 'oldest' === $sort ) {
			$redirect_args['sort'] = 'oldest';
		}
		if ( $paged > 1 ) {
			$redirect_args['paged'] = $paged;
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User ) {
			$redirect_args['penalis_quote_error'] = 'not_found';
			wp_safe_redirect( self::page_url( $redirect_args ) );
			exit;
		}

		$result = Penalis_Daily_Quotes::delete_for_user( $user_id );
		if ( is_wp_error( $result ) ) {
			$redirect_args['penalis_quote_error'] = 'failed';
			wp_safe_redirect( self::page_url( $redirect_args ) );
			exit;
		}

		$redirect_args['penalis_quote_notice'] = 'deleted';
		wp_safe_redirect( self::page_url( $redirect_args ) );
		exit;
	}

	/**
	 * Render the wp-admin #KalimatHariIni moderation screen.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! Penalis_Editorial_Workflow::can_review( get_current_user_id() ) ) {
			wp_die( 'Kamu tidak memiliki izin untuk memoderasi #KalimatHariIni.' );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filter & notice params.
		$search = isset( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : '';
		$sort   = isset( $_GET['sort'] ) && 'oldest' === sanitize_key( wp_unslash( $_GET['sort'] ) ) ? 'oldest' : 'newest';
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$notice = isset( $_GET['penalis_quote_notice'] ) ? sanitize_key( wp_unslash( $_GET['penalis_quote_notice'] ) ) : '';
		$error  = isset( $_GET['penalis_quote_error'] ) ? sanitize_key( wp_unslash( $_GET['penalis_quote_error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$result = Penalis_Daily_Quotes::query_quotes(
			array(
				'search'   => $search,
				'sort'     => $sort,
				'page'     => $paged,
				'per_page' => self::PER_PAGE,
			)
		);

		$items       = is_array( $result['items'] ) ? $result['items'] : array();
		$total       = (int) $result['total'];
		$total_all   = (int) $result['totalAll'];
		$total_pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$public_url  = Penalis_Daily_Quotes::page_url();

		if ( $paged > $total_pages && $total > 0 ) {
			$paged  = $total_pages;
			$result = Penalis_Daily_Quotes::query_quotes(
				array(
					'search'   => $search,
					'sort'     => $sort,
					'page'     => $paged,
					'per_page' => self::PER_PAGE,
				)
			);
			$items  = is_array( $result['items'] ) ? $result['items'] : array();
		}
		?>
		<div class="wrap penalis-review penalis-quotes-admin">
			<h1 class="wp-heading-inline">Kalimat Hari Ini</h1>
			<a class="page-title-action" href="<?php echo esc_url( $public_url ); ?>" target="_blank" rel="noopener noreferrer">
				Lihat halaman publik
			</a>
			<hr class="wp-header-end">

			<?php if ( 'deleted' === $notice ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>#KalimatHariIni berhasil dihapus dari profil penulis dan halaman publik.</p>
				</div>
			<?php endif; ?>

			<?php if ( '' !== $error ) : ?>
				<div class="notice notice-error is-dismissible">
					<p>
						<?php
						echo esc_html(
							'not_found' === $error
								? 'Penulis tidak ditemukan.'
								: '#KalimatHariIni tidak dapat dihapus. Silakan coba lagi.'
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<p class="penalis-review__intro">
				Daftar <strong>#KalimatHariIni</strong> yang sedang aktif dalam 24 jam terakhir
				(<strong><?php echo esc_html( number_format_i18n( $total_all ) ); ?></strong> aktif).
				Gunakan tombol <strong>Hapus</strong> untuk menurunkan kalimat yang melanggar norma komunitas.
			</p>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="penalis-quotes-admin__filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<label class="screen-reader-text" for="penalis-quotes-search">Cari kalimat atau penulis</label>
				<input
					type="search"
					id="penalis-quotes-search"
					name="s"
					value="<?php echo esc_attr( $search ); ?>"
					placeholder="Cari kalimat atau nama penulis…"
				>
				<label class="screen-reader-text" for="penalis-quotes-sort">Urutkan</label>
				<select id="penalis-quotes-sort" name="sort">
					<option value="newest" <?php selected( $sort, 'newest' ); ?>>Terbaru</option>
					<option value="oldest" <?php selected( $sort, 'oldest' ); ?>>Terlama</option>
				</select>
				<button type="submit" class="button">Terapkan</button>
				<?php if ( '' !== $search || 'oldest' === $sort ) : ?>
					<a class="button" href="<?php echo esc_url( self::page_url() ); ?>">Reset</a>
				<?php endif; ?>
			</form>

			<table class="wp-list-table widefat fixed striped penalis-review__table penalis-quotes-admin__table">
				<thead>
					<tr>
						<th scope="col" style="width:24%">Penulis</th>
						<th scope="col">Kalimat</th>
						<th scope="col" style="width:16%">Tayang</th>
						<th scope="col" style="width:16%">Berakhir</th>
						<th scope="col" style="width:11%">Tindakan</th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $items ) ) : ?>
					<tr>
						<td colspan="5">
							<p class="penalis-review__empty">
								<?php
								echo esc_html(
									'' !== $search
										? 'Tidak ada #KalimatHariIni aktif yang cocok dengan pencarian tersebut.'
										: 'Belum ada #KalimatHariIni yang aktif dalam 24 jam terakhir.'
								);
								?>
							</p>
						</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $items as $item ) : ?>
						<?php
						$author_id    = (int) $item['authorId'];
						$display_name = (string) $item['displayName'];
						$username     = (string) $item['username'];
						$quote_text   = (string) $item['text'];
						$created_at   = (int) $item['createdAt'];
						$expires_at   = (int) $item['expiresAt'];
						?>
						<tr>
							<td>
								<div class="penalis-quotes-admin__author">
									<img
										src="<?php echo esc_url( (string) $item['avatarUrl'] ); ?>"
										alt=""
										width="34"
										height="34"
										class="penalis-quotes-admin__avatar"
									>
									<div class="penalis-quotes-admin__identity">
										<strong>
											<a href="<?php echo esc_url( (string) $item['authorUrl'] ); ?>" target="_blank" rel="noopener noreferrer">
												<?php echo esc_html( $display_name ); ?>
											</a>
										</strong>
										<span class="penalis-review__meta">@<?php echo esc_html( $username ); ?></span>
									</div>
								</div>
							</td>
							<td>
								<p class="penalis-quotes-admin__text"><?php echo nl2br( esc_html( $quote_text ) ); ?></p>
								<span class="penalis-quotes-admin__hashtag">#KalimatHariIni</span>
							</td>
							<td>
								<span><?php echo esc_html( Penalis_Datetime::format( $created_at, 'j M Y H:i' ) ); ?></span>
								<span class="penalis-review__meta"><?php echo esc_html( Penalis_Daily_Quotes::format_relative_time( $created_at ) ); ?></span>
							</td>
							<td>
								<span><?php echo esc_html( Penalis_Datetime::format( $expires_at, 'j M Y H:i' ) ); ?></span>
							</td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="penalis_delete_quote_action">
									<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $author_id ); ?>">
									<input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>">
									<input type="hidden" name="sort" value="<?php echo esc_attr( $sort ); ?>">
									<input type="hidden" name="paged" value="<?php echo esc_attr( (string) $paged ); ?>">
									<?php wp_nonce_field( 'penalis_delete_quote_' . $author_id ); ?>
									<button
										type="submit"
										class="button button-small button-link-delete"
										data-penalis-delete-quote
										data-penalis-quote-author="<?php echo esc_attr( $display_name . ' (@' . $username . ')' ); ?>"
										data-penalis-quote-text="<?php echo esc_attr( $quote_text ); ?>"
									>
										Hapus
									</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<?php
				$pagination_base = self::page_url(
					array_filter(
						array(
							's'     => $search,
							'sort'  => 'oldest' === $sort ? 'oldest' : '',
							'paged' => '%#%',
						)
					)
				);
				$page_links      = paginate_links(
					array(
						'base'      => $pagination_base,
						'format'    => '',
						'current'   => $paged,
						'total'     => $total_pages,
						'prev_text' => '&lsaquo; Sebelumnya',
						'next_text' => 'Berikutnya &rsaquo;',
					)
				);
				?>
				<?php if ( is_string( $page_links ) && '' !== $page_links ) : ?>
					<div class="tablenav bottom">
						<div class="tablenav-pages">
							<span class="displaying-num">
								<?php echo esc_html( sprintf( '%s kalimat', number_format_i18n( $total ) ) ); ?>
							</span>
							<span class="pagination-links">
								<?php echo wp_kses_post( $page_links ); ?>
							</span>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<?php
			Penalis_Admin::render_confirm_modal(
				'penalis-quote-confirm',
				'Hapus #KalimatHariIni ini?',
				'Kalimat yang akan dihapus:',
				'penalis-quote-confirm-context-box',
				'penalis-quote-confirm-context-text'
			);
			?>
		</div>
		<?php
	}
}
