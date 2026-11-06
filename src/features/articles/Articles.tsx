/**
 * Paginated list of the authenticated writer's own articles.
 */

import { useCallback, useEffect, useState } from 'react';

import { ApiError, articlesApi } from '../../lib/api';
import type { ArticleList, ArticleSummary } from '../../lib/types';
import { editorialStateLabel, formatDate, truncate } from '../../lib/format';
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
  { value: 'draft', label: 'Draf' },
  { value: 'pending_review', label: 'Menunggu tinjauan' },
  { value: 'changes_requested', label: 'Perlu revisi' },
  { value: 'rejected', label: 'Ditolak' },
  { value: 'published', label: 'Terbit' },
];

/**
 * Which confirmation is open, if any.
 *
 * Holding the article rather than just its ID lets the dialog name the piece
 * being acted on, which is the main advantage over `window.confirm`.
 */
type PendingAction =
  | { kind: 'delete'; article: ArticleSummary }
  | { kind: 'submit'; article: ArticleSummary }
  | { kind: 'resubmit'; article: ArticleSummary }
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

  const isStaleEditorialError = (caught: unknown): boolean => {
    if (!(caught instanceof ApiError)) {
      return false;
    }

    return (
      caught.status === 409 ||
      caught.code === 'penalis_editorial_denied' ||
      caught.code === 'penalis_editorial_locked' ||
      caught.code === 'penalis_editorial_busy'
    );
  };

  const submitForReview = async (id: number) => {
    setBusyId(id);
    setError('');
    setNotice('');
    try {
      const result = await articlesApi.submit(id);
      setNotice(result.message || 'Karya tulis kamu telah diajukan dan sedang menunggu tinjauan admin.');
      setPending(null);
      await load();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Karya tulis tidak dapat diajukan untuk ditinjau.');
      setPending(null);
      if (isStaleEditorialError(caught)) {
        await load();
      }
    } finally {
      setBusyId(null);
    }
  };

  const resubmitForReview = async (id: number) => {
    setBusyId(id);
    setError('');
    setNotice('');
    try {
      const result = await articlesApi.resubmit(id);
      setNotice(result.message || 'Revisi karya tulis kamu telah diajukan ulang untuk ditinjau admin.');
      setPending(null);
      await load();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Revisi karya tulis tidak dapat diajukan ulang.');
      setPending(null);
      if (isStaleEditorialError(caught)) {
        await load();
      }
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
      setPending(null);
    } finally {
      setBusyId(null);
    }
  };

  const activeFilterLabel = FILTERS.find((item) => item.value === status)?.label ?? status;

  return (
    <div className="penalis-stack">
      <div className="penalis-page-heading">
        <div>
          <h2>Daftar Karya Tulis</h2>
          <p className="penalis-muted">
            Tinjau dan kelola daftar karya tulismu di sini. Karya yang sudah terbit bersifat read-only; hubungi admin
            jika memerlukan perubahan.
          </p>
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
              : `Kamu tidak memiliki karya tulis dengan status “${activeFilterLabel}”.`
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
                    {article.feedback ? (
                      <p className="penalis-article-feedback-note">
                        <strong>Catatan tinjauan:</strong> {truncate(article.feedback, 140)}
                      </p>
                    ) : null}
                  </td>
                  <td data-label="Status">
                    <div className="penalis-status-cell">
                      <StatusBadge
                        status={article.editorialState}
                        label={article.editorialStateLabel || editorialStateLabel(article.editorialState)}
                      />
                      {article.reviewCycle > 0 ? (
                        <span className="penalis-table__submeta">Siklus {article.reviewCycle}</span>
                      ) : null}
                    </div>
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
                        aria-label={
                          article.canEdit
                            ? `Ubah “${article.title || 'Tanpa judul'}”`
                            : `Lihat detail “${article.title || 'Tanpa judul'}”`
                        }
                        title={article.canEdit ? 'Ubah' : 'Lihat detail'}
                      >
                        <Icon name={article.canEdit ? 'edit' : 'eye'} />
                      </button>

                      {article.editorialState === 'published' && article.link ? (
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

                      {article.canSubmit ? (
                        <button
                          type="button"
                          className="penalis-icon-button"
                          onClick={() => setPending({ kind: 'submit', article })}
                          disabled={busyId === article.id}
                          aria-label={`Ajukan tinjauan “${article.title || 'Tanpa judul'}”`}
                          title="Ajukan tinjauan"
                        >
                          <Icon name="send" />
                        </button>
                      ) : null}

                      {article.canResubmit ? (
                        <button
                          type="button"
                          className="penalis-icon-button"
                          onClick={() => setPending({ kind: 'resubmit', article })}
                          disabled={busyId === article.id}
                          aria-label={`Ajukan ulang “${article.title || 'Tanpa judul'}”`}
                          title="Ajukan ulang"
                        >
                          <Icon name="send" />
                        </button>
                      ) : null}

                      {article.editorialState !== 'published' && article.editorialState !== 'pending_review' ? (
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
                      ) : null}
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
                <strong>{pending.article.title || 'Tanpa judul'}</strong> akan dihapus permanen. Tindakan ini tidak
                dapat dibatalkan.
              </p>
            </>
          }
          confirmLabel="Hapus permanen"
          busy={busyId === pending.article.id}
          onConfirm={() => void remove(pending.article.id)}
          onCancel={() => setPending(null)}
        />
      ) : null}

      {pending?.kind === 'submit' ? (
        <ConfirmDialog
          title="Ajukan karya tulis untuk ditinjau?"
          description={
            <>
              <p>
                <strong>{pending.article.title || 'Tanpa judul'}</strong> akan dikirim ke antrean tinjauan admin
                Penalis.
              </p>
              <p className="penalis-muted">
                Selama proses tinjauan berlangsung, karya tulis dikunci dari perubahan sampai admin menyetujui,
                meminta revisi, atau menolak pengajuan.
              </p>
            </>
          }
          confirmLabel="Ajukan Tinjauan"
          tone="warning"
          busy={busyId === pending.article.id}
          onConfirm={() => void submitForReview(pending.article.id)}
          onCancel={() => setPending(null)}
        />
      ) : null}

      {pending?.kind === 'resubmit' ? (
        <ConfirmDialog
          title="Ajukan ulang revisi karya tulis?"
          description={
            <>
              <p>
                Revisi untuk <strong>{pending.article.title || 'Tanpa judul'}</strong> akan dikirim kembali ke antrean
                tinjauan admin Penalis.
              </p>
              <p className="penalis-muted">
                Pastikan kamu sudah memperbarui karya tulis sesuai catatan tinjauan admin sebelum mengajukannya ulang.
              </p>
            </>
          }
          confirmLabel="Ajukan Ulang"
          tone="warning"
          busy={busyId === pending.article.id}
          onConfirm={() => void resubmitForReview(pending.article.id)}
          onCancel={() => setPending(null)}
        />
      ) : null}
    </div>
  );
}
