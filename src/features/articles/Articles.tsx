/**
 * Paginated list of the authenticated writer's own articles.
 */

import { useCallback, useEffect, useState } from 'react';

import { ApiError, articlesApi } from '../../lib/api';
import type { ArticleList } from '../../lib/types';
import { formatDate, truncate } from '../../lib/format';
import {
  EmptyState,
  ErrorBanner,
  Notice,
  Pagination,
  Spinner,
  StatusBadge,
} from '../../components/ui';

const FILTERS = [
  { value: 'all', label: 'Semua' },
  { value: 'publish', label: 'Terbit' },
  { value: 'draft', label: 'Draf' },
  { value: 'pending', label: 'Menunggu tinjauan' },
];

export function Articles({
  onEdit,
  onCreate,
}: {
  onEdit: (id: number) => void;
  onCreate: () => void;
}) {
  const [status, setStatus] = useState('all');
  const [page, setPage] = useState(1);
  const [data, setData] = useState<ArticleList | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busyId, setBusyId] = useState<number | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError('');

    try {
      const result = await articlesApi.list(page, status);
      setData(result);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Karya tulismu tidak dapat dimuat.');
    } finally {
      setLoading(false);
    }
  }, [page, status]);

  useEffect(() => {
    void load();
  }, [load]);

  const changeStatus = (next: string) => {
    setStatus(next);
    setPage(1);
  };

  const publish = async (id: number) => {
    setBusyId(id);
    setError('');
    setNotice('');
    try {
      await articlesApi.publish(id);
      setNotice('Karya tulis diterbitkan.');
      await load();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Karya tulis tidak dapat diterbitkan.');
    } finally {
      setBusyId(null);
    }
  };

  const unpublish = async (id: number) => {
    setBusyId(id);
    setError('');
    setNotice('');
    try {
      await articlesApi.unpublish(id);
      setNotice('Karya tulis dikembalikan ke draf.');
      await load();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Karya tulis tidak dapat dikembalikan ke draf.');
    } finally {
      setBusyId(null);
    }
  };

  const remove = async (id: number) => {
    if (!window.confirm('Hapus karya tulis ini secara permanen? Tindakan ini tidak dapat dibatalkan.')) {
      return;
    }

    setBusyId(id);
    setError('');
    setNotice('');
    try {
      await articlesApi.remove(id);
      setNotice('Karya tulis dihapus.');
      await load();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Karya tulis tidak dapat dihapus.');
    } finally {
      setBusyId(null);
    }
  };

  return (
    <div className="penalis-stack">
      <div className="penalis-page-heading">
        <div>
          <h2>Karya tulismu</h2>
          <p className="penalis-muted">Kelola karya tulismu di sini.</p>
        </div>
        <button type="button" className="penalis-button penalis-button--primary" onClick={onCreate}>
          Buat Karya Baru
        </button>
      </div>

      <div className="penalis-tabs" role="tablist" aria-label="Saring menurut status">
        {FILTERS.map((filter) => (
          <button
            key={filter.value}
            type="button"
            role="tab"
            aria-selected={status === filter.value}
            className={`penalis-tab ${status === filter.value ? 'is-active' : ''}`}
            onClick={() => changeStatus(filter.value)}
          >
            {filter.label}
          </button>
        ))}
      </div>

      {notice ? <Notice tone="success">{notice}</Notice> : null}
      {error ? <ErrorBanner message={error} onRetry={() => void load()} /> : null}

      {loading ? (
        <Spinner label="Memuat karya…" />
      ) : data && data.items.length === 0 ? (
        <EmptyState
          title="Belum ada apa pun di sini"
          description={
            status === 'all'
              ? 'Kamu belum menulis karya apa pun.'
              : `Kamu tidak memiliki karya tulis dengan status “${status}”.`
          }
          action={
            <button type="button" className="penalis-button penalis-button--primary" onClick={onCreate}>
              Buat Karya Baru
            </button>
          }
        />
      ) : (
        <div className="penalis-table-wrap">
          <table className="penalis-table">
            <thead>
              <tr>
                <th scope="col">Judul Karya</th>
                <th scope="col">Status</th>
                <th scope="col">Diubah</th>
                <th scope="col">Tindakan</th>
              </tr>
            </thead>
            <tbody>
              {data?.items.map((article) => (
                <tr key={article.id}>
                  <td>
                    <strong>{article.title || 'Tanpa judul'}</strong>
                    <p className="penalis-muted">{truncate(article.excerpt, 100)}</p>
                  </td>
                  <td>
                    <StatusBadge status={article.status} />
                  </td>
                  <td>{formatDate(article.modified)}</td>
                  <td>
                    <div className="penalis-row-actions">
                      <button
                        type="button"
                        className="penalis-button penalis-button--ghost"
                        onClick={() => onEdit(article.id)}
                        disabled={busyId === article.id}
                      >
                        Ubah
                      </button>

                      {article.link ? (
                        <a
                          className="penalis-button penalis-button--ghost"
                          href={article.link}
                          target="_blank"
                          rel="noreferrer noopener"
                        >
                          Lihat
                        </a>
                      ) : null}

                      {article.status === 'publish' ? (
                        <button
                          type="button"
                          className="penalis-button penalis-button--ghost"
                          onClick={() => void unpublish(article.id)}
                          disabled={busyId === article.id}
                        >
                          Batalkan terbit
                        </button>
                      ) : (
                        <button
                          type="button"
                          className="penalis-button penalis-button--ghost"
                          onClick={() => void publish(article.id)}
                          disabled={busyId === article.id}
                        >
                          Terbitkan
                        </button>
                      )}

                      <button
                        type="button"
                        className="penalis-button penalis-button--danger"
                        onClick={() => void remove(article.id)}
                        disabled={busyId === article.id}
                      >
                        Hapus
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {data ? <Pagination page={data.page} totalPages={data.totalPages} onChange={setPage} /> : null}
    </div>
  );
}
