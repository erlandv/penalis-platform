/**
 * Shared image selection controls for the media picker dialogs.
 *
 * Used by the article editor (featured image and inline insert image), so both
 * dialogs behave the same way: the whole card is the target, the selection is
 * a native radio group, and a single "Pilih" button in a sticky footer applies
 * it.
 *
 * The component is presentational on purpose — it owns no data fetching and no
 * upload logic, because each caller has a different source and a different
 * meaning for "confirm".
 */

import { useEffect, useId, useState, type ReactNode } from 'react';

import { ApiError, mediaApi } from '../lib/api';
import { appendUniqueMedia } from '../lib/mediaPagination';
import type { MediaItem } from '../lib/types';
import { Dialog } from './Dialog';
import { ImageDropzone } from './ImageDropzone';
import { ErrorBanner, Spinner } from './ui';

export function MediaSelection({
  items,
  selectedId,
  onSelect,
  onConfirm,
  onCancel,
  busy = false,
  emptyMessage = 'Belum ada gambar. Upload gambar terlebih dahulu.',
  afterGrid,
}: {
  items: MediaItem[];
  selectedId: number | null;
  onSelect: (id: number) => void;
  onConfirm: () => void;
  onCancel: () => void;
  busy?: boolean;
  emptyMessage?: string;
  afterGrid?: ReactNode;
}) {
  // A unique radio group name per instance, so two pickers on one page cannot
  // steal each other's selection.
  const groupName = useId();
  const selected = items.find((item) => item.id === selectedId) ?? null;

  return (
    <>
      {items.length === 0 ? (
        <p className="penalis-muted">{emptyMessage}</p>
      ) : (
        <>
          <div className="penalis-media-grid" role="radiogroup" aria-label="Pilih gambar">
            {items.map((item) => {
              const isSelected = item.id === selectedId;
              const title = item.title || 'Tanpa judul';

              return (
                <label
                  key={item.id}
                  className={`penalis-media-card penalis-media-card--selectable ${isSelected ? 'is-selected' : ''}`}
                  title={title}
                >
                  <input
                    type="radio"
                    name={groupName}
                    className="penalis-media-card__radio"
                    checked={isSelected}
                    disabled={busy}
                    onChange={() => onSelect(item.id)}
                  />
                  <img src={item.thumbnail || item.url} alt="" />
                  <span className="penalis-media-card__caption">{title}</span>
                </label>
              );
            })}
          </div>
          {afterGrid}
        </>
      )}

      <div className="penalis-media-picker__footer">
        <p className="penalis-muted penalis-media-picker__selection" role="status" aria-live="polite">
          {selected ? `Dipilih: ${selected.title || 'Tanpa judul'}` : 'Belum ada gambar yang dipilih.'}
        </p>
        <div className="penalis-media-picker__actions">
          <button type="button" className="penalis-button penalis-button--ghost" onClick={onCancel} disabled={busy}>
            Batal
          </button>
          <button
            type="button"
            className="penalis-button penalis-button--primary"
            onClick={onConfirm}
            disabled={busy || !selected}
          >
            {busy ? 'Memproses…' : 'Pilih'}
          </button>
        </div>
      </div>
    </>
  );
}

/**
 * Shared modal dialog for browsing the writer's media library, uploading a new
 * image, and confirming a selection.
 */
