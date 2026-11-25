/**
 * Article editor.
 *
 * Uses the native WordPress posts endpoint for persistence. The author is
 * always derived server-side. Content is stored as WordPress post content.
 */

import { lazy, Suspense, useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from 'react';

import { ApiError, articlesApi, isAuthFailure, isStaleEditorialError, mediaApi, refreshNonce, taxonomyApi } from '../../lib/api';
import { getBootstrap } from '../../lib/bootstrap';
import { AUTOSAVE_RETRY_MS, autosaveDelay, hasAutosaveContent } from '../../lib/autosave';
import {
  editorialStateLabel as formatEditorialStateLabel,
  formatDateTime,
  formatNumber,
  formatTime,
} from '../../lib/format';
import { buildPreviewDocument, htmlToPlainText, trimTrailingEmptyParagraphs } from '../../lib/html';
import type {
  Article,
  ArticleReview,
  EditorialState,
  EditorFocus,
  FieldErrors,
  MediaItem,
  ReviewHistoryEntry,
  Term,
} from '../../lib/types';
import { hasErrors, isUncategorizedTerm, validateArticle, validateArticleSubmission } from '../../lib/validation';
import { isEditableTarget, matchEditorShortcut, type EditorShortcut } from '../../lib/shortcuts';
import { ErrorBanner, FieldError, Notice, Panel, Spinner, StatusBadge } from '../../components/ui';
import { ChunkErrorBoundary } from '../../components/ChunkErrorBoundary';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { Dialog } from '../../components/Dialog';
import { MediaPickerDialog } from '../../components/MediaSelection';
import { Icon } from '../../components/icons';
import { editorLoader } from './editorLoader';
import { EditorShortcutsDialog } from './EditorShortcutsDialog';
import type { RichTextEditorHandle } from './RichTextEditor';

/**
 * The editor pulls in TipTap and ProseMirror (~135 kB gzip). Loading it on
 * demand keeps the rest of the dashboard light, because only writers who open
 * the editor ever download it. Retrying protects against mobile network hiccups.
 */
const RichTextEditor = lazy(editorLoader.load);

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

/**
 * How a save should behave.
 *
 * `silent` autosaves never announce themselves: they only move the save
 * indicator. `validate` applies the same rules as the manual button; autosave
 * deliberately skips them, because a half-written paragraph is exactly the
 * work that must not be lost.
 */
type PersistOptions = { silent: boolean; validate: boolean };

export function Editor({
  articleId,
  focus = null,
  onSaved,
  onCancel,
}: {
  articleId?: number;
  focus?: EditorFocus;
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
  const [editorialStateLabel, setEditorialStateLabel] = useState('Draft');
  const [canEdit, setCanEdit] = useState(true);
  const [canSubmit, setCanSubmit] = useState(true);
  const [canResubmit, setCanResubmit] = useState(false);
  const [feedback, setFeedback] = useState('');
  const [reviewCycle, setReviewCycle] = useState(0);
  const [submittedAt, setSubmittedAt] = useState('');
  const [reviewedAt, setReviewedAt] = useState('');
  const [reviewHistory, setReviewHistory] = useState<ReviewHistoryEntry[]>([]);
  const [link, setLink] = useState('');
  const [views, setViews] = useState(0);
  const [likes, setLikes] = useState(0);
  const [staleReview, setStaleReview] = useState(false);

  const [categories, setCategories] = useState<Term[]>([]);
  const [selectedCategories, setSelectedCategories] = useState<number[]>([]);
  const [selectedTags, setSelectedTags] = useState<Term[]>([]);
  const [tagSearch, setTagSearch] = useState('');
  const [tagSuggestions, setTagSuggestions] = useState<Term[]>([]);
  const [featuredMedia, setFeaturedMedia] = useState(0);
  const [featuredPreview, setFeaturedPreview] = useState('');
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
  const [previewOpen, setPreviewOpen] = useState(false);
  /** The `?` reference dialog, also reachable from the sticky bar. */
  const [shortcutsOpen, setShortcutsOpen] = useState(false);

  /**
   * Submitting an article locks it while administrators review it, so the
   * writer confirms before sending the draft or revision into the queue.
   */
  const [submitConfirmOpen, setSubmitConfirmOpen] = useState(false);

  /**
   * Leaving with unsaved work used to go through `window.confirm`: an unstyled
   * OS prompt that cannot name the article, cannot be translated, and freezes
   * the whole tab. The same question is now asked in a dialog the writer can
   * read, with the safe answer focused first.
   */
  const [leaveConfirmOpen, setLeaveConfirmOpen] = useState(false);

  /**
   * Snapshot of the last persisted state, used to tell whether the writer has
   * unsaved changes. Without it the save button is always enabled and leaving
   * the editor silently discards work.
   */
  const [savedSnapshot, setSavedSnapshot] = useState(EMPTY_SNAPSHOT);
  const [savedAt, setSavedAt] = useState<Date | null>(null);

  /** Whether the last successful save came from the button or the timer. */
  const [lastSaveKind, setLastSaveKind] = useState<'manual' | 'auto' | null>(null);
  /** Why the last save attempt failed, surfaced in the sticky action bar. */
  const [saveError, setSaveError] = useState('');
  /**
   * True only when the session itself has ended.
   *
   * A stale nonce is recovered silently by the request layer, so reaching this
   * state means no amount of retrying will help until the writer signs in
   * again — and signing in must not cost them the text still in this tab.
   */
  const [authExpired, setAuthExpired] = useState(false);

  const editorRef = useRef<RichTextEditorHandle | null>(null);
  const feedbackRef = useRef<HTMLElement | null>(null);

  /** When the last save attempt ran, so retries cannot form a tight loop. */
  const lastSaveAtRef = useRef(0);
  /** When the current batch of unsaved changes started. */
  const dirtySinceRef = useRef(0);
  /** Earliest time an automatic retry may run after a failure. */
  const retryNotBeforeRef = useRef(0);
  /** Latest save function, read by the timer without re-arming it. */
  const persistRef = useRef<(options: PersistOptions) => Promise<boolean>>(async () => false);
  /**
   * Whether a write is in flight.
   *
   * A ref rather than the `saving` state: the timer, a hidden-tab flush, and
   * the button can all try to save in the same tick, and only a synchronous
   * flag can stop the second one.
   */
  const inFlightRef = useRef(false);

  const { loginUrl } = getBootstrap();

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

  /**
   * Whether there is enough content to autosave.
   *
   * An existing draft always qualifies; a new article waits for a title or a
   * short paragraph so merely opening the editor does not create empty posts.
   */
  const autosaveReady = useMemo(
    () => hasAutosaveContent({ title, content, hasDraft: Boolean(id) }),
    [title, content, id],
  );

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
      setViews(article.views ?? 0);
      setLikes(article.likes ?? 0);
      const initialCategoryIds = article.categories
        .filter((term) => !isUncategorizedTerm(term))
        .map((term) => term.id);
      setSelectedCategories(initialCategoryIds);
      setSelectedTags(article.tags);

      if (article.featuredMedia > 0) {
        setFeaturedMedia(article.featuredMedia);
        if (article.thumbnail) {
          setFeaturedPreview(article.thumbnail);
        } else {
          try {
            const mediaItem = await mediaApi.get(article.featuredMedia);
            setFeaturedPreview(mediaItem.thumbnail || mediaItem.url);
          } catch {
            // A missing preview is not fatal; the ID is still preserved.
          }
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
          categories: initialCategoryIds,
          tags: article.tags.map((term) => term.id),
          featuredMedia: article.featuredMedia,
        }),
      );
    } catch (caught) {
      setLoadError(caught instanceof ApiError ? caught.message : 'Karya tulis ini tidak dapat dimuat.');
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
      setNotice('Status review terbaru berhasil dimuat.');
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Status review terbaru tidak dapat dimuat.');
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
  }, []);
  const closePreview = useCallback(() => setPreviewOpen(false), []);
  const closeSubmitConfirm = useCallback(() => setSubmitConfirmOpen(false), []);
  const closeShortcuts = useCallback(() => setShortcutsOpen(false), []);
  const closeLeaveConfirm = useCallback(() => setLeaveConfirmOpen(false), []);

  /**
   * Apply the selected image according to why the picker was opened.
   *
   * One handler for both flows keeps the dialog's confirm button identical; the
   * only difference is what happens to the chosen item.
   */
  const clearFieldError = (field: string) => {
    setErrors((current) => {
      if (!current[field]) {
        return current;
      }
      const next = { ...current };
      delete next[field];
      return next;
    });
  };

  const confirmMediaSelection = (item: MediaItem) => {
    if (mediaIntent === 'featured') {
      setFeaturedMedia(item.id);
      setFeaturedPreview(item.thumbnail || item.url);
      clearFieldError('featuredMedia');
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
    clearFieldError('tags');
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

  /**
   * Record the draft id in the address bar.
   *
   * `replaceState` rather than navigation: a real page load would discard
   * everything this editor holds in memory. With the id in the URL, a reload
   * reopens the draft instead of a blank "new article" screen.
   */
  const syncEditorUrl = (draftId: number) => {
    const url = new URL(window.location.href);
    url.searchParams.set('view', 'editor');
    url.searchParams.set('id', String(draftId));
    window.history.replaceState({}, '', url.toString());
  };

  /**
   * Turn a failed write into something the writer can act on.
   *
   * WordPress answers a dead session with either "Cookie check failed" or
   * "Sorry, you are not allowed to create posts as this user" — neither of
   * which tells a writer that their text is safe or what to do next. The
   * technical message is kept for every other failure; an authentication
   * failure is replaced by the recovery banner, and `refreshNonce()` doubles
   * as the probe that tells the two apart.
   */
  const reportSaveFailure = async (caught: unknown, fallback: string) => {
    if (isStaleEditorialError(caught)) {
      setStaleReview(true);
    }

    if (caught instanceof ApiError) {
      setErrors(caught.fields);
    }

    const message = describeSaveFailure(caught, fallback);
    setSaveError(message);

    if (isAuthFailure(caught)) {
      const nonce = await refreshNonce();
      const expired = nonce === null;
      setAuthExpired(expired);
      setError(expired ? '' : message);

      if (expired) {
        // Autosave is paused in this state; the banner offers an explicit
        // retry once the writer has signed in again.
        return;
      }
    } else {
      setError(message);
    }

    // Space the retries out so a failing server is not hammered.
    retryNotBeforeRef.current = Date.now() + AUTOSAVE_RETRY_MS;
  };

  /**
   * Persist the current editor state to the draft post.
   *
   * One function serves the manual button, the autosave timer, and the
   * recovery retry. They differ only in whether the writer is told about it
   * and whether the client-side draft rules apply.
   */
  const persistDraft = async ({ silent, validate }: PersistOptions): Promise<boolean> => {
    if (!canEdit || inFlightRef.current) {
      return false;
    }

    // TipTap keeps an empty trailing paragraph after block nodes so the writer
    // can keep typing. Drop it from what is sent to WordPress.
    const payloadContent = trimTrailingEmptyParagraphs(content);

    if (validate) {
      const clientErrors = validateArticle({ title, content: payloadContent });
      if (hasErrors(clientErrors)) {
        setErrors(clientErrors);
        setSubmitConfirmOpen(false);
        return false;
      }
    } else if (!hasAutosaveContent({ title, content: payloadContent, hasDraft: Boolean(id) })) {
      return false;
    }

    if (!silent) {
      setError('');
      setNotice('');
      setStaleReview(false);
      // A manual attempt is an explicit answer to whatever the writer was
      // shown; an autosave must not clear field errors they are still reading.
      setErrors({});
    }

    setSaving(true);
    setSaveError('');
    inFlightRef.current = true;

    // Every attempt moves the clock forward, so a failure cannot schedule the
    // next attempt in the same breath.
    lastSaveAtRef.current = Date.now();

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
      // The server accepted the payload, so any field complaint from an earlier
      // attempt no longer describes what is stored.
      setErrors({});
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
      setLastSaveKind(silent ? 'auto' : 'manual');
      lastSaveAtRef.current = Date.now();
      retryNotBeforeRef.current = 0;
      setAuthExpired(false);

      if (!id) {
        syncEditorUrl(result.id);
      }

      if (!silent) {
        setNotice('Draft karya tulis telah disimpan.');
        onSaved(result.id);
      }

      return true;
    } catch (caught) {
      await reportSaveFailure(caught, 'Draft karya tulis tidak dapat disimpan.');
      return false;
    } finally {
      inFlightRef.current = false;
      setSaving(false);
    }
  };

  persistRef.current = persistDraft;

  const saveDraft = async () => {
    await persistDraft({ silent: false, validate: true });
  };

  /**
   * Retry after the session ended.
   *
   * The writer signs in from another tab; this one keeps its text. Fetching a
   * nonce proves the session is alive again, and only then is the save
   * replayed — no reload, no lost manuscript.
   */
  const retrySave = async () => {
    setError('');
    const nonce = await refreshNonce();

    if (nonce === null) {
      setAuthExpired(true);
      setSaveError('Sesi kamu masih berakhir. Login lewat tab baru, lalu coba lagi.');
      return;
    }

    setAuthExpired(false);
    await persistDraft({ silent: false, validate: false });
  };

  /** Last resort: put the manuscript on the clipboard, in plain text. */
  const copyDraft = async () => {
    const text = [title.trim(), htmlToPlainText(content)].filter((part) => part !== '').join('\n\n');

    try {
      await navigator.clipboard.writeText(text);
      setNotice('Naskah disalin ke clipboard. Simpan di tempat aman sebelum menutup tab ini.');
    } catch {
      setError('Naskah tidak dapat disalin otomatis. Pilih semua teks di editor lalu salin manual.');
    }
  };

  const retrySaveRef = useRef(retrySave);
  retrySaveRef.current = retrySave;

  /**
   * Autosave loop.
   *
   * The timer is rebuilt on every change so it always reflects the latest
   * text: it fires four seconds after the writer pauses, never writes twice
   * within twenty seconds, and caps the age of unsaved work at sixty seconds
   * while the writer keeps typing.
   *
   * It stays idle while a write is in flight, on read-only articles, after the
   * session has ended, and when the server has reported a newer editorial
   * state — retrying any of those only produces requests that cannot succeed.
   */
  useEffect(() => {
    if (!isDirty) {
      dirtySinceRef.current = 0;
      return;
    }

    if (!canEdit || authExpired || saving || staleReview || !autosaveReady) {
      return;
    }

    const now = Date.now();

    if (dirtySinceRef.current === 0) {
      dirtySinceRef.current = now;
    }

    const delay = autosaveDelay({
      now,
      dirtySince: dirtySinceRef.current,
      lastSaveAt: lastSaveAtRef.current,
      retryNotBefore: retryNotBeforeRef.current,
    });

    const timer = window.setTimeout(() => {
      void persistRef.current({ silent: true, validate: false });
    }, delay);

    return () => window.clearTimeout(timer);
  }, [currentSnapshot, isDirty, canEdit, authExpired, saving, staleReview, autosaveReady]);

  /**
   * Flush when the tab is hidden.
   *
   * On a phone this is the last moment the page is guaranteed to be alive:
   * background tabs get frozen and discarded, and `beforeunload` never fires
   * there. Saving on the way out costs one request and can turn "lost
   * manuscript" into "lost sentence".
   */
  useEffect(() => {
    if (!isDirty || !canEdit || authExpired) {
      return;
    }

    const onVisibilityChange = () => {
      if (document.visibilityState === 'hidden') {
        void persistRef.current({ silent: true, validate: false });
      }
    };

    document.addEventListener('visibilitychange', onVisibilityChange);
    return () => document.removeEventListener('visibilitychange', onVisibilityChange);
  }, [isDirty, canEdit, authExpired]);

  /**
   * Finish the recovery on its own.
   *
   * The usual sequence is: sign in from another tab, come back here. Probing
   * the session the moment the tab is visible again means the writer does not
   * have to remember the button — if they are signed in, the save simply
   * completes and the banner disappears.
   */
  useEffect(() => {
    if (!authExpired) {
      return;
    }

    const onVisible = () => {
      if (document.visibilityState === 'visible') {
        void retrySaveRef.current();
      }
    };

    document.addEventListener('visibilitychange', onVisible);
    return () => document.removeEventListener('visibilitychange', onVisible);
  }, [authExpired]);

  const selectedCategoryTerms = useMemo(
    () =>
      selectedCategories.map(
        (categoryId) =>
          categories.find((category) => category.id === categoryId) ?? { id: categoryId, name: '' },
      ),
    [categories, selectedCategories],
  );

  const validateBeforeSubmit = () => {
    const payloadContent = trimTrailingEmptyParagraphs(content);
    const clientErrors = validateArticleSubmission({
      title,
      content: payloadContent,
      featuredMedia,
      categories: selectedCategoryTerms,
      tags: selectedTags,
    });
    if (hasErrors(clientErrors)) {
      setErrors(clientErrors);
      setError(
        'Lengkapi judul, isi karya tulis, thumbnail, kategori, dan minimal satu tag sebelum submit review.',
      );
      return null;
    }
    return payloadContent;
  };

  const requestSubmitConfirm = () => {
    if (!canEdit || (!canSubmit && !canResubmit)) {
      return;
    }
    setError('');
    setNotice('');
    setStaleReview(false);
    if (!validateBeforeSubmit()) {
      setSubmitConfirmOpen(false);
      return;
    }
    setErrors({});
    setSubmitConfirmOpen(true);
  };

  const submitForReview = async () => {
    if (!canEdit || (!canSubmit && !canResubmit)) {
      return;
    }

    setError('');
    setNotice('');
    setStaleReview(false);

    const payloadContent = validateBeforeSubmit();
    if (payloadContent === null) {
      setSubmitConfirmOpen(false);
      return;
    }
    setErrors({});
    setSaving(true);
    // A submit writes too: keep the autosave timer and the hidden-tab flush
    // from racing it with a second request.
    inFlightRef.current = true;

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
      setLastSaveKind('manual');
      lastSaveAtRef.current = Date.now();
      retryNotBeforeRef.current = 0;
      setSaveError('');
      setAuthExpired(false);
      setNotice(
        review.message ||
        (canResubmit
          ? 'Revisi karya tulis kamu telah di-submit ulang untuk di-review Senator.'
          : 'Karya tulis kamu telah di-submit dan sedang menunggu review Senator.'),
      );
      setSubmitConfirmOpen(false);
      onSaved(targetId);
    } catch (caught) {
      await reportSaveFailure(caught, 'Karya tulis tidak dapat di-submit untuk di-review.');
      setSubmitConfirmOpen(false);
    } finally {
      inFlightRef.current = false;
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
   * "Preview" threw away everything typed since the last save with no way
   * back. The question is now a `ConfirmDialog` rather than `window.confirm`,
   * so it names the article and offers a safe default; closing the tab is still
   * covered by `beforeunload`, which is the one prompt the browser owns.
   */
  const leave = useCallback(() => {
    if (!isDirty) {
      onCancel();
      return;
    }

    setLeaveConfirmOpen(true);
  }, [isDirty, onCancel]);

  const confirmLeave = useCallback(() => {
    setLeaveConfirmOpen(false);
    onCancel();
  }, [onCancel]);

  /**
   * Keyboard shortcuts.
   *
   * The handler reads its actions through a ref so the listener is attached
   * once per dialog-state change instead of being torn down on every
   * keystroke — the editor re-renders on each character typed.
   *
   * `preventDefault()` runs for every shortcut, including while a dialog is
   * open: leaving `Ctrl+S` to the browser would pop the "save page" dialog on
   * top of the editor's own dialog.
   */
  const dialogOpen =
    previewOpen || submitConfirmOpen || shortcutsOpen || leaveConfirmOpen || mediaIntent !== null;

  const runShortcutRef = useRef<(shortcut: EditorShortcut) => void>(() => undefined);

  runShortcutRef.current = (shortcut) => {
    if (shortcut === 'help') {
      setShortcutsOpen(true);
      return;
    }

    if (!canEdit || saving) {
      return;
    }

    if (shortcut === 'save') {
      // Mirrors the button, which is disabled while there is nothing to write.
      if (isDirty) {
        void saveDraft();
      }
      return;
    }

    // Never submit straight from the keyboard: the confirmation dialog is what
    // explains that the article locks until an administrator decides.
    requestSubmitConfirm();
  };

  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      const shortcut = matchEditorShortcut(event);

      if (shortcut === null) {
        return;
      }

      // `?` is a printable character: inside the article body or any form
      // control it must type a question mark, not open a dialog.
      if (shortcut === 'help' && isEditableTarget(event.target)) {
        return;
      }

      event.preventDefault();

      if (dialogOpen) {
        return;
      }

      runShortcutRef.current(shortcut);
    };

    // Capture phase: the rich text editor handles keydown on its own element
    // and may stop propagation, which would hide Ctrl+S from a bubbling
    // listener. Nothing here runs for printable characters, so capturing
    // cannot steal typing from the editor.
    window.addEventListener('keydown', onKeyDown, true);
    return () => window.removeEventListener('keydown', onKeyDown, true);
  }, [dialogOpen]);

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

  /*
   * A notification about requested changes deep-links here with
   * `focus="feedback"`. The article loads asynchronously, so the reveal waits
   * until the notes are actually rendered; otherwise the scroll would target an
   * element that does not exist yet.
   */
  useEffect(() => {
    if (focus !== 'feedback' || !feedback) {
      return;
    }

    const target = feedbackRef.current;
    if (!target) {
      return;
    }

    target.scrollIntoView({ block: 'start', behavior: 'smooth' });
    // Move focus so a keyboard or screen-reader user lands on the notes too.
    target.focus({ preventScroll: true });
  }, [focus, feedback]);

  const previewDocument = useMemo(() => {
    return buildPreviewDocument(content, title || 'Tanpa judul');
  }, [content, title]);

  if (loading) {
    return <Spinner label="Memuat karya tulis…" />;
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
        <p
          className={saveError ? 'penalis-save-state penalis-save-state--error' : 'penalis-save-state'}
          role="status"
          aria-live="polite"
        >
          {saving ? (
            <>
              <span className="penalis-spinner" aria-hidden="true" />
              Menyimpan…
            </>
          ) : !canEdit ? (
            `Read-only mode (${editorialStateLabel || formatEditorialStateLabel(editorialState)})`
          ) : saveError ? (
            authExpired ? 'Gagal menyimpan — sesi berakhir' : 'Gagal menyimpan'
          ) : isDirty ? (
            'Belum disimpan'
          ) : savedAt ? (
            `${lastSaveKind === 'auto' ? 'Tersimpan otomatis' : 'Tersimpan'} ${formatTime(savedAt)}`
          ) : (
            'Belum ada perubahan'
          )}
        </p>

        <div className="penalis-action-bar__actions">
          {/*
            The `?` shortcut is invisible knowledge on its own, so the same
            dialog also opens from here. Hidden on touch screens, where the
            shortcut cannot be used and the bar is already short of room.
          */}
          <button
            type="button"
            className="penalis-icon-button penalis-shortcuts-trigger"
            onClick={() => setShortcutsOpen(true)}
            aria-label="Pintasan keyboard"
            aria-keyshortcuts="?"
            title="Pintasan keyboard (?)"
          >
            <span aria-hidden="true">?</span>
          </button>
          <button type="button" className="penalis-button penalis-button--ghost" onClick={() => setPreviewOpen(true)}>
            Preview
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
              aria-keyshortcuts="Control+S Meta+S"
            >
              {saving ? 'Menyimpan…' : 'Simpan Draft'}
            </button>
          ) : null}
          {canEdit && (canSubmit || canResubmit) ? (
            <button
              type="button"
              className="penalis-button penalis-button--primary"
              onClick={requestSubmitConfirm}
              disabled={saving}
              aria-keyshortcuts="Control+Enter Meta+Enter"
            >
              {canResubmit ? 'Submit Ulang' : 'Submit Review'}
            </button>
          ) : null}
        </div>
      </div>

      {editorialState === 'published' ? (
        <Notice tone="info">
          <p>
            <strong>Karya tulis ini sudah terbit (read-only mode).</strong> Karya yang sudah terbit tidak dapat di-edit oleh
            penulis. Jika ada perubahan atau perbaikan yang diperlukan, hubungi Senator Penalis.
          </p>
        </Notice>
      ) : null}

      {editorialState === 'pending_review' ? (
        <Notice tone="info">
          <p>
            <strong>Sedang menunggu review Senator.</strong> Karya tulis ini sedang berada dalam antrean review
            {reviewCycle > 0 ? ` (cycle ${reviewCycle})` : ''} dan dikunci sementara agar isi yang di-review Senator tetap
            konsisten.
          </p>
        </Notice>
      ) : null}

      {editorialState === 'changes_requested' ? (
        <Notice tone="warning">
          <p>
            <strong>Perlu revisi dari penulis.</strong> Senator telah me-review karya tulis ini dan memberikan catatan
            perbaikan. Silakan perbarui karya tulismu sesuai catatan di bawah, simpan draft bila perlu, lalu klik{' '}
            <strong>Submit Ulang</strong>.
          </p>
        </Notice>
      ) : null}

      {editorialState === 'rejected' ? (
        <Notice tone="warning">
          <p>
            <strong>Submit karya tulis ditolak.</strong> Karya tulis ini dikunci dan tidak dapat di-submit ulang kecuali
            dibuka kembali oleh Senator untuk revisi.
          </p>
        </Notice>
      ) : null}

      {staleReview ? (
        <Notice tone="warning">
          <div className="penalis-stale-banner">
            <span>
              Status review karya tulis ini telah berubah di server. Refresh data terbaru sebelum melanjutkan.
            </span>
            <button
              type="button"
              className="penalis-button penalis-button--ghost"
              onClick={() => void (id ? load() : refreshReviewState())}
              disabled={saving}
            >
              Refresh status terbaru
            </button>
          </div>
        </Notice>
      ) : null}

      {/*
        The session ended. Everything typed is still in this tab, so the banner
        must first say that, then offer a way out that does not require a
        reload: sign in from another tab, come back, retry the save here.
      */}
      {authExpired ? (
        <Notice tone="danger">
          <div className="penalis-session-recovery">
            <p>
              <strong>Sesi kamu berakhir dan perubahan terakhir belum terkirim.</strong> Naskah masih ada di
              tab ini — jangan refresh atau tutup halaman sebelum berhasil disimpan.
            </p>
            <p className="penalis-fineprint">
              Buka login di tab baru, lalu kembali ke tab ini dan klik “Coba simpan lagi”. Naskah di tab ini
              tidak akan hilang.
            </p>
            <div className="penalis-row-actions">
              <button
                type="button"
                className="penalis-button penalis-button--primary"
                onClick={() => void retrySave()}
                disabled={saving}
              >
                Coba simpan lagi
              </button>
              <a
                className="penalis-button penalis-button--ghost"
                href={loginUrl}
                target="_blank"
                rel="noreferrer noopener"
              >
                Login di tab baru
              </a>
              <button
                type="button"
                className="penalis-button penalis-button--ghost"
                onClick={() => void copyDraft()}
              >
                Salin naskah
              </button>
            </div>
          </div>
        </Notice>
      ) : null}

      {error && !authExpired ? <ErrorBanner message={error} /> : null}
      {notice ? <Notice tone="success">{notice}</Notice> : null}

      <div className="penalis-editor">
        <div className="penalis-editor__main">
          {feedback ? (
            <Panel
              title="Catatan review Senator:"
              aside={
                reviewCycle > 0 ? (
                  <span className="penalis-editorial-feedback__cycle">Cycle {reviewCycle}</span>
                ) : null
              }
              label="Catatan review Senator"
              className="penalis-editorial-feedback"
              ref={feedbackRef}
              tabIndex={-1}
            >
              <p className="penalis-editorial-feedback__body">{feedback}</p>
              {reviewedAt ? (
                <p className="penalis-fineprint">Di-review pada {formatDateTime(reviewedAt)}</p>
              ) : null}
            </Panel>
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
                    atau refresh halaman untuk mencoba lagi.
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
                  onRequestImage={() => setMediaIntent('insert')}
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
          <Panel
            title="Status Review"
            label="Status dan riwayat review"
            aside={
              <StatusBadge
                status={editorialState}
                label={editorialStateLabel || formatEditorialStateLabel(editorialState)}
              />
            }
          >
            <dl className="penalis-editorial-meta">
              <div>
                <dt>Review cycle</dt>
                <dd>{reviewCycle > 0 ? `Cycle ${reviewCycle}` : 'Belum submit'}</dd>
              </div>
              {editorialState === 'published' ? (
                <>
                  <div>
                    <dt>Views</dt>
                    <dd>{formatNumber(views)}</dd>
                  </div>
                  <div>
                    <dt>Likes</dt>
                    <dd>{formatNumber(likes)}</dd>
                  </div>
                </>
              ) : null}
              {submittedAt ? (
                <div>
                  <dt>Last submitted</dt>
                  <dd>{formatDateTime(submittedAt)}</dd>
                </div>
              ) : null}
              {reviewedAt ? (
                <div>
                  <dt>Last reviewed</dt>
                  <dd>{formatDateTime(reviewedAt)}</dd>
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
                Refresh status
              </button>
            ) : null}

            {id ? (
              <div className="penalis-editorial-history">
                <h4>Riwayat Review</h4>
                {reviewHistory.length === 0 ? (
                  <p className="penalis-muted">Belum ada riwayat review untuk karya tulis ini.</p>
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
              </div>
            ) : null}
          </Panel>

          <Panel title="Thumbnail">
            {featuredMedia > 0 ? (
              <div className="penalis-featured">
                {featuredPreview ? <img src={featuredPreview} alt="" /> : null}
              </div>
            ) : (
              <p className="penalis-muted">Belum ada thumbnail yang dipilih.</p>
            )}

            {canEdit ? (
              <div className="penalis-featured-actions">
                <button
                  type="button"
                  className="penalis-compact-action"
                  aria-describedby={errors.featuredMedia ? 'featuredMedia-error' : undefined}
                  onClick={() => setMediaIntent('featured')}
                >
                  {featuredMedia > 0 ? 'Ganti thumbnail' : 'Pilih thumbnail'}
                </button>
                {featuredMedia > 0 ? (
                  <button
                    type="button"
                    className="penalis-compact-action"
                    onClick={() => {
                      setFeaturedMedia(0);
                      setFeaturedPreview('');
                    }}
                  >
                    Hapus thumbnail
                  </button>
                ) : null}
              </div>
            ) : null}
            <FieldError errors={errors} name="featuredMedia" />
          </Panel>

          <Panel title="Kategori Karya Tulis">
            {categories.length === 0 ? (
              <p className="penalis-muted">Belum ada kategori.</p>
            ) : (
              <div
                className="penalis-checklist"
                aria-describedby={errors.categories ? 'categories-error' : undefined}
              >
                {categories.map((category) => (
                  <label key={category.id} className="penalis-checkbox">
                    <input
                      type="checkbox"
                      checked={selectedCategories.includes(category.id)}
                      disabled={!canEdit}
                      onChange={(event) => {
                        clearFieldError('categories');
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
            <FieldError errors={errors} name="categories" />
          </Panel>

          <Panel title="Tag Karya Tulis">
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
                    aria-invalid={Boolean(errors.tags)}
                    aria-describedby={errors.tags ? 'tags-error' : undefined}
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
            <FieldError errors={errors} name="tags" />
          </Panel>
        </aside>
      </div>

      {mediaIntent ? (
        <MediaPickerDialog
          label="Pustaka media"
          title="Pustaka media"
          description={
            mediaIntent === 'featured'
              ? 'Pilih gambar untuk dijadikan thumbnail karya tulis ini.'
              : 'Pilih gambar untuk disisipkan ke dalam karya tulis.'
          }
          onConfirm={confirmMediaSelection}
          onClose={closeMedia}
        />
      ) : null}

      {previewOpen ? (
        <Dialog label="Preview karya tulis" onClose={closePreview}>
          <div className="penalis-modal__content penalis-modal__content--wide">
            <div className="penalis-modal__header">
              <h3>Preview</h3>
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
              title="Preview karya tulis"
              className="penalis-preview-frame"
              sandbox=""
              srcDoc={previewDocument}
            />
          </div>
        </Dialog>
      ) : null}

      {submitConfirmOpen ? (
        <ConfirmDialog
          title={canResubmit ? 'Submit ulang revisi karya tulis?' : 'Submit karya tulis untuk di-review?'}
          description={
            <>
              <p>
                Karya tulis berjudul <strong>{title.trim() || 'Tanpa judul'}</strong> akan dikirim ke antrean review
                Senator Penalis.
              </p>
              <p className="penalis-muted">
                Selama proses review berlangsung, karya tulis tidak dapat diubah sampai Senator memberikan keputusan
                (menyetujui untuk terbit, meminta revisi, atau menolak).
              </p>
            </>
          }
          confirmLabel={canResubmit ? 'Submit Ulang' : 'Submit Review'}
          tone="warning"
          busy={saving}
          onConfirm={() => void submitForReview()}
          onCancel={closeSubmitConfirm}
        />
      ) : null}

      {leaveConfirmOpen ? (
        <ConfirmDialog
          title="Tinggalkan halaman ini?"
          description={
            <>
              <p>
                Ada perubahan yang belum disimpan pada karya tulis{' '}
                <strong>{title.trim() || 'Tanpa judul'}</strong>.
              </p>
              <p className="penalis-muted">
                Kalau kamu keluar sekarang, semua perubahan sejak penyimpanan terakhir akan hilang.
              </p>
            </>
          }
          confirmLabel="Tinggalkan"
          cancelLabel="Tetap di sini"
          tone="danger"
          onConfirm={confirmLeave}
          onCancel={closeLeaveConfirm}
        />
      ) : null}

      {shortcutsOpen ? <EditorShortcutsDialog onClose={closeShortcuts} /> : null}

      {id ? (
        <p className="penalis-fineprint">ID Karya Tulis: <strong>{id}</strong></p>
      ) : null}
    </form>
  );
}

/**
 * Replace a raw WordPress message with one the writer can act on.
 *
 * Core's wording for a denied write — "Sorry, you are not allowed to create
 * posts as this user." — reads like a ban and is in English inside an
 * Indonesian screen. It surfaces when an account loses write access while the
 * editor is open (an administrator suspends it mid-sentence), where the honest
 * answer is "this account cannot save right now", not a generic refusal.
 *
 * Authentication failures never reach here: they are answered by the recovery
 * banner instead.
 */
function describeSaveFailure(caught: unknown, fallback: string): string {
  if (!(caught instanceof ApiError)) {
    return fallback;
  }

  if (caught.code === 'rest_cannot_create') {
    return 'Akun kamu sedang tidak diizinkan menyimpan karya tulis. Hubungi Senator Penalis jika ini keliru.';
  }

  return caught.message;
}
