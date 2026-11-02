/**
 * Article editor.
 *
 * Uses the native WordPress posts endpoint for persistence. The author is
 * always derived server-side. Content is stored as WordPress post content.
 */

import { lazy, Suspense, useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from 'react';

import { ApiError, articlesApi, mediaApi, taxonomyApi } from '../../lib/api';
import { trimTrailingEmptyParagraphs } from '../../lib/html';
import type { Article, FieldErrors, MediaItem, Term } from '../../lib/types';
import { hasErrors, validateArticle } from '../../lib/validation';
import { ErrorBanner, FieldError, Notice, Spinner, StatusBadge } from '../../components/ui';
import { ChunkErrorBoundary } from '../../components/ChunkErrorBoundary';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { Dialog } from '../../components/Dialog';
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

type SaveStatus = 'draft' | 'pending' | 'publish';

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
  const [categories, setCategories] = useState<Term[]>([]);
  const [selectedCategories, setSelectedCategories] = useState<number[]>([]);
  const [selectedTags, setSelectedTags] = useState<Term[]>([]);
  const [tagSearch, setTagSearch] = useState('');
  const [tagSuggestions, setTagSuggestions] = useState<Term[]>([]);
  const [featuredMedia, setFeaturedMedia] = useState(0);
  const [featuredPreview, setFeaturedPreview] = useState('');
  const [media, setMedia] = useState<MediaItem[]>([]);
  const [mediaOpen, setMediaOpen] = useState(false);
  const [mediaLoading, setMediaLoading] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [uploadError, setUploadError] = useState('');
  const [previewOpen, setPreviewOpen] = useState(false);

  /**
   * Publishing is irreversible from the reader's point of view — the article
   * becomes public the moment it succeeds — so it asks for confirmation first,
   * matching the publish action in the articles list.
   */
  const [publishConfirmOpen, setPublishConfirmOpen] = useState(false);

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

  const isDirty = currentSnapshot !== savedSnapshot;

  const load = useCallback(async () => {
    if (!articleId) {
      return;
    }

    setLoading(true);
    setLoadError('');

    try {
      const article: Article = await articlesApi.get(articleId);
      setId(article.id);
      setTitle(article.title);
      setContent(article.content);
      setStatus(article.status);
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
  const closeMedia = useCallback(() => setMediaOpen(false), []);
  const closePreview = useCallback(() => setPreviewOpen(false), []);
  const closePublishConfirm = useCallback(() => setPublishConfirmOpen(false), []);

  const openMediaPicker = async () => {
    setMediaOpen(true);
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

  const insertMedia = (item: MediaItem) => {
    // The rich text editor owns the caret, so insertion happens through its
    // imperative handle rather than by string-splicing the stored HTML.
    editorRef.current?.insertImage(item.url, item.title);
    setMediaOpen(false);
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

  const save = async (nextStatus: SaveStatus) => {
    setError('');
    setNotice('');

    // TipTap keeps an empty trailing paragraph after block nodes so the writer
    // can keep typing. Drop it from what is sent to WordPress.
    const payloadContent = trimTrailingEmptyParagraphs(content);

    const clientErrors = validateArticle({ title, content: payloadContent });
    if (hasErrors(clientErrors)) {
      setErrors(clientErrors);
      // Close the confirmation so the field errors are not hidden behind the
      // modal overlay; the writer needs to see which field to fix.
      setPublishConfirmOpen(false);
      return;
    }
    setErrors({});
    setSaving(true);

    try {
      const result = await articlesApi.save({
        id,
        title: title.trim(),
        content: payloadContent,
        status: nextStatus,
        categories: selectedCategories,
        tags: selectedTags.map((term) => term.id),
        featured_media: featuredMedia,
      });

      setId(result.id);
      setStatus(result.status);
      setNotice(nextStatus === 'publish' ? 'Artikel Anda telah diterbitkan.' : 'Perubahan Anda telah disimpan.');
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
      setPublishConfirmOpen(false);
      onSaved(result.id);
    } catch (caught) {
      if (caught instanceof ApiError) {
        setErrors(caught.fields);
        setError(caught.message);
      } else {
        setError('Artikel tidak dapat disimpan.');
      }
      // Surface the failure instead of leaving it behind the modal overlay.
      setPublishConfirmOpen(false);
    } finally {
      setSaving(false);
    }
  };

  const submit = (event: FormEvent) => {
    event.preventDefault();
    void save('draft');
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
          Kembali ke karya tulis
        </button>
        <div className="penalis-editor-heading__title">
          <h2>{id ? 'Edit Karya Tulis' : 'Karya Tulis Baru'}</h2>
          <StatusBadge status={status} />
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
              Menyimpan…
            </>
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
          <button
            type="button"
            className="penalis-button penalis-button--soft"
            onClick={() => void save(status === 'publish' ? 'publish' : 'draft')}
            disabled={saving || !isDirty}
          >
            {saving ? 'Menyimpan…' : status === 'publish' ? 'Simpan perubahan' : 'Simpan draf'}
          </button>
          {/*
            The publish slot is always rendered so the action group keeps the
            same width in every state; it is disabled rather than removed once
            the article is already published.
          */}
          <button
            type="button"
            className="penalis-button penalis-button--primary"
            onClick={() => setPublishConfirmOpen(true)}
            disabled={saving || status === 'publish'}
            title={status === 'publish' ? 'Karya tulis ini sudah terbit' : undefined}
          >
            Terbitkan
          </button>
        </div>
      </div>

      {error ? <ErrorBanner message={error} /> : null}
      {notice ? <Notice tone="success">{notice}</Notice> : null}

      <div className="penalis-editor">
        <div className="penalis-editor__main">
          <label className="penalis-field">
            <span>Judul Karya Tulis</span>
            <input
              type="text"
              value={title}
              onChange={(event) => setTitle(event.target.value)}
              maxLength={200}
              placeholder="Judul karya kamu"
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
                  onRequestImage={() => void openMediaPicker()}
                  invalid={Boolean(errors.content)}
                  errorId={errors.content ? 'content-error' : undefined}
                  labelledBy="penalis-content-label"
                />
              </Suspense>
            </ChunkErrorBoundary>
            <FieldError errors={errors} name="content" />
          </div>
        </div>

        <aside className="penalis-editor__side">
          <section className="penalis-panel">
            <div className="penalis-panel__header">
              <h3>Gambar unggulan</h3>
            </div>

            {featuredMedia > 0 ? (
              <div className="penalis-featured">
                {featuredPreview ? <img src={featuredPreview} alt="" /> : null}
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
              </div>
            ) : (
              <p className="penalis-muted">Belum ada gambar unggulan yang dipilih.</p>
            )}

            <button type="button" className="penalis-button penalis-button--ghost" onClick={() => void openMediaPicker()}>
              Pilih dari pustaka media
            </button>
          </section>

          <section className="penalis-panel">
            <div className="penalis-panel__header">
              <h3>Kategori</h3>
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
              <h3>Tag</h3>
            </div>

            <div className="penalis-tag-list">
              {selectedTags.map((term) => (
                <span key={term.id} className="penalis-chip">
                  {term.name}
                  <button
                    type="button"
                    aria-label={`Hapus ${term.name}`}
                    onClick={() => setSelectedTags((current) => current.filter((item) => item.id !== term.id))}
                  >
                    ×
                  </button>
                </span>
              ))}
            </div>

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
          </section>
        </aside>
      </div>

      {mediaOpen ? (
        <Dialog label="Pustaka media" onClose={closeMedia}>
          <div className="penalis-modal__content">
            <div className="penalis-modal__header">
              <h3>Pustaka media</h3>
              <button type="button" className="penalis-button penalis-button--ghost" onClick={closeMedia}>
                Tutup
              </button>
            </div>

            <label className="penalis-upload">
              <span>{uploading ? 'Mengunggah…' : 'Unggah gambar'}</span>
              <input
                type="file"
                accept="image/jpeg,image/png,image/gif,image/webp"
                disabled={uploading}
                onChange={(event) => {
                  const file = event.target.files?.[0];
                  if (file) {
                    void uploadImage(file);
                  }
                  event.target.value = '';
                }}
              />
              <small>JPEG, PNG, GIF, atau WebP. Jenis file lain ditolak oleh server.</small>
            </label>

            {uploadError ? <ErrorBanner message={uploadError} /> : null}

            {mediaLoading ? (
              <Spinner label="Memuat media…" />
            ) : media.length === 0 ? (
              <p className="penalis-muted">
                Anda belum mengunggah media apa pun. Gunakan kontrol unggah di atas untuk menambahkan gambar pertama.
              </p>
            ) : (
              <div className="penalis-media-grid">
                {media.map((item) => (
                  <figure key={item.id} className="penalis-media-card">
                    {item.thumbnail ? <img src={item.thumbnail} alt={item.title} /> : null}
                    <figcaption>{item.title}</figcaption>
                    <div className="penalis-row-actions">
                      <button
                        type="button"
                        className="penalis-button penalis-button--ghost"
                        onClick={() => {
                          setFeaturedMedia(item.id);
                          setFeaturedPreview(item.thumbnail);
                          setMediaOpen(false);
                        }}
                      >
                        Jadikan unggulan
                      </button>
                      <button
                        type="button"
                        className="penalis-button penalis-button--ghost"
                        onClick={() => insertMedia(item)}
                      >
                        Sisipkan
                      </button>
                    </div>
                  </figure>
                ))}
              </div>
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
                {status === 'publish' ? (
                  <a
                    className="penalis-button penalis-button--ghost"
                    href={`${window.location.origin}/?p=${id ?? ''}`}
                    target="_blank"
                    rel="noreferrer noopener"
                  >
                    Buka artikel terbit
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
              title="Pratinjau artikel"
              className="penalis-preview-frame"
              sandbox=""
              srcDoc={previewDocument}
            />
          </div>
        </Dialog>
      ) : null}

      {publishConfirmOpen ? (
        <ConfirmDialog
          title="Terbitkan karya tulis?"
          description={
            <>
              <p>
                <strong>{title.trim() || 'Tanpa judul'}</strong> akan langsung tampil di situs dan dapat
                dibaca siapa saja.
              </p>
              <p className="penalis-muted">
                Kamu masih bisa membatalkan terbit kapan saja jika ada yang perlu diperbaiki.
              </p>
            </>
          }
          confirmLabel="Terbitkan"
          tone="warning"
          busy={saving}
          onConfirm={() => void save('publish')}
          onCancel={closePublishConfirm}
        />
      ) : null}

      {id ? (
        <p className="penalis-fineprint">ID artikel {id}</p>
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
