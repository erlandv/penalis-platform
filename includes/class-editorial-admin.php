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
		$menu_title = 'Tinjauan Karya';

		if ( $pending > 0 ) {
			$menu_title .= sprintf(
				' <span class="awaiting-mod count-%1$d"><span class="pending-count" aria-hidden="true">%2$s</span></span>',
				$pending,
				number_format_i18n( $pending )
			);
		}

		add_submenu_page(
			Penalis_Admin::MENU_SLUG,
			'Tinjauan Karya — Penalis Platform',
			$menu_title,
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
		$allowed_hooks = array(
			Penalis_Admin::MENU_SLUG . '_page_' . self::PAGE_SLUG,
			'posts_page_' . self::PAGE_SLUG,
		);

		if ( ! in_array( $hook, $allowed_hooks, true ) ) {
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
.penalis-review .penalis-review__table td{vertical-align:top;padding:12px 10px}
.penalis-review .penalis-review__title{display:block;font-size:14px;font-weight:600;color:#1d2327;margin-bottom:2px;text-decoration:none}
.penalis-review .penalis-review__title:hover{color:#2271b1}
.penalis-review .penalis-review__meta{display:block;color:#646970;font-size:12px;line-height:1.5}
.penalis-review .penalis-review__state{display:inline-flex;align-items:center;gap:6px;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:600;line-height:1.7;white-space:nowrap}
.penalis-review .penalis-review__state-dot{width:6px;height:6px;border-radius:50%;background:currentColor;flex:0 0 6px}
.penalis-review .penalis-review__state--pending_review{background:#fcf9e8;color:#8a6116}
.penalis-review .penalis-review__state--changes_requested{background:#f0f6fc;color:#0a4b78}
.penalis-review .penalis-review__state--rejected{background:#fcf0f1;color:#8a2424}
.penalis-review .penalis-review__state--published{background:#edfaef;color:#007017}
.penalis-review .penalis-review__state--draft{background:#f0f0f1;color:#50575e}
.penalis-review .penalis-review__empty{padding:28px 16px;text-align:center;color:#646970}
.penalis-review .penalis-review__back{margin:10px 0 14px;font-size:13px}
.penalis-review .penalis-review__back a{text-decoration:none;color:#2271b1;font-weight:500}
.penalis-review .penalis-review__back a:hover{color:#135e96;text-decoration:underline}
.penalis-review .penalis-review__header-card{margin:0 0 20px;padding:20px 24px;background:#fff;border:1px solid #dcdcde;border-radius:6px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
.penalis-review .penalis-review__header-top{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}
.penalis-review .penalis-review__badges{display:flex;flex-wrap:wrap;align-items:center;gap:8px}
.penalis-review .penalis-review__cycle-badge{display:inline-flex;align-items:center;padding:2px 9px;border-radius:999px;background:#f0f0f1;color:#3c434a;font-size:11px;font-weight:600;line-height:1.7}
.penalis-review .penalis-review__header-actions{display:flex;flex-wrap:wrap;align-items:center;gap:8px}
.penalis-review .penalis-review__header-actions .button{margin:0}
.penalis-review .penalis-review__article-title{margin:0 0 14px;padding:0;font-size:22px;font-weight:700;line-height:1.35;color:#1d2327}
.penalis-review .penalis-review__meta-bar{display:flex;flex-wrap:wrap;align-items:center;gap:16px;padding-top:14px;border-top:1px solid #f0f0f1;font-size:12px;color:#50575e}
.penalis-review .penalis-review__author{display:inline-flex;align-items:center;gap:8px}
.penalis-review .penalis-review__author-avatar{width:24px;height:24px;border-radius:50%;object-fit:cover;background:#f0f0f1;flex:0 0 24px}
.penalis-review .penalis-review__author-name{font-weight:600;color:#1d2327}
.penalis-review .penalis-review__author-login{color:#646970}
.penalis-review .penalis-review__terms{display:inline-flex;flex-wrap:wrap;align-items:center;gap:6px}
.penalis-review .penalis-review__term{display:inline-block;padding:1px 8px;border-radius:4px;background:#f6f7f7;border:1px solid #dcdcde;color:#3c434a;font-size:11px;line-height:1.6}
.penalis-review .penalis-review__workspace{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:20px;align-items:start}
.penalis-review .penalis-review__main{min-width:0;display:flex;flex-direction:column;gap:16px}
.penalis-review .penalis-review__sidebar{min-width:0;display:flex;flex-direction:column;gap:16px;position:sticky;top:46px}
.penalis-review .penalis-review__feedback{padding:14px 16px;border:1px solid #f0d878;border-left:4px solid #dba617;border-radius:6px;background:#fcf9e8;color:#3c434a}
.penalis-review .penalis-review__feedback strong{display:block;margin-bottom:4px;font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:#8a6116}
.penalis-review .penalis-review__feedback p{margin:0;font-size:13px;line-height:1.6;white-space:pre-line}
.penalis-review .penalis-review__card{background:#fff;border:1px solid #dcdcde;border-radius:6px;box-shadow:0 1px 2px rgba(0,0,0,.04);overflow:hidden}
.penalis-review .penalis-review__card-header{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:14px 20px;border-bottom:1px solid #f0f0f1;background:#fafafa}
.penalis-review .penalis-review__card-header h2{margin:0;font-size:14px;font-weight:600;color:#1d2327}
.penalis-review .penalis-review__word-count{font-size:12px;color:#646970}
.penalis-review .penalis-review__featured{margin:0;padding:20px 24px 0;text-align:center}
.penalis-review .penalis-review__featured img{display:block;max-width:100%;max-height:360px;width:auto;height:auto;margin:0 auto;border-radius:6px;border:1px solid #e5e5e5}
.penalis-review .penalis-review__featured figcaption{margin-top:6px;font-size:11px;color:#646970}
.penalis-review .penalis-review__content{padding:24px 28px;font-size:15px;line-height:1.8;color:#1d2327;overflow-wrap:break-word}
.penalis-review .penalis-review__content p{margin:0 0 1.2em;font-size:15px;line-height:1.8}
.penalis-review .penalis-review__content p:last-child{margin-bottom:0}
.penalis-review .penalis-review__content h1,.penalis-review .penalis-review__content h2,.penalis-review .penalis-review__content h3,.penalis-review .penalis-review__content h4{margin:1.4em 0 .6em;color:#1d2327;line-height:1.35}
.penalis-review .penalis-review__content blockquote{margin:1.2em 0;padding:10px 16px;border-left:3px solid #c3c4c7;background:#f6f7f7;color:#3c434a;font-style:italic}
.penalis-review .penalis-review__content img{max-width:100%;height:auto;border-radius:4px}
.penalis-review .penalis-review__card-body{padding:18px 20px}
.penalis-review .penalis-review__decision-help{margin:0 0 14px;font-size:12.5px;line-height:1.55;color:#50575e}
.penalis-review .penalis-review__field{margin:0 0 14px}
.penalis-review .penalis-review__field label{display:block;font-size:13px;font-weight:600;color:#1d2327;margin-bottom:6px}
.penalis-review .penalis-review__field textarea{width:100%;min-height:110px;padding:8px 10px;font-size:13px;line-height:1.5;border:1px solid #8c8f94;border-radius:4px;resize:vertical}
.penalis-review .penalis-review__field .description{margin:5px 0 0;font-size:12px;color:#646970;line-height:1.45}
.penalis-review .penalis-review__decision-buttons{display:flex;flex-direction:column;gap:8px}
.penalis-review .penalis-review__btn-approve{width:100%;justify-content:center;text-align:center;padding:4px 12px;font-weight:600}
.penalis-review .penalis-review__decision-secondary{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.penalis-review .penalis-review__decision-secondary .button{margin:0;text-align:center;justify-content:center}
.penalis-review .button.penalis-review__danger{color:#b32d2e;border-color:#b32d2e}
.penalis-review .button.penalis-review__danger:hover{background:#b32d2e;border-color:#b32d2e;color:#fff}
.penalis-review .button.penalis-review__danger:focus{border-color:#b32d2e;color:#b32d2e;box-shadow:0 0 0 1px #b32d2e}
.penalis-review .penalis-review__state-note{margin:0;font-size:13px;line-height:1.6;color:#50575e}
.penalis-review .penalis-review__timeline{margin:0;padding:0;list-style:none}
.penalis-review .penalis-review__timeline-item{position:relative;padding:0 0 14px 18px;border-left:2px solid #e5e5e5;margin-left:5px}
.penalis-review .penalis-review__timeline-item:last-child{padding-bottom:0;border-left-color:transparent}
.penalis-review .penalis-review__timeline-dot{position:absolute;left:-6px;top:3px;width:10px;height:10px;border-radius:50%;background:#c3c4c7;border:2px solid #fff;box-sizing:border-box}
.penalis-review .penalis-review__timeline-item--approve .penalis-review__timeline-dot{background:#007017}
.penalis-review .penalis-review__timeline-item--request_changes .penalis-review__timeline-dot{background:#dba617}
.penalis-review .penalis-review__timeline-item--reject .penalis-review__timeline-dot{background:#b32d2e}
.penalis-review .penalis-review__timeline-item--submit .penalis-review__timeline-dot,.penalis-review .penalis-review__timeline-item--resubmit .penalis-review__timeline-dot{background:#2271b1}
.penalis-review .penalis-review__timeline-head{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-bottom:2px}
.penalis-review .penalis-review__timeline-action{font-size:12.5px;font-weight:600;color:#1d2327}
.penalis-review .penalis-review__timeline-cycle{font-size:11px;padding:0 6px;border-radius:999px;background:#f0f0f1;color:#50575e}
.penalis-review .penalis-review__timeline-meta{display:block;font-size:11.5px;color:#646970;line-height:1.4}
.penalis-review .penalis-review__timeline-note{margin:6px 0 0;padding:8px 10px;border-radius:4px;background:#f6f7f7;border-left:3px solid #c3c4c7;font-size:12px;line-height:1.5;color:#3c434a;white-space:pre-line}
@media screen and (max-width:960px){
.penalis-review .penalis-review__workspace{grid-template-columns:1fr}
.penalis-review .penalis-review__sidebar{position:static}
}
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
		$pages    = (int) $query->max_num_pages;

		?>
		<div class="wrap penalis-review">
			<h1 class="wp-heading-inline">Tinjauan Karya</h1>
			<hr class="wp-header-end">

			<?php self::render_notices(); ?>

			<p class="penalis-review__intro">
				Tinjau karya tulis yang diajukan penulis. Menyetujui akan menerbitkan artikel;
				meminta revisi atau menolak wajib disertai catatan agar penulis tahu apa yang harus
				diperbaiki. Artikel yang sudah terbit tidak dapat diedit penulis.
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
						$detail_url = self::detail_url( (int) $post->ID );
						?>
						<tr>
							<td>
								<a class="penalis-review__title" href="<?php echo esc_url( $detail_url ); ?>">
									<?php echo esc_html( '' !== $post->post_title ? $post->post_title : '(tanpa judul)' ); ?>
								</a>
								<span class="penalis-review__meta">
									<?php echo esc_html( sprintf( 'Siklus tinjauan #%d', max( 1, Penalis_Editorial_Workflow::get_review_cycle( $post ) ) ) ); ?>
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
				<h1>Tinjauan Karya</h1>
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
			<p class="penalis-review__back">
				<a href="<?php echo esc_url( self::list_url( $state ) ); ?>">&larr; Kembali ke daftar tinjauan</a>
			</p>

			<?php self::render_notices(); ?>

			<div class="penalis-review__header-card">
				<div class="penalis-review__header-top">
					<div class="penalis-review__badges">
						<span class="penalis-review__state penalis-review__state--<?php echo esc_attr( $state ); ?>">
							<span class="penalis-review__state-dot" aria-hidden="true"></span>
							<?php echo esc_html( Penalis_Editorial_Policy::state_label( $state ) ); ?>
						</span>
						<span class="penalis-review__cycle-badge">
							<?php echo esc_html( sprintf( 'Siklus tinjauan #%d', $cycle ) ); ?>
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
								Lihat artikel terbit &nearr;
							</a>
						<?php endif; ?>
					</div>
				</div>

				<h1 class="penalis-review__article-title">
					<?php echo esc_html( '' !== $post->post_title ? $post->post_title : '(tanpa judul)' ); ?>
				</h1>

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
						<span><?php echo esc_html( 'Diajukan: ' . date_i18n( 'j M Y H:i', $submitted ) ); ?></span>
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
								<figcaption>Gambar unggulan</figcaption>
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
							<h2>Keputusan Tinjauan</h2>
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
								<h2>Riwayat Tinjauan</h2>
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
														<?php echo esc_html( sprintf( 'Siklus #%d', $entry_cycle ) ); ?>
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
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="penalis_review_action">
			<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post->ID ); ?>">
			<input type="hidden" name="expected_cycle" value="<?php echo esc_attr( (string) $cycle ); ?>">
			<?php wp_nonce_field( 'penalis_review_' . $post->ID ); ?>

			<?php if ( $is_reopen_only ) : ?>
				<p class="penalis-review__decision-help">
					Karya tulis ini berstatus ditolak. Buka kembali agar penulis dapat menyunting dan
					mengajukannya ulang untuk ditinjau.
				</p>
				<div class="penalis-review__decision-buttons">
					<button
						type="submit"
						name="penalis_action"
						value="<?php echo esc_attr( Penalis_Editorial_Policy::ACTION_REOPEN ); ?>"
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
						placeholder="Tulis catatan tinjauan atau masukan perbaikan untuk penulis..."
					></textarea>
					<p class="description">
						Opsional saat menyetujui; wajib diisi saat meminta revisi atau menolak.
					</p>
				</div>

				<div class="penalis-review__decision-buttons">
					<?php if ( in_array( Penalis_Editorial_Policy::ACTION_APPROVE, $actions, true ) ) : ?>
						<button
							type="submit"
							name="penalis_action"
							value="<?php echo esc_attr( Penalis_Editorial_Policy::ACTION_APPROVE ); ?>"
							class="button button-primary penalis-review__btn-approve"
							onclick="var f=document.getElementById('penalis-review-feedback');if(f){f.required=false;}"
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
								class="button"
								onclick="var f=document.getElementById('penalis-review-feedback');if(f){f.required=true;}"
							>
								Minta revisi
							</button>
						<?php endif; ?>

						<?php if ( in_array( Penalis_Editorial_Policy::ACTION_REJECT, $actions, true ) ) : ?>
							<button
								type="submit"
								name="penalis_action"
								value="<?php echo esc_attr( Penalis_Editorial_Policy::ACTION_REJECT ); ?>"
								class="button penalis-review__danger"
								onclick="var f=document.getElementById('penalis-review-feedback');if(f){f.required=true;}"
							>
								Tolak
							</button>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>
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
				return 'Karya tulis ini telah disetujui dan diterbitkan. Tidak ada tindakan tinjauan yang diperlukan.';
			case Penalis_Editorial_Policy::STATE_CHANGES_REQUESTED:
				return 'Menunggu penulis memperbaiki dan mengajukan ulang karya tulis ini.';
			default:
				return 'Karya tulis ini masih berupa draf dan belum diajukan untuk ditinjau.';
		}
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

		$screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$blocked = array(
			'toplevel_page_' . Penalis_Admin::MENU_SLUG,
			Penalis_Admin::MENU_SLUG . '_page_' . self::PAGE_SLUG,
			'posts_page_' . self::PAGE_SLUG,
		);

		if ( $screen && in_array( $screen->id, $blocked, true ) ) {
			return;
		}

		$count = self::pending_review_count();

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
