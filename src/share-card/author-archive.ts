/**
 * Public `/author/<username>/` share-card modal controller.
 *
 * Uses the exact same Canvas 2D renderer as the React SPAs (`src/lib/shareCard.ts`)
 * without loading React on server-rendered author archive pages.
 */

import { copyToClipboard } from '../lib/dailyQuote';
import {
  buildShareCardFilename,
  buildShareCopyContent,
  canNativeShareFiles,
  downloadCardImage,
  exportShareCardBlob,
  renderShareCard,
  SHARE_CARD_FORMATS,
  SHARE_CARD_THEMES,
  shareCardImage,
  type ArticleShareCardPayload,
  type ProfileShareCardPayload,
  type QuoteShareCardPayload,
  type ShareCardBrandAssets,
  type ShareCardFormat,
  type ShareCardPayload,
  type ShareCardTheme,
} from '../lib/shareCard';

interface ShareCardBootConfig {
  brandLogoUrl?: string;
  brandIconUrl?: string;
  profile?: Omit<ProfileShareCardPayload, 'kind'> | null;
  quote?: Omit<QuoteShareCardPayload, 'kind'> | null;
  articles?: Record<string, Omit<ArticleShareCardPayload, 'kind'>>;
}

declare global {
  interface Window {
    penalisAuthorArchive?: ShareCardBootConfig;
    penalisArticleShare?: ShareCardBootConfig;
  }
}

const FOCUSABLE_SELECTOR =
  'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

const CLOSE_ICON =
  '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
const DOWNLOAD_ICON =
  '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>';
const SHARE_ICON =
  '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>';
const COPY_ICON =
  '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';

function resolveArticlePayload(
  articleId: string,
  archiveConfig?: ShareCardBootConfig,
  articleConfig?: ShareCardBootConfig,
): ArticleShareCardPayload | null {
  const fromBoot = archiveConfig?.articles?.[articleId] ?? articleConfig?.articles?.[articleId];
  if (fromBoot) {
    return { kind: 'article', ...fromBoot };
  }

  const scriptEl = document.querySelector<HTMLScriptElement>(
    `script[type="application/json"][data-penalis-article-card="${CSS.escape(articleId)}"]`,
  );
  if (!scriptEl || !scriptEl.textContent) {
    return null;
  }

  try {
    const parsed = JSON.parse(scriptEl.textContent) as Omit<ArticleShareCardPayload, 'kind'>;
    if (parsed && typeof parsed.title === 'string' && typeof parsed.articleUrl === 'string') {
      return { kind: 'article', ...parsed };
    }
  } catch {
    // Ignore malformed embedded JSON.
  }

  return null;
}

