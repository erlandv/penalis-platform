<?php
/**
 * Penalis → Log Email screen.
 *
 * Three jobs, split across two tabs because they are used at different times:
 *
 * - Riwayat pengiriman: did the email leave the site, and why not. This is the
 *   tab the screen opens on — it is what an administrator comes here for.
 * - Kirim percobaan: send a test of any template to any address, and preview
 *   the branded layout in a new tab before a writer ever sees it.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders and handles the email log screen.
 */
final class Penalis_Email_Log_Admin {

	const PAGE_SLUG = 'penalis-email-log';

	/** Rows per page in the log table. */
	const PER_PAGE = 20;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		// Priority 13 puts the submenu after Penulis Penalis (12).
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 13 );
		add_action( 'admin_post_penalis_email_tools', array( __CLASS__, 'handle_tools' ) );
		add_action( 'admin_post_penalis_email_resend', array( __CLASS__, 'handle_resend' ) );
		add_action( 'admin_post_penalis_email_clear_log', array( __CLASS__, 'handle_clear' ) );
	}

	/**
	 * Add the submenu entry.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			Penalis_Admin::MENU_SLUG,
			'Log Email — Penalis Platform',
			'Log Email',
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Tabs on this screen, in display order.
	 *
	 * History first: it is the tab the screen opens on, and the one an
	 * administrator needs when something did not arrive.
	 *
	 * @return array<string,string>
	 */
	public static function tabs() {
		return array(
			'history' => 'Riwayat pengiriman',
			'tools'   => 'Kirim percobaan',
		);
	}

	/**
	 * Currently selected tab, falling back to the first one.
	 *
	 * @return string
	 */
	public static function current_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection.
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$tabs      = self::tabs();

		return isset( $tabs[ $requested ] ) ? $requested : 'history';
	}

	/**
	 * Build a URL for this screen.
	 *
	 * @param array $args Extra query arguments.
	 * @return string
	 */
	public static function page_url( array $args = array() ) {
		$url = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );

		return empty( $args ) ? $url : add_query_arg( $args, $url );
	}

	/**
	 * Send a test email or open a template preview.
	 *
	 * One handler for both because they share a form and a nonce; the submit
	 * button decides which one runs (`tool=send` or `tool=preview`).
	 *
	 * @return void
	 */
	public static function handle_tools() {
		self::require_manager();
		check_admin_referer( 'penalis_email_tools' );

		$tool     = isset( $_POST['tool'] ) ? sanitize_key( wp_unslash( $_POST['tool'] ) ) : '';
		$template = isset( $_POST['template'] ) ? sanitize_key( wp_unslash( $_POST['template'] ) ) : '';
		$registry = Penalis_Email_Settings::get_registry();

		if ( ! isset( $registry[ $template ] ) ) {
			self::redirect( 'invalid_template' );
		}

		$vars = self::sample_variables( $template );

		if ( 'preview' === $tool ) {
			self::render_preview( $template, $vars );
		}

		$recipient = isset( $_POST['recipient'] ) ? sanitize_email( wp_unslash( $_POST['recipient'] ) ) : '';

		if ( ! is_email( $recipient ) ) {
			self::redirect( 'invalid_recipient' );
		}

		$rendered = Penalis_Email_Settings::render( $template, $vars );
		$sent     = Penalis_Emails::send_mail(
			$recipient,
			$rendered['subject'],
			$rendered['body'],
			$rendered['footer'],
			array(
				'template' => $template,
				'vars'     => $vars,
				'test'     => true,
			)
		);

		// Land on the history tab either way: it holds the entry that proves
		// what happened, including the reason when the send failed.
		self::redirect( $sent ? 'test_sent' : 'test_failed', 'history' );
	}

	/**
	 * Repeat a logged delivery with the values it was rendered with.
	 *
	 * @return void
	 */
	public static function handle_resend() {
		self::require_manager();

		$id = isset( $_GET['entry'] ) ? sanitize_text_field( wp_unslash( $_GET['entry'] ) ) : '';

		check_admin_referer( 'penalis_email_resend_' . $id );

		$entry = Penalis_Email_Log::find( $id );

		if ( null === $entry || empty( $entry['resendable'] ) ) {
			self::redirect( 'not_resendable' );
		}

		$rendered = Penalis_Email_Settings::render( (string) $entry['template'], (array) $entry['vars'] );
		$sent     = Penalis_Emails::send_mail(
			(string) $entry['to'],
			$rendered['subject'],
			$rendered['body'],
			$rendered['footer'],
			array(
				'template'  => (string) $entry['template'],
				'vars'      => (array) $entry['vars'],
				'user_id'   => (int) $entry['user_id'],
				'post_id'   => (int) $entry['post_id'],
				'resend_of' => (string) $entry['id'],
			)
		);

		self::redirect( $sent ? 'resent' : 'resend_failed' );
	}

	/**
	 * Empty the log.
	 *
	 * @return void
	 */
	public static function handle_clear() {
		self::require_manager();
		check_admin_referer( 'penalis_email_clear_log' );

		Penalis_Email_Log::clear();

		self::redirect( 'cleared' );
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render_page() {
		self::require_manager();

		$tab   = self::current_tab();
		$tabs  = self::tabs();
		$intro = 'history' === $tab
			? 'Setiap email transaksional yang dikirim Penalis dicatat di sini, lengkap dengan alasan bila pengiriman gagal.'
			: 'Kirim email percobaan ke alamat sendiri untuk memeriksa tampilan dan keterkiriman sebelum penulis menerima email yang sama.';

		?>
		<div class="wrap penalis-email-log">
			<h1 class="wp-heading-inline">Log Email</h1>
			<hr class="wp-header-end">

			<?php self::render_notice(); ?>

			<p class="penalis-intro"><?php echo esc_html( $intro ); ?></p>

			<nav class="nav-tab-wrapper penalis-settings__tabs" aria-label="Bagian log email">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a
						href="<?php echo esc_url( self::page_url( array( 'tab' => $slug ) ) ); ?>"
						class="nav-tab <?php echo $slug === $tab ? 'nav-tab-active' : ''; ?>"
						<?php echo $slug === $tab ? 'aria-current="page"' : ''; ?>
					>
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php
			if ( 'tools' === $tab ) {
				self::render_tools_tab();
			} else {
				self::render_history_tab();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Template group labels matching Penalis_Email_Settings, in display order.
	 *
	 * @return array<string,string>
	 */
	private static function template_groups() {
		return array(
			'verifikasi' => 'Verifikasi & Aktivasi',
			'keamanan'   => 'Keamanan Akun',
			'status'     => 'Status Penulis',
			'kurasi'     => 'Kurasi Karya Tulis',
		);
	}

	/**
	 * Render grouped template `<optgroup>` and `<option>` elements.
	 *
	 * @param array<string,array<string,mixed>> $registry Template definitions.
	 * @param string                            $selected Currently selected template key.
	 * @return void
	 */
	private static function render_template_options( array $registry, $selected = '' ) {
		$groups    = self::template_groups();
		$grouped   = array_fill_keys( array_keys( $groups ), array() );
		$ungrouped = array();

		foreach ( $registry as $key => $meta ) {
			$group = isset( $meta['group'] ) ? (string) $meta['group'] : '';
			if ( isset( $grouped[ $group ] ) ) {
				$grouped[ $group ][ (string) $key ] = $meta;
			} else {
				$ungrouped[ (string) $key ] = $meta;
			}
		}

		foreach ( $groups as $group_slug => $group_label ) :
			if ( empty( $grouped[ $group_slug ] ) ) {
				continue;
			}
			?>
			<optgroup label="<?php echo esc_attr( $group_label ); ?>">
				<?php foreach ( $grouped[ $group_slug ] as $key => $meta ) : ?>
					<option value="<?php echo esc_attr( (string) $key ); ?>" <?php selected( $selected, (string) $key ); ?>>
						<?php echo esc_html( (string) $meta['title'] ); ?>
					</option>
				<?php endforeach; ?>
			</optgroup>
			<?php
		endforeach;

		foreach ( $ungrouped as $key => $meta ) :
			?>
			<option value="<?php echo esc_attr( (string) $key ); ?>" <?php selected( $selected, (string) $key ); ?>>
				<?php echo esc_html( (string) $meta['title'] ); ?>
			</option>
			<?php
		endforeach;
	}

	/**
	 * Render the test-send tab.
	 *
	 * @return void
	 */
	private static function render_tools_tab() {
		$registry = Penalis_Email_Settings::get_registry();
		?>
		<div class="penalis-settings__panel penalis-email-log__panel">
			<h2>Kirim email percobaan</h2>
			<p class="description">
				Pilih template dan alamat tujuan untuk menguji pengiriman email transaksional, atau
				buka pratinjau di tab baru untuk memeriksa tampilan tanpa mengirim email.
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="penalis-email-log__tools">
				<input type="hidden" name="action" value="penalis_email_tools" />
				<?php wp_nonce_field( 'penalis_email_tools' ); ?>

				<table class="form-table penalis-email-log__form-table" role="presentation">
					<tbody>
						<tr class="penalis-email-log__field">
							<th scope="row">
								<label for="penalis-email-template">Template</label>
							</th>
							<td>
								<select
									id="penalis-email-template"
									name="template"
									class="regular-text"
									aria-describedby="penalis-email-template-help"
								>
									<?php self::render_template_options( $registry ); ?>
								</select>
								<p class="description" id="penalis-email-template-help">
									Pilih template email yang ingin dikirim sebagai percobaan atau dilihat pratinjaunya.
								</p>
							</td>
						</tr>

						<tr class="penalis-email-log__field">
							<th scope="row">
								<label for="penalis-email-recipient">Kirim ke</label>
							</th>
							<td>
								<input
									id="penalis-email-recipient"
									type="email"
									name="recipient"
									value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>"
									class="regular-text"
									aria-describedby="penalis-email-recipient-help"
									required
								/>
								<p class="description" id="penalis-email-recipient-help">
									Alamat email tujuan untuk menerima email percobaan. Tidak digunakan saat membuka pratinjau.
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="penalis-settings__note">
					Pesan dirender dengan data contoh, jadi kode OTP dan tautan sekali pakai tidak pernah
					berisi nilai asli. Hasil pengiriman percobaan akan tercatat di tab Riwayat pengiriman.
					Untuk mengubah teks subjek, body, atau footer template, buka
					<a href="<?php echo esc_url( Penalis_Email_Settings::tab_url() ); ?>">Pengaturan &rarr; Email</a>.
				</p>

				<div class="penalis-email-log__actions">
					<button type="submit" name="tool" value="send" class="button button-primary">
						Kirim email percobaan
					</button>
					<button type="submit" name="tool" value="preview" class="button" formtarget="_blank" formnovalidate>
						Pratinjau di tab baru &nearr;
					</button>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the history tab.
	 *
	 * @return void
	 */
	private static function render_history_tab() {
		$registry = Penalis_Email_Settings::get_registry();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$template = isset( $_GET['template'] ) ? sanitize_key( wp_unslash( $_GET['template'] ) ) : '';
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $status, array( Penalis_Email_Log::STATUS_SENT, Penalis_Email_Log::STATUS_FAILED ), true ) ) {
			$status = '';
		}

		if ( '' !== $template && ! isset( $registry[ $template ] ) ) {
			$template = '';
		}

		$entries   = Penalis_Email_Log::filter( Penalis_Email_Log::entries(), $status, $template );
		$total     = count( $entries );
		$pages     = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged     = min( $paged, $pages );
		$page_rows = array_slice( $entries, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE );

		?>
		<div class="penalis-email-log__section">
			<h2 class="screen-reader-text">Riwayat pengiriman</h2>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="penalis-toolbar penalis-email-log__filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<input type="hidden" name="tab" value="history" />

				<div class="penalis-email-log__filter-group">
					<label for="penalis-email-status">Status</label>
					<select id="penalis-email-status" name="status">
						<option value="">Semua status</option>
						<option value="<?php echo esc_attr( Penalis_Email_Log::STATUS_SENT ); ?>" <?php selected( $status, Penalis_Email_Log::STATUS_SENT ); ?>>Terkirim</option>
						<option value="<?php echo esc_attr( Penalis_Email_Log::STATUS_FAILED ); ?>" <?php selected( $status, Penalis_Email_Log::STATUS_FAILED ); ?>>Gagal</option>
					</select>
				</div>

				<div class="penalis-email-log__filter-group">
					<label for="penalis-email-template-filter">Template</label>
					<select id="penalis-email-template-filter" name="template">
						<option value="">Semua template</option>
						<?php self::render_template_options( $registry, $template ); ?>
					</select>
				</div>

				<button type="submit" class="button">Terapkan</button>

				<?php if ( '' !== $status || '' !== $template ) : ?>
					<a class="button button-link" href="<?php echo esc_url( self::page_url( array( 'tab' => 'history' ) ) ); ?>">Hapus filter</a>
				<?php endif; ?>

				<span class="penalis-toolbar__count penalis-email-log__count">
					<?php
					echo esc_html(
						sprintf(
							'%1$d entri tersimpan, maksimal %2$d. Entri lama otomatis terhapus saat batas tercapai.',
							$total,
							Penalis_Email_Log::MAX_ENTRIES
						)
					);
					?>
				</span>
			</form>

			<?php if ( empty( $page_rows ) ) : ?>
				<p class="penalis-email-log__empty">
					<?php
					echo esc_html(
						$total > 0
							? 'Tidak ada entri pada halaman ini.'
							: 'Belum ada email yang tercatat. Kirim email percobaan dari tab “Kirim percobaan” untuk memulai.'
					);
					?>
				</p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped penalis-email-log__table">
					<thead>
						<tr>
							<th scope="col">Waktu</th>
							<th scope="col">Penerima</th>
							<th scope="col">Template</th>
							<th scope="col">Subjek</th>
							<th scope="col">Status</th>
							<th scope="col">Tindakan</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $page_rows as $entry ) : ?>
							<?php $entry = (array) $entry; ?>
							<tr>
								<td>
									<?php echo esc_html( Penalis_Datetime::format( (int) $entry['time'], 'd M Y H:i' ) ); ?>
									<?php if ( ! empty( $entry['test'] ) ) : ?>
										<span class="penalis-email-log__tag">Percobaan</span>
									<?php endif; ?>
									<?php if ( ! empty( $entry['resend_of'] ) ) : ?>
										<span class="penalis-email-log__tag">Kirim ulang</span>
									<?php endif; ?>
								</td>
								<td class="penalis-email-log__recipient"><?php echo esc_html( (string) $entry['to'] ); ?></td>
								<td
									class="penalis-email-log__template"
									title="<?php echo esc_attr( isset( $registry[ (string) $entry['template'] ] ) ? (string) $registry[ (string) $entry['template'] ]['title'] : 'Template tidak dikenal' ); ?>"
								>
									<?php
									$entry_template = (string) $entry['template'];
									echo esc_html( isset( $registry[ $entry_template ] ) ? (string) $registry[ $entry_template ]['title'] : '—' );
									?>
								</td>
								<td><?php echo esc_html( (string) $entry['subject'] ); ?></td>
								<td>
									<?php if ( Penalis_Email_Log::STATUS_SENT === $entry['status'] ) : ?>
										<span class="penalis-email-log__status penalis-email-log__status--sent">Terkirim</span>
									<?php else : ?>
										<span class="penalis-email-log__status penalis-email-log__status--failed">Gagal</span>
										<?php if ( '' !== (string) $entry['error'] ) : ?>
											<span class="penalis-email-log__error" title="<?php echo esc_attr( (string) $entry['error'] ); ?>"><?php echo esc_html( (string) $entry['error'] ); ?></span>
										<?php endif; ?>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( ! empty( $entry['resendable'] ) ) : ?>
										<a class="button button-small" href="<?php echo esc_url( self::resend_url( (string) $entry['id'] ) ); ?>">
											Kirim ulang
										</a>
									<?php else : ?>
										<span class="penalis-email-log__muted" title="Berisi kode atau tautan sekali pakai, atau berasal dari pengiriman percobaan.">—</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $pages > 1 ) : ?>
					<div class="tablenav bottom penalis-email-log__pagination">
						<div class="tablenav-pages">
							<?php
							echo wp_kses_post(
								(string) paginate_links(
									array(
										'base'    => add_query_arg(
											array(
												'page'     => self::PAGE_SLUG,
												'tab'      => 'history',
												'status'   => $status,
												'template' => $template,
												'paged'    => '%#%',
											),
											admin_url( 'admin.php' )
										),
										'format'  => '',
										'current' => $paged,
										'total'   => $pages,
									)
								)
							);
							?>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( $total > 0 ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="penalis-email-log__clear">
					<input type="hidden" name="action" value="penalis_email_clear_log" />
					<?php wp_nonce_field( 'penalis_email_clear_log' ); ?>
					<p class="penalis-email-log__clear-hint">
						Menghapus riwayat tidak memengaruhi email yang sudah terkirim.
					</p>
					<button type="submit" class="button button-link-delete penalis-button--danger">Kosongkan riwayat</button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Build the resend URL for one entry.
	 *
	 * @param string $id Entry identifier.
	 * @return string
	 */
	public static function resend_url( $id ) {
		$url = add_query_arg(
			array(
				'action' => 'penalis_email_resend',
				'entry'  => $id,
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, 'penalis_email_resend_' . $id );
	}

	/**
	 * Sample values for every placeholder a template uses.
	 *
	 * Real values cannot be used here: a preview must never mint a working OTP
	 * code or reset link. Every tag the registry declares gets a readable stand
	 * in, so the administrator can judge the layout, not the data.
	 *
	 * @param string $template_key Registry key.
	 * @return array<string,string>
	 */
	public static function sample_variables( $template_key ) {
		$registry = Penalis_Email_Settings::get_registry();
		$tags     = isset( $registry[ $template_key ]['tags'] ) && is_array( $registry[ $template_key ]['tags'] )
			? $registry[ $template_key ]['tags']
			: array();

		$user = wp_get_current_user();

		$samples = array(
			'{nama_penulis}'        => '' !== (string) $user->display_name ? (string) $user->display_name : 'Penulis Contoh',
			'{username_penulis}'    => '' !== (string) $user->user_login ? (string) $user->user_login : 'penulis_contoh',
			'{username_baru}'       => 'penulis_baru',
			'{email}'               => '' !== (string) $user->user_email ? (string) $user->user_email : 'penulis@example.com',
			'{nama_situs}'          => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{judul_karya}'         => 'Contoh Judul Karya Tulis',
			'{catatan_review}'      => 'Contoh catatan review dari senator untuk penulis.',
			'{review_cycle}'        => '1',
			'{kode_otp}'            => '123456',
			'{menit_berlaku}'       => '10',
			'{maksimal_percobaan}'  => '5',
			'{link_dashboard}'      => Penalis_Dashboard::dashboard_url(),
			'{link_editor}'         => Penalis_Dashboard::dashboard_url( array( 'view' => 'editor' ) ),
			'{link_karya_terbit}'   => home_url( '/contoh-karya-tulis/' ),
			'{link_review_admin}'   => admin_url( 'admin.php?page=penalis-review' ),
			'{link_trash_admin}'    => admin_url( 'admin.php?page=penalis-trash' ),
			'{link_reset_password}' => home_url( '/contoh-link-reset/' ),
			'{link_aktivasi}'       => home_url( '/contoh-link-aktivasi/' ),
		);

		$resolved = array();

		foreach ( array_keys( $tags ) as $tag ) {
			$tag              = (string) $tag;
			$resolved[ $tag ] = isset( $samples[ $tag ] ) ? $samples[ $tag ] : 'Contoh ' . trim( $tag, '{}' );
		}

		return $resolved;
	}

	/**
	 * Print a rendered template as a standalone page.
	 *
	 * Ends the request: the response is the email itself, not an admin screen.
	 *
	 * @param string               $template Registry key.
	 * @param array<string,string> $vars     Sample placeholder values.
	 * @return void
	 */
	private static function render_preview( $template, array $vars ) {
		$rendered = Penalis_Email_Settings::render( $template, $vars );
		$html     = Penalis_Email_Template::render( $rendered['body'], $rendered['subject'], $rendered['footer'] );

		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The email template escapes its own content.
		echo $html;
		exit;
	}

	/**
	 * Stop unless the current user may manage email.
	 *
	 * @return void
	 */
	private static function require_manager() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'Kamu tidak memiliki izin untuk melihat halaman ini.', 'penalis-platform' ),
				'',
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Redirect back to the screen with a notice code.
	 *
	 * @param string $notice Notice code.
	 * @param string $tab    Tab to land on.
	 * @return void
	 */
	private static function redirect( $notice, $tab = 'history' ) {
		wp_safe_redirect(
			self::page_url(
				array(
					'tab'                  => $tab,
					'penalis_email_notice' => $notice,
				)
			)
		);
		exit;
	}

	/**
	 * Print the notice named by the current request, if any.
	 *
	 * @return void
	 */
	private static function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice code.
		$code = isset( $_GET['penalis_email_notice'] ) ? sanitize_key( wp_unslash( $_GET['penalis_email_notice'] ) ) : '';

		$notices = array(
			'test_sent'         => array( 'success', 'Email percobaan berhasil dikirim. Periksa kotak masuk penerima.' ),
			'test_failed'       => array( 'error', 'Email percobaan gagal dikirim. Alasan kegagalan tercatat pada entri terbaru di bawah.' ),
			'invalid_recipient' => array( 'error', 'Alamat email penerima tidak valid.' ),
			'invalid_template'  => array( 'error', 'Template email tidak dikenal.' ),
			'resent'            => array( 'success', 'Email dikirim ulang. Entri baru tercatat di riwayat.' ),
			'resend_failed'     => array( 'error', 'Email gagal dikirim ulang. Alasan kegagalan tercatat pada entri terbaru.' ),
			'not_resendable'    => array( 'warning', 'Entri ini tidak dapat dikirim ulang: isinya memuat kode atau tautan sekali pakai, atau berasal dari pengiriman percobaan.' ),
			'cleared'           => array( 'success', 'Riwayat pengiriman dikosongkan.' ),
		);

		if ( ! isset( $notices[ $code ] ) ) {
			return;
		}
		?>
		<div class="notice notice-<?php echo esc_attr( $notices[ $code ][0] ); ?> is-dismissible">
			<p><?php echo esc_html( $notices[ $code ][1] ); ?></p>
		</div>
		<?php
	}
}
