/**
 * Read-only media library for the current writer.
 */

import { useEffect, useState } from 'react';

import { ApiError, mediaApi } from '../../lib/api';
import type { MediaList } from '../../lib/types';
import { formatDate } from '../../lib/format';
import { EmptyState, ErrorBanner, Pagination, Spinner } from '../../components/ui';

export function Media() {
  const [page, setPage] = useState(1);
  const [data, setData] = useState<MediaList | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError('');

    mediaApi
      .list(page)
      .then((result) => {
        if (!cancelled) {
          setData(result);
        }
      })
      .catch((caught: unknown) => {
        if (!cancelled) {
          setError(caught instanceof ApiError ? caught.message : 'Media Anda tidak dapat dimuat.');
        }
      })
      .finally(() => {
        if (!cancelled) {
          setLoading(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [page]);

  return (
    <div className="penalis-stack">
      <div className="penalis-page-heading">
        <div>
          <h2>Media</h2>
          <p className="penalis-muted">
            Gambar yang Anda unggah. Tambahkan gambar baru dari panel media editor artikel; hanya file JPEG, PNG, GIF,
            dan WebP yang diterima.
          </p>
        </div>
      </div>

      {error ? <ErrorBanner message={error} /> : null}

      {loading ? (
        <Spinner label="Memuat media…" />
      ) : data && data.items.length === 0 ? (
        <EmptyState
          title="Belum ada media"
          description="Gambar yang Anda unggah untuk artikel akan tampil di sini."
        />
      ) : (
        <div className="penalis-media-grid">
          {data?.items.map((item) => (
            <figure key={item.id} className="penalis-media-card">
              {item.thumbnail ? <img src={item.thumbnail} alt={item.title} /> : null}
              <figcaption>
                <strong>{item.title}</strong>
                <span className="penalis-muted">{formatDate(item.date)}</span>
              </figcaption>
            </figure>
          ))}
        </div>
      )}

      {data ? <Pagination page={data.page} totalPages={data.totalPages} onChange={setPage} /> : null}
    </div>
  );
}
