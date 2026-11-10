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
 * Adds the Penalis article review screen under the unified Penalis Platform menu.
 */
final class Penalis_Editorial_Admin {

	public const PAGE_SLUG = 'penalis-review';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 11 );
		add_action( 'admin_post_penalis_review_action', array( __CLASS__, 'handle_review_action' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_styles' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
	}

	/**
	 * Register the review screen under the unified Penalis Platform menu.
	 *
	 * @return void
	 */
	public static function register_menu() {
		$pending    = self::pending_review_count();
		$menu_title = 'Review Karya Tulis';

		if ( $pending > 0 ) {
			$menu_title .= sprintf(
				' <span class="awaiting-mod update-plugins count-%1$d"><span class="pending-count" aria-hidden="true">%2$s</span></span>',
				$pending,
				number_format_i18n( $pending )
			);
		}

		add_submenu_page(
			Penalis_Admin::MENU_SLUG,
			'Review Karya Tulis — Penalis Platform',
			$menu_title,
			'edit_others_posts',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue the review screen stylesheet (`assets/css/admin.css`) and
	 * confirmation dialog script (`assets/js/admin-review.js`).
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public static function admin_styles( $hook ) {
		if ( ! Penalis_Admin::is_penalis_screen( $hook ) ) {
			return;
		}

		Penalis_Admin::enqueue_stylesheet();
		Penalis_Admin::enqueue_confirmation_script();
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
			wp_die( 'Kamu tidak memiliki izin untuk review karya tulis.' );
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
			Penalis_Editorial_Policy::STATE_PENDING_REVIEW => 'Menunggu review',
			Penalis_Editorial_Policy::STATE_CHANGES_REQUESTED => 'Perlu revisi',
			Penalis_Editorial_Policy::STATE_REJECTED       => 'Ditolak',
			Penalis_Editorial_Policy::STATE_PUBLISHED      => 'Terbit',
		);

		if ( ! isset( $tabs[ $state ] ) ) {
			$state = Penalis_Editorial_Policy::STATE_PENDING_REVIEW;
		}

		$per_page = 20;
		$query    = new WP_Query( self::query_args( $state, $paged, $per_page ) );
		$pages    = (int) $query->max_num_pages;

		?>
		<div class="wrap penalis-review">
			<h1 class="wp-heading-inline">Review Karya Tulis</h1>
			<hr class="wp-header-end">

			<?php self::render_notices(); ?>

			<p class="penalis-review__intro">
				Review karya tulis yang di-submit penulis. Menyetujui akan menerbitkan karya tulis;
				meminta revisi atau menolak wajib disertai catatan agar penulis tahu apa yang harus
				diperbaiki. Karya tulis yang sudah terbit tidak dapat di-edit penulis.
			</p>

			<ul class="subsubsub">
				<?php foreach ( $tabs as $value => $label ) : ?>
					<?php $tab_url = self::list_url( $value ); ?>
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
						<th scope="col" style="width:15%">Status</th>
						<th scope="col" style="width:16%">Di-submit</th>
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
						$detail_url = self::detail_url( (int) $post->ID );
						?>
						<tr>
							<td>
								<a class="penalis-review__title" href="<?php echo esc_url( $detail_url ); ?>">
									<?php echo esc_html( '' !== $post->post_title ? $post->post_title : '(tanpa judul)' ); ?>
								</a>
								<span class="penalis-review__meta">
									<?php echo esc_html( sprintf( 'Review Cycle #%d', max( 1, Penalis_Editorial_Workflow::get_review_cycle( $post ) ) ) ); ?>
								</span>
							</td>
							<td>
								<?php echo esc_html( $author ? $author->display_name : 'Penulis tidak ditemukan' ); ?>
							</td>
							<td>
								<span class="penalis-review__state penalis-review__state--<?php echo esc_attr( $post_state ); ?>">
									<span class="penalis-review__state-dot" aria-hidden="true"></span>
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
								<a class="button button-small" href="<?php echo esc_url( $detail_url ); ?>">Review</a>
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
									admin_url( 'admin.php' )
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
	 * Render one submission with its 2-column editorial review workspace.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private static function render_detail( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || 'post' !== $post->post_type ) {
			?>
			<div class="wrap penalis-review">
				<h1>Review Karya Tulis</h1>
				<p class="penalis-review__empty">Karya tulis tidak ditemukan.</p>
				<p><a class="button" href="<?php echo esc_url( self::list_url() ); ?>">Kembali ke daftar</a></p>
			</div>
			<?php
			return;
		}

		$state      = Penalis_Editorial_Workflow::get_state( $post );
		$cycle      = max( 1, Penalis_Editorial_Workflow::get_review_cycle( $post ) );
		$feedback   = Penalis_Editorial_Workflow::get_feedback( $post );
		$history    = Penalis_Editorial_Workflow::get_history( $post );
		$author     = get_userdata( (int) $post->post_author );
		$submitted  = Penalis_Editorial_Workflow::get_submitted_at( $post );
		$actions    = self::actions_for_state( $state );
		$thumb_url  = (string) get_the_post_thumbnail_url( $post, 'large' );
		$categories = wp_get_post_terms( $post->ID, array( 'category' ) );
		$tags       = wp_get_post_terms( $post->ID, array( 'post_tag' ) );
		$plain_text = trim( wp_strip_all_tags( (string) $post->post_content ) );
		$word_count = '' === $plain_text ? 0 : count( (array) preg_split( '/\s+/u', $plain_text ) );
		?>
		<div class="wrap penalis-review">
			<h1 class="wp-heading-inline">Review Karya Tulis</h1>
			<a class="page-title-action" href="<?php echo esc_url( self::list_url( $state ) ); ?>">
				&larr; Kembali ke daftar review
			</a>
			<hr class="wp-header-end">

			<?php self::render_notices(); ?>

			<div class="penalis-review__header-card">
				<div class="penalis-review__header-top">
					<div class="penalis-review__badges">
						<span class="penalis-review__state penalis-review__state--<?php echo esc_attr( $state ); ?>">
							<span class="penalis-review__state-dot" aria-hidden="true"></span>
							<?php echo esc_html( Penalis_Editorial_Policy::state_label( $state ) ); ?>
						</span>
						<span class="penalis-review__cycle-badge">
							<?php echo esc_html( sprintf( 'Review Cycle #%d', $cycle ) ); ?>
						</span>
					</div>

					<div class="penalis-review__header-actions">
						<a class="button" href="<?php echo esc_url( (string) get_edit_post_link( $post->ID, 'url' ) ); ?>">
							Edit di editor WordPress
						</a>
						<?php if ( Penalis_Editorial_Policy::STATE_PUBLISHED === $state || 'publish' === $post->post_status ) : ?>
							<a
								class="button button-primary"
								href="<?php echo esc_url( (string) get_permalink( $post ) ); ?>"
								target="_blank"
								rel="noopener noreferrer"
							>
								Lihat karya tulis terbit &nearr;
							</a>
						<?php endif; ?>
					</div>
				</div>

				<h2 class="penalis-review__article-title">
					<?php echo esc_html( '' !== $post->post_title ? $post->post_title : '(tanpa judul)' ); ?>
				</h2>

				<div class="penalis-review__meta-bar">
					<span class="penalis-review__author">
						<?php
						if ( $author && function_exists( 'get_avatar' ) ) {
							$avatar = get_avatar( (int) $author->ID, 24, '', '', array( 'class' => 'penalis-review__author-avatar' ) );
							if ( $avatar ) {
								echo wp_kses_post( $avatar );
							}
						}
						?>
						<span class="penalis-review__author-name">
							<?php echo esc_html( $author ? $author->display_name : 'Penulis tidak ditemukan' ); ?>
						</span>
						<?php if ( $author ) : ?>
							<span class="penalis-review__author-login">(<?php echo esc_html( $author->user_login ); ?>)</span>
						<?php endif; ?>
					</span>

					<?php if ( $submitted > 0 ) : ?>
						<span><?php echo esc_html( 'Di-submit: ' . date_i18n( 'j M Y H:i', $submitted ) ); ?></span>
					<?php endif; ?>

					<?php if ( is_array( $categories ) && ! empty( $categories ) ) : ?>
						<span class="penalis-review__terms">
							<span>Kategori:</span>
							<?php foreach ( $categories as $term ) : ?>
								<?php if ( is_object( $term ) && isset( $term->name ) ) : ?>
									<span class="penalis-review__term"><?php echo esc_html( (string) $term->name ); ?></span>
								<?php endif; ?>
							<?php endforeach; ?>
						</span>
					<?php endif; ?>

					<?php if ( is_array( $tags ) && ! empty( $tags ) ) : ?>
						<span class="penalis-review__terms">
							<span>Tag:</span>
							<?php foreach ( $tags as $term ) : ?>
								<?php if ( is_object( $term ) && isset( $term->name ) ) : ?>
									<span class="penalis-review__term">#<?php echo esc_html( (string) $term->name ); ?></span>
								<?php endif; ?>
							<?php endforeach; ?>
						</span>
					<?php endif; ?>
				</div>
			</div>

			<div class="penalis-review__workspace">
				<div class="penalis-review__main">
					<?php if ( '' !== $feedback ) : ?>
						<div class="penalis-review__feedback">
							<strong>Catatan terakhir untuk penulis</strong>
							<p><?php echo esc_html( $feedback ); ?></p>
						</div>
					<?php endif; ?>

					<div class="penalis-review__card">
						<div class="penalis-review__card-header">
							<h2>Isi Karya Tulis</h2>
							<span class="penalis-review__word-count">
								<?php echo esc_html( sprintf( '%s kata', number_format_i18n( $word_count ) ) ); ?>
							</span>
						</div>

						<?php if ( '' !== $thumb_url ) : ?>
							<figure class="penalis-review__featured">
								<img src="<?php echo esc_url( $thumb_url ); ?>" alt="" />
								<figcaption>Thumbnail</figcaption>
							</figure>
						<?php endif; ?>

						<div class="penalis-review__content">
							<?php echo wp_kses_post( $post->post_content ); ?>
						</div>
					</div>
				</div>

				<div class="penalis-review__sidebar">
					<div class="penalis-review__card">
						<div class="penalis-review__card-header">
							<h2>Keputusan Review</h2>
						</div>
						<div class="penalis-review__card-body">
							<?php if ( ! empty( $actions ) ) : ?>
								<?php self::render_decision_form( $post, $actions, $cycle ); ?>
							<?php else : ?>
								<p class="penalis-review__state-note">
									<?php echo esc_html( self::no_action_note( $state ) ); ?>
								</p>
							<?php endif; ?>
						</div>
					</div>

					<?php if ( ! empty( $history ) ) : ?>
						<div class="penalis-review__card">
							<div class="penalis-review__card-header">
								<h2>Riwayat Review</h2>
								<span class="penalis-review__word-count">
									<?php echo esc_html( sprintf( '%d aktivitas', count( $history ) ) ); ?>
								</span>
							</div>
							<div class="penalis-review__card-body">
								<ul class="penalis-review__timeline">
									<?php foreach ( array_reverse( $history ) as $entry ) : ?>
										<?php
										$entry_action = isset( $entry['action'] ) ? (string) $entry['action'] : '';
										$actor        = isset( $entry['actor_id'] ) ? get_userdata( (int) $entry['actor_id'] ) : null;
										$entry_cycle  = isset( $entry['review_cycle'] ) ? (int) $entry['review_cycle'] : 0;
										?>
										<li class="penalis-review__timeline-item penalis-review__timeline-item--<?php echo esc_attr( $entry_action ); ?>">
											<span class="penalis-review__timeline-dot" aria-hidden="true"></span>
											<div class="penalis-review__timeline-head">
												<span class="penalis-review__timeline-action">
													<?php echo esc_html( Penalis_Editorial_Policy::action_label( $entry_action ) ); ?>
												</span>
												<?php if ( $entry_cycle > 0 ) : ?>
													<span class="penalis-review__timeline-cycle">
														<?php echo esc_html( sprintf( 'Cycle #%d', $entry_cycle ) ); ?>
													</span>
												<?php endif; ?>
											</div>
											<span class="penalis-review__timeline-meta">
												<?php echo esc_html( $actor ? $actor->display_name : 'Pengguna tidak ditemukan' ); ?>
												<?php if ( ! empty( $entry['created_at'] ) ) : ?>
													<?php echo esc_html( ' · ' . date_i18n( 'j M Y H:i', (int) $entry['created_at'] ) ); ?>
												<?php endif; ?>
											</span>
											<?php if ( ! empty( $entry['feedback'] ) ) : ?>
												<p class="penalis-review__timeline-note"><?php echo esc_html( (string) $entry['feedback'] ); ?></p>
											<?php endif; ?>
										</li>
									<?php endforeach; ?>
								</ul>
							</div>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a single unified decision form for all available reviewer actions.
	 *
	 * @param WP_Post  $post    Post.
	 * @param string[] $actions Available reviewer actions for the post's state.
	 * @param int      $cycle   Review cycle the administrator is looking at.
	 * @return void
	 */
	private static function render_decision_form( $post, $actions, $cycle ) {
		$is_reopen_only = array( Penalis_Editorial_Policy::ACTION_REOPEN ) === $actions;
		?>
		<form id="penalis-decision-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="penalis_review_action">
			<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post->ID ); ?>">
			<input type="hidden" name="expected_cycle" value="<?php echo esc_attr( (string) $cycle ); ?>">
			<input type="hidden" name="penalis_action" id="penalis-confirmed-action" value="" disabled>
			<?php wp_nonce_field( 'penalis_review_' . $post->ID ); ?>

			<?php if ( $is_reopen_only ) : ?>
				<p class="penalis-review__decision-help">
					Karya tulis ini berstatus ditolak. Buka kembali agar penulis dapat edit dan
					submit ulang untuk review.
				</p>
				<div class="penalis-review__decision-buttons">
					<button
						type="submit"
						name="penalis_action"
						value="<?php echo esc_attr( Penalis_Editorial_Policy::ACTION_REOPEN ); ?>"
						data-penalis-decision="<?php echo esc_attr( Penalis_Editorial_Policy::ACTION_REOPEN ); ?>"
						class="button button-primary penalis-review__btn-approve"
					>
						Buka kembali untuk revisi
					</button>
				</div>
			<?php else : ?>
				<p class="penalis-review__decision-help">
					Menyetujui akan langsung menerbitkan karya tulis. Meminta revisi atau menolak wajib
					disertai catatan untuk penulis.
				</p>

				<div class="penalis-review__field">
					<label for="penalis-review-feedback">Catatan untuk penulis</label>
					<textarea
						id="penalis-review-feedback"
						name="feedback"
						rows="5"
						aria-describedby="penalis-review-feedback-desc penalis-review-feedback-error"
						placeholder="Tulis catatan review atau feedback perbaikan untuk penulis..."
					></textarea>
					<p id="penalis-review-feedback-error" class="penalis-review__field-error" role="alert" hidden></p>
					<p id="penalis-review-feedback-desc" class="description">
						Opsional saat menyetujui; wajib diisi saat meminta revisi atau menolak.
					</p>
				</div>

				<div class="penalis-review__decision-buttons">
					<?php if ( in_array( Penalis_Editorial_Policy::ACTION_APPROVE, $actions, true ) ) : ?>
						<button
							type="submit"
							name="penalis_action"
							value="<?php echo esc_attr( Penalis_Editorial_Policy::ACTION_APPROVE ); ?>"
							data-penalis-decision="<?php echo esc_attr( Penalis_Editorial_Policy::ACTION_APPROVE ); ?>"
							class="button button-primary penalis-review__btn-approve"
						>
							Setujui dan terbitkan
						</button>
					<?php endif; ?>

					<div class="penalis-review__decision-secondary">
						<?php if ( in_array( Penalis_Editorial_Policy::ACTION_REQUEST_CHANGES, $actions, true ) ) : ?>
							<button
								type="submit"
								name="penalis_action"
								value="<?php echo esc_attr( Penalis_Editorial_Policy::ACTION_REQUEST_CHANGES ); ?>"
								data-penalis-decision="<?php echo esc_attr( Penalis_Editorial_Policy::ACTION_REQUEST_CHANGES ); ?>"
								class="button"
							>
								Minta revisi
							</button>
						<?php endif; ?>

						<?php if ( in_array( Penalis_Editorial_Policy::ACTION_REJECT, $actions, true ) ) : ?>
							<button
								type="submit"
								name="penalis_action"
								value="<?php echo esc_attr( Penalis_Editorial_Policy::ACTION_REJECT ); ?>"
								data-penalis-decision="<?php echo esc_attr( Penalis_Editorial_Policy::ACTION_REJECT ); ?>"
								class="button penalis-review__danger"
							>
								Tolak
							</button>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>

			<div id="penalis-confirm-modal" class="penalis-confirm-modal" hidden>
				<div class="penalis-confirm-modal__backdrop" data-penalis-confirm-close></div>
				<div
					class="penalis-confirm-modal__dialog"
					role="dialog"
					aria-modal="true"
					aria-labelledby="penalis-confirm-title"
					aria-describedby="penalis-confirm-desc"
				>
					<div class="penalis-confirm-modal__head">
						<span id="penalis-confirm-badge" class="penalis-badge">Konfirmasi</span>
						<h4 id="penalis-confirm-title" class="penalis-confirm-modal__title">Konfirmasi Keputusan Review</h4>
					</div>
					<p id="penalis-confirm-desc" class="penalis-confirm-modal__desc"></p>
					<div id="penalis-confirm-feedback-box" class="penalis-confirm-modal__feedback">
						<span class="penalis-confirm-modal__feedback-label">Catatan untuk penulis:</span>
						<div id="penalis-confirm-feedback-text" class="penalis-confirm-modal__feedback-text"></div>
					</div>
					<div class="penalis-confirm-modal__actions">
						<button type="button" id="penalis-confirm-cancel" class="button">
							Batal
						</button>
						<button type="button" id="penalis-confirm-submit" class="button button-primary">
							Ya, Lanjutkan
						</button>
					</div>
				</div>
			</div>
		</form>
		<?php
	}

	/**
	 * Explanation shown in the decision card when no reviewer action applies.
	 *
	 * @param string $state Editorial state.
	 * @return string
	 */
	private static function no_action_note( $state ) {
		switch ( $state ) {
			case Penalis_Editorial_Policy::STATE_PUBLISHED:
				return 'Karya tulis ini telah disetujui dan diterbitkan. Tidak ada tindakan review yang diperlukan.';
			case Penalis_Editorial_Policy::STATE_CHANGES_REQUESTED:
				return 'Menunggu penulis memperbaiki dan submit ulang karya tulis ini.';
			default:
				return 'Karya tulis ini masih berupa draft dan belum di-submit untuk di-review.';
		}
	}

	/**
	 * Handle a review decision.
	 *
	 * @return void
	 */
	public static function handle_review_action() {
		if ( ! Penalis_Editorial_Workflow::can_review( get_current_user_id() ) ) {
			wp_die( 'Kamu tidak memiliki izin untuk me-review karya tulis.' );
		}

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$action  = isset( $_POST['penalis_action'] ) ? sanitize_key( wp_unslash( $_POST['penalis_action'] ) ) : '';
		$nonce   = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';

		if ( function_exists( 'wp_verify_nonce' ) && wp_verify_nonce( $nonce, 'penalis_review_' . $post_id ) ) {
			check_admin_referer( 'penalis_review_' . $post_id );
		} else {
			check_admin_referer( 'penalis_review_' . $action . '_' . $post_id );
		}

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

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
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
			'done'                      => array( 'success', 'Keputusan review telah disimpan.' ),
			'invalid_action'            => array( 'error', 'Tindakan review tersebut tidak diizinkan.' ),
			'penalis_editorial_denied'  => array( 'error', 'Keputusan tidak dapat diterapkan pada status karya tulis saat ini.' ),
			'penalis_editorial_busy'    => array( 'error', 'Karya tulis ini sedang diproses admin lain. Coba sesaat lagi.' ),
			'penalis_editorial_failed'  => array( 'error', 'Status karya tulis tidak dapat diperbarui.' ),
			'penalis_not_found'         => array( 'error', 'Karya tulis tidak ditemukan.' ),
			'penalis_not_authenticated' => array( 'error', 'Sesi kamu berakhir. Silakan login kembali.' ),
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

		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$screen_id = $screen ? (string) $screen->id : '';

		if ( Penalis_Admin::is_penalis_screen( $screen_id ) ) {
			return;
		}

		$count = self::pending_review_count();

		if ( $count <= 0 ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
			esc_html( sprintf( '%d karya tulis menunggu review.', $count ) ),
			esc_url( self::list_url() ),
			'Review karya tulis'
		);
	}

	/**
	 * URL of the review list.
	 *
	 * @param string $state Editorial state filter.
	 * @return string
	 */
	public static function list_url( $state = Penalis_Editorial_Policy::STATE_PENDING_REVIEW ) {
		return add_query_arg(
			array(
				'page'  => self::PAGE_SLUG,
				'state' => $state,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * URL of a single submission review screen.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function detail_url( $post_id ) {
		return add_query_arg(
			array(
				'page'    => self::PAGE_SLUG,
				'post_id' => (int) $post_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Count submissions awaiting administrator review.
	 *
	 * @return int
	 */
	public static function pending_review_count() {
		return self::count_state( Penalis_Editorial_Policy::STATE_PENDING_REVIEW );
	}

	/**
	 * Count submissions across all four review states.
	 *
	 * @return array<string,int>
	 */
	public static function state_counts() {
		return array(
			Penalis_Editorial_Policy::STATE_PENDING_REVIEW => self::count_state( Penalis_Editorial_Policy::STATE_PENDING_REVIEW ),
			Penalis_Editorial_Policy::STATE_CHANGES_REQUESTED => self::count_state( Penalis_Editorial_Policy::STATE_CHANGES_REQUESTED ),
			Penalis_Editorial_Policy::STATE_REJECTED       => self::count_state( Penalis_Editorial_Policy::STATE_REJECTED ),
			Penalis_Editorial_Policy::STATE_PUBLISHED      => self::count_state( Penalis_Editorial_Policy::STATE_PUBLISHED ),
		);
	}

	/**
	 * Recent submissions awaiting administrator review.
	 *
	 * @param int $limit Max items.
	 * @return WP_Post[]
	 */
	public static function recent_pending_submissions( $limit = 5 ) {
		$query = new WP_Query(
			array_merge(
				self::query_args( Penalis_Editorial_Policy::STATE_PENDING_REVIEW, 1, max( 1, (int) $limit ) ),
				array(
					'no_found_rows' => true,
				)
			)
		);

		return is_array( $query->posts ) ? $query->posts : array();
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
