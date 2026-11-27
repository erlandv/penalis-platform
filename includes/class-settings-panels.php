<?php
/**
 * Panel renderers and form handlers for centralized Penalis Platform settings.
 *
 * Keeps `Penalis_Settings` focused on the settings shell, sidebar navigation,
 * and legacy tab handlers while new option groups live in small, testable
 * methods backed by `Penalis_Config`.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders and handles settings cards backed by `Penalis_Config`.
 */
final class Penalis_Settings_Panels {

	/**
	 * Register `admin_post_` handlers for centralized configuration panels.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_penalis_save_general', array( __CLASS__, 'handle_save_general' ) );
		add_action( 'admin_post_penalis_save_email_branding', array( __CLASS__, 'handle_save_email_branding' ) );
		add_action( 'admin_post_penalis_reset_email_branding', array( __CLASS__, 'handle_reset_email_branding' ) );
		add_action( 'admin_post_penalis_save_editorial', array( __CLASS__, 'handle_save_editorial' ) );
		add_action( 'admin_post_penalis_save_article_display', array( __CLASS__, 'handle_save_article_display' ) );
		add_action( 'admin_post_penalis_save_account_policy', array( __CLASS__, 'handle_save_account_policy' ) );
		add_action( 'admin_post_penalis_save_security_advanced', array( __CLASS__, 'handle_save_security_advanced' ) );
		add_action( 'admin_post_penalis_reset_security_advanced', array( __CLASS__, 'handle_reset_security_advanced' ) );
		add_action( 'admin_post_penalis_save_performance', array( __CLASS__, 'handle_save_performance' ) );
		add_action( 'admin_post_penalis_reset_performance', array( __CLASS__, 'handle_reset_performance' ) );
		add_action( 'admin_post_penalis_purge_caches', array( __CLASS__, 'handle_purge_caches' ) );
	}

	/**
	 * Render the Umum & Halaman tab body.
	 *
	 * @return void
	 */
	public static function render_general_panels() {
		$cfg           = Penalis_Config::general();
		$dashboard_id  = Penalis_Dashboard::page_id();
		$quotes_id     = Penalis_Daily_Quotes::page_id();
		$logo_id       = (int) $cfg['brand_logo_id'];
		$icon_id       = (int) $cfg['brand_icon_id'];
		$brand_assets  = Penalis_Dashboard::brand_assets();
		$dashboard_url = Penalis_Dashboard::dashboard_url();
		$quotes_url    = Penalis_Daily_Quotes::page_url();
		$directory_url = Penalis_Author_Archive::directory_url();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="penalis_save_general" />
			<?php wp_nonce_field( 'penalis_save_general' ); ?>

			<div class="penalis-settings__panel" id="penalis-public-pages">
				<h3>Halaman Publik</h3>
				<p class="description">
					Pilih halaman WordPress untuk setiap fitur publik Penalis.
				</p>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="penalis-dashboard-page-id">Halaman Dashboard Penulis</label></th>
							<td>
								<?php
								self::render_page_dropdown(
									'penalis_dashboard_page_id',
									'penalis-dashboard-page-id',
									$dashboard_id,
									'— Deteksi otomatis (/dashboard/) —'
								);
								?>
								<p class="description">Gunakan halaman yang memuat <code>[penalis_dashboard]</code>.</p>
								<p class="penalis-settings__current-url">
									<span>URL aktif</span>
									<a href="<?php echo esc_url( $dashboard_url ); ?>"><?php echo esc_html( $dashboard_url ); ?></a>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="penalis-daily-quotes-page-id">Halaman #KalimatHariIni</label></th>
							<td>
								<?php
								self::render_page_dropdown(
									'penalis_daily_quotes_page_id',
									'penalis-daily-quotes-page-id',
									$quotes_id,
									'— Deteksi otomatis (/kalimat-hari-ini/) —'
								);
								?>
								<p class="description">Gunakan halaman yang memuat <code>[penalis_kalimat_hari_ini]</code>.</p>
								<p class="penalis-settings__current-url">
									<span>URL aktif</span>
									<a href="<?php echo esc_url( $quotes_url ); ?>"><?php echo esc_html( $quotes_url ); ?></a>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="penalis-authors-page-id">Halaman Direktori Kontributor</label></th>
							<td>
								<?php
								self::render_page_dropdown(
									'penalis_authors_page_id',
									'penalis-authors-page-id',
									(int) $cfg['authors_page_id'],
									'— Deteksi otomatis (/author/) —'
								);
								?>
								<p class="description">Gunakan halaman yang memuat <code>[penalis_authors]</code>.</p>
								<p class="penalis-settings__current-url">
									<span>URL aktif</span>
									<a href="<?php echo esc_url( $directory_url ); ?>"><?php echo esc_html( $directory_url ); ?></a>
								</p>
								<details class="penalis-settings__disclosure" <?php echo '' !== (string) $cfg['authors_directory_url'] ? 'open' : ''; ?>>
									<summary>URL manual direktori</summary>
									<label for="penalis-authors-directory-url" class="screen-reader-text">URL manual direktori kontributor</label>
									<input
										type="text"
										id="penalis-authors-directory-url"
										name="penalis_authors_directory_url"
										class="regular-text"
										placeholder="/penulis/"
										aria-describedby="penalis-authors-directory-help"
										value="<?php echo esc_attr( (string) $cfg['authors_directory_url'] ); ?>"
									/>
									<p class="description" id="penalis-authors-directory-help">Digunakan jika halaman WordPress tidak dipilih atau tidak tersedia.</p>
								</details>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="penalis-settings__panel" id="penalis-legal">
				<h3>Tautan Legal</h3>
				<p class="description">Tampil di kotak persetujuan pada formulir pendaftaran penulis.</p>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="penalis-terms-url">URL Ketentuan Layanan</label></th>
							<td>
								<input
									type="text"
									id="penalis-terms-url"
									name="penalis_terms_url"
									class="regular-text"
									value="<?php echo esc_attr( (string) $cfg['terms_url'] ); ?>"
								/>
								<p class="description">Bawaan: <code>/ketentuan-layanan/</code>. Ditampilkan di kotak persetujuan pendaftaran penulis.</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="penalis-privacy-url">URL Kebijakan Privasi</label></th>
							<td>
								<input
									type="text"
									id="penalis-privacy-url"
									name="penalis_privacy_url"
									class="regular-text"
									value="<?php echo esc_attr( (string) $cfg['privacy_url'] ); ?>"
								/>
								<p class="description">Bawaan: <code>/kebijakan-privasi/</code>.</p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="penalis-settings__panel" id="penalis-page-templates">
				<h3>Template Bawaan Plugin</h3>
				<p class="description">
					Nonaktifkan salah satu template di bawah jika kamu ingin memakai template khusus dari tema atau page builder. Filter PHP tetap berlaku sebagai override akhir.
				</p>

				<fieldset class="penalis-settings__group">
					<legend class="screen-reader-text">Template bawaan plugin</legend>
					<?php
					$template_toggles = array(
						'use_author_archive_template' => array(
							'label' => 'Template arsip profil penulis (/author/&lt;username&gt;/)',
							'desc'  => 'Menampilkan kartu profil sosial, badge terverifikasi/Senator, #KalimatHariIni, dan grid karya terbit.',
						),
						'use_dashboard_template'      => array(
							'label' => 'Template halaman penuh untuk Dashboard (/dashboard/)',
							'desc'  => 'Menampilkan SPA dashboard tanpa sidebar dan header tema agar ruang menulis lebih lega.',
						),
						'use_authors_template'        => array(
							'label' => 'Template bawaan untuk Direktori Kontributor',
							'desc'  => 'Membungkus shortcode [penalis_authors] dengan tata letak bersih bawaan plugin.',
						),
						'use_daily_quotes_template'   => array(
							'label' => 'Template bawaan untuk halaman #KalimatHariIni',
							'desc'  => 'Membungkus shortcode [penalis_kalimat_hari_ini] dengan tata letak bersih bawaan plugin.',
						),
					);
					foreach ( $template_toggles as $key => $info ) :
						?>
						<div class="penalis-settings__choice">
							<input
								type="checkbox"
								id="penalis-<?php echo esc_attr( $key ); ?>"
								name="penalis_general[<?php echo esc_attr( $key ); ?>]"
								value="1"
								<?php checked( ! empty( $cfg[ $key ] ) ); ?>
							/>
							<div class="penalis-settings__choice-text">
								<label for="penalis-<?php echo esc_attr( $key ); ?>"><?php echo wp_kses_post( $info['label'] ); ?></label>
								<p class="description"><?php echo esc_html( $info['desc'] ); ?></p>
							</div>
						</div>
					<?php endforeach; ?>
				</fieldset>
			</div>

			<div class="penalis-settings__panel" id="penalis-share-card">
				<h3>Aset Share Card</h3>
				<p class="description">
					Logo dan ikon watermark yang disematkan pada kartu gambar Instagram Story &amp; Feed. Biarkan kosong untuk memakai aset bawaan plugin.
				</p>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">Logo Share Card</th>
							<td>
								<input type="hidden" id="penalis-brand-logo-id" name="penalis_brand_logo_id" value="<?php echo esc_attr( (string) $logo_id ); ?>" />
								<div class="penalis-settings__avatar">
									<img
										id="penalis-brand-logo-preview"
										src="<?php echo esc_url( $brand_assets['logoUrl'] ); ?>"
										alt="Preview logo Share Card"
										style="max-height:44px;width:auto;background:#f6f7f7;padding:6px 10px;border:1px solid #dcdcde;border-radius:4px;"
									/>
									<div class="penalis-settings__avatar-controls">
										<button type="button" id="penalis-brand-logo-choose" class="button">Pilih Logo</button>
										<?php if ( $logo_id > 0 ) : ?>
											<label style="margin-left:6px;">
												<input type="checkbox" name="penalis_clear_brand_logo" value="1" />
												Kembalikan ke logo bawaan
											</label>
										<?php endif; ?>
									</div>
								</div>
							</td>
						</tr>
						<tr>
							<th scope="row">Ikon Share Card</th>
							<td>
								<input type="hidden" id="penalis-brand-icon-id" name="penalis_brand_icon_id" value="<?php echo esc_attr( (string) $icon_id ); ?>" />
								<div class="penalis-settings__avatar">
									<img
										id="penalis-brand-icon-preview"
										src="<?php echo esc_url( $brand_assets['iconUrl'] ); ?>"
										alt="Preview ikon Share Card"
										style="height:44px;width:44px;object-fit:contain;background:#f6f7f7;padding:4px;border:1px solid #dcdcde;border-radius:4px;"
									/>
									<div class="penalis-settings__avatar-controls">
										<button type="button" id="penalis-brand-icon-choose" class="button">Pilih Ikon</button>
										<?php if ( $icon_id > 0 ) : ?>
											<label style="margin-left:6px;">
												<input type="checkbox" name="penalis_clear_brand_icon" value="1" />
												Kembalikan ke ikon bawaan
											</label>
										<?php endif; ?>
									</div>
								</div>
							</td>
						</tr>
					</tbody>
				</table>

				<?php Penalis_Settings::render_save_action( 'Simpan Umum & Halaman' ); ?>
			</div>
		</form>
		<?php
	}

