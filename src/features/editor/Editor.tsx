/**
 * Article editor.
 *
 * Uses the native WordPress posts endpoint for persistence. The author is
 * always derived server-side. Content is stored as WordPress post content.
 */

import { lazy, Suspense, useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from 'react';

import { ApiError, articlesApi, mediaApi, taxonomyApi } from '../../lib/api';
import { editorialStateLabel as formatEditorialStateLabel, formatDate } from '../../lib/format';
import { trimTrailingEmptyParagraphs } from '../../lib/html';
import type {
  Article,
  ArticleReview,
  EditorialState,
  FieldErrors,
  MediaItem,
  ReviewHistoryEntry,
  Term,
} from '../../lib/types';
import { hasErrors, validateArticle } from '../../lib/validation';
import { ErrorBanner, FieldError, Notice, Spinner, StatusBadge } from '../../components/ui';
import { ChunkErrorBoundary } from '../../components/ChunkErrorBoundary';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { Dialog } from '../../components/Dialog';
import { ImageDropzone } from '../../components/ImageDropzone';
import { MediaSelection } from '../../components/MediaSelection';
import { Icon } from '../../components/icons';
import type { RichTextEditorHandle } from './RichTextEditor';

/**
 * The editor pulls in TipTap and ProseMirror (~135 kB gzip). Loading it on
 * demand keeps the rest of the dashboard light, because only writers who open
 * the editor ever download it.
 */
const RichTextEditor = lazy(() =>
  import('./RichTextEditor').then((module) => ({ default: module.RichTextEditor })),
);

/**
 * What the media picker should do with the chosen image.
 *
 * `featured` sets the post thumbnail; `insert` drops the image into the article
 * at the caret. The dialog itself is identical for both — only the confirm
 * action differs.
 */
type MediaIntent = 'featured' | 'insert';

/** The parts of the editor state that end up in the saved post. */
type SnapshotInput = {
  title: string;
  content: string;
  status: string;
  categories: number[];
  tags: number[];
  featuredMedia: number;
};

/**
 * Serialise the editable state for the dirty check.
 *
 * Content is trimmed the same way `save()` trims it, so the empty trailing
 * paragraph TipTap keeps for the caret does not count as a change. Category and
 * tag order is normalised because the server does not preserve it.
 */
function snapshotOf(state: SnapshotInput): string {
  return JSON.stringify({
    title: state.title.trim(),
    content: trimTrailingEmptyParagraphs(state.content),
    status: state.status,
    categories: [...state.categories].sort((a, b) => a - b),
    tags: [...state.tags].sort((a, b) => a - b),
    featuredMedia: state.featuredMedia,
  });
}

/** Snapshot of a blank editor, so a new article does not start out "dirty". */
const EMPTY_SNAPSHOT = snapshotOf({
  title: '',
  content: '',
  status: 'draft',
  categories: [],
  tags: [],
  featuredMedia: 0,
});

export function Editor({
  articleId,
  onSaved,
  onCancel,
}: {
  articleId?: number;
  onSaved: (id: number) => void;
  onCancel: () => void;
}) {
  const [loading, setLoading] = useState(Boolean(articleId));
  const [loadError, setLoadError] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [errors, setErrors] = useState<FieldErrors>({});

  const [id, setId] = useState<number | undefined>(articleId);
  const [title, setTitle] = useState('');
  const [content, setContent] = useState('');
  const [status, setStatus] = useState('draft');
  const [editorialState, setEditorialState] = useState<EditorialState>('draft');
  const [editorialStateLabel, setEditorialStateLabel] = useState('Draf');
  const [canEdit, setCanEdit] = useState(true);
  const [canSubmit, setCanSubmit] = useState(true);
  const [canResubmit, setCanResubmit] = useState(false);
  const [feedback, setFeedback] = useState('');
  const [reviewCycle, setReviewCycle] = useState(0);
  const [submittedAt, setSubmittedAt] = useState('');
  const [reviewedAt, setReviewedAt] = useState('');
  const [reviewHistory, setReviewHistory] = useState<ReviewHistoryEntry[]>([]);
  const [link, setLink] = useState('');
  const [staleReview, setStaleReview] = useState(false);

  const [categories, setCategories] = useState<Term[]>([]);
  const [selectedCategories, setSelectedCategories] = useState<number[]>([]);
  const [selectedTags, setSelectedTags] = useState<Term[]>([]);
  const [tagSearch, setTagSearch] = useState('');
  const [tagSuggestions, setTagSuggestions] = useState<Term[]>([]);
  const [featuredMedia, setFeaturedMedia] = useState(0);
  const [featuredPreview, setFeaturedPreview] = useState('');
  const [media, setMedia] = useState<MediaItem[]>([]);
  /**
   * Why the media picker is open, or `null` when it is closed.
   *
   * The same dialog serves two purposes — choosing the featured image and
   * inserting an image into the article — so the caller states its intent when
   * opening it. The dialog then shows one "Pilih" button whose effect depends
   * on this value, instead of two buttons per card that forced the writer to
   * decide which one to press on every thumbnail.
   */
  const [mediaIntent, setMediaIntent] = useState<MediaIntent | null>(null);
  const [selectedMediaId, setSelectedMediaId] = useState<number | null>(null);
  const [mediaLoading, setMediaLoading] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [uploadError, setUploadError] = useState('');
  const [previewOpen, setPreviewOpen] = useState(false);

  /**
   * Submitting an article locks it while administrators review it, so the
   * writer confirms before sending the draft or revision into the queue.
   */
  const [submitConfirmOpen, setSubmitConfirmOpen] = useState(false);

  /**
   * Snapshot of the last persisted state, used to tell whether the writer has
   * unsaved changes. Without it the save button is always enabled and leaving
   * the editor silently discards work.
   */
  const [savedSnapshot, setSavedSnapshot] = useState(EMPTY_SNAPSHOT);
  const [savedAt, setSavedAt] = useState<Date | null>(null);

  const editorRef = useRef<RichTextEditorHandle | null>(null);

  const currentSnapshot = useMemo(
    () =>
      snapshotOf({
        title,
        content,
        status,
        categories: selectedCategories,
        tags: selectedTags.map((term) => term.id),
        featuredMedia,
      }),
    [title, content, status, selectedCategories, selectedTags, featuredMedia],
  );

  const isDirty = canEdit && currentSnapshot !== savedSnapshot;

  const applyReviewState = useCallback((review: ArticleReview) => {
    setStatus(review.status);
    setEditorialState(review.editorialState);
    setEditorialStateLabel(review.editorialStateLabel || formatEditorialStateLabel(review.editorialState));
    setCanEdit(review.canEdit);
    setCanSubmit(review.canSubmit);
    setCanResubmit(review.canResubmit);
    setFeedback(review.feedback ?? '');
    setReviewCycle(review.reviewCycle ?? 0);
    setSubmittedAt(review.submittedAt ?? '');
    setReviewedAt(review.reviewedAt ?? '');
    setReviewHistory(review.reviewHistory ?? []);
    setStaleReview(false);
  }, []);

  const load = useCallback(async () => {
    if (!articleId) {
      return;
    }

    setLoading(true);
    setLoadError('');
    setStaleReview(false);

    try {
      const article: Article = await articlesApi.get(articleId);
      setId(article.id);
      setTitle(article.title);
      setContent(article.content);
      setStatus(article.status);
      setEditorialState(article.editorialState);
      setEditorialStateLabel(article.editorialStateLabel || formatEditorialStateLabel(article.editorialState));
      setCanEdit(article.canEdit);
      setCanSubmit(article.canSubmit);
      setCanResubmit(article.canResubmit);
      setFeedback(article.feedback ?? '');
      setReviewCycle(article.reviewCycle ?? 0);
      setSubmittedAt(article.submittedAt ?? '');
      setReviewedAt(article.reviewedAt ?? '');
      setReviewHistory(article.reviewHistory ?? []);
      setLink(article.link ?? '');
      setSelectedCategories(article.categories.map((term) => term.id));
      setSelectedTags(article.tags);

      if (article.featuredMedia > 0) {
        setFeaturedMedia(article.featuredMedia);
        try {
          const mediaItem = await mediaApi.get(article.featuredMedia);
          setFeaturedPreview(mediaItem.thumbnail || mediaItem.url);
        } catch {
          // A missing preview is not fatal; the ID is still preserved.
        }
      } else {
        setFeaturedMedia(0);
        setFeaturedPreview('');
      }

      // Baseline for the dirty check: the article as it exists on the server.
      setSavedSnapshot(
        snapshotOf({
          title: article.title,
          content: article.content,
          status: article.status,
          categories: article.categories.map((term) => term.id),
          tags: article.tags.map((term) => term.id),
          featuredMedia: article.featuredMedia,
        }),
      );
    } catch (caught) {
      setLoadError(caught instanceof ApiError ? caught.message : 'Artikel ini tidak dapat dimuat.');
    } finally {
      setLoading(false);
    }
  }, [articleId]);

  const refreshReviewState = useCallback(async () => {
    if (!id) {
      return;
    }

    setSaving(true);
    setError('');
    setNotice('');

    try {
      const review = await articlesApi.review(id);
      applyReviewState(review);
      setNotice('Status tinjauan terbaru berhasil dimuat.');
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Status tinjauan terbaru tidak dapat dimuat.');
    } finally {
      setSaving(false);
    }
  }, [id, applyReviewState]);

  useEffect(() => {
    void load();
  }, [load]);

  useEffect(() => {
    let cancelled = false;

    taxonomyApi
      .categories()
      .then((result) => {
        if (!cancelled) {
          setCategories(result);
        }
      })
      .catch(() => {
        if (!cancelled) {
          setCategories([]);
        }
      });

    return () => {
      cancelled = true;
    };
  }, []);

  useEffect(() => {
    const handle = window.setTimeout(() => {
      if (tagSearch.trim().length < 2) {
        setTagSuggestions([]);
        return;
      }

      taxonomyApi
        .tags(tagSearch.trim())
        .then(setTagSuggestions)
        .catch(() => setTagSuggestions([]));
    }, 250);

    return () => window.clearTimeout(handle);
  }, [tagSearch]);

  /**
   * Stable close handler: `Dialog` re-runs its focus setup whenever `onClose`
   * changes identity, so an inline arrow would move focus on every render.
   */
  const closeMedia = useCallback(() => {
    setMediaIntent(null);
    setSelectedMediaId(null);
  }, []);
  const closePreview = useCallback(() => setPreviewOpen(false), []);
  const closeSubmitConfirm = useCallback(() => setSubmitConfirmOpen(false), []);

  /**
   * Open the media picker for a specific purpose.
   *
   * The intent is captured here rather than inferred later, so the confirm
   * button always knows what to do even if the writer uploads a new image or
   * switches selection while the dialog is open.
   */
  const openMediaPicker = async (intent: MediaIntent) => {
    setMediaIntent(intent);
    setSelectedMediaId(null);
    setMediaLoading(true);
    try {
      const list = await mediaApi.list(1);
      setMedia(list.items);
    } catch {
      setMedia([]);
    } finally {
      setMediaLoading(false);
    }
  };

  const uploadImage = async (file: File) => {
    setUploading(true);
    setUploadError('');

    try {
      const uploaded = await mediaApi.upload(file);
      setMedia((current) => [uploaded, ...current]);
    } catch (caught) {
      setUploadError(caught instanceof ApiError ? caught.message : 'Gambar tidak dapat diunggah.');
    } finally {
      setUploading(false);
    }
  };

  /**
   * Apply the selected image according to why the picker was opened.
   *
   * One handler for both flows keeps the dialog's confirm button identical; the
   * only difference is what happens to the chosen item.
   */
  const confirmMediaSelection = () => {
    const item = media.find((entry) => entry.id === selectedMediaId);
    if (!item) {
      return;
    }

    if (mediaIntent === 'featured') {
      setFeaturedMedia(item.id);
      setFeaturedPreview(item.thumbnail || item.url);
    } else if (mediaIntent === 'insert') {
      // The rich text editor owns the caret, so insertion happens through its
      // imperative handle rather than by string-splicing the stored HTML.
      editorRef.current?.insertImage(item.url, item.title);
    }

    closeMedia();
  };

  const addTag = (term: Term) => {
    setSelectedTags((current) => {
      if (current.some((item) => item.id === term.id)) {
        return current;
      }
      return [...current, term];
    });
    setTagSearch('');
    setTagSuggestions([]);
  };

  const createTag = async () => {
    const name = tagSearch.trim();
    if (!name) {
      return;
    }

    try {
      const created = await taxonomyApi.createTag(name);
      addTag({ id: created.id, name: created.name });
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Tag tidak dapat dibuat.');
    }
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

  const saveDraft = async () => {
    if (!canEdit) {
      return;
    }

    setError('');
    setNotice('');
    setStaleReview(false);

    // TipTap keeps an empty trailing paragraph after block nodes so the writer
    // can keep typing. Drop it from what is sent to WordPress.
    const payloadContent = trimTrailingEmptyParagraphs(content);

    const clientErrors = validateArticle({ title, content: payloadContent });
    if (hasErrors(clientErrors)) {
      setErrors(clientErrors);
      setSubmitConfirmOpen(false);
      return;
    }
    setErrors({});
    setSaving(true);

    try {
      const result = await articlesApi.save({
        id,
        title: title.trim(),
        content: payloadContent,
        status: 'draft',
        categories: selectedCategories,
        tags: selectedTags.map((term) => term.id),
        featured_media: featuredMedia,
      });

      setId(result.id);
      setStatus(result.status);
      setNotice('Draf karya tulis telah disimpan.');
      // The server may normalise the payload (kses filtering, slug, status),
      // so the baseline is rebuilt from what was actually sent plus the status
      // the server reported back.
      setSavedSnapshot(
        snapshotOf({
          title,
          content: payloadContent,
          status: result.status,
          categories: selectedCategories,
          tags: selectedTags.map((term) => term.id),
          featuredMedia,
        }),
      );
      setSavedAt(new Date());
      onSaved(result.id);
    } catch (caught) {
      if (isStaleEditorialError(caught)) {
        setStaleReview(true);
      }
      if (caught instanceof ApiError) {
        setErrors(caught.fields);
        setError(caught.message);
      } else {
        setError('Draf karya tulis tidak dapat disimpan.');
      }
    } finally {
      setSaving(false);
    }
  };

  const submitForReview = async () => {
    if (!canEdit || (!canSubmit && !canResubmit)) {
      return;
    }

    setError('');
    setNotice('');
    setStaleReview(false);

    const payloadContent = trimTrailingEmptyParagraphs(content);
    const clientErrors = validateArticle({ title, content: payloadContent });
    if (hasErrors(clientErrors)) {
      setErrors(clientErrors);
      setSubmitConfirmOpen(false);
      return;
    }
    setErrors({});
    setSaving(true);

    try {
      let targetId = id;

      // Persist any unsaved draft edits before handing the article to the
      // editorial workflow service.
      if (!targetId || isDirty) {
        const saved = await articlesApi.save({
          id: targetId,
          title: title.trim(),
          content: payloadContent,
          status: 'draft',
          categories: selectedCategories,
          tags: selectedTags.map((term) => term.id),
          featured_media: featuredMedia,
        });
        targetId = saved.id;
        setId(saved.id);
      }

      const review = canResubmit
        ? await articlesApi.resubmit(targetId)
        : await articlesApi.submit(targetId);

      applyReviewState(review);
      setSavedSnapshot(
        snapshotOf({
          title,
          content: payloadContent,
          status: review.status,
          categories: selectedCategories,
          tags: selectedTags.map((term) => term.id),
          featuredMedia,
        }),
      );
      setSavedAt(new Date());
      setNotice(
        review.message ||
        (canResubmit
          ? 'Revisi karya tulis kamu telah diajukan ulang untuk ditinjau admin.'
          : 'Karya tulis kamu telah diajukan dan sedang menunggu tinjauan admin.'),
      );
      setSubmitConfirmOpen(false);
      onSaved(targetId);
    } catch (caught) {
      if (isStaleEditorialError(caught)) {
        setStaleReview(true);
      }
      if (caught instanceof ApiError) {
        setErrors(caught.fields);
        setError(caught.message);
      } else {
        setError('Karya tulis tidak dapat diajukan untuk ditinjau.');
      }
      setSubmitConfirmOpen(false);
    } finally {
      setSaving(false);
    }
  };

  const submit = (event: FormEvent) => {
    event.preventDefault();
    void saveDraft();
  };

  /**
   * Leave the editor, asking first when there is unsaved work.
   *
   * "Batal" used to navigate away immediately, so a mis-click next to
   * "Pratinjau" threw away everything typed since the last save with no way
   * back. The confirmation is the browser's own dialog: it is the only prompt
   * that also covers closing the tab, and it needs no extra UI.
   */
  const leave = useCallback(() => {
    if (isDirty && !window.confirm('Ada perubahan yang belum disimpan. Tinggalkan halaman ini?')) {
      return;
    }
    onCancel();
  }, [isDirty, onCancel]);

  /**
   * Warn on tab close / reload while there are unsaved changes.
   *
   * `beforeunload` cannot show custom text, but the browser's generic prompt is
   * enough to stop an accidental reload from discarding a draft.
   */
  useEffect(() => {
    if (!isDirty) {
      return;
    }

    const onBeforeUnload = (event: BeforeUnloadEvent) => {
      event.preventDefault();
      // Older browsers require a truthy returnValue to show the prompt.
      event.returnValue = '';
    };

    window.addEventListener('beforeunload', onBeforeUnload);
    return () => window.removeEventListener('beforeunload', onBeforeUnload);
  }, [isDirty]);

  const previewDocument = useMemo(() => {
    return `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>
      body{font-family:Georgia,serif;line-height:1.7;color:#1f2933;max-width:720px;margin:0 auto;padding:2rem;background:#fff}
      h1{font-size:2rem;line-height:1.2;margin-bottom:1rem}
      img{max-width:100%;height:auto}
      a{color:#7c3aed}
      blockquote{border-left:4px solid #ddd;margin-left:0;padding-left:1rem;color:#52606d}
    </style></head><body><h1>${escapeHtml(title || 'Tanpa judul')}</h1>${content}</body></html>`;
  }, [content, title]);

  if (loading) {
    return <Spinner label="Memuat artikel…" />;
  }

  if (loadError) {
    return (
      <div className="penalis-stack">
        <ErrorBanner message={loadError} onRetry={() => void load()} />
        <button type="button" className="penalis-back-link" onClick={onCancel}>
          <Icon name="arrowLeft" />
          Kembali ke karya tulis
        </button>
      </div>
    );
  }

  return (
    <form className="penalis-stack" onSubmit={submit} noValidate>
      {/*
        The heading carries only the title and status. Actions live in their own
        bar below it, so their position no longer depends on how long the
        description happens to be — previously a long subtitle pushed the
        buttons onto a second line while a short one kept them beside the title.
      */}
      <div className="penalis-editor-heading">
        <button type="button" className="penalis-back-link" onClick={leave}>
          <Icon name="arrowLeft" />
          Kembali ke daftar karya
        </button>
        <div className="penalis-editor-heading__title">
          <h2>{!id ? 'Buat Karya Tulis' : canEdit ? 'Edit Karya Tulis' : 'Detail Karya Tulis'}</h2>
          <StatusBadge
            status={editorialState}
            label={editorialStateLabel || formatEditorialStateLabel(editorialState)}
          />
        </div>
      </div>

      {/*
        Sticky so the primary actions stay reachable while writing a long
        article; the save state sits on the left of the same bar.
      */}
      <div className="penalis-action-bar">
        <p className="penalis-save-state" role="status" aria-live="polite">
          {saving ? (
            <>
              <span className="penalis-spinner" aria-hidden="true" />
              Memproses…
            </>
          ) : !canEdit ? (
            `Read-only mode (${editorialStateLabel || formatEditorialStateLabel(editorialState)})`
          ) : isDirty ? (
            'Belum disimpan'
          ) : savedAt ? (
            `Tersimpan ${savedAt.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}`
          ) : (
            'Belum ada perubahan'
          )}
        </p>

        <div className="penalis-action-bar__actions">
          <button type="button" className="penalis-button penalis-button--ghost" onClick={() => setPreviewOpen(true)}>
            Pratinjau
          </button>
          {editorialState === 'published' && link ? (
            <a
              className="penalis-button penalis-button--ghost"
              href={link}
              target="_blank"
              rel="noreferrer noopener"
            >
              <Icon name="externalLink" />
              Lihat di Situs
            </a>
          ) : null}
          {canEdit ? (
            <button
              type="button"
              className="penalis-button penalis-button--soft"
              onClick={() => void saveDraft()}
              disabled={saving || !isDirty}
            >
              {saving ? 'Menyimpan…' : 'Simpan Draf'}
            </button>
          ) : null}
          {canEdit && (canSubmit || canResubmit) ? (
            <button
              type="button"
              className="penalis-button penalis-button--primary"
              onClick={() => setSubmitConfirmOpen(true)}
              disabled={saving}
            >
              {canResubmit ? 'Ajukan Ulang' : 'Ajukan Tinjauan'}
            </button>
          ) : null}
        </div>
      </div>

      {editorialState === 'published' ? (
        <Notice tone="info">
          <strong>Karya tulis sudah publish (read-only mode).</strong> Karya yang telah publish tidak dapat diubah oleh
          penulis. Jika ada perubahan atau perbaikan yang diperlukan, silakan ajukan permintaan kepada admin Penalis.
        </Notice>
      ) : null}

      {editorialState === 'pending_review' ? (
        <Notice tone="info">
          <strong>Sedang menunggu tinjauan admin.</strong> Karya tulis ini sedang berada dalam antrean tinjauan
          {reviewCycle > 0 ? ` (siklus ${reviewCycle})` : ''} dan dikunci sementara agar isi yang ditinjau admin tetap
          konsisten.
        </Notice>
      ) : null}

      {editorialState === 'changes_requested' ? (
        <Notice tone="warning">
          <strong>Perlu revisi dari penulis.</strong> Admin telah meninjau karya tulis ini dan memberikan catatan
          perbaikan. Silakan perbarui karya tulismu sesuai catatan di bawah, simpan draf bila perlu, lalu klik{' '}
          <strong>Ajukan Ulang</strong>.
        </Notice>
      ) : null}

      {editorialState === 'rejected' ? (
        <Notice tone="warning">
          <strong>Pengajuan karya tulis ditolak.</strong> Karya tulis ini dikunci dan tidak dapat diajukan ulang kecuali
          admin membukanya kembali untuk revisi.
        </Notice>
      ) : null}

      {staleReview ? (
        <Notice tone="warning">
          <div className="penalis-stale-banner">
            <span>
              Status tinjauan karya tulis ini telah berubah di server. Muat ulang data terbaru sebelum melanjutkan.
            </span>
            <button
              type="button"
              className="penalis-button penalis-button--ghost"
              onClick={() => void (id ? load() : refreshReviewState())}
              disabled={saving}
            >
              Muat ulang status terbaru
            </button>
          </div>
        </Notice>
      ) : null}

      {error ? <ErrorBanner message={error} /> : null}
      {notice ? <Notice tone="success">{notice}</Notice> : null}

      <div className="penalis-editor">
        <div className="penalis-editor__main">
          {feedback ? (
            <section className="penalis-panel penalis-editorial-feedback" aria-label="Catatan tinjauan admin">
              <div className="penalis-panel__header">
                <h3>Catatan Tinjauan Admin</h3>
                {reviewCycle > 0 ? <span className="penalis-muted">Siklus {reviewCycle}</span> : null}
              </div>
              <p className="penalis-editorial-feedback__body">{feedback}</p>
              {reviewedAt ? (
                <p className="penalis-fineprint">Ditinjau pada {formatDate(reviewedAt)}</p>
              ) : null}
            </section>
          ) : null}

          <label className="penalis-field">
            <span>Judul Karya Tulis</span>
            <input
              type="text"
              value={title}
              onChange={(event) => setTitle(event.target.value)}
              maxLength={200}
              placeholder="Judul karya kamu"
              readOnly={!canEdit}
              disabled={!canEdit}
              aria-invalid={Boolean(errors.title)}
            />
            <FieldError errors={errors} name="title" />
          </label>

          <div className="penalis-field">
            {/* A contenteditable div cannot be labelled with htmlFor, so the
                visible label is referenced through aria-labelledby instead. */}
            <span className="penalis-field-label" id="penalis-content-label">
              Isi Karya Tulis
            </span>
            <ChunkErrorBoundary
              fallback={
                <div className="penalis-editor-fallback">
                  <p className="penalis-muted">
                    Editor visual tidak dapat dimuat. Kamu tetap bisa menulis di kolom HTML di bawah ini,
                    atau muat ulang halaman untuk mencoba lagi.
                  </p>
                  <textarea
                    className="penalis-editor-html"
                    value={content}
                    onChange={(event) => setContent(event.target.value)}
                    rows={18}
                    spellCheck={false}
                    readOnly={!canEdit}
                    disabled={!canEdit}
                    aria-label="Isi karya tulis dalam HTML"
                    aria-invalid={Boolean(errors.content)}
                    aria-describedby={errors.content ? 'content-error' : undefined}
                  />
                </div>
              }
            >
              <Suspense fallback={<Spinner label="Memuat editor…" />}>
                <RichTextEditor
                  ref={editorRef}
                  value={content}
                  onChange={setContent}
                  onRequestImage={() => void openMediaPicker('insert')}
                  invalid={Boolean(errors.content)}
                  errorId={errors.content ? 'content-error' : undefined}
                  labelledBy="penalis-content-label"
                  readOnly={!canEdit}
                />
              </Suspense>
            </ChunkErrorBoundary>
            <FieldError errors={errors} name="content" />
          </div>
        </div>

        <aside className="penalis-editor__side">
          <section className="penalis-panel" aria-label="Status dan riwayat tinjauan">
            <div className="penalis-panel__header">
              <h3>Status Tinjauan</h3>
              <StatusBadge
                status={editorialState}
                label={editorialStateLabel || formatEditorialStateLabel(editorialState)}
              />
            </div>

            <dl className="penalis-editorial-meta">
              <div>
                <dt>Siklus tinjauan</dt>
                <dd>{reviewCycle > 0 ? `Siklus ${reviewCycle}` : 'Belum diajukan'}</dd>
              </div>
              {submittedAt ? (
                <div>
                  <dt>Terakhir diajukan</dt>
                  <dd>{formatDate(submittedAt)}</dd>
                </div>
              ) : null}
              {reviewedAt ? (
                <div>
                  <dt>Terakhir ditinjau</dt>
                  <dd>{formatDate(reviewedAt)}</dd>
                </div>
              ) : null}
            </dl>

            {id ? (
              <button
                type="button"
                className="penalis-button penalis-button--ghost"
                onClick={() => void refreshReviewState()}
                disabled={saving}
              >
                Perbarui status tinjauan
              </button>
            ) : null}

            {id ? (
              <div className="penalis-editorial-history">
                <h4>Riwayat Tinjauan</h4>
                {reviewHistory.length === 0 ? (
                  <p className="penalis-muted">Belum ada riwayat tinjauan untuk karya tulis ini.</p>
                ) : (
                  <ol className="penalis-review-history">
                    {reviewHistory.map((entry, index) => (
                      <li
                        key={`${entry.action}-${entry.reviewCycle}-${entry.createdAt}-${index}`}
                        className="penalis-review-history__item"
                      >
                        <div className="penalis-review-history__head">
                          <strong>{entry.actionLabel}</strong>
                          {entry.reviewCycle > 0 ? (
                            <span className="penalis-muted">Siklus {entry.reviewCycle}</span>
                          ) : null}
                        </div>
                        <p className="penalis-review-history__meta">
                          {entry.actorName}
                          {entry.createdAt ? ` · ${formatDate(entry.createdAt)}` : ''}
                        </p>
                        {entry.feedback ? (
                          <p className="penalis-review-history__feedback">{entry.feedback}</p>
                        ) : null}
                      </li>
                    ))}
                  </ol>
                )}
              </div>
            ) : null}
          </section>

          <section className="penalis-panel">
            <div className="penalis-panel__header">
              <h3>Thumbnail</h3>
            </div>

            {featuredMedia > 0 ? (
              <div className="penalis-featured">
                {featuredPreview ? <img src={featuredPreview} alt="" /> : null}
                {canEdit ? (
                  <button
                    type="button"
                    className="penalis-button penalis-button--ghost"
                    onClick={() => {
                      setFeaturedMedia(0);
                      setFeaturedPreview('');
                    }}
                  >
                    Hapus
                  </button>
                ) : null}
              </div>
            ) : (
              <p className="penalis-muted">Belum ada thumbnail yang dipilih.</p>
            )}

            {canEdit ? (
              <button
                type="button"
                className="penalis-button penalis-button--ghost"
                onClick={() => void openMediaPicker('featured')}
              >
                Pilih dari pustaka media
              </button>
            ) : null}
          </section>

          <section className="penalis-panel">
            <div className="penalis-panel__header">
              <h3>Kategori Karya Tulis</h3>
            </div>

            {categories.length === 0 ? (
              <p className="penalis-muted">Belum ada kategori.</p>
            ) : (
              <div className="penalis-checklist">
                {categories.map((category) => (
                  <label key={category.id} className="penalis-checkbox">
                    <input
                      type="checkbox"
                      checked={selectedCategories.includes(category.id)}
                      disabled={!canEdit}
                      onChange={(event) => {
                        setSelectedCategories((current) =>
                          event.target.checked
                            ? [...current, category.id]
                            : current.filter((item) => item !== category.id),
                        );
                      }}
                    />
                    <span>{category.name}</span>
                  </label>
                ))}
              </div>
            )}
          </section>

          <section className="penalis-panel">
            <div className="penalis-panel__header">
              <h3>Tag Karya Tulis</h3>
            </div>

            <div className="penalis-tag-list">
              {selectedTags.map((term) => (
                <span key={term.id} className="penalis-chip">
                  {term.name}
                  {canEdit ? (
                    <button
                      type="button"
                      aria-label={`Hapus ${term.name}`}
                      onClick={() => setSelectedTags((current) => current.filter((item) => item.id !== term.id))}
                    >
                      x
                    </button>
                  ) : null}
                </span>
              ))}
            </div>

            {canEdit ? (
              <>
                <label className="penalis-field">
                  <span>Tambahkan tag</span>
                  <input
                    type="text"
                    value={tagSearch}
                    onChange={(event) => setTagSearch(event.target.value)}
                    placeholder="Cari atau buat tag"
                  />
                </label>

                {tagSuggestions.length > 0 ? (
                  <ul className="penalis-suggestions">
                    {tagSuggestions.map((term) => (
                      <li key={term.id}>
                        <button type="button" onClick={() => addTag(term)}>
                          {term.name}
                        </button>
                      </li>
                    ))}
                  </ul>
                ) : null}

                {tagSearch.trim().length >= 2 ? (
                  <button type="button" className="penalis-button penalis-button--ghost" onClick={() => void createTag()}>
                    Buat “{tagSearch.trim()}”
                  </button>
                ) : null}
              </>
            ) : selectedTags.length === 0 ? (
              <p className="penalis-muted">Belum ada tag yang dipilih.</p>
            ) : null}
          </section>
        </aside>
      </div>

      {mediaIntent ? (
        <Dialog label="Pustaka media" onClose={closeMedia}>
          <div className="penalis-modal__content">
            <div className="penalis-modal__header">
              <div>
                <h3>Pustaka media</h3>
                <p className="penalis-muted penalis-media-picker__hint">
                  {mediaIntent === 'featured'
                    ? 'Pilih gambar untuk dijadikan thumbnail karya tulis ini.'
                    : 'Pilih gambar untuk disisipkan ke dalam karya tulis.'}
                </p>
              </div>
            </div>

            <ImageDropzone
              onFile={(file) => void uploadImage(file)}
              onReject={setUploadError}
              busy={uploading}
              title="Letakkan gambar di sini"
              subtitle="atau klik untuk memilih file"
              hint="Hanya dapat mengunggah file JPEG, PNG, GIF, atau WebP."
            />

            {uploadError ? <ErrorBanner message={uploadError} /> : null}

            {mediaLoading ? (
              <Spinner label="Memuat media…" />
            ) : media.length === 0 ? (
              <p className="penalis-muted">
                Kamu belum mengunggah media apa pun. Gunakan kontrol unggah di atas untuk menambahkan gambar pertama.
              </p>
            ) : (

              <MediaSelection items={media} selectedId={selectedMediaId} onSelect={setSelectedMediaId}
                onConfirm={confirmMediaSelection} onCancel={closeMedia} busy={uploading} />
            )}
          </div>
        </Dialog>
      ) : null}

      {previewOpen ? (
        <Dialog label="Pratinjau artikel" onClose={closePreview}>
          <div className="penalis-modal__content penalis-modal__content--wide">
            <div className="penalis-modal__header">
              <h3>Pratinjau</h3>
              <div className="penalis-row-actions">
                {editorialState === 'published' && link ? (
                  <a
                    className="penalis-button penalis-button--ghost"
                    href={link}
                    target="_blank"
                    rel="noreferrer noopener"
                  >
                    Lihat Karya Terbit
                  </a>
                ) : null}
                <button
                  type="button"
                  className="penalis-button penalis-button--ghost"
                  onClick={closePreview}
                >
                  Tutup
                </button>
              </div>
            </div>
            <iframe
              title="Pratinjau karya tulis"
              className="penalis-preview-frame"
              sandbox=""
              srcDoc={previewDocument}
            />
          </div>
        </Dialog>
      ) : null}

      {submitConfirmOpen ? (
        <ConfirmDialog
          title={canResubmit ? 'Ajukan ulang revisi karya tulis?' : 'Ajukan karya tulis untuk ditinjau?'}
          description={
            <>
              <p>
                Karya tulis berjudul <strong>{title.trim() || 'Tanpa judul'}</strong> akan dikirim ke antrean tinjauan
                admin Penalis.
              </p>
              <p className="penalis-muted">
                Selama proses tinjauan berlangsung, karya tulis tidak dapat diubah sampai admin memberikan keputusan
                (menyetujui untuk terbit, meminta revisi, atau menolak).
              </p>
            </>
          }
          confirmLabel={canResubmit ? 'Ajukan Ulang' : 'Ajukan Tinjauan'}
          tone="warning"
          busy={saving}
          onConfirm={() => void submitForReview()}
          onCancel={closeSubmitConfirm}
        />
      ) : null}

      {id ? (
        <p className="penalis-fineprint">ID Karya Tulis: {id}</p>
      ) : null}
    </form>
  );
}

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}
