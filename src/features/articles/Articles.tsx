/**
 * Paginated list of the authenticated writer's own articles.
 */

import { useCallback, useEffect, useState } from 'react';

import { ApiError, articlesApi } from '../../lib/api';
import type { ArticleList, ArticleSummary } from '../../lib/types';
import { formatDate, truncate } from '../../lib/format';
import { Icon } from '../../components/icons';
import { ConfirmDialog } from '../../components/ConfirmDialog';
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

/**
 * Which confirmation is open, if any.
 *
 * Holding the article rather than just its ID lets the dialog name the piece
 * being acted on, which is the main advantage over `window.confirm`.
 */
type PendingAction =
  | { kind: 'delete'; article: ArticleSummary }
  | { kind: 'unpublish'; article: ArticleSummary }
  | { kind: 'publish'; article: ArticleSummary }
  | null;

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
  const [pending, setPending] = useState<PendingAction>(null);

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
      setPending(null);
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
      setPending(null);
      await load();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Karya tulis tidak dapat dikembalikan ke draf.');
    } finally {
      setBusyId(null);
    }
  };

  const remove = async (id: number) => {
    setBusyId(id);
    setError('');
    setNotice('');
    try {
      await articlesApi.remove(id);
      setNotice('Karya tulis dihapus.');
      setPending(null);
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
                  <td data-label="Status">
                    <StatusBadge status={article.status} />
                  </td>
                  <td data-label="Diubah">{formatDate(article.modified)}</td>
                  <td data-label="Tindakan">
                    {/*
                      Icon-only actions keep the table readable: four labelled
                      buttons per row wrapped onto two lines and made the
                      "Tindakan" column wider than the title column. Each button
                      carries an `aria-label` that names both the action and the
                      article, so screen readers still get full context, and a
                      `title` so a mouse user can hover for the same text.
                    */}
                    <div className="penalis-row-actions penalis-row-actions--compact">
                      <button
                        type="button"
                        className="penalis-icon-button"
                        onClick={() => onEdit(article.id)}
                        disabled={busyId === article.id}
                        aria-label={`Ubah “${article.title || 'Tanpa judul'}”`}
                        title="Ubah"
                      >
                        <Icon name="edit" />
                      </button>

                      {article.link ? (
                        <a
                          className="penalis-icon-button"
                          href={article.link}
                          target="_blank"
                          rel="noreferrer noopener"
                          aria-label={`Lihat “${article.title || 'Tanpa judul'}” di situs`}
                          title="Lihat di situs"
                        >
                          <Icon name="externalLink" />
                        </a>
                      ) : null}

                      {article.status === 'publish' ? (
                        <button
                          type="button"
                          className="penalis-icon-button"
                          onClick={() => setPending({ kind: 'unpublish', article })}
                          disabled={busyId === article.id}
                          aria-label={`Batalkan terbit “${article.title || 'Tanpa judul'}”`}
                          title="Batalkan terbit"
                        >
                          <Icon name="eyeOff" />
                        </button>
                      ) : (
                        <button
                          type="button"
                          className="penalis-icon-button"
                          onClick={() => setPending({ kind: 'publish', article })}
                          disabled={busyId === article.id}
                          aria-label={`Terbitkan “${article.title || 'Tanpa judul'}”`}
                          title="Terbitkan"
                        >
                          <Icon name="send" />
                        </button>
                      )}

                      <button
                        type="button"
                        className="penalis-icon-button penalis-icon-button--danger"
                        onClick={() => setPending({ kind: 'delete', article })}
                        disabled={busyId === article.id}
                        aria-label={`Hapus “${article.title || 'Tanpa judul'}”`}
                        title="Hapus"
                      >
                        <Icon name="trash" />
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

      {pending?.kind === 'delete' ? (
        <ConfirmDialog
          title="Hapus karya tulis?"
          description={
            <>
              <p>
                <strong>{pending.article.title || 'Tanpa judul'}</strong> akan dihapus permanen beserta
                gambarnya. Tindakan ini tidak dapat dibatalkan.
              </p>
              <p className="penalis-muted">Jika hanya ingin menyembunyikannya, gunakan “Batalkan terbit”.</p>
            </>
          }
          confirmLabel="Hapus permanen"
          busy={busyId === pending.article.id}
          onConfirm={() => void remove(pending.article.id)}
          onCancel={() => setPending(null)}
        />
      ) : null}

      {pending?.kind === 'unpublish' ? (
        <ConfirmDialog
          title="Batalkan terbit?"
          description={
            <>
              <p>
                <strong>{pending.article.title || 'Tanpa judul'}</strong> akan kembali menjadi draf dan tidak
                lagi tampil di situs. Karya tulisnya tetap tersimpan dan bisa diterbitkan lagi kapan saja.
              </p>
            </>
          }
          confirmLabel="Batalkan terbit"
          tone="warning"
          busy={busyId === pending.article.id}
          onConfirm={() => void unpublish(pending.article.id)}
          onCancel={() => setPending(null)}
        />
      ) : null}

      {pending?.kind === 'publish' ? (
        <ConfirmDialog
          title="Terbitkan karya tulis?"
          description={
            <>
              <p>
                <strong>{pending.article.title || 'Tanpa judul'}</strong> akan langsung tampil di situs dan
                dapat dibaca siapa saja.
              </p>
              <p className="penalis-muted">
                Kamu masih bisa membatalkan terbit kapan saja jika ada yang perlu diperbaiki.
              </p>
            </>
          }
          confirmLabel="Terbitkan"
          tone="warning"
          busy={busyId === pending.article.id}
          onConfirm={() => void publish(pending.article.id)}
          onCancel={() => setPending(null)}
        />
      ) : null}
    </div>
  );
}
