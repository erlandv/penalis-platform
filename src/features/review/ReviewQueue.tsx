/**
 * Reviewer queue.
 *
 * Reviewers reach the dashboard to work through submissions without opening
 * wp-admin. They can read a submission in full — body content, thumbnail,
 * taxonomy, and review history — or decide directly from the queue. Every
 * decision goes through `POST /articles/{id}/decision`, which runs the same
 * workflow service as the wp-admin screen — including the per-article lock and
 * the stale-cycle guard — so this screen cannot approve something the admin
 * screen would refuse.
 */

import { useCallback, useEffect, useRef, useState } from 'react';

import { ApiError, articlesApi, mediaApi, reviewApi } from '../../lib/api';
import { editorialStateLabel, formatDateTime, formatNumber, truncate } from '../../lib/format';
import { buildPreviewDocument, countWords } from '../../lib/html';
import type { Account, Article, ReviewDecisionAction, ReviewQueueItem, ReviewQueueList } from '../../lib/types';
import { Dialog } from '../../components/Dialog';
import { Icon } from '../../components/icons';
import { useToast } from '../../components/toastContext';
import { EmptyState, ErrorBanner, PageHeading, Pagination, Panel, Spinner, StatusBadge } from '../../components/ui';
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

  const [readingItem, setReadingItem] = useState<ReviewQueueItem | null>(null);
  const [articleDetail, setArticleDetail] = useState<Article | null>(null);
  const [articleThumbnail, setArticleThumbnail] = useState('');
  const [detailLoading, setDetailLoading] = useState(false);
  const [detailError, setDetailError] = useState('');
  const detailRequestRef = useRef(0);

  const showToast = useToast();

  useEffect(() => {
    return () => {
      detailRequestRef.current += 1;
    };
  }, []);

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

  const closeReader = useCallback(() => {
    detailRequestRef.current += 1;
    setReadingItem(null);
    setArticleDetail(null);
    setArticleThumbnail('');
    setDetailError('');
    setDetailLoading(false);
  }, []);

  const openReader = useCallback(async (item: ReviewQueueItem) => {
    const requestId = detailRequestRef.current + 1;
    detailRequestRef.current = requestId;

    setReadingItem(item);
    setArticleDetail(null);
    setArticleThumbnail('');
    setDetailError('');
    setDetailLoading(true);

    try {
      const article = await articlesApi.get(item.id);
      if (detailRequestRef.current !== requestId) {
        return;
      }

      setArticleDetail(article);

      if (article.thumbnail) {
        setArticleThumbnail(article.thumbnail);
      } else if (article.featuredMedia > 0) {
        try {
          const media = await mediaApi.get(article.featuredMedia);
          if (detailRequestRef.current === requestId) {
            setArticleThumbnail(media.full || media.url || media.thumbnail);
          }
        } catch {
          // A missing thumbnail preview is not fatal while reading the article.
        }
      }
    } catch (caught) {
      if (detailRequestRef.current !== requestId) {
        return;
      }

      setDetailError(caught instanceof ApiError ? caught.message : 'Isi karya tulis tidak dapat dimuat.');
    } finally {
      if (detailRequestRef.current === requestId) {
        setDetailLoading(false);
      }
    }
  }, []);

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
      closeReader();
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

  const renderReader = (item: ReviewQueueItem) => {
    const activeTitle = (articleDetail?.title || item.title) || 'Tanpa judul';
    const activeState = articleDetail?.editorialState ?? item.editorialState;
    const activeStateLabel =
      articleDetail?.editorialStateLabel ||
      item.editorialStateLabel ||
      editorialStateLabel(activeState);
    const activeAuthorName = articleDetail?.authorName ?? item.authorName;
    const activeAuthorLogin = articleDetail?.authorLogin ?? item.authorLogin;
    const activeCycle = articleDetail?.reviewCycle || item.reviewCycle;
    const activeSubmittedAt = articleDetail?.submittedAt || item.submittedAt;
    const activeQueueItem: ReviewQueueItem = {
      ...item,
      title: articleDetail?.title ?? item.title,
      reviewCycle: activeCycle,
    };
    const wordCount = articleDetail ? countWords(articleDetail.content) : 0;
    const readerDocument = articleDetail ? buildPreviewDocument(articleDetail.content) : '';

    return (
      <div className="penalis-stack">
        <div className="penalis-review__reader-header">
          <button type="button" className="penalis-back-link" onClick={closeReader}>
            <Icon name="arrowLeft" />
            Kembali ke antrean review
          </button>

          <div className="penalis-editor-heading__title">
            <h2>{activeTitle}</h2>
            <StatusBadge status={activeState} label={activeStateLabel} />
          </div>

          <p className="penalis-review__meta">
            Oleh{' '}
            <strong>{activeAuthorName || activeAuthorLogin || 'Penulis tidak diketahui'}</strong>
            {activeAuthorName && activeAuthorLogin && activeAuthorName !== activeAuthorLogin
              ? ` (@${activeAuthorLogin})`
              : ''}
            <span aria-hidden="true"> · </span>
            Cycle {activeCycle}
            <span aria-hidden="true"> · </span>
            {activeSubmittedAt
              ? `Dikirim ${formatDateTime(activeSubmittedAt)}`
              : 'Waktu submit tidak tercatat'}
          </p>
        </div>

        {detailError ? (
          <ErrorBanner message={detailError} onRetry={() => void openReader(item)} />
        ) : null}

        {detailLoading ? <Spinner label="Memuat isi karya tulis…" /> : null}

        {articleDetail ? (
          <div className="penalis-editor penalis-review__workspace">
            <div className="penalis-editor__main">
              {articleDetail.feedback ? (
                <Panel
                  title="Catatan review terakhir"
                  aside={
                    articleDetail.reviewCycle > 0 ? (
                      <span className="penalis-editorial-feedback__cycle">
                        Cycle {articleDetail.reviewCycle}
                      </span>
                    ) : null
                  }
                  className="penalis-editorial-feedback"
                >
                  <p className="penalis-editorial-feedback__body">{articleDetail.feedback}</p>
                  {articleDetail.reviewedAt ? (
                    <p className="penalis-fineprint">
                      Di-review pada {formatDateTime(articleDetail.reviewedAt)}
                    </p>
                  ) : null}
                </Panel>
              ) : null}

              <Panel
                title="Isi Karya Tulis"
                aside={<span className="penalis-muted">{formatNumber(wordCount)} kata</span>}
              >
                <iframe
                  title={`Isi karya tulis: ${activeTitle}`}
                  className="penalis-review__reader-frame"
                  sandbox=""
                  srcDoc={readerDocument}
                />
              </Panel>
            </div>

            <aside className="penalis-editor__side">
              <Panel title="Keputusan Review">
                <p className="penalis-muted">
                  Menyetujui akan langsung menerbitkan karya tulis. Meminta revisi atau menolak wajib
                  disertai catatan untuk penulis.
                </p>
                <div className="penalis-review__decision-actions">
                  <button
                    type="button"
                    className="penalis-button penalis-button--primary"
                    onClick={() => openDecision(activeQueueItem, 'approve')}
                    disabled={busyId === activeQueueItem.id}
                  >
                    Setujui
                  </button>
                  <button
                    type="button"
                    className="penalis-button penalis-button--ghost"
                    onClick={() => openDecision(activeQueueItem, 'request_changes')}
                    disabled={busyId === activeQueueItem.id}
                  >
                    Minta revisi
                  </button>
                  <button
                    type="button"
                    className="penalis-button penalis-button--danger"
                    onClick={() => openDecision(activeQueueItem, 'reject')}
                    disabled={busyId === activeQueueItem.id}
                  >
                    Tolak
                  </button>
                </div>
              </Panel>

              <Panel title="Thumbnail">
                {articleThumbnail ? (
                  <div className="penalis-featured">
                    <img src={articleThumbnail} alt={`Thumbnail ${activeTitle}`} />
                  </div>
                ) : (
                  <p className="penalis-muted">Belum ada thumbnail yang dipilih.</p>
                )}
              </Panel>

              <Panel title="Kategori & Tag">
                <div className="penalis-review__taxonomy-group">
                  <span className="penalis-review__taxonomy-label">Kategori</span>
                  {articleDetail.categories.length > 0 ? (
                    <div className="penalis-tag-list">
                      {articleDetail.categories.map((term) => (
                        <span key={term.id} className="penalis-chip">
                          {term.name}
                        </span>
                      ))}
                    </div>
                  ) : (
                    <p className="penalis-muted">Belum ada kategori.</p>
                  )}
                </div>

                <div className="penalis-review__taxonomy-group">
                  <span className="penalis-review__taxonomy-label">Tag</span>
                  {articleDetail.tags.length > 0 ? (
                    <div className="penalis-tag-list">
                      {articleDetail.tags.map((term) => (
                        <span key={term.id} className="penalis-chip">
                          #{term.name}
                        </span>
                      ))}
                    </div>
                  ) : (
                    <p className="penalis-muted">Belum ada tag.</p>
                  )}
                </div>
              </Panel>

              <Panel title="Riwayat Review">
                {articleDetail.reviewHistory.length === 0 ? (
                  <p className="penalis-muted">Belum ada riwayat review untuk karya tulis ini.</p>
                ) : (
                  <ol className="penalis-review-history">
                    {articleDetail.reviewHistory.map((entry, index) => (
                      <li
                        key={`${entry.action}-${entry.reviewCycle}-${entry.createdAt}-${index}`}
                        className="penalis-review-history__item"
                      >
                        <div className="penalis-review-history__head">
                          <strong>{entry.actionLabel}</strong>
                          {entry.reviewCycle > 0 ? (
                            <span className="penalis-muted">Cycle {entry.reviewCycle}</span>
                          ) : null}
                        </div>
                        <p className="penalis-review-history__meta">
                          {entry.actorName}
                          {entry.createdAt ? ` · ${formatDateTime(entry.createdAt)}` : ''}
                        </p>
                        {entry.feedback ? (
                          <p className="penalis-review-history__feedback">{entry.feedback}</p>
                        ) : null}
                      </li>
                    ))}
                  </ol>
                )}
              </Panel>
            </aside>
          </div>
        ) : null}
      </div>
    );
  };

  return (
    <div className="penalis-review">
      {readingItem ? (
        renderReader(readingItem)
      ) : (
        <>
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
                    <h3 className="penalis-review__title">
                      <button
                        type="button"
                        className="penalis-review__title-button"
                        onClick={() => void openReader(item)}
                      >
                        {item.title || 'Tanpa judul'}
                      </button>
                    </h3>
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
                      className="penalis-button penalis-button--soft"
                      onClick={() => void openReader(item)}
                      disabled={busyId === item.id}
                    >
                      Baca karya
                    </button>
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
        </>
      )}

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