	/**
	 * Render the Identitas & Branding Email panel at the top of the Email tab.
	 *
	 * @return void
	 */
	public static function render_email_branding_panel() {
		$cfg      = Penalis_Config::email_branding();
		$logo_url = Penalis_Config::resolved_email_logo_url();
		?>
		<div class="penalis-settings__panel" id="penalis-email-branding">
			<h3>Identitas &amp; Tampilan Email</h3>
			<p class="description">
				Mengatur logo header, alamat kontak footer, dan warna aksen untuk seluruh email transaksional yang dikirim Penalis Platform.
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="penalis_save_email_branding" />
				<?php wp_nonce_field( 'penalis_save_email_branding' ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="penalis-email-logo-url">Logo Header Email</label></th>
							<td>
								<input type="hidden" id="penalis-email-logo-id" name="penalis_email_logo_id" value="<?php echo esc_attr( (string) $cfg['logo_id'] ); ?>" />
								<div class="penalis-settings__avatar" style="margin-top:0;margin-bottom:8px;">
									<img
										id="penalis-email-logo-preview"
										src="<?php echo esc_url( $logo_url ); ?>"
										alt="Preview logo email"
										style="max-height:44px;width:auto;background:#f0f0f0;padding:6px 10px;border:1px solid #dcdcde;border-radius:4px;"
									/>
									<div class="penalis-settings__avatar-controls">
										<button type="button" id="penalis-email-logo-choose" class="button">Pilih dari Media</button>
									</div>
								</div>
								<input
									type="url"
									id="penalis-email-logo-url"
									name="penalis_email_logo_url"
									class="regular-text code"
									value="<?php echo esc_attr( (string) $cfg['logo_url'] ); ?>"
								/>
								<p class="description">Gunakan gambar berformat PNG/JPEG (URL publik HTTPS agar tampil di semua aplikasi email).</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="penalis-email-contact">Email Kontak Footer</label></th>
							<td>
								<input
									type="email"
									id="penalis-email-contact"
									name="penalis_email_contact"
									class="regular-text"
									value="<?php echo esc_attr( (string) $cfg['contact_email'] ); ?>"
								/>
								<p class="description">Bawaan: <code><?php echo esc_html( Penalis_Config::DEFAULT_CONTACT_EMAIL ); ?></code>. Menggantikan placeholder <code>{email_kontak}</code> di footer.</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="penalis-email-footer">Teks Footer Bawaan</label></th>
							<td>
								<textarea
									id="penalis-email-footer"
									name="penalis_email_footer"
									rows="3"
									class="large-text"
								><?php echo esc_textarea( (string) $cfg['footer_text'] ); ?></textarea>
								<p class="description">Gunakan <code>{email_kontak}</code> agar alamat email kontak otomatis menjadi tautan <code>mailto:</code>.</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="penalis-email-button-color">Warna Tombol &amp; Tautan</label></th>
							<td>
								<label for="penalis-email-button-color" style="margin-right:16px;">
									Tombol CTA:
									<input
										type="color"
										id="penalis-email-button-color"
										name="penalis_email_button_color"
										value="<?php echo esc_attr( (string) $cfg['button_color'] ); ?>"
										style="vertical-align:middle;"
									/>
									<code><?php echo esc_html( (string) $cfg['button_color'] ); ?></code>
								</label>
								<label for="penalis-email-link-color">
									Tautan inline:
									<input
										type="color"
										id="penalis-email-link-color"
										name="penalis_email_link_color"
										value="<?php echo esc_attr( (string) $cfg['link_color'] ); ?>"
										style="vertical-align:middle;"
									/>
									<code><?php echo esc_html( (string) $cfg['link_color'] ); ?></code>
								</label>
								<p class="description">Bawaan: tombol <code><?php echo esc_html( Penalis_Config::DEFAULT_BUTTON_COLOR ); ?></code>, tautan <code><?php echo esc_html( Penalis_Config::DEFAULT_LINK_COLOR ); ?></code>.</p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php Penalis_Settings::render_save_action( 'Simpan Identitas Email' ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="penalis-settings__reset">
				<input type="hidden" name="action" value="penalis_reset_email_branding" />
				<?php wp_nonce_field( 'penalis_reset_email_branding' ); ?>
				<button type="submit" class="button button-secondary">Kembalikan Branding ke Default</button>
				<span class="description">Mengembalikan logo, footer, dan warna aksen email ke nilai bawaan plugin.</span>
			</form>
		</div>
		<?php
	}

	/**
	 * Render Editorial Rules and #KalimatHariIni configuration panels.
	 *
	 * @return void
	 */
	public static function render_editorial_panels() {
		$cfg          = Penalis_Config::editorial();
		$presets_text = implode( "\n", (array) $cfg['quote_removal_presets'] );
		$image_labels = array(
			'jpeg' => 'JPEG (.jpg, .jpeg)',
			'png'  => 'PNG (.png)',
			'gif'  => 'GIF (.gif)',
			'webp' => 'WebP (.webp)',
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="penalis_save_editorial" />
			<?php wp_nonce_field( 'penalis_save_editorial' ); ?>

			<div class="penalis-settings__panel" id="penalis-editorial">
				<h3>Aturan Pengiriman Karya</h3>
				<p class="description">
					Mengatur syarat kelengkapan karya tulis sebelum penulis dapat melakukan submit review, batas revisi draft, dan format gambar yang boleh diunggah.
				</p>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">Syarat Submit Review</th>
							<td>
								<label style="display:block;margin-bottom:6px;">
									<input type="checkbox" name="penalis_require_thumbnail" value="1" <?php checked( ! empty( $cfg['require_thumbnail'] ) ); ?> />
									Wajib memilih thumbnail (featured image) sebelum submit
								</label>
								<label style="display:block;margin-bottom:6px;">
									<input type="checkbox" name="penalis_require_category" value="1" <?php checked( ! empty( $cfg['require_category'] ) ); ?> />
									Wajib memilih minimal 1 kategori selain Uncategorized
								</label>
								<label for="penalis-min-tags" style="display:block;margin-top:8px;">
									Minimal jumlah tag:
									<input
										type="number"
										id="penalis-min-tags"
										name="penalis_min_tags"
										class="small-text"
										min="0"
										max="10"
										value="<?php echo esc_attr( (string) $cfg['min_tags'] ); ?>"
									/>
									<span class="description">(0 = tidak wajib tag; bawaan: 1)</span>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="penalis-draft-revisions">Batas Revisi Draft</label></th>
							<td>
								<div class="penalis-settings__number-field">
									<input
										type="number"
										id="penalis-draft-revisions"
										name="penalis_draft_revisions"
										class="small-text"
										min="0"
										max="100"
										value="<?php echo esc_attr( (string) $cfg['draft_revisions'] ); ?>"
									/>
									<label for="penalis-draft-revisions">revisi per draft</label>
								</div>
								<p class="description">Rentang 0–100 (bawaan: 20). Membatasi jumlah autosave/revisi draft penulis agar basis data tetap ramping.</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Format Upload Gambar Penulis</th>
							<td>
								<?php foreach ( $image_labels as $type_key => $type_label ) : ?>
									<label style="display:inline-block;margin-right:16px;">
										<input
											type="checkbox"
											name="penalis_allowed_image_types[]"
											value="<?php echo esc_attr( $type_key ); ?>"
											<?php checked( in_array( $type_key, (array) $cfg['allowed_image_types'], true ) ); ?>
										/>
										<?php echo esc_html( $type_label ); ?>
									</label>
								<?php endforeach; ?>
								<p class="description">Minimal satu format harus aktif. SVG dan file non-gambar tetap selalu ditolak demi keamanan.</p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="penalis-settings__panel" id="penalis-daily-quotes">
				<h3>#KalimatHariIni</h3>
				<p class="description">
					Mengatur ketersediaan fitur catatan harian penulis, batas panjang, masa tayang, kuota harian, serta daftar preset alasan takedown untuk Senator.
				</p>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">Status Fitur</th>
							<td>
								<label>
									<input type="checkbox" name="penalis_quote_enabled" value="1" <?php checked( ! empty( $cfg['quote_enabled'] ) ); ?> />
									Aktifkan penerbitan #KalimatHariIni di dashboard penulis
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="penalis-quote-max-length">Batas Karakter &amp; Baris</label></th>
							<td>
								<div class="penalis-settings__number-field">
									<input
										type="number"
										id="penalis-quote-max-length"
										name="penalis_quote_max_length"
										class="small-text"
										min="40"
										max="500"
										value="<?php echo esc_attr( (string) $cfg['quote_max_length'] ); ?>"
									/>
									<label for="penalis-quote-max-length">karakter (40–500, bawaan: 200)</label>
								</div>
								<div class="penalis-settings__number-field">
									<input
										type="number"
										id="penalis-quote-max-lines"
										name="penalis_quote_max_lines"
										class="small-text"
										min="1"
										max="6"
										value="<?php echo esc_attr( (string) $cfg['quote_max_lines'] ); ?>"
									/>
									<label for="penalis-quote-max-lines">baris (1–6, bawaan: 3)</label>
								</div>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="penalis-quote-ttl-hours">Masa Tayang &amp; Kuota</label></th>
							<td>
								<div class="penalis-settings__number-field">
									<input
										type="number"
										id="penalis-quote-ttl-hours"
										name="penalis_quote_ttl_hours"
										class="small-text"
										min="1"
										max="168"
										value="<?php echo esc_attr( (string) $cfg['quote_ttl_hours'] ); ?>"
									/>
									<label for="penalis-quote-ttl-hours">jam aktif (1–168, bawaan: 24)</label>
								</div>
								<div class="penalis-settings__number-field">
									<input
										type="number"
										id="penalis-quote-daily-limit"
										name="penalis_quote_daily_limit"
										class="small-text"
										min="1"
										max="20"
										value="<?php echo esc_attr( (string) $cfg['quote_daily_limit'] ); ?>"
									/>
									<label for="penalis-quote-daily-limit">kali terbit per jendela (1–20, bawaan: 3)</label>
								</div>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="penalis-quote-per-page">Tampilan &amp; Moderasi</label></th>
							<td>
								<div class="penalis-settings__number-field">
									<input
										type="number"
										id="penalis-quote-per-page"
										name="penalis_quote_per_page"
										class="small-text"
										min="6"
										max="50"
										value="<?php echo esc_attr( (string) $cfg['quote_per_page'] ); ?>"
									/>
									<label for="penalis-quote-per-page">kalimat/halaman (6–50, bawaan: 24)</label>
								</div>
								<div class="penalis-settings__number-field">
									<input
										type="number"
										id="penalis-quote-reason-max"
										name="penalis_quote_reason_max_length"
										class="small-text"
										min="50"
										max="500"
										value="<?php echo esc_attr( (string) $cfg['quote_reason_max_length'] ); ?>"
									/>
									<label for="penalis-quote-reason-max">maks karakter alasan takedown (bawaan: 300)</label>
								</div>
							</td>
						</tr>
						<tr class="penalis-settings__field-group">
							<td colspan="2"><h4>Moderasi</h4></td>
						</tr>
						<tr>
							<th scope="row"><label for="penalis-quote-presets">Preset Alasan Takedown Senator</label></th>
							<td>
								<textarea
									id="penalis-quote-presets"
									name="penalis_quote_removal_presets"
									rows="5"
									class="large-text"
								><?php echo esc_textarea( $presets_text ); ?></textarea>
								<p class="description">
									Tulis satu alasan cepat per baris (maksimal 10 preset). Otomatis dipakai baik di Dashboard Senator maupun di wp-admin Penalis → Kalimat Hari Ini.
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php Penalis_Settings::render_save_action( 'Simpan Editorial & Komunitas' ); ?>
			</div>
		</form>
		<?php
	}

	/**
	 * Render the single-post Reactions & Share Bar configuration panel.
	 *
	 * @return void
	 */
	public static function render_article_display_panel() {
		$cfg = Penalis_Config::article_display();
		?>
		<div class="penalis-settings__panel" id="penalis-reactions">
			<h3>Reaksi &amp; Bagikan</h3>
			<p class="description">
				Mengatur bar apresiasi pembaca (Like/Dislike) dan tombol Bagikan Share Card yang tampil otomatis di bagian bawah setiap artikel terbit.
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="penalis_save_article_display" />
				<?php wp_nonce_field( 'penalis_save_article_display' ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">Bar Bagikan Otomatis</th>
							<td>
								<label>
									<input type="checkbox" name="penalis_auto_append_share_bar" value="1" <?php checked( ! empty( $cfg['auto_append_share_bar'] ) ); ?> />
									Tampilkan bar reaksi &amp; tombol Bagikan otomatis di akhir artikel terbit
								</label>
								<p class="description">Shortcode <code>[penalis_share_article]</code> dan <code>[penalis_reactions]</code> tetap bisa dipakai manual meski opsi otomatis nonaktif.</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="penalis-share-bar-title">Judul Bar Bagikan</label></th>
							<td>
								<input
									type="text"
									id="penalis-share-bar-title"
									name="penalis_share_bar_title"
									class="regular-text"
									maxlength="120"
									value="<?php echo esc_attr( (string) $cfg['share_bar_title'] ); ?>"
								/>
								<p class="description">Bawaan: <code><?php echo esc_html( Penalis_Config::DEFAULT_SHARE_BAR_TITLE ); ?></code>.</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="penalis-share-bar-desc">Deskripsi Bar Bagikan</label></th>
							<td>
								<textarea
									id="penalis-share-bar-desc"
									name="penalis_share_bar_description"
									rows="2"
									class="large-text"
									maxlength="240"
								><?php echo esc_textarea( (string) $cfg['share_bar_description'] ); ?></textarea>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="penalis-reactions-mode">Mode Tombol Reaksi</label></th>
							<td>
								<select id="penalis-reactions-mode" name="penalis_reactions_mode">
									<option value="both" <?php selected( $cfg['reactions_mode'], 'both' ); ?>>Like &amp; Dislike (bawaan)</option>
									<option value="like_only" <?php selected( $cfg['reactions_mode'], 'like_only' ); ?>>Like saja (sembunyikan Dislike)</option>
									<option value="off" <?php selected( $cfg['reactions_mode'], 'off' ); ?>>Nonaktifkan tombol reaksi</option>
								</select>
								<p class="description">Data jumlah likes yang sudah tersimpan tetap dipertahankan meskipun tombol reaksi dinonaktifkan.</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="penalis-reactions-cookie-days">Masa Simpan Cookie Reaksi</label></th>
							<td>
								<div class="penalis-settings__number-field">
									<input
										type="number"
										id="penalis-reactions-cookie-days"
										name="penalis_reactions_cookie_days"
										class="small-text"
										min="1"
										max="365"
										value="<?php echo esc_attr( (string) $cfg['reactions_cookie_days'] ); ?>"
									/>
									<label for="penalis-reactions-cookie-days">hari (1–365, bawaan: 365)</label>
								</div>
							</td>
						</tr>
					</tbody>
				</table>

				<?php Penalis_Settings::render_save_action( 'Simpan Reaksi & Bagikan' ); ?>
			</form>
		</div>
		<?php
	}

	// =====================================================================
	// Phase 3 Panels: Account Policy, Security (OTP/Turnstile/CDN), Performance.
	// =====================================================================

	/**
	 * Render Kebijakan Akun, Profil & Batas Upload Avatar on the Akun & Pendaftaran tab.
	 *
	 * @return void
	 */
	public static function render_account_policy_panel() {
		$cfg = Penalis_Config::account();
		?>
		<div class="penalis-settings__panel" id="penalis-account-policy">
			<h3>Kebijakan Akun &amp; Profil</h3>
			<p class="description">
				Mengatur batas minimal panjang password, batas panjang bio penulis, serta batas teknis unggahan foto profil.
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="penalis_save_account_policy" />
				<?php wp_nonce_field( 'penalis_save_account_policy' ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="penalis-min-password">Panjang Minimal Password</label></th>
							<td>
								<div class="penalis-settings__number-field">
									<input
										type="number"
										id="penalis-min-password"
										name="penalis_min_password"
										class="small-text"
										min="8"
										max="64"
										value="<?php echo esc_attr( (string) $cfg['min_password'] ); ?>"
									/>
									<label for="penalis-min-password">karakter (8–64, bawaan: 12)</label>
								</div>
								<p class="description">Berlaku untuk pendaftaran akun baru, aktivasi akun lama, ganti password, dan reset password.</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="penalis-max-bio">Batas Panjang Bio Penulis</label></th>
							<td>
								<div class="penalis-settings__number-field">
									<input
										type="number"
										id="penalis-max-bio"
										name="penalis_max_bio"
										class="small-text"
										min="50"
										max="1000"
										value="<?php echo esc_attr( (string) $cfg['max_bio'] ); ?>"
									/>
									<label for="penalis-max-bio">karakter (50–1000, bawaan: 250)</label>
								</div>
								<p class="description">Bio lama yang melebihi batas tetap tampil dengan tombol Selengkapnya di arsip penulis.</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="penalis-avatar-max-mb">Batas Upload Foto Profil</label></th>
							<td>
								<div class="penalis-settings__number-field">
									<input
										type="number"
										id="penalis-avatar-max-mb"
										name="penalis_avatar_max_mb"
										class="small-text"
										min="1"
										max="10"
										value="<?php echo esc_attr( (string) $cfg['avatar_max_mb'] ); ?>"
									/>
									<label for="penalis-avatar-max-mb">MB (1–10, bawaan: 5)</label>
								</div>
								<div class="penalis-settings__number-field">
									<input
										type="number"
										id="penalis-avatar-min-edge"
										name="penalis_avatar_min_edge"
										class="small-text"
										min="50"
										max="512"
										value="<?php echo esc_attr( (string) $cfg['avatar_min_edge'] ); ?>"
									/>
									<label for="penalis-avatar-min-edge">px sisi minimal (50–512, bawaan: 100)</label>
								</div>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="penalis-avatar-output-size">Output JPEG Foto Profil</label></th>
							<td>
								<div class="penalis-settings__number-field">
									<input
										type="number"
										id="penalis-avatar-output-size"
										name="penalis_avatar_output_size"
										class="small-text"
										min="128"
										max="512"
										value="<?php echo esc_attr( (string) $cfg['avatar_output_size'] ); ?>"
									/>
									<label for="penalis-avatar-output-size">px persegi (128–512, bawaan: 256)</label>
								</div>
								<div class="penalis-settings__number-field">
									<input
										type="number"
										id="penalis-avatar-quality"
										name="penalis_avatar_output_quality"
										class="small-text"
										min="60"
										max="95"
										value="<?php echo esc_attr( (string) $cfg['avatar_output_quality'] ); ?>"
									/>
									<label for="penalis-avatar-quality">% kualitas JPEG (60–95, bawaan: 85)</label>
								</div>
							</td>
						</tr>
					</tbody>
				</table>

				<?php Penalis_Settings::render_save_action( 'Simpan Kebijakan Akun & Profil' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render sensitive Turnstile per-form overrides, OTP parameters, and CDN override.
	 *
	 * @return void
	 */
	public static function render_security_advanced_panels() {
		$cfg         = Penalis_Config::security();
		$forms       = (array) $cfg['turnstile_forms'];
		$form_labels = array(
			'login'    => 'Form Login (/dashboard/)',
			'register' => 'Form Pendaftaran Akun Baru',
			'activate' => 'Form Aktivasi Penulis Lama',
			'forgot'   => 'Form Lupa Password',
			'reset'    => 'Form Atur Password Baru',
		);
		?>
		<div class="penalis-settings__panel" id="penalis-security-advanced">
			<h3>Pengaturan Lanjutan</h3>
			<p class="description">
				Mengatur perlindungan Cloudflare Turnstile per form dashboard, masa berlaku &amp; batas kode verifikasi email (OTP), serta override Base URL aset jika menggunakan CDN khusus.
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="penalis_save_security_advanced" />
				<?php wp_nonce_field( 'penalis_save_security_advanced' ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">Turnstile per Form Dashboard</th>
							<td>
								<?php foreach ( $form_labels as $key => $label ) : ?>
									<label style="display:block;margin-bottom:5px;">
										<input
											type="checkbox"
											name="penalis_turnstile_forms[<?php echo esc_attr( $key ); ?>]"
											value="1"
											<?php checked( ! empty( $forms[ $key ] ) ); ?>
										/>
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
								<p class="description">Hanya berlaku jika sakelar CAPTCHA dashboard di atas aktif dan plugin Simple Cloudflare Turnstile juga mengaktifkan kategori form terkait.</p>
							</td>
						</tr>
						<tr class="penalis-settings__field-group">
							<td colspan="2"><h4>Kode Verifikasi (OTP)</h4></td>
						</tr>
						<tr>
							<th scope="row"><label for="penalis-otp-ttl">Masa Berlaku &amp; Jeda OTP</label></th>
							<td>
								<div class="penalis-settings__number-field">
									<input
										type="number"
										id="penalis-otp-ttl"
										name="penalis_otp_ttl_minutes"
										class="small-text"
										min="3"
										max="30"
										value="<?php echo esc_attr( (string) $cfg['otp_ttl_minutes'] ); ?>"
									/>
									<label for="penalis-otp-ttl">menit berlaku (3–30, bawaan: 10)</label>
								</div>
								<div class="penalis-settings__number-field">
									<input
										type="number"
										id="penalis-otp-resend"
										name="penalis_otp_resend_wait"
										class="small-text"
										min="30"
										max="300"
										value="<?php echo esc_attr( (string) $cfg['otp_resend_wait'] ); ?>"
									/>
									<label for="penalis-otp-resend">detik jeda kirim ulang (30–300, bawaan: 60)</label>
								</div>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="penalis-otp-attempts">Batas Percobaan &amp; Kuota OTP</label></th>
							<td>
								<div class="penalis-settings__number-field">
									<input
										type="number"
										id="penalis-otp-attempts"
										name="penalis_otp_max_attempts"
										class="small-text"
										min="3"
										max="10"
										value="<?php echo esc_attr( (string) $cfg['otp_max_attempts'] ); ?>"
									/>
									<label for="penalis-otp-attempts">maks salah input (3–10, bawaan: 5)</label>
								</div>
								<div class="penalis-settings__number-field">
									<input
										type="number"
										id="penalis-otp-hour"
										name="penalis_otp_max_issues"
										class="small-text"
										min="2"
										max="20"
										value="<?php echo esc_attr( (string) $cfg['otp_max_issues'] ); ?>"
									/>
									<label for="penalis-otp-hour">maks kirim/jam (2–20, bawaan: 5)</label>
								</div>
								<div class="penalis-settings__number-field">
									<input
										type="number"
										id="penalis-otp-day"
										name="penalis_otp_daily_issues"
										class="small-text"
										min="3"
										max="50"
										value="<?php echo esc_attr( (string) $cfg['otp_daily_issues'] ); ?>"
									/>
									<label for="penalis-otp-day">maks kirim/hari (3–50, bawaan: 10)</label>
								</div>
							</td>
						</tr>
						<tr class="penalis-settings__field-group">
							<td colspan="2"><h4>Distribusi Aset</h4></td>
						</tr>
						<tr>
							<th scope="row"><label for="penalis-asset-base-url">Override Base URL Aset / CDN</label></th>
							<td>
								<input
									type="url"
									id="penalis-asset-base-url"
									name="penalis_asset_base_url"
									class="regular-text code"
									placeholder="Biarkan kosong (same-origin otomatis)"
									value="<?php echo esc_attr( (string) $cfg['asset_base_url'] ); ?>"
								/>
								<p class="description">Opsional. Kosongkan pada instalasi normal agar aset SPA selalu dimuat same-origin tanpa masalah CORS.</p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php Penalis_Settings::render_save_action( 'Simpan Keamanan & OTP' ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="penalis-settings__reset">
				<input type="hidden" name="action" value="penalis_reset_security_advanced" />
				<?php wp_nonce_field( 'penalis_reset_security_advanced' ); ?>
				<button type="submit" class="button button-secondary">Kembalikan Keamanan &amp; OTP ke Default</button>
				<span class="description">Mengembalikan kontrol per form, parameter OTP, dan URL aset CDN ke nilai bawaan aman.</span>
			</form>
		</div>
		<?php
	}

	/**
	 * Render sensitive Cache, Log Retention & Analytics panels on the Data tab.
	 *
	 * @return void
	 */
	public static function render_performance_panel() {
		$cfg = Penalis_Config::performance();
		?>
		<div class="penalis-settings__panel" id="penalis-performance">
			<h3>Performa &amp; Retensi</h3>
			<p class="description">
				Mengatur durasi cache direktori publik, batas penyimpanan log &amp; notifikasi agar database tetap sehat, serta rentang grafik analitik di halaman Ringkasan.
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="penalis_save_performance" />
				<?php wp_nonce_field( 'penalis_save_performance' ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="penalis-authors-cache-ttl">Cache &amp; Pagination Kontributor</label></th>
							<td>
								<div class="penalis-settings__number-field">
									<input
										type="number"
										id="penalis-authors-cache-ttl"
										name="penalis_authors_cache_ttl"
										class="small-text"
										min="0"
										max="3600"
										value="<?php echo esc_attr( (string) $cfg['authors_cache_ttl'] ); ?>"
									/>
									<label for="penalis-authors-cache-ttl">detik TTL cache (0 = nonaktif, bawaan: 600)</label>
								</div>
								<div class="penalis-settings__number-field">
									<input
										type="number"
										id="penalis-authors-per-page"
										name="penalis_authors_per_page"
										class="small-text"
										min="12"
										max="100"
										value="<?php echo esc_attr( (string) $cfg['authors_per_page'] ); ?>"
									/>
									<label for="penalis-authors-per-page">kontributor/halaman (12–100, bawaan: 100)</label>
								</div>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="penalis-quotes-cache-ttl">Cache #KalimatHariIni</label></th>
							<td>
								<div class="penalis-settings__number-field">
									<input
										type="number"
										id="penalis-quotes-cache-ttl"
										name="penalis_quotes_cache_ttl"
										class="small-text"
										min="0"
										max="3600"
										value="<?php echo esc_attr( (string) $cfg['quotes_cache_ttl'] ); ?>"
									/>
									<label for="penalis-quotes-cache-ttl">detik TTL cache (0 = nonaktif, bawaan: 120)</label>
								</div>
							</td>
						</tr>
						<tr class="penalis-settings__field-group">
							<td colspan="2"><h4>Retensi Log &amp; Riwayat</h4></td>
						</tr>
						<tr>
							<th scope="row"><label for="penalis-email-log-limit">Retensi Log &amp; Riwayat</label></th>
							<td>
								<label style="display:inline-block;margin:0 16px 6px 0;">
									Log Email:
									<input
										type="number"
										id="penalis-email-log-limit"
										name="penalis_email_log_limit"
										class="small-text"
										min="20"
										max="500"
										value="<?php echo esc_attr( (string) $cfg['email_log_limit'] ); ?>"
									/>
									<span class="description">(bawaan: 100)</span>
								</label>
								<label style="display:inline-block;margin:0 16px 6px 0;">
									Notifikasi/akun:
									<input
										type="number"
										name="penalis_notification_limit"
										class="small-text"
										min="10"
										max="200"
										value="<?php echo esc_attr( (string) $cfg['notification_limit'] ); ?>"
									/>
									<span class="description">(bawaan: 50)</span>
								</label>
								<br />
								<label style="display:inline-block;margin:4px 16px 0 0;">
									Riwayat review/karya:
									<input
										type="number"
										name="penalis_review_history_limit"
										class="small-text"
										min="10"
										max="200"
										value="<?php echo esc_attr( (string) $cfg['review_history_limit'] ); ?>"
									/>
									<span class="description">(bawaan: 50)</span>
								</label>
								<label style="display:inline-block;margin:4px 0 0 0;">
									Audit log akun:
									<input
										type="number"
										name="penalis_account_log_limit"
										class="small-text"
										min="10"
										max="100"
										value="<?php echo esc_attr( (string) $cfg['account_log_limit'] ); ?>"
									/>
									<span class="description">(bawaan: 20)</span>
								</label>
							</td>
						</tr>
						<tr class="penalis-settings__field-group">
							<td colspan="2"><h4>Analitik</h4></td>
						</tr>
						<tr>
							<th scope="row"><label for="penalis-analytics-weeks">Grafik Analitik Ringkasan</label></th>
							<td>
								<div class="penalis-settings__number-field">
									<input
										type="number"
										id="penalis-analytics-weeks"
										name="penalis_analytics_weeks"
										class="small-text"
										min="4"
										max="26"
										value="<?php echo esc_attr( (string) $cfg['analytics_weeks'] ); ?>"
									/>
									<label for="penalis-analytics-weeks">minggu tren (4–26, bawaan: 8)</label>
								</div>
								<input
									type="number"
									name="penalis_analytics_top_authors"
									class="small-text"
									min="3"
									max="10"
									value="<?php echo esc_attr( (string) $cfg['analytics_top_authors'] ); ?>"
								/>
								<span>penulis teratas (3–10, bawaan: 3)</span>
							</td>
						</tr>
					</tbody>
				</table>

				<?php Penalis_Settings::render_save_action( 'Simpan Cache & Retensi' ); ?>
			</form>

			<div class="penalis-settings__reset">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="penalis_purge_caches" />
					<?php wp_nonce_field( 'penalis_purge_caches' ); ?>
					<button type="submit" class="button button-secondary">Bersihkan Cache Direktori Sekarang</button>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="penalis_reset_performance" />
					<?php wp_nonce_field( 'penalis_reset_performance' ); ?>
					<button type="submit" class="button button-secondary">Kembalikan Cache &amp; Retensi ke Default</button>
				</form>
				<span class="description">Membersihkan cache tidak menghapus data apa pun; tombol reset hanya mengembalikan angka ke nilai bawaan.</span>
			</div>
		</div>
		<?php
	}

	// =====================================================================
	// Form Handlers (Phase 2 & Phase 3).
	// =====================================================================

	/**
	 * Save Umum & Halaman settings.
	 *
	 * @return void
	 */
	public static function handle_save_general() {
		self::require_manage();
		check_admin_referer( 'penalis_save_general' );

		$dashboard_id = isset( $_POST['penalis_dashboard_page_id'] ) ? absint( wp_unslash( $_POST['penalis_dashboard_page_id'] ) ) : 0;
		$quotes_id    = isset( $_POST['penalis_daily_quotes_page_id'] ) ? absint( wp_unslash( $_POST['penalis_daily_quotes_page_id'] ) ) : 0;

		if ( $dashboard_id > 0 && 'page' === get_post_type( $dashboard_id ) ) {
			update_option( 'penalis_dashboard_page_id', $dashboard_id, false );
		} elseif ( 0 === $dashboard_id ) {
			delete_option( 'penalis_dashboard_page_id' );
		}

		if ( $quotes_id > 0 && 'page' === get_post_type( $quotes_id ) ) {
			update_option( Penalis_Daily_Quotes::OPTION_PAGE_ID, $quotes_id, false );
		} elseif ( 0 === $quotes_id ) {
			delete_option( Penalis_Daily_Quotes::OPTION_PAGE_ID );
		}

		$raw_toggles = isset( $_POST['penalis_general'] ) && is_array( $_POST['penalis_general'] )
			? wp_unslash( $_POST['penalis_general'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Converted to booleans below.
			: array();

		$brand_logo_id = isset( $_POST['penalis_clear_brand_logo'] )
			? 0
			: ( isset( $_POST['penalis_brand_logo_id'] ) ? absint( wp_unslash( $_POST['penalis_brand_logo_id'] ) ) : 0 );
		if ( $brand_logo_id > 0 && ! wp_attachment_is_image( $brand_logo_id ) ) {
			$brand_logo_id = 0;
		}

		$brand_icon_id = isset( $_POST['penalis_clear_brand_icon'] )
			? 0
			: ( isset( $_POST['penalis_brand_icon_id'] ) ? absint( wp_unslash( $_POST['penalis_brand_icon_id'] ) ) : 0 );
		if ( $brand_icon_id > 0 && ! wp_attachment_is_image( $brand_icon_id ) ) {
			$brand_icon_id = 0;
		}

		$clean = Penalis_Config::sanitize_general(
			array(
				'authors_page_id'             => isset( $_POST['penalis_authors_page_id'] ) ? absint( wp_unslash( $_POST['penalis_authors_page_id'] ) ) : 0,
				'authors_directory_url'       => isset( $_POST['penalis_authors_directory_url'] ) ? wp_unslash( $_POST['penalis_authors_directory_url'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in Penalis_Config::sanitize_general().
				'terms_url'                   => isset( $_POST['penalis_terms_url'] ) ? wp_unslash( $_POST['penalis_terms_url'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in Penalis_Config::sanitize_general().
				'privacy_url'                 => isset( $_POST['penalis_privacy_url'] ) ? wp_unslash( $_POST['penalis_privacy_url'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in Penalis_Config::sanitize_general().
				'use_dashboard_template'      => ! empty( $raw_toggles['use_dashboard_template'] ),
				'use_authors_template'        => ! empty( $raw_toggles['use_authors_template'] ),
				'use_daily_quotes_template'   => ! empty( $raw_toggles['use_daily_quotes_template'] ),
				'use_author_archive_template' => ! empty( $raw_toggles['use_author_archive_template'] ),
				'brand_logo_id'               => $brand_logo_id,
				'brand_icon_id'               => $brand_icon_id,
			)
		);

		update_option( Penalis_Config::OPTION_GENERAL, $clean, false );
		Penalis_Settings::redirect( 'general', 'saved', array( 'penalis_section' => 'public-pages' ) );
	}

	/**
	 * Save Email Branding settings.
	 *
	 * @return void
	 */
	public static function handle_save_email_branding() {
		self::require_manage();
		check_admin_referer( 'penalis_save_email_branding' );

		$logo_id = isset( $_POST['penalis_email_logo_id'] ) ? absint( wp_unslash( $_POST['penalis_email_logo_id'] ) ) : 0;
		if ( $logo_id > 0 && ! wp_attachment_is_image( $logo_id ) ) {
			$logo_id = 0;
		}

		$clean = Penalis_Config::sanitize_email_branding(
			array(
				'logo_id'       => $logo_id,
				'logo_url'      => isset( $_POST['penalis_email_logo_url'] ) ? wp_unslash( $_POST['penalis_email_logo_url'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in Penalis_Config::sanitize_email_branding().
				'contact_email' => isset( $_POST['penalis_email_contact'] ) ? wp_unslash( $_POST['penalis_email_contact'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in Penalis_Config::sanitize_email_branding().
				'footer_text'   => isset( $_POST['penalis_email_footer'] ) ? wp_unslash( $_POST['penalis_email_footer'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in Penalis_Config::sanitize_email_branding().
				'button_color'  => isset( $_POST['penalis_email_button_color'] ) ? wp_unslash( $_POST['penalis_email_button_color'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in Penalis_Config::sanitize_email_branding().
				'link_color'    => isset( $_POST['penalis_email_link_color'] ) ? wp_unslash( $_POST['penalis_email_link_color'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in Penalis_Config::sanitize_email_branding().
			)
		);

		update_option( Penalis_Config::OPTION_EMAIL_BRANDING, $clean, false );
		Penalis_Settings::redirect( 'email', 'email_branding_saved', array( 'penalis_section' => 'email-branding' ) );
	}

	/**
	 * Reset Email Branding settings to built-in defaults.
	 *
	 * @return void
	 */
	public static function handle_reset_email_branding() {
		self::require_manage();
		check_admin_referer( 'penalis_reset_email_branding' );

		delete_option( Penalis_Config::OPTION_EMAIL_BRANDING );
		Penalis_Settings::redirect( 'email', 'email_branding_reset', array( 'penalis_section' => 'email-branding' ) );
	}

	/**
	 * Save Editorial & #KalimatHariIni settings.
	 *
	 * @return void
	 */
	public static function handle_save_editorial() {
		self::require_manage();
		check_admin_referer( 'penalis_save_editorial' );

		$raw_types = isset( $_POST['penalis_allowed_image_types'] ) && is_array( $_POST['penalis_allowed_image_types'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['penalis_allowed_image_types'] ) )
			: array();

		$clean = Penalis_Config::sanitize_editorial(
			array(
				'require_thumbnail'       => isset( $_POST['penalis_require_thumbnail'] ),
				'require_category'        => isset( $_POST['penalis_require_category'] ),
				'min_tags'                => isset( $_POST['penalis_min_tags'] ) ? wp_unslash( $_POST['penalis_min_tags'] ) : 1, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Clamped in Penalis_Config::sanitize_editorial().
				'draft_revisions'         => isset( $_POST['penalis_draft_revisions'] ) ? wp_unslash( $_POST['penalis_draft_revisions'] ) : 20, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Clamped in Penalis_Config::sanitize_editorial().
				'allowed_image_types'     => $raw_types,
				'quote_enabled'           => isset( $_POST['penalis_quote_enabled'] ),
				'quote_max_length'        => isset( $_POST['penalis_quote_max_length'] ) ? wp_unslash( $_POST['penalis_quote_max_length'] ) : 200, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Clamped in Penalis_Config::sanitize_editorial().
				'quote_max_lines'         => isset( $_POST['penalis_quote_max_lines'] ) ? wp_unslash( $_POST['penalis_quote_max_lines'] ) : 3, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Clamped in Penalis_Config::sanitize_editorial().
				'quote_ttl_hours'         => isset( $_POST['penalis_quote_ttl_hours'] ) ? wp_unslash( $_POST['penalis_quote_ttl_hours'] ) : 24, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Clamped in Penalis_Config::sanitize_editorial().
				'quote_daily_limit'       => isset( $_POST['penalis_quote_daily_limit'] ) ? wp_unslash( $_POST['penalis_quote_daily_limit'] ) : 3, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Clamped in Penalis_Config::sanitize_editorial().
				'quote_per_page'          => isset( $_POST['penalis_quote_per_page'] ) ? wp_unslash( $_POST['penalis_quote_per_page'] ) : 24, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Clamped in Penalis_Config::sanitize_editorial().
				'quote_reason_max_length' => isset( $_POST['penalis_quote_reason_max_length'] ) ? wp_unslash( $_POST['penalis_quote_reason_max_length'] ) : 300, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Clamped in Penalis_Config::sanitize_editorial().
				'quote_removal_presets'   => isset( $_POST['penalis_quote_removal_presets'] ) ? wp_unslash( $_POST['penalis_quote_removal_presets'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in Penalis_Config::sanitize_editorial().
			)
		);

		update_option( Penalis_Config::OPTION_EDITORIAL, $clean, false );
		Penalis_Settings::redirect( 'editorial', 'saved', array( 'penalis_section' => 'editorial' ) );
	}

	/**
	 * Save single-post Reactions & Share Bar settings.
	 *
	 * @return void
	 */
	public static function handle_save_article_display() {
		self::require_manage();
		check_admin_referer( 'penalis_save_article_display' );

		$clean = Penalis_Config::sanitize_article_display(
			array(
				'auto_append_share_bar' => isset( $_POST['penalis_auto_append_share_bar'] ),
				'share_bar_title'       => isset( $_POST['penalis_share_bar_title'] ) ? wp_unslash( $_POST['penalis_share_bar_title'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in Penalis_Config::sanitize_article_display().
				'share_bar_description' => isset( $_POST['penalis_share_bar_description'] ) ? wp_unslash( $_POST['penalis_share_bar_description'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in Penalis_Config::sanitize_article_display().
				'reactions_mode'        => isset( $_POST['penalis_reactions_mode'] ) ? wp_unslash( $_POST['penalis_reactions_mode'] ) : 'both', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in Penalis_Config::sanitize_article_display().
				'reactions_cookie_days' => isset( $_POST['penalis_reactions_cookie_days'] ) ? wp_unslash( $_POST['penalis_reactions_cookie_days'] ) : 365, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Clamped in Penalis_Config::sanitize_article_display().
			)
		);

		update_option( Penalis_Config::OPTION_ARTICLE_DISPLAY, $clean, false );
		Penalis_Settings::redirect( 'article-display', 'saved', array( 'penalis_section' => 'reactions' ) );
	}

	/**
	 * Save Account, Profile & Avatar upload limits.
	 *
	 * @return void
	 */
	public static function handle_save_account_policy() {
		self::require_manage();
		check_admin_referer( 'penalis_save_account_policy' );

		$clean = Penalis_Config::sanitize_account(
			array(
				'min_password'          => isset( $_POST['penalis_min_password'] ) ? wp_unslash( $_POST['penalis_min_password'] ) : 12, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Clamped in Penalis_Config::sanitize_account().
				'max_bio'               => isset( $_POST['penalis_max_bio'] ) ? wp_unslash( $_POST['penalis_max_bio'] ) : 250, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Clamped in Penalis_Config::sanitize_account().
				'avatar_max_mb'         => isset( $_POST['penalis_avatar_max_mb'] ) ? wp_unslash( $_POST['penalis_avatar_max_mb'] ) : 5, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Clamped in Penalis_Config::sanitize_account().
				'avatar_output_size'    => isset( $_POST['penalis_avatar_output_size'] ) ? wp_unslash( $_POST['penalis_avatar_output_size'] ) : 256, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Clamped in Penalis_Config::sanitize_account().
				'avatar_output_quality' => isset( $_POST['penalis_avatar_output_quality'] ) ? wp_unslash( $_POST['penalis_avatar_output_quality'] ) : 85, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Clamped in Penalis_Config::sanitize_account().
				'avatar_min_edge'       => isset( $_POST['penalis_avatar_min_edge'] ) ? wp_unslash( $_POST['penalis_avatar_min_edge'] ) : 100, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Clamped in Penalis_Config::sanitize_account().
			)
		);

		update_option( Penalis_Config::OPTION_ACCOUNT, $clean, false );
		Penalis_Settings::redirect( 'registration', 'saved', array( 'penalis_section' => 'account-policy' ) );
	}

	/**
	 * Save sensitive Turnstile per-form overrides, OTP parameters, and CDN base URL.
	 *
	 * @return void
	 */
	public static function handle_save_security_advanced() {
		self::require_manage();
		check_admin_referer( 'penalis_save_security_advanced' );

		$raw_forms = isset( $_POST['penalis_turnstile_forms'] ) && is_array( $_POST['penalis_turnstile_forms'] )
			? wp_unslash( $_POST['penalis_turnstile_forms'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Normalized to booleans in Penalis_Config::sanitize_security().
			: array();

		$clean = Penalis_Config::sanitize_security(
			array(
				'turnstile_forms'  => $raw_forms,
				'otp_ttl_minutes'  => isset( $_POST['penalis_otp_ttl_minutes'] ) ? wp_unslash( $_POST['penalis_otp_ttl_minutes'] ) : 10, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Clamped in Penalis_Config::sanitize_security().
				'otp_resend_wait'  => isset( $_POST['penalis_otp_resend_wait'] ) ? wp_unslash( $_POST['penalis_otp_resend_wait'] ) : 60, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Clamped in Penalis_Config::sanitize_security().
				'otp_max_attempts' => isset( $_POST['penalis_otp_max_attempts'] ) ? wp_unslash( $_POST['penalis_otp_max_attempts'] ) : 5, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Clamped in Penalis_Config::sanitize_security().
				'otp_max_issues'   => isset( $_POST['penalis_otp_max_issues'] ) ? wp_unslash( $_POST['penalis_otp_max_issues'] ) : 5, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Clamped in Penalis_Config::sanitize_security().
				'otp_daily_issues' => isset( $_POST['penalis_otp_daily_issues'] ) ? wp_unslash( $_POST['penalis_otp_daily_issues'] ) : 10, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Clamped in Penalis_Config::sanitize_security().
				'asset_base_url'   => isset( $_POST['penalis_asset_base_url'] ) ? wp_unslash( $_POST['penalis_asset_base_url'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated in Penalis_Config::sanitize_security().
			)
		);

		update_option( Penalis_Config::OPTION_SECURITY, $clean, false );
		Penalis_Settings::redirect( 'security', 'saved', array( 'penalis_section' => 'security-advanced' ) );
	}

	/**
	 * Reset sensitive Turnstile per-form, OTP, and CDN settings to defaults.
	 *
	 * @return void
	 */
	public static function handle_reset_security_advanced() {
		self::require_manage();
		check_admin_referer( 'penalis_reset_security_advanced' );

		delete_option( Penalis_Config::OPTION_SECURITY );
		Penalis_Settings::redirect( 'security', 'security_reset', array( 'penalis_section' => 'security-advanced' ) );
	}

	/**
	 * Save Cache, Log Retention & Analytics settings.
	 *
	 * @return void
	 */
	public static function handle_save_performance() {
		self::require_manage();
		check_admin_referer( 'penalis_save_performance' );

		$clean = Penalis_Config::sanitize_performance(
			array(
				'authors_cache_ttl'     => isset( $_POST['penalis_authors_cache_ttl'] ) ? wp_unslash( $_POST['penalis_authors_cache_ttl'] ) : 600, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Clamped in Penalis_Config::sanitize_performance().
				'authors_per_page'      => isset( $_POST['penalis_authors_per_page'] ) ? wp_unslash( $_POST['penalis_authors_per_page'] ) : 100, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Clamped in Penalis_Config::sanitize_performance().
				'quotes_cache_ttl'      => isset( $_POST['penalis_quotes_cache_ttl'] ) ? wp_unslash( $_POST['penalis_quotes_cache_ttl'] ) : 120, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Clamped in Penalis_Config::sanitize_performance().
				'email_log_limit'       => isset( $_POST['penalis_email_log_limit'] ) ? wp_unslash( $_POST['penalis_email_log_limit'] ) : 100, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Clamped in Penalis_Config::sanitize_performance().
				'notification_limit'    => isset( $_POST['penalis_notification_limit'] ) ? wp_unslash( $_POST['penalis_notification_limit'] ) : 50, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Clamped in Penalis_Config::sanitize_performance().
				'review_history_limit'  => isset( $_POST['penalis_review_history_limit'] ) ? wp_unslash( $_POST['penalis_review_history_limit'] ) : 50, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Clamped in Penalis_Config::sanitize_performance().
				'account_log_limit'     => isset( $_POST['penalis_account_log_limit'] ) ? wp_unslash( $_POST['penalis_account_log_limit'] ) : 20, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Clamped in Penalis_Config::sanitize_performance().
				'analytics_weeks'       => isset( $_POST['penalis_analytics_weeks'] ) ? wp_unslash( $_POST['penalis_analytics_weeks'] ) : 8, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Clamped in Penalis_Config::sanitize_performance().
				'analytics_top_authors' => isset( $_POST['penalis_analytics_top_authors'] ) ? wp_unslash( $_POST['penalis_analytics_top_authors'] ) : 3, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Clamped in Penalis_Config::sanitize_performance().
			)
		);

		update_option( Penalis_Config::OPTION_PERFORMANCE, $clean, false );
		Penalis_Settings::redirect( 'data', 'saved', array( 'penalis_section' => 'performance' ) );
	}

	/**
	 * Reset Cache, Log Retention & Analytics settings to defaults.
	 *
	 * @return void
	 */
	public static function handle_reset_performance() {
		self::require_manage();
		check_admin_referer( 'penalis_reset_performance' );

		delete_option( Penalis_Config::OPTION_PERFORMANCE );
		Penalis_Settings::redirect( 'data', 'performance_reset', array( 'penalis_section' => 'performance' ) );
	}

	/**
	 * Immediately clear public directory transient caches.
	 *
	 * @return void
	 */
	public static function handle_purge_caches() {
		self::require_manage();
		check_admin_referer( 'penalis_purge_caches' );

		if ( class_exists( 'Penalis_Authors' ) ) {
			Penalis_Authors::invalidate_cache();
		}
		if ( class_exists( 'Penalis_Daily_Quotes' ) ) {
			Penalis_Daily_Quotes::invalidate_cache();
		}

		Penalis_Settings::redirect( 'data', 'caches_cleared', array( 'penalis_section' => 'performance' ) );
	}

	/**
	 * Guard every handler behind `manage_options`.
	 *
	 * @return void
	 */
	private static function require_manage() {
		if ( ! Penalis_Settings::can_manage() ) {
			wp_die( 'Kamu tidak memiliki izin untuk mengubah pengaturan ini.', '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Render a WordPress page selector dropdown safely.
	 *
	 * @param string $name        Input name.
	 * @param string $id          Element ID.
	 * @param int    $selected    Selected page ID.
	 * @param string $none_label  Empty option label.
	 * @return void
	 */
	private static function render_page_dropdown( $name, $id, $selected, $none_label ) {
		if ( function_exists( 'wp_dropdown_pages' ) ) {
			wp_dropdown_pages(
				array(
					'name'              => esc_attr( $name ),
					'id'                => esc_attr( $id ),
					'selected'          => (int) $selected,
					'show_option_none'  => esc_html( $none_label ),
					'option_none_value' => '0',
					'post_status'       => array( 'publish', 'private', 'draft' ),
				)
			);
			return;
		}

		printf(
			'<input type="number" id="%1$s" name="%2$s" class="small-text" min="0" value="%3$d" />',
			esc_attr( $id ),
			esc_attr( $name ),
			(int) $selected
		);
	}
}