export function MediaPickerDialog({
  label,
  title,
  description,
  dropzoneHint = 'Hanya dapat upload file JPEG, PNG, GIF, atau WebP.',
  emptyMessage = 'Kamu belum upload media apa pun. Gunakan kontrol upload di atas untuk menambahkan gambar pertama.',
  externalError = '',
  busy = false,
  onFile,
  onConfirm,
  onClose,
}: {
  label: string;
  title: string;
  description?: string;
  dropzoneHint?: string;
  emptyMessage?: string;
  externalError?: string;
  busy?: boolean;
  onFile?: (file: File) => void;
  onConfirm: (item: MediaItem) => void;
  onClose: () => void;
}) {
  const [media, setMedia] = useState<MediaItem[]>([]);
  const [mediaPage, setMediaPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [totalMedia, setTotalMedia] = useState(0);
  const [mediaLoading, setMediaLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [loadMoreError, setLoadMoreError] = useState('');
  const [selectedMediaId, setSelectedMediaId] = useState<number | null>(null);
  const [uploading, setUploading] = useState(false);
  const [uploadError, setUploadError] = useState('');

  useEffect(() => {
    let cancelled = false;
    setMediaLoading(true);
    setLoadMoreError('');

    mediaApi
      .list(1)
      .then((list) => {
        if (!cancelled) {
          setMedia(list.items);
          setMediaPage(list.page);
          setTotalPages(Math.max(1, list.totalPages));
          setTotalMedia(list.total);
        }
      })
      .catch(() => {
        if (!cancelled) {
          setMedia([]);
          setMediaPage(1);
          setTotalPages(1);
          setTotalMedia(0);
        }
      })
      .finally(() => {
        if (!cancelled) {
          setMediaLoading(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, []);

  const loadMore = async () => {
    if (loadingMore || mediaPage >= totalPages) {
      return;
    }

    const nextPage = mediaPage + 1;
    setLoadingMore(true);
    setLoadMoreError('');

    try {
      const list = await mediaApi.list(nextPage);
      setMedia((current) => appendUniqueMedia(current, list.items));
      setMediaPage(list.page);
      setTotalPages(Math.max(1, list.totalPages));
      setTotalMedia(list.total);
    } catch (caught) {
      setLoadMoreError(caught instanceof ApiError ? caught.message : 'Media tambahan tidak dapat dimuat.');
    } finally {
      setLoadingMore(false);
    }
  };

  const handleFile = async (file: File) => {
    setUploadError('');
    if (onFile) {
      onFile(file);
      return;
    }

    setUploading(true);
    try {
      const uploaded = await mediaApi.upload(file);
      setMedia((current) => [uploaded, ...current.filter((entry) => entry.id !== uploaded.id)]);
      setTotalMedia((current) => current + 1);
      setSelectedMediaId(uploaded.id);
    } catch (caught) {
      setUploadError(caught instanceof ApiError ? caught.message : 'Gambar tidak dapat di-upload.');
    } finally {
      setUploading(false);
    }
  };

  const isBusy = busy || uploading;
  const hasMore = mediaPage < totalPages;

  return (
    <Dialog label={label} onClose={onClose}>
      <div className="penalis-modal__content">
        <div className="penalis-modal__header">
          <div>
            <h3>{title}</h3>
            {description ? <p className="penalis-muted penalis-media-picker__hint">{description}</p> : null}
          </div>
        </div>

        <ImageDropzone
          onFile={(file) => void handleFile(file)}
          onReject={setUploadError}
          busy={isBusy}
          title="Letakkan gambar di sini"
          subtitle="atau klik untuk memilih file"
          hint={dropzoneHint}
        />

        {uploadError ? <ErrorBanner message={uploadError} /> : null}
        {externalError ? <ErrorBanner message={externalError} /> : null}

        {mediaLoading ? (
          <Spinner label="Memuat media…" />
        ) : media.length === 0 ? (
          <p className="penalis-muted">{emptyMessage}</p>
        ) : (
          <MediaSelection
            items={media}
            selectedId={selectedMediaId}
            onSelect={setSelectedMediaId}
            onConfirm={() => {
              const item = media.find((entry) => entry.id === selectedMediaId);
              if (item) {
                onConfirm(item);
              }
            }}
            onCancel={onClose}
            busy={isBusy}
            afterGrid={
              hasMore || loadMoreError ? (
                <div className="penalis-media-picker__more">
                  {loadMoreError ? <ErrorBanner message={loadMoreError} /> : null}
                  {hasMore ? (
                    <button
                      type="button"
                      className="penalis-button penalis-button--ghost"
                      onClick={() => void loadMore()}
                      disabled={loadingMore || isBusy}
                    >
                      {loadingMore
                        ? 'Memuat…'
                        : `Muat lebih banyak (${media.length} dari ${Math.max(totalMedia, media.length)})`}
                    </button>
                  ) : null}
                </div>
              ) : null
            }
          />
        )}
      </div>
    </Dialog>
  );
}
