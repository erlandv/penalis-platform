/**
 * Accessible preview, theme/format switcher, and PNG export modal for
 * shareable Penalis cards (#KalimatHariIni & Profil Penulis).
 *
 * Reuses `Dialog` for focus trapping and Escape handling, and delegates all
 * drawing/export to `src/lib/shareCard.ts`. Styling lives in
 * `src/styles/share-card.css` (imported via CSS `@import` from `main.css` and
 * `quotes.css`, never imported from TSX).
 */

import { useEffect, useMemo, useRef, useState } from 'react';

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
  type ShareCardBrandAssets,
  type ShareCardFormat,
  type ShareCardPayload,
  type ShareCardTheme,
} from '../lib/shareCard';
import { Dialog } from './Dialog';

function CloseSvg() {
  return (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <line x1="18" y1="6" x2="6" y2="18" />
      <line x1="6" y1="6" x2="18" y2="18" />
    </svg>
  );
}

function DownloadSvg() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
      <polyline points="7 10 12 15 17 10" />
      <line x1="12" y1="15" x2="12" y2="3" />
    </svg>
  );
}

function ShareSvg() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <circle cx="18" cy="5" r="3" />
      <circle cx="6" cy="12" r="3" />
      <circle cx="18" cy="19" r="3" />
      <line x1="8.59" y1="13.51" x2="15.42" y2="17.49" />
      <line x1="15.41" y1="6.51" x2="8.59" y2="10.49" />
    </svg>
  );
}

function CopySvg() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <rect x="9" y="9" width="13" height="13" rx="2" ry="2" />
      <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />
    </svg>
  );
}

