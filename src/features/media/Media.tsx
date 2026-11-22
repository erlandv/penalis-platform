/**
 * Writer media library: browse, upload, and delete unused images.
 */

import { useCallback, useEffect, useState } from 'react';

import { ApiError, mediaApi } from '../../lib/api';
import { prependUploadedMedia, removeDeletedMedia } from '../../lib/mediaPagination';
import type { MediaItem, MediaList } from '../../lib/types';
import { formatDate } from '../../lib/format';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { Icon } from '../../components/icons';
import { ImageDropzone } from '../../components/ImageDropzone';
import { useToast } from '../../components/toastContext';
import { EmptyState, ErrorBanner, PageHeading, Pagination, Spinner } from '../../components/ui';

function formatUsageLabel(item: MediaItem): string {
  const count = item.usageCount ?? 0;
  if (count > 0) {
    return `Dipakai di ${count} karya`;
  }

  return 'Sedang dipakai';
}

function formatUsageTooltip(item: MediaItem): string {
  if (item.deleteBlockedReason) {
    return item.deleteBlockedReason;
  }

  if (item.usedIn && item.usedIn.length > 0) {
    return `Dipakai di: ${item.usedIn.map((entry) => entry.title).join(', ')}`;
  }

  return 'Gambar ini sedang dipakai dan tidak dapat dihapus.';
}

export function Media() {
  const [page, setPage] = useState(1);
  const [data, setData] = useState<MediaList | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [uploading, setUploading] = useState(false);
  const [uploadError, setUploadError] = useState('');
  const [deleteTarget, setDeleteTarget] = useState<MediaItem | null>(null);
  const [deleting, setDeleting] = useState(false);
  const [deleteError, setDeleteError] = useState('');
  const showToast = useToast();

  const loadPage = useCallback((targetPage: number, isCancelled?: () => boolean) => {
    setLoading(true);
    setError('');

    mediaApi
      .list(targetPage)
      .then((result) => {
        if (!isCancelled?.()) {
          setData(result);
        }
      })
      .catch((caught: unknown) => {
        if (!isCancelled?.()) {
          setError(caught instanceof ApiError ? caught.message : 'Media kamu tidak dapat dimuat.');
        }
      })
      .finally(() => {
        if (!isCancelled?.()) {
          setLoading(false);
        }
      });
  }, []);

  const upload = async (file: File) => {
    setUploading(true);
    setUploadError('');
    setDeleteError('');
    try {
      const item = await mediaApi.upload(file);
      if (page > 1) {
        // Jump to page 1 so the newly uploaded image appears at the top with fresh pagination.
        setPage(1);
      } else {
        setData((current) => prependUploadedMedia(current, item));
      }
    } catch (caught) {
      setUploadError(caught instanceof ApiError ? caught.message : 'Gambar tidak dapat di-upload.');
    } finally {
      setUploading(false);
    }
  };

  const confirmDelete = async () => {
    if (!deleteTarget) {
      return;
    }

    const target = deleteTarget;
    setDeleting(true);
    setDeleteError('');

    try {
      await mediaApi.remove(target.id);
      setDeleteTarget(null);
      showToast('Gambar telah dihapus.');

      const remainingOnPage = (data?.items.length ?? 0) - 1;
      const hasNextPage = (data?.totalPages ?? 1) > page;

      if (remainingOnPage <= 0 && page > 1) {
        setPage((current) => Math.max(1, current - 1));
      } else if (hasNextPage) {
        // Reload current page so the next page's first item fills the slot and totalPages stays exact.
        loadPage(page);
      } else {
        setData((current) => removeDeletedMedia(current, target.id));
      }
    } catch (caught) {
      setDeleteTarget(null);
      if (caught instanceof ApiError) {
        setDeleteError(caught.message);
        if (caught.status === 409 || caught.code === 'penalis_media_in_use') {
          loadPage(page);
        }
      } else {
        setDeleteError('Gambar tidak dapat dihapus.');
      }
    } finally {
      setDeleting(false);
    }
  };

  useEffect(() => {
    let cancelled = false;
    loadPage(page, () => cancelled);

    return () => {
      cancelled = true;
    };
  }, [page, loadPage]);

  return (
    <div className="penalis-stack">
      <PageHeading
        title="Pustaka Media"
        description="Kelola gambar yang kamu upload. Gambar yang tidak sedang dipakai di karya tulis dapat dihapus."
      />

      <ImageDropzone
        onFile={(file) => void upload(file)}
        onReject={setUploadError}
        busy={uploading}
        disabled={loading}
        title="Letakkan gambar di sini"
        subtitle="atau klik untuk memilih file"
        hint="JPEG, PNG, GIF, atau WebP."
      />
      {uploadError ? <ErrorBanner message={uploadError} /> : null}
      {deleteError ? <ErrorBanner message={deleteError} /> : null}
      {error ? <ErrorBanner message={error} /> : null}

      {loading ? (
        <Spinner label="Memuat media…" />
      ) : data && data.items.length === 0 ? (
        <EmptyState
          title="Belum ada media"
          description="Gambar yang kamu upload akan tampil di sini."
        />
      ) : (
        <div className="penalis-media-grid">
          {data?.items.map((item) => {
            const title = item.title || 'Tanpa judul';
            const inUse = Boolean(item.inUse);
            const canDelete = item.canDelete ?? !inUse;

            return (
              <figure key={item.id} className="penalis-media-card" title={title}>
                {item.thumbnail ? <img src={item.thumbnail} alt={title} /> : null}
                <figcaption>
                  <strong className="penalis-media-card__title">{title}</strong>
                  <span className="penalis-media-card__date">{formatDate(item.date)}</span>
                </figcaption>
                <div className="penalis-media-card__footer">
                  {inUse || !canDelete ? (
                    <span
                      className="penalis-media-card__usage"
                      title={formatUsageTooltip(item)}
                    >
                      {formatUsageLabel(item)}
                    </span>
                  ) : (
                    <button
                      type="button"
                      className="penalis-media-card__delete"
                      aria-label={`Hapus ${title}`}
                      disabled={deleting}
                      onClick={() => {
                        setDeleteError('');
                        setDeleteTarget(item);
                      }}
                    >
                      <Icon name="trash" />
                      <span>Hapus</span>
                    </button>
                  )}
                </div>
              </figure>
            );
          })}
        </div>
      )}

      {data ? <Pagination page={data.page} totalPages={data.totalPages} onChange={setPage} /> : null}

      {deleteTarget !== null ? (
        <ConfirmDialog
          title="Hapus gambar ini?"
          description={
            <p>
              Gambar <strong>{deleteTarget.title || 'Tanpa judul'}</strong> akan dihapus secara permanen dari
              Pustaka Media dan tidak dapat dibatalkan.
            </p>
          }
          confirmLabel="Hapus permanen"
          tone="danger"
          busy={deleting}
          onConfirm={() => void confirmDelete()}
          onCancel={() => setDeleteTarget(null)}
        />
      ) : null}
    </div>
  );
}
