/**
 * Reviewer queue.
 *
 * Reviewers reach the dashboard to work through submissions without opening
 * wp-admin. Every decision goes through `POST /articles/{id}/decision`, which
 * runs the same workflow service as the wp-admin screen — including the
 * per-article lock and the stale-cycle guard — so this screen cannot approve
 * something the admin screen would refuse.
 */

import { useCallback, useEffect, useState } from 'react';

import { ApiError, reviewApi } from '../../lib/api';
import { formatDateTime, truncate } from '../../lib/format';
import type { Account, ReviewDecisionAction, ReviewQueueItem, ReviewQueueList } from '../../lib/types';
import { Dialog } from '../../components/Dialog';
import { useToast } from '../../components/toastContext';
import { EmptyState, ErrorBanner, PageHeading, Pagination, Spinner } from '../../components/ui';
import { canSubmitDecision, decisionOption } from './decision';

export function ReviewQueue({ account }: { account: Account }) {
  const [page, setPage] = useState(1);
  const [data, setData] = useState<ReviewQueueList | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [busyId, setBusyId] = useState<number | null>(null);
  const [pending, setPending] = useState<{ item: ReviewQueueItem; action: ReviewDecisionAction } | null>(null);
  const [feedback, setFeedback] = useState('');
  const [decisionError, setDecisionError] = useState('');
  const showToast = useToast();

  const load = useCallback(async () => {
    setLoading(true);
    setError('');

    try {
      setData(await reviewApi.queue(page));
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Antrean review tidak dapat dimuat.');
    } finally {
      setLoading(false);
    }
  }, [page]);

  useEffect(() => {
    void load();
  }, [load]);

  const openDecision = (item: ReviewQueueItem, action: ReviewDecisionAction) => {
    setPending({ item, action });
    setFeedback('');
    setDecisionError('');
  };

  const closeDecision = () => {
    if (busyId !== null) {
      return;
    }

    setPending(null);
    setFeedback('');
    setDecisionError('');
  };

  const submitDecision = async () => {
    if (!pending) {
      return;
    }

    const { item, action } = pending;
    setBusyId(item.id);
    setDecisionError('');

    try {
      const result = await reviewApi.decide(item.id, action, feedback.trim(), item.reviewCycle);
      showToast(result.message);
      setPending(null);
      setFeedback('');
      await load();
    } catch (caught) {
      // Keep the dialog open: a stale cycle or a lock is recoverable once the
      // reviewer sees the reason, and closing would throw away their notes.
      setDecisionError(
        caught instanceof ApiError
          ? caught.message
          : 'Keputusan tidak dapat disimpan. Silakan coba lagi.'
      );
    } finally {
      setBusyId(null);
    }
  };

  const option = pending ? decisionOption(pending.action) : null;

  return (
    <div className="penalis-review">
      <PageHeading
        title="Review Karya Tulis"
        description={
          account.canWrite
            ? 'Karya tulis yang menunggu keputusan. Setiap keputusan tercatat di riwayat karya.'
            : 'Karya tulis yang menunggu keputusan dari reviewer.'
        }
      />

      {error ? <ErrorBanner message={error} onRetry={() => void load()} /> : null}

      {loading ? (
        <Spinner label="Memuat antrean review…" />
      ) : data && data.items.length === 0 ? (
        <EmptyState
          title="Antrean bersih"
          description="Tidak ada karya tulis yang menunggu review saat ini. Antrean akan terisi saat penulis mengirim karya."
        />
      ) : (
        <ul className="penalis-review__list">
          {data?.items.map((item) => (
            <li key={item.id} className="penalis-review__item">
              <div className="penalis-review__main">
                <h3 className="penalis-review__title">{item.title || 'Tanpa judul'}</h3>
                {item.excerpt ? <p className="penalis-muted">{truncate(item.excerpt, 160)}</p> : null}
                <p className="penalis-review__meta">
                  <strong>{item.authorName || item.authorLogin}</strong>
                  <span aria-hidden="true"> · </span>
                  Cycle {item.reviewCycle}
                  <span aria-hidden="true"> · </span>
                  {item.submittedAt ? `Dikirim ${formatDateTime(item.submittedAt)}` : 'Waktu submit tidak tercatat'}
                </p>
              </div>

              <div className="penalis-review__actions">
                <button
                  type="button"
                  className="penalis-button penalis-button--primary"
                  onClick={() => openDecision(item, 'approve')}
                  disabled={busyId === item.id}
                >
                  Setujui
                </button>
                <button
                  type="button"
                  className="penalis-button penalis-button--ghost"
                  onClick={() => openDecision(item, 'request_changes')}
                  disabled={busyId === item.id}
                >
                  Minta revisi
                </button>
                <button
                  type="button"
                  className="penalis-button penalis-button--danger"
                  onClick={() => openDecision(item, 'reject')}
                  disabled={busyId === item.id}
                >
                  Tolak
                </button>
              </div>
            </li>
          ))}
        </ul>
      )}

      {data ? <Pagination page={data.page} totalPages={data.totalPages} onChange={setPage} /> : null}

      {pending && option ? (
        <Dialog label={`${option.label}: ${pending.item.title || 'Tanpa judul'}`} onClose={closeDecision}>
          <div className="penalis-modal__content penalis-modal__content--confirm">
            <div className="penalis-modal__header">
              <h3>{option.label}</h3>
            </div>

            <div className="penalis-confirm__body">
              <p className="penalis-muted">{option.description}</p>

              <p className="penalis-review__dialog-subject">
                <strong>{pending.item.title || 'Tanpa judul'}</strong>
                <span className="penalis-muted"> oleh {pending.item.authorName || pending.item.authorLogin}</span>
              </p>

              {decisionError ? <ErrorBanner message={decisionError} /> : null}

              <label className="penalis-field" htmlFor="review-feedback">
                <span>
                  Catatan untuk penulis {option.requiresFeedback ? '(wajib)' : '(opsional)'}
                </span>
                <textarea
                  id="review-feedback"
                  rows={5}
                  value={feedback}
                  onChange={(event) => setFeedback(event.target.value)}
                  aria-describedby="review-feedback-hint"
                  required={option.requiresFeedback}
                />
                <span className="penalis-field-hint" id="review-feedback-hint">
                  {option.requiresFeedback
                    ? 'Catatan ini wajib diisi dan akan dikirim ke penulis bersama email pemberitahuan.'
                    : 'Boleh dikosongkan. Catatan tetap terlihat oleh penulis di dashboard.'}
                </span>
              </label>
            </div>

            <div className="penalis-confirm__actions">
              <button
                type="button"
                className="penalis-button penalis-button--ghost"
                onClick={closeDecision}
                disabled={busyId !== null}
              >
                Batal
              </button>
              <button
                type="button"
                className={`penalis-button penalis-button--${option.value === 'reject' ? 'danger-solid' : 'primary'}`}
                onClick={() => void submitDecision()}
                disabled={busyId !== null || !canSubmitDecision(pending.action, feedback)}
              >
                {busyId !== null ? 'Menyimpan…' : option.label}
              </button>
            </div>
          </div>
        </Dialog>
      ) : null}
    </div>
  );
}
