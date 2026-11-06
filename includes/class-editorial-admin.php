<?php
/**
 * Administrator editorial review screen.
 *
 * This is the curation surface for the V2 workflow: administrators read
 * submissions, request changes, reject, reopen, and approve. It is deliberately
 * separate from the account screen under Users, because reviewing an article
 * and approving an account are different jobs.
 *
 * Every decision goes through Penalis_Editorial_Workflow::apply(), so the state
 * machine, the review cycle guard, the per-post lock, and the audit history are
 * the same ones the REST layer uses.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the Penalis review screen under Posts.
 */
final class Penalis_Editorial_Admin {

	public const PAGE_SLUG = 'penalis-review';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_penalis_review_action', array( __CLASS__, 'handle_review_action' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_styles' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
	}

	/**
	 * Register the review screen as a Posts submenu.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_posts_page(
			'Tinjauan Penalis',
			'Tinjauan Penalis',
			'edit_others_posts',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue the review screen stylesheet.
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public static function admin_styles( $hook ) {
		if ( 'posts_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_register_style( 'penalis-review', false, array(), PENALIS_PLATFORM_VERSION );
		wp_enqueue_style( 'penalis-review' );
		wp_add_inline_style( 'penalis-review', self::admin_css() );
	}

	/**
	 * Stylesheet contents.
	 *
	 * @return string
	 */
	private static function admin_css() {
		return <<<'CSS'
.penalis-review .penalis-review__intro{margin:2px 0 18px;max-width:72ch;color:#50575e;font-size:13px}
.penalis-review .subsubsub{float:none;clear:both;margin:0 0 14px;font-size:13px}
.penalis-review .subsubsub li:not(:last-child)::after{content:"|";margin:0 6px;color:#c3c4c7}
.penalis-review .penalis-review__table{margin:0}
.penalis-review .penalis-review__table td{vertical-align:top}
.penalis-review .penalis-review__title{display:block;font-size:14px;font-weight:600;color:#1d2327;margin-bottom:2px}
.penalis-review .penalis-review__meta{display:block;color:#646970;font-size:12px;line-height:1.5}
.penalis-review .penalis-review__state{display:inline-flex;align-items:center;gap:6px;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:600;line-height:1.7;white-space:nowrap}
.penalis-review .penalis-review__state--pending_review{background:#fcf9e8;color:#8a6116}
.penalis-review .penalis-review__state--changes_requested{background:#f0f6fc;color:#0a4b78}
.penalis-review .penalis-review__state--rejected{background:#fcf0f1;color:#8a2424}
.penalis-review .penalis-review__state--published{background:#edfaef;color:#007017}
.penalis-review .penalis-review__state--draft{background:#f0f0f1;color:#50575e}
.penalis-review .penalis-review__panel{max-width:900px;margin:16px 0 0;padding:16px 18px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;box-shadow:0 1px 1px rgba(0,0,0,.04)}
.penalis-review .penalis-review__panel h2{margin-top:0}
.penalis-review .penalis-review__content{margin:12px 0 0;padding:14px 16px;border:1px solid #dcdcde;border-radius:4px;background:#fbfbfc;max-height:520px;overflow:auto}
.penalis-review .penalis-review__content img{max-width:100%;height:auto}
.penalis-review .penalis-review__feedback{margin:12px 0 0;padding:10px 12px;border-left:4px solid #dba617;background:#fcf9e8;color:#3c434a}
.penalis-review .penalis-review__actions{display:flex;flex-wrap:wrap;gap:10px;margin:16px 0 0}
.penalis-review .penalis-review__actions form{margin:0}
.penalis-review .penalis-review__field{margin:0 0 12px}
.penalis-review .penalis-review__field label{display:block;font-weight:600;margin-bottom:4px}
.penalis-review .penalis-review__field textarea{width:100%;min-height:90px}
.penalis-review .penalis-review__field .description{margin-top:4px}
.penalis-review .penalis-review__history{margin:16px 0 0;padding:0;list-style:none}
.penalis-review .penalis-review__history li{padding:8px 0;border-top:1px solid #f0f0f1;font-size:12px;color:#50575e}
.penalis-review .penalis-review__history strong{color:#1d2327}
.penalis-review .penalis-review__history p{margin:4px 0 0;color:#3c434a}
.penalis-review .penalis-review__empty{padding:24px;text-align:center;color:#646970}
.penalis-review .penalis-review__back{margin:0 0 12px}
.penalis-review .button.penalis-review__danger{color:#b32d2e;border-color:#b32d2e}
.penalis-review .button.penalis-review__danger:hover{background:#b32d2e;border-color:#b32d2e;color:#fff}
CSS;
	}

	/**
	 * Reviewer actions available for an editorial state, in display order.
	 *
	 * Shared by the renderer and the tests so the buttons shown, the buttons
	 * accepted, and the state machine cannot drift apart.
	 *
	 * @param string $state Editorial state.
	 * @return string[]
	 */
	public static function actions_for_state( $state ) {
		$candidates = array(
			Penalis_Editorial_Policy::ACTION_APPROVE,
			Penalis_Editorial_Policy::ACTION_REQUEST_CHANGES,
			Penalis_Editorial_Policy::ACTION_REJECT,
			Penalis_Editorial_Policy::ACTION_REOPEN,
		);

		$available = array();
		foreach ( $candidates as $action ) {
			if ( Penalis_Editorial_Policy::can_transition( $state, $action ) ) {
				$available[] = $action;
			}
		}

		return $available;
	}

	/**
	 * Render the review screen.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! Penalis_Editorial_Workflow::can_review( get_current_user_id() ) ) {
			wp_die( 'Kamu tidak memiliki izin untuk meninjau karya tulis.' );
		}

		$post_id = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $post_id > 0 ) {
			self::render_detail( $post_id );
			return;
		}

		self::render_list();
	}

	/**
	 * Render the submission list.
	 *
	 * @return void
	 */
	private static function render_list() {
		$state = isset( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$tabs = array(
			Penalis_Editorial_Policy::STATE_PENDING_REVIEW => 'Menunggu tinjauan',
			Penalis_Editorial_Policy::STATE_CHANGES_REQUESTED => 'Perlu revisi',
			Penalis_Editorial_Policy::STATE_REJECTED       => 'Ditolak',
			Penalis_Editorial_Policy::STATE_PUBLISHED      => 'Terbit',
		);

		if ( ! isset( $tabs[ $state ] ) ) {
			$state = Penalis_Editorial_Policy::STATE_PENDING_REVIEW;
		}

		$per_page = 20;
		$query    = new WP_Query( self::query_args( $state, $paged, $per_page ) );
		$total    = (int) $query->found_posts;
		$pages    = (int) $query->max_num_pages;

		?>
		<div class="wrap penalis-review">
			<h1 class="wp-heading-inline">Tinjauan Penalis</h1>
			<hr class="wp-header-end">

			<?php self::render_notices(); ?>

			<p class="penalis-review__intro">
				Tinjau karya tulis yang diajukan penulis. Menyetujui akan menerbitkan artikel;
				meminta revisi atau menolak wajib disertai catatan agar penulis tahu apa yang harus
				diperbaiki. Artikel yang sudah terbit tidak dapat diedit penulis.
			</p>

			<ul class="subsubsub">
				<?php foreach ( $tabs as $value => $label ) : ?>
					<?php
					$tab_url = add_query_arg(
						array(
							'page'  => self::PAGE_SLUG,
							'state' => $value,
						),
						admin_url( 'edit.php' )
					);
					?>
					<li>
						<a
							href="<?php echo esc_url( $tab_url ); ?>"
							class="<?php echo esc_attr( $state === $value ? 'current' : '' ); ?>"
							<?php echo $state === $value ? 'aria-current="page"' : ''; ?>
						>
							<?php echo esc_html( $label ); ?>
							<span class="count">(<?php echo esc_html( (string) self::count_state( $value ) ); ?>)</span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>

			<table class="wp-list-table widefat fixed striped penalis-review__table">
				<thead>
					<tr>
						<th scope="col">Karya tulis</th>
						<th scope="col" style="width:18%">Penulis</th>
						<th scope="col" style="width:14%">Status</th>
						<th scope="col" style="width:16%">Diajukan</th>
						<th scope="col" style="width:12%">Tindakan</th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $query->posts ) ) : ?>
					<tr>
						<td colspan="5">
							<p class="penalis-review__empty">Tidak ada karya tulis di tampilan ini.</p>
						</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $query->posts as $post ) : ?>
						<?php
						$author     = get_userdata( (int) $post->post_author );
						$post_state = Penalis_Editorial_Workflow::get_state( $post );
						$submitted  = Penalis_Editorial_Workflow::get_submitted_at( $post );
						$detail_url = add_query_arg(
							array(
								'page'    => self::PAGE_SLUG,
								'post_id' => (int) $post->ID,
							),
							admin_url( 'edit.php' )
						);
						?>
						<tr>
							<td>
								<a class="penalis-review__title" href="<?php echo esc_url( $detail_url ); ?>">
									<?php echo esc_html( '' !== $post->post_title ? $post->post_title : '(tanpa judul)' ); ?>
								</a>
								<span class="penalis-review__meta">
									<?php echo esc_html( sprintf( 'Siklus tinjauan %d', Penalis_Editorial_Workflow::get_review_cycle( $post ) ) ); ?>
								</span>
							</td>
							<td>
								<?php echo esc_html( $author ? $author->display_name : 'Penulis tidak ditemukan' ); ?>
							</td>
							<td>
								<span class="penalis-review__state penalis-review__state--<?php echo esc_attr( $post_state ); ?>">
									<?php echo esc_html( Penalis_Editorial_Policy::state_label( $post_state ) ); ?>
								</span>
							</td>
							<td>
								<?php
								echo esc_html(
									$submitted > 0
										? date_i18n( 'j M Y H:i', $submitted )
										: mysql2date( 'j M Y H:i', $post->post_modified )
								);
								?>
							</td>
							<td>
								<a class="button button-small" href="<?php echo esc_url( $detail_url ); ?>">Tinjau</a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg(
									array(
										'page'  => self::PAGE_SLUG,
										'state' => $state,
										'paged' => '%#%',
									),
									admin_url( 'edit.php' )
								),
								'format'    => '',
								'current'   => $paged,
								'total'     => $pages,
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
							)
						)
					);
					?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render one submission with its review actions.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private static function render_detail( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || 'post' !== $post->post_type ) {
			?>
			<div class="wrap penalis-review">
				<h1>Tinjauan Penalis</h1>
				<p class="penalis-review__empty">Karya tulis tidak ditemukan.</p>
				<p><a class="button" href="<?php echo esc_url( self::list_url() ); ?>">Kembali ke daftar</a></p>
			</div>
			<?php
			return;
		}

		$state     = Penalis_Editorial_Workflow::get_state( $post );
		$cycle     = Penalis_Editorial_Workflow::get_review_cycle( $post );
		$feedback  = Penalis_Editorial_Workflow::get_feedback( $post );
		$history   = Penalis_Editorial_Workflow::get_history( $post );
		$author    = get_userdata( (int) $post->post_author );
		$submitted = Penalis_Editorial_Workflow::get_submitted_at( $post );

		$actions = self::actions_for_state( $state );
		?>
		<div class="wrap penalis-review">
			<p class="penalis-review__back">
				<a href="<?php echo esc_url( self::list_url() ); ?>">&larr; Kembali ke daftar tinjauan</a>
			</p>

			<h1 class="wp-heading-inline"><?php echo esc_html( '' !== $post->post_title ? $post->post_title : '(tanpa judul)' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( (string) get_edit_post_link( $post->ID, 'url' ) ); ?>">
				Edit di editor WordPress
			</a>
			<hr class="wp-header-end">

			<?php self::render_notices(); ?>

			<p class="penalis-review__meta">
				<?php
				echo esc_html(
					sprintf(
						'Penulis: %1$s · Status: %2$s · Siklus tinjauan %3$d',
						$author ? $author->display_name : 'Penulis tidak ditemukan',
						Penalis_Editorial_Policy::state_label( $state ),
						$cycle
					)
				);
				?>
				<?php if ( $submitted > 0 ) : ?>
					<br>
					<?php echo esc_html( 'Diajukan: ' . date_i18n( 'j M Y H:i', $submitted ) ); ?>
				<?php endif; ?>
			</p>

			<?php if ( '' !== $feedback ) : ?>
				<div class="penalis-review__feedback">
					<strong>Catatan terakhir untuk penulis</strong>
					<p><?php echo esc_html( $feedback ); ?></p>
				</div>
			<?php endif; ?>

			<div class="penalis-review__panel">
				<h2>Isi karya tulis</h2>
				<div class="penalis-review__content">
					<?php echo wp_kses_post( $post->post_content ); ?>
				</div>
			</div>

			<?php if ( ! empty( $actions ) ) : ?>
				<div class="penalis-review__panel">
					<h2>Keputusan tinjauan</h2>
					<p class="description">
						Menyetujui akan menerbitkan artikel. Meminta revisi atau menolak wajib disertai
						catatan. Artikel yang ditolak hanya dapat diajukan ulang setelah kamu membukanya
						kembali.
					</p>

					<?php foreach ( $actions as $action ) : ?>
						<?php self::render_action_form( $post, $action, $cycle ); ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $history ) ) : ?>
				<div class="penalis-review__panel">
					<h2>Riwayat tinjauan</h2>
					<ul class="penalis-review__history">
						<?php foreach ( array_reverse( $history ) as $entry ) : ?>
							<?php
							$actor = isset( $entry['actor_id'] ) ? get_userdata( (int) $entry['actor_id'] ) : null;
							?>
							<li>
								<strong><?php echo esc_html( Penalis_Editorial_Policy::action_label( (string) $entry['action'] ) ); ?></strong>
								<?php
								echo esc_html(
									sprintf(
										' · %1$s · siklus %2$d',
										$actor ? $actor->display_name : 'Pengguna tidak ditemukan',
										(int) $entry['review_cycle']
									)
								);
								?>
								<?php if ( ! empty( $entry['created_at'] ) ) : ?>
									<?php echo esc_html( ' · ' . date_i18n( 'j M Y H:i', (int) $entry['created_at'] ) ); ?>
								<?php endif; ?>
								<?php if ( ! empty( $entry['feedback'] ) ) : ?>
									<p><?php echo esc_html( (string) $entry['feedback'] ); ?></p>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render one decision form.
	 *
	 * @param WP_Post $post   Post.
	 * @param string  $action Workflow action.
	 * @param int     $cycle  Review cycle the administrator is looking at.
	 * @return void
	 */
	private static function render_action_form( $post, $action, $cycle ) {
		$labels = array(
			Penalis_Editorial_Policy::ACTION_APPROVE => 'Setujui dan terbitkan',
			Penalis_Editorial_Policy::ACTION_REQUEST_CHANGES => 'Minta revisi',
			Penalis_Editorial_Policy::ACTION_REJECT  => 'Tolak',
			Penalis_Editorial_Policy::ACTION_REOPEN  => 'Buka kembali',
		);

		$label    = isset( $labels[ $action ] ) ? $labels[ $action ] : $action;
		$required = Penalis_Editorial_Policy::requires_feedback( $action );
		$danger   = in_array( $action, array( Penalis_Editorial_Policy::ACTION_REJECT ), true );
		$primary  = Penalis_Editorial_Policy::ACTION_APPROVE === $action;
		$field_id = 'penalis-feedback-' . $action;

		$classes = 'button';
		if ( $danger ) {
			$classes .= ' penalis-review__danger';
		} elseif ( $primary ) {
			$classes .= ' button-primary';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="penalis_review_action">
			<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post->ID ); ?>">
			<input type="hidden" name="penalis_action" value="<?php echo esc_attr( $action ); ?>">
			<input type="hidden" name="expected_cycle" value="<?php echo esc_attr( (string) $cycle ); ?>">
			<?php wp_nonce_field( 'penalis_review_' . $action . '_' . $post->ID ); ?>

			<div class="penalis-review__field">
				<label for="<?php echo esc_attr( $field_id ); ?>">
					Catatan untuk penulis<?php echo $required ? ' (wajib)' : ' (opsional)'; ?>
				</label>
				<textarea
					id="<?php echo esc_attr( $field_id ); ?>"
					name="feedback"
					<?php echo $required ? 'required' : ''; ?>
					placeholder="<?php echo esc_attr( $required ? 'Jelaskan bagian yang perlu diperbaiki.' : 'Tambahkan catatan bila perlu.' ); ?>"
				></textarea>
				<?php if ( $required ) : ?>
					<p class="description">Penulis akan melihat catatan ini di dasbor.</p>
				<?php endif; ?>
			</div>

			<button type="submit" class="<?php echo esc_attr( $classes ); ?>">
				<?php echo esc_html( $label ); ?>
			</button>
		</form>
		<?php
	}

	/**
	 * Handle a review decision.
	 *
	 * @return void
	 */
	public static function handle_review_action() {
		if ( ! Penalis_Editorial_Workflow::can_review( get_current_user_id() ) ) {
			wp_die( 'Kamu tidak memiliki izin untuk meninjau karya tulis.' );
		}

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$action  = isset( $_POST['penalis_action'] ) ? sanitize_key( wp_unslash( $_POST['penalis_action'] ) ) : '';

		check_admin_referer( 'penalis_review_' . $action . '_' . $post_id );

		if ( $post_id <= 0 || ! Penalis_Editorial_Policy::is_reviewer_action( $action ) ) {
			self::redirect( $post_id, 'invalid_action' );
		}

		$feedback = isset( $_POST['feedback'] ) ? sanitize_textarea_field( wp_unslash( $_POST['feedback'] ) ) : '';
		$cycle    = isset( $_POST['expected_cycle'] ) ? (int) $_POST['expected_cycle'] : 0;

		$result = Penalis_Editorial_Workflow::apply(
			$post_id,
			$action,
			array(
				'actor_id'       => get_current_user_id(),
				'feedback'       => $feedback,
				'expected_cycle' => $cycle,
			)
		);

		if ( is_wp_error( $result ) ) {
			self::redirect( $post_id, $result->get_error_code() );
		}

		self::redirect( $post_id, 'done' );
	}

	/**
	 * Redirect back to the review screen.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $message Message key.
	 * @return void
	 */
	private static function redirect( $post_id, $message ) {
		$args = array(
			'page' => self::PAGE_SLUG,
		);

		if ( $post_id > 0 ) {
			$args['post_id'] = $post_id;
		}

		$args['penalis_review_message'] = $message;

		wp_safe_redirect( add_query_arg( $args, admin_url( 'edit.php' ) ) );
		exit;
	}

	/**
	 * Render the result notice.
	 *
	 * @return void
	 */
	private static function render_notices() {
		if ( ! isset( $_GET['penalis_review_message'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$key = sanitize_key( wp_unslash( $_GET['penalis_review_message'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$messages = array(
			'done'                      => array( 'success', 'Keputusan tinjauan telah disimpan.' ),
			'invalid_action'            => array( 'error', 'Tindakan tinjauan tersebut tidak diizinkan.' ),
			'penalis_editorial_denied'  => array( 'error', 'Keputusan tidak dapat diterapkan pada status karya tulis saat ini.' ),
			'penalis_editorial_busy'    => array( 'error', 'Karya tulis ini sedang diproses admin lain. Coba lagi sebentar lagi.' ),
			'penalis_editorial_failed'  => array( 'error', 'Status karya tulis tidak dapat diperbarui.' ),
			'penalis_not_found'         => array( 'error', 'Karya tulis tidak ditemukan.' ),
			'penalis_not_authenticated' => array( 'error', 'Sesi kamu berakhir. Silakan masuk kembali.' ),
			'penalis_account_inactive'  => array( 'error', 'Akun kamu tidak diizinkan melakukan tindakan ini.' ),
		);

		$notice = isset( $messages[ $key ] ) ? $messages[ $key ] : array( 'info', 'Permintaan telah diselesaikan.' );

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $notice[0] ),
			esc_html( $notice[1] )
		);
	}

	/**
	 * Show a notice when submissions are waiting for review.
	 *
	 * @return void
	 */
	public static function admin_notices() {
		if ( ! Penalis_Editorial_Workflow::can_review( get_current_user_id() ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && 'posts_page_' . self::PAGE_SLUG === $screen->id ) {
			return;
		}

		$count = self::count_state( Penalis_Editorial_Policy::STATE_PENDING_REVIEW );

		if ( $count <= 0 ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
			esc_html( sprintf( '%d karya tulis menunggu tinjauan.', $count ) ),
			esc_url( self::list_url() ),
			'Tinjau karya tulis'
		);
	}

	/**
	 * URL of the review list.
	 *
	 * @return string
	 */
	private static function list_url() {
		return add_query_arg(
			array(
				'page'  => self::PAGE_SLUG,
				'state' => Penalis_Editorial_Policy::STATE_PENDING_REVIEW,
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Query arguments for one editorial state.
	 *
	 * @param string $state    Editorial state.
	 * @param int    $paged    Page number.
	 * @param int    $per_page Items per page.
	 * @return array<string,mixed>
	 */
	private static function query_args( $state, $paged, $per_page ) {
		$args = array(
			'post_type'      => 'post',
			'post_status'    => Penalis_Editorial_Policy::post_status_for_state( $state ),
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);

		// `changes_requested` and `rejected` share the `draft` post status, so
		// the distinction only exists in metadata.
		if ( in_array( $state, array( Penalis_Editorial_Policy::STATE_CHANGES_REQUESTED, Penalis_Editorial_Policy::STATE_REJECTED ), true ) ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => Penalis_Editorial_Policy::META_STATE,
					'value' => $state,
				),
			);
		}

		return $args;
	}

	/**
	 * Count submissions in one editorial state.
	 *
	 * @param string $state Editorial state.
	 * @return int
	 */
	private static function count_state( $state ) {
		$query = new WP_Query(
			array_merge(
				self::query_args( $state, 1, 1 ),
				array(
					'fields'         => 'ids',
					'no_found_rows'  => false,
					'posts_per_page' => 1,
				)
			)
		);

		return (int) $query->found_posts;
	}
}
