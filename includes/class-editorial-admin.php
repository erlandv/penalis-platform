<?php
/**
 * Editorial review screen.
 *
 * This is the curation surface for the V2 workflow: reviewers (administrators
 * and editors, both holding `edit_others_posts`) read submissions, request
 * changes, reject, reopen, and approve. It is deliberately separate from the
 * account screen under Users, because reviewing an article and approving an
 * account are different jobs.
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

	public const PAGE_SLUG         = 'penalis-review';
	public const TRASHED_PAGE_SLUG = 'penalis-trashed';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 11 );
		add_action( 'admin_post_penalis_review_action', array( __CLASS__, 'handle_review_action' ) );
		add_action( 'admin_post_penalis_restore_action', array( __CLASS__, 'handle_restore_action' ) );
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
		$menu_title = Penalis_Admin::format_menu_label( 'Review Karya Tulis', $pending );

		add_submenu_page(
			Penalis_Admin::MENU_SLUG,
			'Review Karya Tulis — Penalis Platform',
			$menu_title,
			'edit_others_posts',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);

		$trashed = Penalis_Article_Takedown::trashed_count();
		add_submenu_page(
			Penalis_Admin::MENU_SLUG,
			'Karya Terhapus — Penalis Platform',
			Penalis_Admin::format_menu_label( 'Karya Terhapus', $trashed ),
			'edit_others_posts',
			self::TRASHED_PAGE_SLUG,
			array( __CLASS__, 'render_trashed_page' )
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
	 * Render the published-article takedown and recovery screen.
	 *
	 * @return void
	 */
	public static function render_trashed_page() {
		if ( ! Penalis_Editorial_Workflow::can_review( get_current_user_id() ) ) {
			wp_die( 'Kamu tidak memiliki izin untuk memulihkan karya tulis.' );
		}

		$posts = Penalis_Article_Takedown::trashed_posts();
		?>
		<div class="wrap penalis-review penalis-trashed">
			<h1 class="wp-heading-inline">Karya Terhapus</h1>
			<a class="page-title-action" href="<?php echo esc_url( self::list_url() ); ?>">&larr; Kembali ke review</a>
			<hr class="wp-header-end">

			<?php self::render_notices(); ?>

			<p class="penalis-review__intro">
				Karya tulis terbit yang masuk ke WordPress trash dicatat di sini agar tidak hilang tanpa jejak.
				Gunakan <strong>Pulihkan</strong> untuk mengembalikannya ke status terbit semula. Artikel yang dihapus permanen
				dari trash tidak dapat dipulihkan dari layar ini.
			</p>

			<table class="wp-list-table widefat fixed striped penalis-review__table">
				<thead>
					<tr>
						<th scope="col">Karya tulis</th>
						<th scope="col" style="width:20%">Penulis</th>
						<th scope="col" style="width:17%">Di-trash</th>
						<th scope="col" style="width:17%">Oleh</th>
						<th scope="col" style="width:12%">Tindakan</th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $posts ) ) : ?>
					<tr>
						<td colspan="5">
							<p class="penalis-review__empty">Belum ada karya tulis terbit yang berada di trash.</p>
						</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $posts as $post ) : ?>
						<?php
						$author     = get_userdata( (int) $post->post_author );
						$trashed_at = (int) get_post_meta( $post->ID, Penalis_Article_Takedown::META_TRASHED_AT, true );
						$actor_id   = (int) get_post_meta( $post->ID, Penalis_Article_Takedown::META_TRASHED_BY, true );
						$actor      = $actor_id > 0 ? get_userdata( $actor_id ) : null;
						$title      = '' !== $post->post_title ? $post->post_title : '(tanpa judul)';
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $title ); ?></strong>
								<span class="penalis-review__meta">
									<?php echo esc_html( sprintf( 'ID #%d', (int) $post->ID ) ); ?>
								</span>
							</td>
							<td><?php echo esc_html( $author ? $author->display_name : 'Penulis tidak ditemukan' ); ?></td>
							<td><?php echo esc_html( Penalis_Datetime::format( $trashed_at, 'j M Y H:i' ) ); ?></td>
							<td><?php echo esc_html( $actor ? $actor->display_name : 'Sistem / tidak diketahui' ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="penalis_restore_action">
									<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post->ID ); ?>">
									<input type="hidden" name="penalis_action" value="restore">
									<?php wp_nonce_field( 'penalis_restore_' . $post->ID ); ?>
									<button
										type="submit"
										class="button button-small"
										data-penalis-restore
										data-penalis-restore-title="<?php echo esc_attr( $title ); ?>"
									>
										Pulihkan
									</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<?php
			Penalis_Admin::render_confirm_modal(
				'penalis-restore-confirm',
				'Pulihkan karya tulis dari trash?',
				'Karya tulis:',
				'penalis-restore-confirm-context-box',
				'penalis-restore-confirm-context-text'
			);
			?>
		</div>
		<?php
	}

	/**
	 * Render the submission list.
	 *
	 * @return void
	 */
	private static function render_list() {
		$state   = isset( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged   = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filters = self::parse_list_filters();

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
		$query    = new WP_Query( self::query_args( $state, $paged, $per_page, $filters ) );
		$pages    = (int) $query->max_num_pages;
		$list_url = self::list_url( $state, $filters );
		$found    = (int) $query->found_posts;

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
					<?php $tab_url = self::list_url( $value, $filters ); ?>
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

			<?php self::render_filter_bar( $state, $filters, $found ); ?>

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
						$detail_url = self::detail_url( (int) $post->ID, self::return_args( $state, $filters ) );
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
										? Penalis_Datetime::format( $submitted, 'j M Y H:i' )
										: Penalis_Datetime::format_local( $post->post_modified, 'j M Y H:i' )
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

			<?php
			Penalis_Admin::render_pagination(
				$found,
				$per_page,
				$paged,
				$list_url
			);
			?>
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

		$state        = Penalis_Editorial_Workflow::get_state( $post );
		$cycle        = max( 1, Penalis_Editorial_Workflow::get_review_cycle( $post ) );
		$feedback     = Penalis_Editorial_Workflow::get_feedback( $post );
		$history      = Penalis_Editorial_Workflow::get_history( $post );
		$author       = get_userdata( (int) $post->post_author );
		$submitted    = Penalis_Editorial_Workflow::get_submitted_at( $post );
		$actions      = self::actions_for_state( $state );
		$thumb_url    = (string) get_the_post_thumbnail_url( $post, 'large' );
		$categories   = wp_get_post_terms( $post->ID, array( 'category' ) );
		$tags         = wp_get_post_terms( $post->ID, array( 'post_tag' ) );
		$plain_text   = trim( wp_strip_all_tags( (string) $post->post_content ) );
		$word_count   = '' === $plain_text ? 0 : count( (array) preg_split( '/\s+/u', $plain_text ) );
		$return_state = isset( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : Penalis_Editorial_Policy::STATE_PENDING_REVIEW; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$return_args  = self::return_args( $return_state, self::parse_list_filters() );
		$back_url     = self::list_url( $return_state, $return_args );
		?>
		<div class="wrap penalis-review">
			<h1 class="wp-heading-inline">Review Karya Tulis</h1>
			<a class="page-title-action" href="<?php echo esc_url( $back_url ); ?>">
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
							<?php if ( $author ) : ?>
								<a
									href="<?php echo esc_url( get_author_posts_url( $author->ID ) ); ?>"
									target="_blank"
									rel="noopener noreferrer"
								>
									<?php echo esc_html( $author->display_name ); ?>
								</a>
							<?php else : ?>
								Penulis tidak ditemukan
							<?php endif; ?>
						</span>
						<?php if ( $author ) : ?>
							<span class="penalis-review__author-login">(<?php echo esc_html( $author->user_login ); ?>)</span>
						<?php endif; ?>
					</span>

					<?php if ( $submitted > 0 ) : ?>
						<span><?php echo esc_html( 'Di-submit: ' . Penalis_Datetime::format( $submitted, 'j M Y H:i' ) ); ?></span>
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
								<?php self::render_decision_form( $post, $actions, $cycle, $return_args ); ?>
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
														<?php echo esc_html( ' · ' . Penalis_Datetime::format( (int) $entry['created_at'], 'j M Y H:i' ) ); ?>
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
	 * @param WP_Post  $post        Post.
	 * @param string[] $actions     Available reviewer actions for the post's state.
	 * @param int      $cycle       Review cycle the reviewer is looking at.
	 * @param array    $return_args List filters to restore after the decision.
	 * @return void
	 */
	private static function render_decision_form( $post, $actions, $cycle, $return_args = array() ) {
		$is_reopen_only = array( Penalis_Editorial_Policy::ACTION_REOPEN ) === $actions;
		?>
		<form id="penalis-decision-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="penalis_review_action">
			<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post->ID ); ?>">
			<input type="hidden" name="expected_cycle" value="<?php echo esc_attr( (string) $cycle ); ?>">
			<input type="hidden" name="penalis_action" id="penalis-confirmed-action" value="" disabled>
			<?php
			foreach ( $return_args as $key => $value ) {
				if ( ! is_string( $key ) || '' === $key || is_array( $value ) ) {
					continue;
				}
				?>
				<input
					type="hidden"
					name="<?php echo esc_attr( 'penalis_return[' . $key . ']' ); ?>"
					value="<?php echo esc_attr( (string) $value ); ?>"
				>
				<?php
			}
			?>
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

			<?php
			Penalis_Admin::render_confirm_modal(
				'penalis-confirm',
				'Konfirmasi Keputusan Review',
				'Catatan untuk penulis:',
				'penalis-confirm-feedback-box',
				'penalis-confirm-feedback-text'
			);
			?>
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

		$feedback    = isset( $_POST['feedback'] ) ? sanitize_textarea_field( wp_unslash( $_POST['feedback'] ) ) : '';
		$cycle       = isset( $_POST['expected_cycle'] ) ? (int) $_POST['expected_cycle'] : 0;
		$raw_return  = isset( $_POST['penalis_return'] ) && is_array( $_POST['penalis_return'] ) ? wp_unslash( $_POST['penalis_return'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$return_args = self::return_args(
			isset( $raw_return['state'] ) ? sanitize_key( (string) $raw_return['state'] ) : '',
			self::parse_list_filters( $raw_return )
		);

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
			self::redirect( $post_id, $result->get_error_code(), $return_args );
		}

		self::redirect( $post_id, 'done', $return_args );
	}

	/**
	 * Restore a recorded published article from the WordPress trash.
	 *
	 * @return void
	 */
	public static function handle_restore_action() {
		if ( ! Penalis_Editorial_Workflow::can_review( get_current_user_id() ) ) {
			wp_die( 'Kamu tidak memiliki izin untuk memulihkan karya tulis.' );
		}

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$action  = isset( $_POST['penalis_action'] ) ? sanitize_key( wp_unslash( $_POST['penalis_action'] ) ) : '';

		check_admin_referer( 'penalis_restore_' . $post_id );

		if ( $post_id <= 0 || 'restore' !== $action ) {
			self::redirect_trashed( 'restore_invalid' );
		}

		$result = Penalis_Article_Takedown::restore( $post_id, get_current_user_id() );

		self::redirect_trashed( is_wp_error( $result ) ? $result->get_error_code() : 'restored' );
	}

	/**
	 * Redirect back to the takedown and recovery screen.
	 *
	 * @param string $message Message key.
	 * @return void
	 */
	private static function redirect_trashed( $message ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                   => self::TRASHED_PAGE_SLUG,
					'penalis_review_message' => $message,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Redirect back to the review screen.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $message     Message key.
	 * @param array  $return_args List filters to restore on the detail screen.
	 * @return void
	 */
	private static function redirect( $post_id, $message, $return_args = array() ) {
		$args = array(
			'page' => self::PAGE_SLUG,
		);

		if ( $post_id > 0 ) {
			$args['post_id'] = $post_id;
		}

		$args['penalis_review_message'] = $message;

		if ( ! empty( $return_args['state'] ) ) {
			$args['state'] = sanitize_key( (string) $return_args['state'] );
		}

		$args = array_merge( $args, self::filter_query_args( $return_args ) );

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
			'penalis_editorial_busy'    => array( 'error', 'Karya tulis ini sedang diproses senator lain. Coba sesaat lagi.' ),
			'penalis_editorial_failed'  => array( 'error', 'Status karya tulis tidak dapat diperbarui.' ),
			'penalis_not_found'         => array( 'error', 'Karya tulis tidak ditemukan.' ),
			'penalis_not_authenticated' => array( 'error', 'Sesi kamu berakhir. Silakan login kembali.' ),
			'penalis_account_inactive'  => array( 'error', 'Akun kamu tidak diizinkan melakukan tindakan ini.' ),
			'restored'                  => array( 'success', 'Karya tulis berhasil dipulihkan dari trash.' ),
			'penalis_restore_failed'    => array( 'error', 'Karya tulis tidak dapat dipulihkan. Coba lagi atau hubungi Senator Penalis.' ),
			'restore_invalid'           => array( 'error', 'Permintaan pemulihan tidak valid.' ),
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
	 * @param string $state   Editorial state filter.
	 * @param array  $filters Optional normalized or raw list filters.
	 * @return string
	 */
	public static function list_url( $state = Penalis_Editorial_Policy::STATE_PENDING_REVIEW, $filters = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page'  => self::PAGE_SLUG,
					'state' => $state,
				),
				self::filter_query_args( $filters )
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * URL of a single submission review screen.
	 *
	 * List filters are carried in the URL so the detail screen can link back
	 * to the exact slice of the queue the reviewer came from.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $args    Optional state and list filters to preserve.
	 * @return string
	 */
	public static function detail_url( $post_id, $args = array() ) {
		$query_args = array(
			'page'    => self::PAGE_SLUG,
			'post_id' => (int) $post_id,
		);

		if ( ! empty( $args['state'] ) ) {
			$query_args['state'] = sanitize_key( (string) $args['state'] );
		}

		$query_args = array_merge( $query_args, self::filter_query_args( $args ) );

		return add_query_arg( $query_args, admin_url( 'admin.php' ) );
	}

	/**
	 * Normalize the review list filters from a request or an explicit array.
	 *
	 * Keeping this pure (apart from reading `$_GET` when no input is given)
	 * lets the admin screen and the tests share one definition of a valid
	 * filter. Values are sanitized here, before they reach `WP_Query` or a URL.
	 *
	 * @param array|null $params Optional raw parameters; defaults to `$_GET`.
	 * @return array<string,string|int> Normalized filters.
	 */
	public static function parse_list_filters( $params = null ) {
		$keys = array( 's', 'author', 'cat', 'from', 'to', 'cycle', 'orderby', 'order' );

		if ( null === $params ) {
			$params = array();
			foreach ( $keys as $key ) {
				if ( isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$params[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				}
			}
		}

		$params = is_array( $params ) ? $params : array();
		$read   = static function ( $key ) use ( $params ) {
			if ( ! isset( $params[ $key ] ) || ! is_scalar( $params[ $key ] ) ) {
				return '';
			}

			return trim( sanitize_text_field( (string) $params[ $key ] ) );
		};

		$search = $read( 's' );
		if ( function_exists( 'mb_substr' ) ) {
			$search = mb_substr( $search, 0, 100 );
		} else {
			$search = substr( $search, 0, 100 );
		}

		$from = self::normalize_filter_date( $read( 'from' ) );
		$to   = self::normalize_filter_date( $read( 'to' ) );

		if ( '' !== $from && '' !== $to && $from > $to ) {
			$swap = $from;
			$from = $to;
			$to   = $swap;
		}

		$orderby = $read( 'orderby' );
		if ( ! in_array( $orderby, array( 'modified', 'submitted', 'title', 'author', 'cycle' ), true ) ) {
			$orderby = 'modified';
		}

		$order = strtoupper( $read( 'order' ) );
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = 'DESC';
		}

		return array(
			's'       => $search,
			'author'  => max( 0, (int) $read( 'author' ) ),
			'cat'     => max( 0, (int) $read( 'cat' ) ),
			'from'    => $from,
			'to'      => $to,
			'cycle'   => max( 0, (int) $read( 'cycle' ) ),
			'orderby' => $orderby,
			'order'   => $order,
		);
	}

	/**
	 * Keep only non-default filter values for links and hidden form fields.
	 *
	 * @param array $filters Normalized or raw filters.
	 * @return array<string,string|int>
	 */
	private static function filter_query_args( $filters ) {
		$filters = self::parse_list_filters( $filters );
		$args    = array();

		if ( '' !== $filters['s'] ) {
			$args['s'] = $filters['s'];
		}
		if ( $filters['author'] > 0 ) {
			$args['author'] = $filters['author'];
		}
		if ( $filters['cat'] > 0 ) {
			$args['cat'] = $filters['cat'];
		}
		if ( '' !== $filters['from'] ) {
			$args['from'] = $filters['from'];
		}
		if ( '' !== $filters['to'] ) {
			$args['to'] = $filters['to'];
		}
		if ( $filters['cycle'] > 0 ) {
			$args['cycle'] = $filters['cycle'];
		}
		if ( 'modified' !== $filters['orderby'] ) {
			$args['orderby'] = $filters['orderby'];
		}
		if ( 'DESC' !== $filters['order'] ) {
			$args['order'] = $filters['order'];
		}

		return $args;
	}

	/**
	 * Build the state plus filter payload used by detail and redirect links.
	 *
	 * @param string $state   Editorial state.
	 * @param array  $filters Raw or normalized filters.
	 * @return array<string,string|int>
	 */
	public static function return_args( $state, $filters ) {
		return array_merge(
			array( 'state' => sanitize_key( (string) $state ) ),
			self::filter_query_args( $filters )
		);
	}

	/**
	 * Validate a `Y-m-d` filter value without letting PHP roll over bad dates.
	 *
	 * @param string $value Raw date.
	 * @return string Valid date or an empty string.
	 */
	private static function normalize_filter_date( $value ) {
		$value = trim( (string) $value );

		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches ) ) {
			return '';
		}

		return checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] ) ? $value : '';
	}

	/**
	 * Convert a site-local calendar day to a Unix timestamp for meta queries.
	 *
	 * @param string $date       Valid `Y-m-d` date.
	 * @param bool   $end_of_day Include the whole day when true.
	 * @return int Unix timestamp, or 0 when the date cannot be parsed.
	 */
	private static function filter_date_timestamp( $date, $end_of_day ) {
		$time = $end_of_day ? '23:59:59' : '00:00:00';
		$date = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $date . ' ' . $time, Penalis_Datetime::site_timezone() );

		return $date ? (int) $date->getTimestamp() : 0;
	}

	/**
	 * Merge normalized list filters into `WP_Query` arguments.
	 *
	 * Existing state clauses are preserved. When a state contributes an `OR`
	 * meta query (drafts without workflow metadata), it is wrapped in an `AND`
	 * group so the added filters still narrow the result set.
	 *
	 * @param array $args    Base `WP_Query` arguments.
	 * @param array $filters Raw or normalized filters.
	 * @return array<string,mixed>
	 */
	public static function apply_list_filters( $args, $filters ) {
		$args    = is_array( $args ) ? $args : array();
		$filters = self::parse_list_filters( $filters );

		if ( '' !== $filters['s'] ) {
			$args['s'] = $filters['s'];
		}
		if ( $filters['author'] > 0 ) {
			$args['author'] = $filters['author'];
		}
		if ( $filters['cat'] > 0 ) {
			$args['cat'] = $filters['cat'];
		}

		$extra = array();

		if ( $filters['cycle'] > 0 ) {
			$extra[] = array(
				'key'   => Penalis_Editorial_Policy::META_REVIEW_CYCLE,
				'value' => $filters['cycle'],
				'type'  => 'NUMERIC',
			);
		}

		if ( '' !== $filters['from'] ) {
			$extra[] = array(
				'key'     => Penalis_Editorial_Policy::META_SUBMITTED_AT,
				'value'   => self::filter_date_timestamp( $filters['from'], false ),
				'compare' => '>=',
				'type'    => 'NUMERIC',
			);
		}

		if ( '' !== $filters['to'] ) {
			$extra[] = array(
				'key'     => Penalis_Editorial_Policy::META_SUBMITTED_AT,
				'value'   => self::filter_date_timestamp( $filters['to'], true ),
				'compare' => '<=',
				'type'    => 'NUMERIC',
			);
		}

		$sort_key = '';
		if ( 'submitted' === $filters['orderby'] ) {
			$sort_key = Penalis_Editorial_Policy::META_SUBMITTED_AT;
		} elseif ( 'cycle' === $filters['orderby'] ) {
			$sort_key = Penalis_Editorial_Policy::META_REVIEW_CYCLE;
		}

		if ( '' !== $sort_key ) {
			$extra['penalis_sort'] = array(
				'key'     => $sort_key,
				'compare' => 'EXISTS',
				'type'    => 'NUMERIC',
			);
			$args['orderby']       = array( 'penalis_sort' => $filters['order'] );
		} else {
			$args['orderby'] = $filters['orderby'];
		}

		$args['order'] = $filters['order'];

		if ( ! empty( $extra ) ) {
			$meta_query = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();

			if ( ! empty( $meta_query ) && isset( $meta_query['relation'] ) && 'OR' === strtoupper( (string) $meta_query['relation'] ) ) {
				$meta_query = array(
					'relation' => 'AND',
					$meta_query,
				);
			}

			$args['meta_query'] = array_merge( $meta_query, $extra ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		return $args;
	}

	/**
	 * Render the search/filter/sort toolbar above the submission table.
	 *
	 * @param string $state   Current editorial state.
	 * @param array  $filters Normalized list filters.
	 * @param int    $total   Number of submissions matching the current view.
	 * @return void
	 */
	private static function render_filter_bar( $state, $filters, $total ) {
		$authors    = function_exists( 'get_users' )
			? get_users(
				array(
					'fields'  => 'all',
					'number'  => 500,
					'orderby' => 'display_name',
					'order'   => 'ASC',
				)
			)
			: array();
		$categories = function_exists( 'get_categories' )
			? get_categories(
				array(
					'hide_empty' => false,
					'number'     => 500,
					'orderby'    => 'name',
					'order'      => 'ASC',
				)
			)
			: array();

		$sort_options = array(
			'modified'  => 'Terakhir diubah',
			'submitted' => 'Tanggal submit',
			'title'     => 'Judul',
			'author'    => 'Nama penulis',
			'cycle'     => 'Review cycle',
		);
		?>
		<form method="get" class="penalis-toolbar penalis-review__filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<input type="hidden" name="state" value="<?php echo esc_attr( $state ); ?>">

			<label for="penalis-review-search" class="screen-reader-text">Cari judul atau isi karya tulis</label>
			<input
				type="search"
				id="penalis-review-search"
				name="s"
				value="<?php echo esc_attr( (string) $filters['s'] ); ?>"
				placeholder="Cari judul atau isi"
			>

			<label for="penalis-review-author" class="screen-reader-text">Filter penulis</label>
			<select id="penalis-review-author" name="author">
				<option value="">Semua penulis</option>
				<?php foreach ( (array) $authors as $author ) : ?>
					<option value="<?php echo esc_attr( (string) $author->ID ); ?>" <?php selected( $filters['author'], (int) $author->ID ); ?>>
						<?php echo esc_html( $author->display_name . ' (' . $author->user_login . ')' ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="penalis-review-category" class="screen-reader-text">Filter kategori</label>
			<select id="penalis-review-category" name="cat">
				<option value="">Semua kategori</option>
				<?php foreach ( (array) $categories as $category ) : ?>
					<option value="<?php echo esc_attr( (string) $category->term_id ); ?>" <?php selected( $filters['cat'], (int) $category->term_id ); ?>>
						<?php echo esc_html( (string) $category->name ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="penalis-review-from" class="screen-reader-text">Submit dari tanggal</label>
			<input type="date" id="penalis-review-from" name="from" value="<?php echo esc_attr( (string) $filters['from'] ); ?>">

			<label for="penalis-review-to" class="screen-reader-text">Submit sampai tanggal</label>
			<input type="date" id="penalis-review-to" name="to" value="<?php echo esc_attr( (string) $filters['to'] ); ?>">

			<label for="penalis-review-cycle" class="screen-reader-text">Filter review cycle</label>
			<input
				type="number"
				id="penalis-review-cycle"
				name="cycle"
				min="1"
				value="<?php echo esc_attr( 0 === $filters['cycle'] ? '' : (string) $filters['cycle'] ); ?>"
				placeholder="Cycle"
			>

			<label for="penalis-review-orderby" class="screen-reader-text">Urutkan berdasarkan</label>
			<select id="penalis-review-orderby" name="orderby">
				<?php foreach ( $sort_options as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['orderby'], $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="penalis-review-order" class="screen-reader-text">Arah urutan</label>
			<select id="penalis-review-order" name="order">
				<option value="DESC" <?php selected( $filters['order'], 'DESC' ); ?>>Menurun</option>
				<option value="ASC" <?php selected( $filters['order'], 'ASC' ); ?>>Menaik</option>
			</select>

			<button type="submit" class="button">Terapkan</button>
			<?php if ( ! empty( self::filter_query_args( $filters ) ) ) : ?>
				<a class="button button-link" href="<?php echo esc_url( self::list_url( $state ) ); ?>">Hapus filter</a>
			<?php endif; ?>
			<span class="penalis-toolbar__count">
				<?php echo esc_html( number_format_i18n( $total ) . ' karya tulis' ); ?>
			</span>
		</form>
		<?php
	}

	/**
	 * Count submissions awaiting reviewer review.
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
	 * Recent submissions awaiting reviewer review.
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
	 * @param array  $filters  Optional raw or normalized list filters.
	 * @return array<string,mixed>
	 */
	public static function query_args( $state, $paged, $per_page, $filters = array() ) {
		return self::apply_list_filters(
			array_merge(
				array(
					'post_type'      => 'post',
					'posts_per_page' => $per_page,
					'paged'          => $paged,
					'orderby'        => 'modified',
					'order'          => 'DESC',
				),
				Penalis_Editorial_Workflow::state_query_clauses( $state )
			),
			$filters
		);
	}

	/**
	 * Count submissions in one editorial state.
	 *
	 * @param string $state Editorial state.
	 * @return int
	 */
	private static function count_state( $state ) {
		return Penalis_Editorial_Workflow::count_matching_posts( self::query_args( $state, 1, 1 ) );
	}
}
