/**
 * Paginated list of the authenticated writer's own articles.
 */

import { useCallback, useEffect, useState } from 'react';

import { ApiError, articlesApi, isStaleEditorialError } from '../../lib/api';
import type {
  AccountCounts,
  ArticleList,
  ArticleSummary,
  EditorialState,
  EditorFocus,
} from '../../lib/types';
import { editorialStateLabel, formatDate, formatNumber, truncate } from '../../lib/format';
import { Icon } from '../../components/icons';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { useToast } from '../../components/toastContext';
import {
  EmptyState,
  ErrorBanner,
  PageHeading,
  Pagination,
  Spinner,
  StatusBadge,
} from '../../components/ui';

const FILTERS: ReadonlyArray<{
  value: string;
  label: string;
  countOf: (counts: AccountCounts) => number;
}> = [
  { value: 'all', label: 'Semua', countOf: (counts) => counts.all },
  { value: 'draft', label: 'Draft', countOf: (counts) => counts.draft },
  { value: 'pending_review', label: 'Menunggu review', countOf: (counts) => counts.pending },
  {
    value: 'changes_requested',
    label: 'Perlu revisi',
    countOf: (counts) => counts.changes_requested ?? 0,
  },
  { value: 'rejected', label: 'Ditolak', countOf: (counts) => counts.rejected ?? 0 },
  { value: 'published', label: 'Terbit', countOf: (counts) => counts.publish },
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

function editorialFooterHint(state: EditorialState): string {
  switch (state) {
    case 'pending_review':
      return 'Sedang dalam antrean review Senator';
    case 'changes_requested':
      return 'Perbarui karya sesuai catatan lalu submit ulang';
    case 'rejected':
      return 'Karya tidak lolos kurasi Senator';
    case 'draft':
      return 'Draft belum di-submit untuk review';
    case 'published':
      return 'Terbit di situs Penalis';
  }
}

export function Articles({
  onEdit,
  onCreate,
}: {
  onEdit: (id: number, focus?: EditorFocus) => void;
  onCreate: () => void;
}) {
  const [status, setStatus] = useState('all');
  const [page, setPage] = useState(1);
  const [data, setData] = useState<ArticleList | null>(null);
  const [counts, setCounts] = useState<AccountCounts | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [busyId, setBusyId] = useState<number | null>(null);
  const [pending, setPending] = useState<PendingAction>(null);
  const showToast = useToast();

  const load = useCallback(async () => {
    setLoading(true);
    setError('');

    try {
      const result = await articlesApi.list(page, status);
      setData(result);
      setCounts(result.counts);
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

  const runEditorialSubmit = async (id: number, mode: 'submit' | 'resubmit') => {
    const isResubmit = mode === 'resubmit';
    setBusyId(id);
    setError('');
    try {
      const result = isResubmit ? await articlesApi.resubmit(id) : await articlesApi.submit(id);
      showToast(
        result.message ||
          (isResubmit
            ? 'Revisi karya tulis kamu telah di-submit ulang untuk di-review Senator.'
            : 'Karya tulis kamu telah di-submit dan sedang menunggu review Senator.'),
      );
      setPending(null);
      await load();
    } catch (caught) {
      setError(
        caught instanceof ApiError
          ? caught.message
          : isResubmit
            ? 'Revisi karya tulis tidak dapat di-submit ulang.'
            : 'Karya tulis tidak dapat di-submit untuk di-review.',
      );
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
    try {
      await articlesApi.remove(id);
      showToast('Karya tulis dihapus.');
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
      <PageHeading
        title="Daftar Karya Tulis"
        description="Lihat dan kelola daftar karya tulismu di sini. Karya yang sudah terbit bersifat read-only; hubungi Senator Penalis jika memerlukan perubahan."
        action={
          <button type="button" className="penalis-button penalis-button--primary" onClick={onCreate}>
            Buat Karya Baru
          </button>
        }
      />

      <div className="penalis-tabs" role="tablist" aria-label="Saring menurut status">
        {FILTERS.map((filter) => {
          const isActive = status === filter.value;
          const tabCount = counts ? filter.countOf(counts) : null;

          return (
            <button
              key={filter.value}
              type="button"
              role="tab"
              aria-selected={isActive}
              className={`penalis-tab ${isActive ? 'is-active' : ''}`}
              onClick={() => changeStatus(filter.value)}
            >
              <span>{filter.label}</span>
              {tabCount !== null ? (
                <span className="penalis-tab__count">{formatNumber(tabCount)}</span>
              ) : null}
            </button>
          );
        })}
      </div>

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
        <ul className="penalis-manuscript-list">
          {data?.items.map((article) => {
            const titleText = article.title || 'Tanpa judul';
            const showViews = article.editorialState === 'published' || article.views > 0;
            const editLabel =
              article.editorialState === 'changes_requested'
                ? 'Revisi'
                : article.canEdit
                  ? 'Ubah'
                  : 'Lihat detail';

            return (
              <li
                key={article.id}
                className={`penalis-manuscript-card penalis-manuscript-card--${article.editorialState}`}
              >
                <div className="penalis-manuscript-card__header">
                  <div className="penalis-manuscript-card__badges">
                    <StatusBadge
                      status={article.editorialState}
                      label={
                        article.editorialStateLabel ||
                        editorialStateLabel(article.editorialState)
                      }
                    />
                    {article.reviewCycle > 0 ? (
                      <span className="penalis-table__cycle-pill">
                        Cycle {article.reviewCycle}
                      </span>
                    ) : null}
                  </div>

                  <span className="penalis-table__date">
                    Diubah {formatDate(article.modified)}
                  </span>
                </div>

                <div className="penalis-manuscript-card__body">
                  <h3 className="penalis-manuscript-card__title">
                    <button
                      type="button"
                      className="penalis-table__title-button"
                      onClick={() => onEdit(article.id)}
                      disabled={busyId === article.id}
                    >
                      {titleText}
                    </button>
                  </h3>

                  {article.excerpt ? (
                    <p className="penalis-manuscript-card__excerpt penalis-muted">
                      {truncate(article.excerpt, 200)}
                    </p>
                  ) : null}

                  {article.feedback ? (
                    <button
                      type="button"
                      className={`penalis-article-feedback-note penalis-article-feedback-note--${article.editorialState}`}
                      onClick={() => onEdit(article.id, 'feedback')}
                      disabled={busyId === article.id}
                      title="Buka catatan review Senator di editor"
                    >
                      <span className="penalis-article-feedback-note__label">
                        Catatan Senator:
                      </span>
                      <span className="penalis-article-feedback-note__text">
                        {article.feedback}
                      </span>
                      <span className="penalis-article-feedback-note__arrow" aria-hidden="true">
                        <Icon name="chevronRight" />
                      </span>
                    </button>
                  ) : null}
                </div>

                <div className="penalis-manuscript-card__footer">
                  <div className="penalis-manuscript-card__metrics">
                    {showViews ? (
                      <span
                        className="penalis-article-views"
                        aria-label={`${formatNumber(article.views)} views`}
                      >
                        <Icon name="eye" />
                        <span>{formatNumber(article.views)} views</span>
                      </span>
                    ) : (
                      <span className="penalis-manuscript-card__hint">
                        {editorialFooterHint(article.editorialState)}
                      </span>
                    )}
                  </div>

                  <div className="penalis-manuscript-card__actions">
                    <button
                      type="button"
                      className={`penalis-button ${article.canEdit ? 'penalis-button--soft' : 'penalis-button--ghost'}`}
                      onClick={() => onEdit(article.id)}
                      disabled={busyId === article.id}
                      aria-label={`${editLabel} “${titleText}”`}
                    >
                      <Icon name={article.canEdit ? 'edit' : 'eye'} />
                      <span>{editLabel}</span>
                    </button>

                    {article.editorialState === 'published' && article.link ? (
                      <a
                        className="penalis-button penalis-button--ghost"
                        href={article.link}
                        target="_blank"
                        rel="noreferrer noopener"
                        aria-label="Lihat karya terbit"
                      >
                        <Icon name="externalLink" />
                        <span>Lihat karya terbit</span>
                      </a>
                    ) : null}

                    {article.canSubmit ? (
                      <button
                        type="button"
                        className="penalis-button penalis-button--primary"
                        onClick={() => setPending({ kind: 'submit', article })}
                        disabled={busyId === article.id}
                        aria-label={`Submit review “${titleText}”`}
                      >
                        <Icon name="send" />
                        <span>Submit review</span>
                      </button>
                    ) : null}

                    {article.canResubmit ? (
                      <button
                        type="button"
                        className="penalis-button penalis-button--primary"
                        onClick={() => setPending({ kind: 'resubmit', article })}
                        disabled={busyId === article.id}
                        aria-label={`Submit ulang “${titleText}”`}
                      >
                        <Icon name="send" />
                        <span>Submit ulang</span>
                      </button>
                    ) : null}

                    {article.editorialState !== 'published' &&
                    article.editorialState !== 'pending_review' ? (
                      <button
                        type="button"
                        className="penalis-button penalis-button--danger"
                        onClick={() => setPending({ kind: 'delete', article })}
                        disabled={busyId === article.id}
                        aria-label={`Hapus “${titleText}”`}
                      >
                        <Icon name="trash" />
                        <span>Hapus</span>
                      </button>
                    ) : null}
                  </div>
                </div>
              </li>
            );
          })}
        </ul>
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
          title="Submit karya tulis untuk di-review?"
          description={
            <>
              <p>
                <strong>{pending.article.title || 'Tanpa judul'}</strong> akan dikirim ke antrean review
                Senator Penalis.
              </p>
              <p className="penalis-muted">
                Selama proses review berlangsung, karya tulis dikunci dari perubahan sampai Senator
                menyetujui, meminta revisi, atau menolak.
              </p>
            </>
          }
          confirmLabel="Submit Review"
          tone="warning"
          busy={busyId === pending.article.id}
          onConfirm={() => void runEditorialSubmit(pending.article.id, 'submit')}
          onCancel={() => setPending(null)}
        />
      ) : null}

      {pending?.kind === 'resubmit' ? (
        <ConfirmDialog
          title="Submit ulang revisi karya tulis?"
          description={
            <>
              <p>
                Revisi untuk <strong>{pending.article.title || 'Tanpa judul'}</strong> akan dikirim kembali ke
                antrean review Senator Penalis.
              </p>
              <p className="penalis-muted">
                Pastikan kamu sudah memperbarui karya tulis sesuai catatan review Senator sebelum submit ulang.
              </p>
            </>
          }
          confirmLabel="Submit Ulang"
          tone="warning"
          busy={busyId === pending.article.id}
          onConfirm={() => void runEditorialSubmit(pending.article.id, 'resubmit')}
          onCancel={() => setPending(null)}
        />
      ) : null}
    </div>
  );
}