export function ShareCardModal({
  payload,
  brand,
  onClose,
  onNotify,
}: {
  payload: ShareCardPayload;
  brand?: ShareCardBrandAssets;
  onClose: () => void;
  onNotify?: (message: string, tone?: 'success' | 'danger') => void;
}) {
  const [format, setFormat] = useState<ShareCardFormat>('story');
  const [theme, setTheme] = useState<ShareCardTheme>('senja');
  const [rendering, setRendering] = useState(true);
  const [busy, setBusy] = useState(false);
  const [copied, setCopied] = useState(false);
  const [error, setError] = useState('');
  const [statusMessage, setStatusMessage] = useState('');

  const canvasRef = useRef<HTMLCanvasElement>(null);
  const renderSeqRef = useRef(0);
  const copyTimerRef = useRef<number | null>(null);
  const supportsFileShare = useMemo(() => canNativeShareFiles(), []);
  const copyContent = useMemo(() => buildShareCopyContent(payload), [payload]);

  const title =
    payload.kind === 'quote'
      ? 'Bagikan #KalimatHariIni'
      : payload.kind === 'article'
        ? 'Bagikan Karya Tulis'
        : 'Bagikan Profil';

  useEffect(() => {
    return () => {
      if (copyTimerRef.current !== null) {
        window.clearTimeout(copyTimerRef.current);
      }
    };
  }, []);

  const brandLogoUrl = brand?.logoUrl ?? '';
  const brandIconUrl = brand?.iconUrl ?? '';

  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas) {
      return;
    }

    const seq = renderSeqRef.current + 1;
    renderSeqRef.current = seq;
    setRendering(true);
    setError('');

    void renderShareCard(canvas, payload, {
      format,
      theme,
      brand: { logoUrl: brandLogoUrl, iconUrl: brandIconUrl },
    })
      .then(() => {
        if (renderSeqRef.current === seq) {
          setRendering(false);
        }
      })
      .catch(() => {
        if (renderSeqRef.current === seq) {
          setRendering(false);
          setError('Gagal merender pratinjau kartu. Coba muat ulang halaman.');
        }
      });
  }, [payload, format, theme, brandLogoUrl, brandIconUrl]);

  const handleDownload = async () => {
    const canvas = canvasRef.current;
    if (!canvas || rendering || busy) {
      return;
    }

    setBusy(true);
    setError('');
    try {
      const blob = await exportShareCardBlob(canvas);
      const filename = buildShareCardFilename(payload, format);
      downloadCardImage(blob, filename);
      const msg = 'Kartu gambar berhasil diunduh.';
      setStatusMessage(msg);
      onNotify?.(msg, 'success');
    } catch {
      const msg = 'Gambar tidak dapat diunduh dari browser ini.';
      setError(msg);
      onNotify?.(msg, 'danger');
    } finally {
      setBusy(false);
    }
  };

  const handleNativeShare = async () => {
    const canvas = canvasRef.current;
    if (!canvas || rendering || busy) {
      return;
    }

    setBusy(true);
    setError('');
    try {
      const blob = await exportShareCardBlob(canvas);
      const filename = buildShareCardFilename(payload, format);
      const shareTitle =
        payload.kind === 'quote'
          ? `#KalimatHariIni — ${payload.displayName}`
          : payload.kind === 'article'
            ? `${payload.title} — ${payload.displayName}`
            : `${payload.displayName} di Penalis`;
      const shareText =
        payload.kind === 'quote'
          ? `"${payload.text}" — ${payload.displayName} (#KalimatHariIni di Penalis)`
          : payload.kind === 'article'
            ? `Baca "${payload.title}" oleh ${payload.displayName} di Penalis: ${payload.articleUrl}`
            : `Lihat profil dan karya tulis ${payload.displayName} di Penalis.`;

      const outcome = await shareCardImage(blob, filename, shareTitle, shareText);
      if (outcome === 'unsupported') {
        downloadCardImage(blob, filename);
        const msg = 'Kartu gambar diunduh (perangkat belum mendukung berbagi gambar langsung).';
        setStatusMessage(msg);
        onNotify?.(msg, 'success');
      } else if (outcome === 'shared') {
        setStatusMessage('Kartu siap dibagikan.');
      }
    } catch {
      const msg = 'Gagal membagikan gambar. Gunakan tombol Unduh PNG.';
      setError(msg);
      onNotify?.(msg, 'danger');
    } finally {
      setBusy(false);
    }
  };

  const handleCopyText = async () => {
    if (busy) {
      return;
    }

    const ok = await copyToClipboard(copyContent.text);
    if (!ok) {
      const msg = 'Gagal menyalin ke clipboard.';
      setError(msg);
      onNotify?.(msg, 'danger');
      return;
    }

    setError('');
    setCopied(true);
    setStatusMessage(copyContent.copiedLabel);
    onNotify?.(copyContent.copiedLabel, 'success');

    if (copyTimerRef.current !== null) {
      window.clearTimeout(copyTimerRef.current);
    }
    copyTimerRef.current = window.setTimeout(() => {
      setCopied(false);
    }, 2200);
  };

  return (
    <Dialog label={title} onClose={busy ? () => undefined : onClose}>
      <div className="penalis-share-card-modal">
        <div className="penalis-share-card-modal__head">
          <div>
            <h3 className="penalis-share-card-modal__title">{title}</h3>
            <p className="penalis-share-card-modal__subtitle">
              Pilih ukuran &amp; nuansa warna, lalu bagikan ke Instagram Story atau Feed.
            </p>
          </div>
          <button
            type="button"
            className="penalis-share-card-modal__close"
            onClick={onClose}
            disabled={busy}
            aria-label="Tutup dialog kartu gambar"
          >
            <CloseSvg />
          </button>
        </div>

        <div className="penalis-share-card-modal__body">
          <div className="penalis-share-card-modal__stage">
            <div
              className={`penalis-share-card-modal__frame penalis-share-card-modal__frame--${format}`}
            >
              <canvas
                ref={canvasRef}
                className={`penalis-share-card-modal__canvas${rendering ? ' is-loading' : ''}`}
                aria-label={`Pratinjau ${title}`}
              />
              {rendering ? (
                <div className="penalis-share-card-modal__loading" aria-live="polite">
                  Menyiapkan kartu…
                </div>
              ) : null}
            </div>
          </div>

          <div className="penalis-share-card-modal__controls">
            <div className="penalis-share-card-modal__group">
              <span className="penalis-share-card-modal__label">Ukuran</span>
              <div className="penalis-share-card-modal__segmented" role="group" aria-label="Pilih ukuran kartu">
                {SHARE_CARD_FORMATS.map((item) => {
                  const active = format === item.id;
                  return (
                    <button
                      key={item.id}
                      type="button"
                      className={`penalis-share-card-modal__pill${active ? ' is-active' : ''}`}
                      aria-pressed={active}
                      onClick={() => setFormat(item.id)}
                    >
                      <span>{item.label}</span>
                      <span className="penalis-share-card-modal__ratio">{item.ratioLabel}</span>
                    </button>
                  );
                })}
              </div>
            </div>

            <div className="penalis-share-card-modal__group">
              <span className="penalis-share-card-modal__label">Nuansa</span>
              <div className="penalis-share-card-modal__themes" role="group" aria-label="Pilih tema warna kartu">
                {SHARE_CARD_THEMES.map((item) => {
                  const active = theme === item.id;
                  return (
                    <button
                      key={item.id}
                      type="button"
                      className={`penalis-share-card-modal__theme-btn${active ? ' is-active' : ''}`}
                      aria-pressed={active}
                      onClick={() => setTheme(item.id)}
                    >
                      <span
                        className="penalis-share-card-modal__swatch"
                        style={{ background: item.swatch }}
                        aria-hidden="true"
                      />
                      <span>{item.label}</span>
                    </button>
                  );
                })}
              </div>
            </div>

            {error ? (
              <p className="penalis-share-card-modal__error" role="alert">
                {error}
              </p>
            ) : null}

            {statusMessage ? (
              <p className="penalis-share-card-modal__status" role="status" aria-live="polite">
                {statusMessage}
              </p>
            ) : null}

            <div className="penalis-share-card-modal__actions">
              {supportsFileShare ? (
                <button
                  type="button"
                  className="penalis-share-card-modal__btn penalis-share-card-modal__btn--primary"
                  onClick={() => void handleNativeShare()}
                  disabled={rendering || busy}
                >
                  <ShareSvg />
                  <span>{busy ? 'Menyiapkan…' : 'Bagikan Gambar'}</span>
                </button>
              ) : (
                <button
                  type="button"
                  className="penalis-share-card-modal__btn penalis-share-card-modal__btn--primary"
                  onClick={() => void handleDownload()}
                  disabled={rendering || busy}
                >
                  <DownloadSvg />
                  <span>{busy ? 'Menyiapkan…' : 'Unduh PNG (Siap Upload)'}</span>
                </button>
              )}

              <div className="penalis-share-card-modal__actions-secondary">
                {supportsFileShare ? (
                  <button
                    type="button"
                    className="penalis-share-card-modal__btn penalis-share-card-modal__btn--ghost"
                    onClick={() => void handleDownload()}
                    disabled={rendering || busy}
                  >
                    <DownloadSvg />
                    <span>Unduh PNG</span>
                  </button>
                ) : null}

                <button
                  type="button"
                  className={`penalis-share-card-modal__btn penalis-share-card-modal__btn--ghost${copied ? ' is-copied' : ''}`}
                  onClick={() => void handleCopyText()}
                  disabled={busy}
                >
                  <CopySvg />
                  <span>{copied ? copyContent.copiedLabel : copyContent.label}</span>
                </button>
              </div>
            </div>

            <p className="penalis-share-card-modal__hint">
              Resolusi tajam 1080px — siap langsung diunggah ke Instagram Story, Feed, atau WhatsApp Status.
            </p>
          </div>
        </div>
      </div>
    </Dialog>
  );
}
