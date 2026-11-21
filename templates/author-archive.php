<?php
/**
 * Public author archive template.
 *
 * Uses the active theme's header and footer so the profile and works grid keep
 * the site's navigation and branding without relying on Elementor templates.
 *
 * A theme may override this by adding penalis-author-archive.php.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$penalis_author = Penalis_Author_Archive::resolve_queried_author();
$penalis_data   = $penalis_author instanceof WP_User ? Penalis_Author_Archive::get_profile_data( $penalis_author ) : null;

get_header();
?>
<div class="penalis-author-archive-root">
	<main id="primary" class="penalis-archive-container">
		<?php if ( ! is_array( $penalis_data ) ) : ?>
			<div class="penalis-archive-empty">
				<p>Profil penulis tidak ditemukan.</p>
				<a class="penalis-archive-btn" href="<?php echo esc_url( Penalis_Author_Archive::directory_url() ); ?>">
					Lihat semua kontributor
				</a>
			</div>
		<?php else : ?>
			<section class="penalis-profile-card" aria-label="Profil penulis">
				<div class="penalis-profile-cover" aria-hidden="true"></div>

				<div class="penalis-profile-body">
					<div class="penalis-profile-top">
						<div class="penalis-profile-avatar-wrap">
							<img
								src="<?php echo esc_url( (string) $penalis_data['avatar_url'] ); ?>"
								alt="<?php echo esc_attr( (string) $penalis_data['display_name'] ); ?>"
								class="penalis-profile-avatar"
								width="104"
								height="104"
							>
						</div>

						<div class="penalis-profile-actions">
							<button
								type="button"
								class="penalis-archive-btn penalis-archive-btn--primary"
								data-penalis-share
								data-share-url="<?php echo esc_url( (string) $penalis_data['profile_url'] ); ?>"
								data-share-title="<?php echo esc_attr( (string) $penalis_data['display_name'] . ' — ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?>"
								data-share-text="<?php echo esc_attr( sprintf( 'Baca karya tulis %s di Penalis.', (string) $penalis_data['display_name'] ) ); ?>"
								data-default-label="Bagikan"
								data-copied-label="Tautan disalin!"
							>
								<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
									<circle cx="18" cy="5" r="3" />
									<circle cx="6" cy="12" r="3" />
									<circle cx="18" cy="19" r="3" />
									<line x1="8.59" y1="13.51" x2="15.42" y2="17.49" />
									<line x1="15.41" y1="6.51" x2="8.59" y2="10.49" />
								</svg>
								<span data-penalis-share-label>Bagikan</span>
							</button>
						</div>
					</div>

					<div class="penalis-profile-identity">
						<h1 class="penalis-profile-name">
							<span><?php echo esc_html( (string) $penalis_data['display_name'] ); ?></span>
							<?php if ( ! empty( $penalis_data['is_verified'] ) || ! empty( $penalis_data['is_senator'] ) ) : ?>
								<span class="penalis-profile-badges">
									<?php
									if ( ! empty( $penalis_data['is_verified'] ) ) {
										echo Penalis_Authors::verified_badge_html( '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static escaped badge markup.
									}
									if ( ! empty( $penalis_data['is_senator'] ) ) {
										echo Penalis_Authors::senator_badge_html( '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static escaped badge markup.
									}
									?>
								</span>
							<?php endif; ?>
						</h1>

						<div class="penalis-profile-meta">
							<span class="penalis-profile-handle">@<?php echo esc_html( (string) $penalis_data['username'] ); ?></span>

							<?php if ( '' !== (string) $penalis_data['profile_url_label'] || '' !== (string) $penalis_data['joined_label'] ) : ?>
								<div class="penalis-profile-meta-details">
									<?php if ( '' !== (string) $penalis_data['profile_url_label'] ) : ?>
										<button
											type="button"
											class="penalis-profile-url-btn"
											data-penalis-copy-url
											data-copy-url="<?php echo esc_url( (string) $penalis_data['profile_url'] ); ?>"
											data-default-label="<?php echo esc_attr( (string) $penalis_data['profile_url_label'] ); ?>"
											data-copied-label="URL profil disalin!"
											title="Salin tautan profil"
										>
											<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
												<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
												<path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
											</svg>
											<span data-penalis-copy-label><?php echo esc_html( (string) $penalis_data['profile_url_label'] ); ?></span>
										</button>
									<?php endif; ?>

									<?php if ( '' !== (string) $penalis_data['joined_label'] ) : ?>
										<span class="penalis-profile-joined">
											<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
												<rect x="3" y="4" width="18" height="18" rx="2" ry="2" />
												<line x1="16" y1="2" x2="16" y2="6" />
												<line x1="8" y1="2" x2="8" y2="6" />
												<line x1="3" y1="10" x2="21" y2="10" />
											</svg>
											<span>Bergabung <?php echo esc_html( (string) $penalis_data['joined_label'] ); ?></span>
										</span>
									<?php endif; ?>
								</div>
							<?php endif; ?>
						</div>

						<?php if ( '' !== (string) $penalis_data['bio'] ) : ?>
							<?php if ( ! empty( $penalis_data['is_bio_collapsible'] ) ) : ?>
								<div class="penalis-profile-bio-wrap">
									<p id="penalis-author-bio" class="penalis-profile-bio is-clamped" data-penalis-bio><?php echo nl2br( esc_html( (string) $penalis_data['bio'] ) ); ?></p>
									<button
										type="button"
										class="penalis-profile-bio-toggle"
										data-penalis-bio-toggle
										aria-expanded="false"
										aria-controls="penalis-author-bio"
									>
										Selengkapnya
									</button>
								</div>
							<?php else : ?>
								<p class="penalis-profile-bio"><?php echo nl2br( esc_html( (string) $penalis_data['bio'] ) ); ?></p>
							<?php endif; ?>
						<?php endif; ?>

						<?php if ( ! empty( $penalis_data['can_write'] ) ) : ?>
							<div class="penalis-profile-owner-actions" aria-label="Tindakan pemilik profil">
								<a
									class="penalis-archive-btn"
									href="<?php echo esc_url( (string) $penalis_data['edit_profile_url'] ); ?>"
								>
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
										<path d="M12 20h9" />
										<path d="M16.5 3.5a2.121 2.121 0 1 1 3 3L7 19l-4 1 1-4L16.5 3.5z" />
									</svg>
									<span>Edit Profil</span>
								</a>
								<a
									class="penalis-archive-btn"
									href="<?php echo esc_url( (string) $penalis_data['new_article_url'] ); ?>"
								>
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
										<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
										<polyline points="14 2 14 8 20 8" />
										<line x1="12" y1="18" x2="12" y2="12" />
										<line x1="9" y1="15" x2="15" y2="15" />
									</svg>
									<span>Tulis Karya</span>
								</a>
							</div>
						<?php endif; ?>

						<div class="penalis-profile-stats" aria-label="Statistik kontribusi">
							<div class="penalis-profile-stat">
								<span class="penalis-profile-stat-value"><?php echo esc_html( Penalis_Author_Archive::format_number( (int) $penalis_data['post_count'] ) ); ?></span>
								<span class="penalis-profile-stat-label">Karya Terbit</span>
							</div>
							<?php if ( null !== $penalis_data['total_views'] ) : ?>
								<div class="penalis-profile-stat">
									<span class="penalis-profile-stat-value"><?php echo esc_html( Penalis_Author_Archive::format_number( (int) $penalis_data['total_views'] ) ); ?></span>
									<span class="penalis-profile-stat-label">Total Views</span>
								</div>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</section>

			<p class="penalis-archive-sr-status" role="status" aria-live="polite" data-penalis-archive-status></p>

			<section class="penalis-archive-works" aria-label="Karya tulis">
				<div class="penalis-archive-works-head">
					<h2 class="penalis-archive-works-title">
						<?php
						echo esc_html(
							sprintf(
								'%s karya tulis dari %s',
								Penalis_Author_Archive::format_number( (int) $penalis_data['post_count'] ),
								(string) $penalis_data['display_name']
							)
						);
						?>
					</h2>
				</div>

				<?php if ( have_posts() ) : ?>
					<div class="penalis-archive-grid">
						<?php
						while ( have_posts() ) :
							the_post();
							$penalis_post_id   = (int) get_the_ID();
							$penalis_post_obj  = get_post( $penalis_post_id );
							$penalis_category  = Penalis_Author_Archive::get_primary_category( $penalis_post_id );
							$penalis_views     = Penalis_Author_Archive::get_post_views( $penalis_post_id );
							$penalis_date_text = $penalis_post_obj instanceof WP_Post
								? Penalis_Datetime::format_gmt( (string) $penalis_post_obj->post_date_gmt, 'd/m/Y' )
								: get_the_date( 'd/m/Y' );
							$penalis_excerpt   = wp_trim_words( wp_strip_all_tags( (string) get_the_excerpt() ), 22, '…' );
							?>
							<article class="penalis-archive-card">
								<a class="penalis-archive-card-media" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
									<?php if ( has_post_thumbnail() ) : ?>
										<?php
										the_post_thumbnail(
											'medium_large',
											array(
												'class'   => 'penalis-archive-card-img',
												'loading' => 'lazy',
											)
										);
										?>
									<?php else : ?>
										<div class="penalis-archive-card-placeholder">
											<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
												<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
												<polyline points="14 2 14 8 20 8" />
												<line x1="16" y1="13" x2="8" y2="13" />
												<line x1="16" y1="17" x2="8" y2="17" />
											</svg>
										</div>
									<?php endif; ?>
								</a>

								<div class="penalis-archive-card-body">
									<?php if ( $penalis_category instanceof WP_Term ) : ?>
										<a class="penalis-archive-card-cat" href="<?php echo esc_url( (string) get_category_link( $penalis_category->term_id ) ); ?>">
											<?php echo esc_html( $penalis_category->name ); ?>
										</a>
									<?php endif; ?>

									<h3 class="penalis-archive-card-title">
										<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
									</h3>

									<?php if ( '' !== $penalis_excerpt ) : ?>
										<p class="penalis-archive-card-excerpt"><?php echo esc_html( $penalis_excerpt ); ?></p>
									<?php endif; ?>

									<div class="penalis-archive-card-meta">
										<span><?php echo esc_html( $penalis_date_text ); ?></span>
										<?php if ( null !== $penalis_views ) : ?>
											<span aria-hidden="true">·</span>
											<span><?php echo esc_html( Penalis_Author_Archive::format_number( $penalis_views ) ); ?> views</span>
										<?php endif; ?>
									</div>
								</div>
							</article>
						<?php endwhile; ?>
					</div>

					<?php
					$penalis_pagination = paginate_links(
						array(
							'type'      => 'list',
							'prev_text' => '&lsaquo; Sebelumnya',
							'next_text' => 'Berikutnya &rsaquo;',
						)
					);
					if ( is_string( $penalis_pagination ) && '' !== $penalis_pagination ) :
						?>
						<nav class="penalis-archive-pagination" aria-label="Navigasi halaman karya">
							<?php echo wp_kses_post( $penalis_pagination ); ?>
						</nav>
					<?php endif; ?>
				<?php else : ?>
					<div class="penalis-archive-empty">
						<p>Belum ada karya tulis yang diterbitkan oleh penulis ini.</p>
						<?php if ( ! empty( $penalis_data['can_write'] ) ) : ?>
							<a class="penalis-archive-btn penalis-archive-btn--primary" href="<?php echo esc_url( (string) $penalis_data['new_article_url'] ); ?>">
								Mulai Menulis Karya
							</a>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</section>
		<?php endif; ?>
	</main>
</div>
<?php
get_footer();