function mountAuthorArchiveCardModal(): void {
  const archiveConfig = window.penalisAuthorArchive;
  const articleConfig = window.penalisArticleShare;

  const brand: ShareCardBrandAssets = {
    logoUrl: archiveConfig?.brandLogoUrl ?? articleConfig?.brandLogoUrl,
    iconUrl: archiveConfig?.brandIconUrl ?? articleConfig?.brandIconUrl,
  };

  if (!archiveConfig && !articleConfig && !document.querySelector('[data-penalis-open-card]')) {
    return;
  }

  const supportsFileShare = canNativeShareFiles();
  let currentPayload: ShareCardPayload | null = null;
  let currentFormat: ShareCardFormat = 'story';
  let currentTheme: ShareCardTheme = 'senja';
  let isRendering = false;
  let isBusy = false;
  let renderSeq = 0;
  let copyTimer: number | null = null;
  let lastTrigger: HTMLElement | null = null;

  let overlay: HTMLDivElement | null = null;
  let titleEl: HTMLHeadingElement | null = null;
  let frameEl: HTMLDivElement | null = null;
  let canvasEl: HTMLCanvasElement | null = null;
  let loadingEl: HTMLDivElement | null = null;
  let errorEl: HTMLParagraphElement | null = null;
  let statusEl: HTMLParagraphElement | null = null;
  let shareBtn: HTMLButtonElement | null = null;
  let downloadBtn: HTMLButtonElement | null = null;
  let copyBtn: HTMLButtonElement | null = null;
  let copyBtnLabel: HTMLSpanElement | null = null;
  let closeBtn: HTMLButtonElement | null = null;
  let formatBtns: HTMLButtonElement[] = [];
  let themeBtns: HTMLButtonElement[] = [];

  function setError(message: string): void {
    if (!errorEl) {
      return;
    }
    errorEl.textContent = message;
    errorEl.hidden = message === '';
  }

  function setStatus(message: string): void {
    if (!statusEl) {
      return;
    }
    statusEl.textContent = message;
    statusEl.hidden = message === '';
    const liveStatus = document.querySelector('[data-penalis-archive-status]');
    if (liveStatus && message !== '') {
      liveStatus.textContent = message;
    }
  }

  function syncButtons(): void {
    const disabled = isRendering || isBusy;
    if (shareBtn) {
      shareBtn.disabled = disabled;
    }
    if (downloadBtn) {
      downloadBtn.disabled = disabled;
    }
    if (copyBtn) {
      copyBtn.disabled = isBusy;
    }
    if (closeBtn) {
      closeBtn.disabled = isBusy;
    }
    formatBtns.forEach((btn) => {
      const active = btn.dataset.format === currentFormat;
      btn.classList.toggle('is-active', active);
      btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    themeBtns.forEach((btn) => {
      const active = btn.dataset.theme === currentTheme;
      btn.classList.toggle('is-active', active);
      btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    if (frameEl) {
      frameEl.className = `penalis-share-card-modal__frame penalis-share-card-modal__frame--${currentFormat}`;
    }
    if (canvasEl) {
      canvasEl.classList.toggle('is-loading', isRendering);
    }
    if (loadingEl) {
      loadingEl.hidden = !isRendering;
    }
  }

  async function paint(): Promise<void> {
    if (!canvasEl || !currentPayload) {
      return;
    }

    renderSeq += 1;
    const seq = renderSeq;
    isRendering = true;
    setError('');
    syncButtons();

    try {
      await renderShareCard(canvasEl, currentPayload, {
        format: currentFormat,
        theme: currentTheme,
        brand,
      });
      if (seq === renderSeq) {
        isRendering = false;
        syncButtons();
      }
    } catch {
      if (seq === renderSeq) {
        isRendering = false;
        setError('Gagal merender pratinjau kartu. Coba muat ulang halaman.');
        syncButtons();
      }
    }
  }

  async function handleDownload(): Promise<void> {
    if (!canvasEl || !currentPayload || isRendering || isBusy) {
      return;
    }

    isBusy = true;
    setError('');
    setStatus('');
    syncButtons();

    try {
      const blob = await exportShareCardBlob(canvasEl);
      const filename = buildShareCardFilename(currentPayload, currentFormat);
      downloadCardImage(blob, filename);
      setStatus('Kartu gambar berhasil diunduh.');
    } catch {
      setError('Gambar tidak dapat diunduh dari browser ini.');
    } finally {
      isBusy = false;
      syncButtons();
    }
  }

  async function handleNativeShare(): Promise<void> {
    if (!canvasEl || !currentPayload || isRendering || isBusy) {
      return;
    }

    isBusy = true;
    setError('');
    setStatus('');
    syncButtons();

    try {
      const blob = await exportShareCardBlob(canvasEl);
      const filename = buildShareCardFilename(currentPayload, currentFormat);
      const shareTitle =
        currentPayload.kind === 'quote'
          ? `#KalimatHariIni — ${currentPayload.displayName}`
          : currentPayload.kind === 'article'
            ? `${currentPayload.title} — ${currentPayload.displayName}`
            : `${currentPayload.displayName} di Penalis`;
      const shareText =
        currentPayload.kind === 'quote'
          ? `"${currentPayload.text}" — ${currentPayload.displayName} (#KalimatHariIni di Penalis)`
          : currentPayload.kind === 'article'
            ? `Baca "${currentPayload.title}" oleh ${currentPayload.displayName} di Penalis: ${currentPayload.articleUrl}`
            : `Lihat profil dan karya tulis ${currentPayload.displayName} di Penalis.`;

      const outcome = await shareCardImage(blob, filename, shareTitle, shareText);
      if (outcome === 'unsupported') {
        downloadCardImage(blob, filename);
        setStatus('Kartu gambar diunduh.');
      } else if (outcome === 'shared') {
        setStatus('Kartu siap dibagikan.');
      }
    } catch {
      setError('Gagal membagikan gambar. Gunakan tombol Unduh PNG.');
    } finally {
      isBusy = false;
      syncButtons();
    }
  }

  async function handleCopyText(): Promise<void> {
    if (!currentPayload || isBusy) {
      return;
    }

    const content = buildShareCopyContent(currentPayload);
    const ok = await copyToClipboard(content.text);
    if (!ok) {
      setError('Gagal menyalin ke clipboard.');
      return;
    }

    setError('');
    setStatus(content.copiedLabel);
    if (copyBtn) {
      copyBtn.classList.add('is-copied');
    }
    if (copyBtnLabel) {
      copyBtnLabel.textContent = content.copiedLabel;
    }
    if (copyTimer !== null) {
      window.clearTimeout(copyTimer);
    }
    copyTimer = window.setTimeout(() => {
      if (copyBtn) {
        copyBtn.classList.remove('is-copied');
      }
      if (copyBtnLabel && currentPayload) {
        copyBtnLabel.textContent = buildShareCopyContent(currentPayload).label;
      }
      copyTimer = null;
    }, 2200);
  }

  function close(): void {
    if (isBusy || !overlay || overlay.hidden) {
      return;
    }
    if (copyTimer !== null) {
      window.clearTimeout(copyTimer);
      copyTimer = null;
    }
    if (copyBtn) {
      copyBtn.classList.remove('is-copied');
    }
    overlay.hidden = true;
    document.body.style.overflow = '';
    currentPayload = null;
    if (lastTrigger && typeof lastTrigger.focus === 'function') {
      lastTrigger.focus();
    }
  }

  function ensureModal(): void {
    if (overlay) {
      return;
    }

    overlay = document.createElement('div');
    overlay.className = 'penalis-share-modal';
    overlay.hidden = true;
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-labelledby', 'penalis-archive-card-title');

    const dialog = document.createElement('div');
    dialog.className = 'penalis-share-card-modal';

    const head = document.createElement('div');
    head.className = 'penalis-share-card-modal__head';

    const titleWrap = document.createElement('div');
    titleEl = document.createElement('h3');
    titleEl.id = 'penalis-archive-card-title';
    titleEl.className = 'penalis-share-card-modal__title';
    titleEl.textContent = 'Kartu Profil Penulis';

    const subtitleEl = document.createElement('p');
    subtitleEl.className = 'penalis-share-card-modal__subtitle';
    subtitleEl.textContent = 'Pilih ukuran & nuansa warna, lalu bagikan ke Instagram Story atau Feed.';

    titleWrap.appendChild(titleEl);
    titleWrap.appendChild(subtitleEl);

    closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'penalis-share-card-modal__close';
    closeBtn.setAttribute('aria-label', 'Tutup dialog kartu gambar');
    closeBtn.innerHTML = CLOSE_ICON;
    closeBtn.addEventListener('click', close);

    head.appendChild(titleWrap);
    head.appendChild(closeBtn);

    const body = document.createElement('div');
    body.className = 'penalis-share-card-modal__body';

    const stage = document.createElement('div');
    stage.className = 'penalis-share-card-modal__stage';

    frameEl = document.createElement('div');
    frameEl.className = 'penalis-share-card-modal__frame penalis-share-card-modal__frame--story';

    canvasEl = document.createElement('canvas');
    canvasEl.className = 'penalis-share-card-modal__canvas';
    canvasEl.setAttribute('aria-label', 'Pratinjau kartu gambar');

    loadingEl = document.createElement('div');
    loadingEl.className = 'penalis-share-card-modal__loading';
    loadingEl.textContent = 'Menyiapkan kartu…';

    frameEl.appendChild(canvasEl);
    frameEl.appendChild(loadingEl);
    stage.appendChild(frameEl);

    const controls = document.createElement('div');
    controls.className = 'penalis-share-card-modal__controls';

    // Format switcher
    const formatGroup = document.createElement('div');
    formatGroup.className = 'penalis-share-card-modal__group';
    const formatLabel = document.createElement('span');
    formatLabel.className = 'penalis-share-card-modal__label';
    formatLabel.textContent = 'Ukuran';
    const formatRow = document.createElement('div');
    formatRow.className = 'penalis-share-card-modal__segmented';
    formatRow.setAttribute('role', 'group');
    formatRow.setAttribute('aria-label', 'Pilih ukuran kartu');

    formatBtns = SHARE_CARD_FORMATS.map((item) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'penalis-share-card-modal__pill';
      btn.dataset.format = item.id;
      const nameSpan = document.createElement('span');
      nameSpan.textContent = item.label;
      const ratioSpan = document.createElement('span');
      ratioSpan.className = 'penalis-share-card-modal__ratio';
      ratioSpan.textContent = item.ratioLabel;
      btn.appendChild(nameSpan);
      btn.appendChild(ratioSpan);
      btn.addEventListener('click', () => {
        if (currentFormat === item.id) {
          return;
        }
        currentFormat = item.id;
        setStatus('');
        void paint();
      });
      formatRow.appendChild(btn);
      return btn;
    });

    formatGroup.appendChild(formatLabel);
    formatGroup.appendChild(formatRow);

    // Theme switcher
    const themeGroup = document.createElement('div');
    themeGroup.className = 'penalis-share-card-modal__group';
    const themeLabel = document.createElement('span');
    themeLabel.className = 'penalis-share-card-modal__label';
    themeLabel.textContent = 'Nuansa';
    const themeRow = document.createElement('div');
    themeRow.className = 'penalis-share-card-modal__themes';
    themeRow.setAttribute('role', 'group');
    themeRow.setAttribute('aria-label', 'Pilih tema warna kartu');

    themeBtns = SHARE_CARD_THEMES.map((item) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'penalis-share-card-modal__theme-btn';
      btn.dataset.theme = item.id;
      const swatch = document.createElement('span');
      swatch.className = 'penalis-share-card-modal__swatch';
      swatch.style.background = item.swatch;
      swatch.setAttribute('aria-hidden', 'true');
      const label = document.createElement('span');
      label.textContent = item.label;
      btn.appendChild(swatch);
      btn.appendChild(label);
      btn.addEventListener('click', () => {
        if (currentTheme === item.id) {
          return;
        }
        currentTheme = item.id;
        setStatus('');
        void paint();
      });
      themeRow.appendChild(btn);
      return btn;
    });

    themeGroup.appendChild(themeLabel);
    themeGroup.appendChild(themeRow);

    errorEl = document.createElement('p');
    errorEl.className = 'penalis-share-card-modal__error';
    errorEl.setAttribute('role', 'alert');
    errorEl.hidden = true;

    statusEl = document.createElement('p');
    statusEl.className = 'penalis-share-card-modal__status';
    statusEl.setAttribute('role', 'status');
    statusEl.setAttribute('aria-live', 'polite');
    statusEl.hidden = true;

    const actions = document.createElement('div');
    actions.className = 'penalis-share-card-modal__actions';

    const secondaryActions = document.createElement('div');
    secondaryActions.className = 'penalis-share-card-modal__actions-secondary';

    if (supportsFileShare) {
      shareBtn = document.createElement('button');
      shareBtn.type = 'button';
      shareBtn.className = 'penalis-share-card-modal__btn penalis-share-card-modal__btn--primary';
      shareBtn.innerHTML = `${SHARE_ICON}<span>Bagikan Gambar</span>`;
      shareBtn.addEventListener('click', () => void handleNativeShare());
      actions.appendChild(shareBtn);

      downloadBtn = document.createElement('button');
      downloadBtn.type = 'button';
      downloadBtn.className = 'penalis-share-card-modal__btn penalis-share-card-modal__btn--ghost';
      downloadBtn.innerHTML = `${DOWNLOAD_ICON}<span>Unduh PNG</span>`;
      downloadBtn.addEventListener('click', () => void handleDownload());
      secondaryActions.appendChild(downloadBtn);
    } else {
      downloadBtn = document.createElement('button');
      downloadBtn.type = 'button';
      downloadBtn.className = 'penalis-share-card-modal__btn penalis-share-card-modal__btn--primary';
      downloadBtn.innerHTML = `${DOWNLOAD_ICON}<span>Unduh PNG (Siap Upload)</span>`;
      downloadBtn.addEventListener('click', () => void handleDownload());
      actions.appendChild(downloadBtn);
    }

    copyBtn = document.createElement('button');
    copyBtn.type = 'button';
    copyBtn.className = 'penalis-share-card-modal__btn penalis-share-card-modal__btn--ghost';
    copyBtn.innerHTML = COPY_ICON;
    copyBtnLabel = document.createElement('span');
    copyBtnLabel.textContent = 'Salin tautan profil';
    copyBtn.appendChild(copyBtnLabel);
    copyBtn.addEventListener('click', () => void handleCopyText());
    secondaryActions.appendChild(copyBtn);
    actions.appendChild(secondaryActions);

    const hint = document.createElement('p');
    hint.className = 'penalis-share-card-modal__hint';
    hint.textContent =
      'Resolusi tajam 1080px — siap langsung diunggah ke Instagram Story, Feed, atau WhatsApp Status.';

    controls.appendChild(formatGroup);
    controls.appendChild(themeGroup);
    controls.appendChild(errorEl);
    controls.appendChild(statusEl);
    controls.appendChild(actions);
    controls.appendChild(hint);

    body.appendChild(stage);
    body.appendChild(controls);

    dialog.appendChild(head);
    dialog.appendChild(body);
    overlay.appendChild(dialog);

    overlay.addEventListener('mousedown', (event) => {
      if (event.target === overlay) {
        close();
      }
    });

    document.addEventListener('keydown', (event) => {
      if (!overlay || overlay.hidden) {
        return;
      }

      if (event.key === 'Escape') {
        event.preventDefault();
        close();
        return;
      }

      if (event.key !== 'Tab') {
        return;
      }

      const nodes = Array.from(overlay.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR)).filter(
        (el) => el.getClientRects().length > 0,
      );
      const first = nodes[0];
      const last = nodes[nodes.length - 1];
      if (!first || !last) {
        return;
      }

      const active = document.activeElement;
      if (event.shiftKey && (active === first || !overlay.contains(active))) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && active === last) {
        event.preventDefault();
        first.focus();
      }
    });

    const mountRoot = document.querySelector('.penalis-author-archive-root') ?? document.body;
    mountRoot.appendChild(overlay);
  }

  function open(kind: 'profile' | 'quote' | 'article', trigger: HTMLElement): void {
    let payload: ShareCardPayload | null = null;

    if (kind === 'profile' && archiveConfig?.profile) {
      payload = { kind: 'profile', ...archiveConfig.profile };
    } else if (kind === 'quote' && archiveConfig?.quote) {
      payload = { kind: 'quote', ...archiveConfig.quote };
    } else if (kind === 'article') {
      const articleId = trigger.dataset.penalisArticleId ?? '';
      if (articleId !== '') {
        payload = resolveArticlePayload(articleId, archiveConfig, articleConfig);
      }
    }

    if (!payload) {
      return;
    }

    ensureModal();
    if (!overlay || !titleEl) {
      return;
    }

    currentPayload = payload;
    lastTrigger = trigger;
    titleEl.textContent =
      kind === 'quote'
        ? 'Bagikan #KalimatHariIni'
        : kind === 'article'
          ? 'Bagikan Karya Tulis'
          : 'Bagikan Profil';
    if (copyBtn) {
      copyBtn.classList.remove('is-copied');
    }
    if (copyBtnLabel) {
      copyBtnLabel.textContent = buildShareCopyContent(payload).label;
    }
    setError('');
    setStatus('');

    overlay.hidden = false;
    document.body.style.overflow = 'hidden';
    syncButtons();
    void paint();
    closeBtn?.focus();
  }

  document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target.closest<HTMLElement>('[data-penalis-open-card]') : null;
    if (!target) {
      return;
    }
    const kind = target.dataset.penalisOpenCard;
    if (kind === 'profile' || kind === 'quote' || kind === 'article') {
      open(kind, target);
    }
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mountAuthorArchiveCardModal);
} else {
  mountAuthorArchiveCardModal();
}
