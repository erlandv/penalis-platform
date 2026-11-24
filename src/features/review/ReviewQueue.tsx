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
 *
 * The open submission is owned by the caller (`readingId`), not by this
 * component, so `?view=review&id=<post_id>` is a real address: a notification
 * can deep-link to one manuscript, Back closes the reader, and a reload keeps
 * the same article on screen. That mirrors how the writer editor carries its
 * `id`.
 */

import { useCallback, useEffect, useRef, useState } from 'react';

import { ApiError, articlesApi, isStaleEditorialError, mediaApi, reviewApi } from '../../lib/api';
import { editorialStateLabel, formatDateTime, formatNumber, truncate } from '../../lib/format';
import { buildPreviewDocument, countWords } from '../../lib/html';
import type { Article, ReviewDecisionAction, ReviewQueueItem, ReviewQueueList } from '../../lib/types';
import { Dialog } from '../../components/Dialog';
import { Icon } from '../../components/icons';
import { useToast } from '../../components/toastContext';
import {
  EmptyState,
  ErrorBanner,
  Notice,
  PageHeading,
  Pagination,
  Panel,
  Spinner,
  StatusBadge,
} from '../../components/ui';
import { canSubmitDecision, decisionOption } from './decision';

export function ReviewQueue({
  readingId,
  onOpenReader,
  onCloseReader,
}: {
  /** Article open in the reader, or `undefined` for the queue list. */
  readingId?: number;
  onOpenReader: (id: number) => void;
  onCloseReader: () => void;
}) {
  const [page, setPage] = useState(1);
  const [data, setData] = useState<ReviewQueueList | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [busyId, setBusyId] = useState<number | null>(null);
  const [pending, setPending] = useState<{
    item: ReviewQueueItem;
    action: ReviewDecisionAction;
    /** Screen the dialog was opened from: `undefined` for the queue list. */
    readingId?: number;
  } | null>(null);
  const [feedback, setFeedback] = useState('');
  const [decisionError, setDecisionError] = useState('');
  const [decisionStale, setDecisionStale] = useState(false);
  const [refreshingStale, setRefreshingStale] = useState(false);

  const [articleDetail, setArticleDetail] = useState<Article | null>(null);
  const [articleThumbnail, setArticleThumbnail] = useState('');
  const [detailLoading, setDetailLoading] = useState(false);
  const [detailError, setDetailError] = useState('');
  /** Bumped by the reader's retry button to re-run the detail request. */
  const [detailReload, setDetailReload] = useState(0);
  const detailRequestRef = useRef(0);

  const rootRef = useRef<HTMLDivElement>(null);
  const previousReadingIdRef = useRef(readingId);

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
      const result = await reviewApi.queue(page);
      if (result.items.length === 0 && result.total > 0 && page > 1) {
        setPage(Math.min(page - 1, Math.max(1, result.totalPages)));
        return;
      }
      setData(result);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Antrean review tidak dapat dimuat.');
    } finally {
      setLoading(false);
    }
  }, [page]);

  useEffect(() => {
    void load();
  }, [load]);

  /*
   * Load the article the caller asked for.
   *
   * The request is keyed on `readingId` and guarded by a monotonic counter, so
   * a fast Back/Forward or two notification clicks cannot let an older response
   * overwrite the article now on screen.
   */
  useEffect(() => {
    if (!readingId) {
      detailRequestRef.current += 1;
      setArticleDetail(null);
      setArticleThumbnail('');
      setDetailError('');
      setDetailLoading(false);
      return;
    }

    const requestId = detailRequestRef.current + 1;
    detailRequestRef.current = requestId;

    setArticleDetail(null);
    setArticleThumbnail('');
    setDetailError('');
    setDetailLoading(true);

    void (async () => {
      try {
        const article = await articlesApi.get(readingId);
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
    })();
  }, [readingId, detailReload]);

  /*
   * A decision dialog belongs to the article it was opened for.
   *
   * The reader is URL state, so Back, Forward, or a notification for another
   * article can swap the article underneath an open dialog. Leaving it up
   * would show "Setujui: <article A>" over article B — or over the queue list —
   * and submitting it would decide the wrong article. Closing it here keeps the
   * dialog and the screen in agreement.
   */
  useEffect(() => {
    setPending(null);
    setFeedback('');
    setDecisionError('');
    setDecisionStale(false);
  }, [readingId]);

  /*
   * Opening or closing the reader is a screen change inside one view, so the
   * shell's view-change effect does not fire. Reset the scroll position and
   * move focus into the new screen here, mirroring what the shell does for a
   * view change: otherwise a Senator who scrolled the queue lands halfway down
   * the article, and a keyboard user keeps focus on a row that no longer
   * exists.
   *
   * The previous id is tracked in a ref so a deep link that mounts straight
   * into the reader does not steal focus from the browser's own navigation.
   */
  useEffect(() => {
    if (previousReadingIdRef.current === readingId) {
      return;
    }

    previousReadingIdRef.current = readingId;

    window.scrollTo({ top: 0, behavior: 'auto' });

    const root = rootRef.current;
    if (!root) {
      return;
    }

    // The reader heading only exists once the article has loaded; the back link
    // is always rendered, so it is the fallback that still moves focus off the
    // row that was clicked.
    const target =
      root.querySelector<HTMLElement>('h2') ??
      root.querySelector<HTMLElement>('.penalis-back-link') ??
      root;

    if (!target.hasAttribute('tabindex')) {
      target.setAttribute('tabindex', '-1');
    }

    target.focus({ preventScroll: true });
  }, [readingId]);

  const closeReader = useCallback(() => {
    detailRequestRef.current += 1;
    setArticleDetail(null);
    setArticleThumbnail('');
    setDetailError('');
    setDetailLoading(false);
    onCloseReader();
  }, [onCloseReader]);

  const openDecision = (item: ReviewQueueItem, action: ReviewDecisionAction) => {
    // Record the screen the dialog belongs to, so it can be hidden the moment
    // the reader switches to another article (or back to the queue).
    setPending({ item, action, readingId });
    setFeedback('');
    setDecisionError('');
    setDecisionStale(false);
  };

  const closeDecision = () => {
    if (busyId !== null || refreshingStale) {
      return;
    }

    setPending(null);
    setFeedback('');
    setDecisionError('');
    setDecisionStale(false);
  };

  const refreshStaleDecision = async () => {
    if (!pending || refreshingStale) {
      return;
    }

    setRefreshingStale(true);
    try {
      const freshReview = await articlesApi.review(pending.item.id);
      await load();

      if (freshReview.editorialState !== 'pending_review') {
        const currentLabel =
          freshReview.editorialStateLabel || editorialStateLabel(freshReview.editorialState);
        setPending(null);
        setFeedback('');
        setDecisionStale(false);
        setDecisionError('');
        closeReader();
        showToast(`Karya tulis sudah diproses (status: ${currentLabel}).`);
        return;
      }

      setPending((current) =>
        current
          ? {
              ...current,
              item: {
                ...current.item,
                reviewCycle: freshReview.reviewCycle,
                editorialState: freshReview.editorialState,
                editorialStateLabel: freshReview.editorialStateLabel,
              },
            }
          : null,
      );
      setArticleDetail((current) =>
        current
          ? {
              ...current,
              reviewCycle: freshReview.reviewCycle,
              editorialState: freshReview.editorialState,
              editorialStateLabel: freshReview.editorialStateLabel,
              feedback: freshReview.feedback,
              reviewedAt: freshReview.reviewedAt,
              reviewHistory: freshReview.reviewHistory,
            }
          : null,
      );
      setDecisionStale(false);
      setDecisionError('');
      showToast(`Status karya diperbarui ke Cycle ${freshReview.reviewCycle}. Silakan kirim keputusan.`);
    } catch (caught) {
      setDecisionError(
        caught instanceof ApiError
          ? caught.message
          : 'Status terbaru karya tulis tidak dapat dimuat.',
      );
    } finally {
      setRefreshingStale(false);
    }
  };

  const submitDecision = async () => {
    if (!pending) {
      return;
    }

    const { item, action } = pending;
    setBusyId(item.id);
    setDecisionError('');
    setDecisionStale(false);

    try {
      const result = await reviewApi.decide(item.id, action, feedback.trim(), item.reviewCycle);
      showToast(result.message);
      setPending(null);
      setFeedback('');
      setDecisionStale(false);
      closeReader();
      await load();
    } catch (caught) {
      // Keep the dialog open: a stale cycle or a lock is recoverable once the
      // reviewer sees the reason, and closing would throw away their notes.
      const stale = isStaleEditorialError(caught);
      setDecisionStale(stale);
      setDecisionError(
        caught instanceof ApiError
          ? caught.message
          : 'Keputusan tidak dapat disimpan. Silakan coba lagi.'
      );
      if (stale) {
        void load();
      }
    } finally {
      setBusyId(null);
    }
  };

  const option = pending ? decisionOption(pending.action) : null;

  /*
   * A dialog is only valid while the screen still shows the article it was
   * opened for. The reset effect above closes it on the next commit; this guard
   * closes it in the same render, so a dialog for article A can never paint
   * over article B for even one frame.
   */
  const decisionTargetMatches = pending !== null && pending.readingId === readingId;

  /*
   * The reader is opened by id, so the queue row may not be loaded (a
   * notification can point at a submission that has since left the queue).
   * Prefer the article payload, which is fetched fresh for this id, and fall
   * back to the queue row for the fields it also carries.
   */
  const readingItem = readingId
    ? data?.items.find((item) => item.id === readingId) ?? null
    : null;

  const renderReader = () => {
    const activeTitle = (articleDetail?.title || readingItem?.title) || 'Tanpa judul';
    const activeState = articleDetail?.editorialState ?? readingItem?.editorialState ?? '';
    const activeStateLabel =
      articleDetail?.editorialStateLabel ||
      readingItem?.editorialStateLabel ||
      (activeState ? editorialStateLabel(activeState) : '');
    const activeAuthorName = articleDetail?.authorName ?? readingItem?.authorName;
    const activeAuthorLogin = articleDetail?.authorLogin ?? readingItem?.authorLogin;
    const authorLabel = activeAuthorName || activeAuthorLogin || 'Penulis tidak diketahui';
    /*
     * `??`, not `||`: cycle 0 is a real value — a first submission that has
     * never been reviewed. `||` would skip it and fall back to the queue row,
     * which may be stale, and a decision sent with the wrong `expected_cycle`
     * is refused as stale.
     */
    const activeCycle = articleDetail?.reviewCycle ?? readingItem?.reviewCycle ?? 0;
    // Same reasoning as `activeCycle`: the detail payload is authoritative, so
    // an empty value there must not be replaced by a possibly stale queue row.
    const activeSubmittedAt = articleDetail?.submittedAt ?? readingItem?.submittedAt;
    const wordCount = articleDetail ? countWords(articleDetail.content) : 0;
    const readerDocument = articleDetail ? buildPreviewDocument(articleDetail.content) : '';

    /*
     * A notification can outlive the round it announced: another reviewer may
     * have already approved, rejected, or sent the article back. The reader
     * still shows the manuscript and its history, but the decision buttons are
     * withheld — offering them would only produce a stale-cycle 409.
     */
    const canDecide = activeState === 'pending_review';
    const activeQueueItem: ReviewQueueItem = {
      id: readingId ?? 0,
      title: activeTitle,
      excerpt: articleDetail?.excerpt ?? readingItem?.excerpt ?? '',
      authorName: activeAuthorName ?? '',
      authorLogin: activeAuthorLogin ?? '',
      submittedAt: activeSubmittedAt ?? '',
      reviewCycle: activeCycle,
      editorialState: activeState || 'pending_review',
      editorialStateLabel: activeStateLabel,
    };

    /*
     * A deep link can arrive before either source has data (the queue page may
     * not contain this article, and the detail request is still in flight).
     * Show the way back and the loading state instead of a header claiming
     * "Tanpa judul · Menunggu review".
     */
    const hasIdentity = Boolean(articleDetail || readingItem);

    return (
      <>
        <div className="penalis-review__reader-header">
          <button type="button" className="penalis-back-link" onClick={closeReader}>
            <Icon name="arrowLeft" />
            Kembali ke antrean review
          </button>

          {hasIdentity ? (
            <>
              <div className="penalis-editor-heading__title">
                <h2>{activeTitle}</h2>
                {activeStateLabel ? (
                  <StatusBadge status={activeState} label={activeStateLabel} />
                ) : null}
              </div>

              <p className="penalis-review__meta">
                Oleh{' '}
                {articleDetail?.authorUrl ? (
                  <a
                    className="penalis-review__author-link"
                    href={articleDetail.authorUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                  >
                    {authorLabel}
                  </a>
                ) : (
                  <strong>{authorLabel}</strong>
                )}
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
            </>
          ) : null}
        </div>

        {detailError ? (
          <ErrorBanner message={detailError} onRetry={() => setDetailReload((current) => current + 1)} />
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
                {canDecide ? (
                  <>
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
                  </>
                ) : (
                  <Notice tone="info">
                    Karya tulis ini tidak sedang menunggu keputusan, jadi tombol keputusan tidak
                    ditampilkan. Riwayat review, jika ada, tercatat di panel Riwayat Review.
                  </Notice>
                )}
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
      </>
    );
  };

  return (
    <div className="penalis-review penalis-stack" ref={rootRef}>
      {readingId ? (
        renderReader()
      ) : (
        <>
          <PageHeading
            title="Review Karya Tulis"
            description="Daftar karya tulis yang menunggu keputusan. Setiap keputusan tercatat di riwayat karya."
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
                    <div className="penalis-review__item-head">
                      <span className="penalis-review__cycle-pill">Cycle {item.reviewCycle}</span>
                      <span className="penalis-review__submitted">
                        {item.submittedAt
                          ? `Dikirim ${formatDateTime(item.submittedAt)}`
                          : 'Waktu submit tidak tercatat'}
                      </span>
                    </div>

                    <h3 className="penalis-review__title">
                      <button
                        type="button"
                        className="penalis-review__title-button"
                        onClick={() => onOpenReader(item.id)}
                      >
                        {item.title || 'Tanpa judul'}
                      </button>
                    </h3>

                    {item.excerpt ? (
                      <p className="penalis-review__excerpt">{truncate(item.excerpt, 160)}</p>
                    ) : null}

                    <p className="penalis-review__meta">
                      <span>Penulis: </span>
                      <strong>{item.authorName || item.authorLogin}</strong>
                      {item.authorName && item.authorLogin && item.authorName !== item.authorLogin ? (
                        <span> (@{item.authorLogin})</span>
                      ) : null}
                    </p>
                  </div>

                  <div className="penalis-review__actions">
                    <button
                      type="button"
                      className="penalis-button penalis-button--soft"
                      onClick={() => onOpenReader(item.id)}
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

      {pending && option && decisionTargetMatches ? (
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

              {decisionError ? (
                <ErrorBanner
                  message={decisionError}
                  onRetry={decisionStale ? () => void refreshStaleDecision() : undefined}
                />
              ) : null}

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
                  placeholder="Tulis catatan review atau feedback perbaikan untuk penulis..."
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
                disabled={busyId !== null || refreshingStale}
              >
                Batal
              </button>
              <button
                type="button"
                className={`penalis-button penalis-button--${option.value === 'reject' ? 'danger-solid' : 'primary'}`}
                onClick={() => void submitDecision()}
                disabled={busyId !== null || refreshingStale || !canSubmitDecision(pending.action, feedback)}
              >
                {busyId !== null ? 'Menyimpan…' : refreshingStale ? 'Memperbarui…' : option.label}
              </button>
            </div>
          </div>
        </Dialog>
      ) : null}
    </div>
  );
}
