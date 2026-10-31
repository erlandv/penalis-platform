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
import { Dialog } from '../../components/Dialog';
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

  const editorRef = useRef<RichTextEditorHandle | null>(null);

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
      onSaved(result.id);
    } catch (caught) {
      if (caught instanceof ApiError) {
        setErrors(caught.fields);
        setError(caught.message);
      } else {
        setError('Artikel tidak dapat disimpan.');
      }
    } finally {
      setSaving(false);
    }
  };

  const submit = (event: FormEvent) => {
    event.preventDefault();
    void save('draft');
  };

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
        <button type="button" className="penalis-button penalis-button--ghost" onClick={onCancel}>
          Kembali ke artikel
        </button>
      </div>
    );
  }

  return (
    <form className="penalis-stack" onSubmit={submit} noValidate>
      <div className="penalis-page-heading">
        <div>
          <h2>{id ? 'Ubah artikel' : 'Artikel baru'}</h2>
          <p className="penalis-muted">
            {id ? (
              <>
                Status: <StatusBadge status={status} />
              </>
            ) : (
              'Simpan draf kapan saja. Anda dapat menerbitkan saat artikel sudah siap.'
            )}
          </p>
        </div>
        <div className="penalis-row-actions">
          <button type="button" className="penalis-button penalis-button--ghost" onClick={onCancel}>
            Batal
          </button>
          <button type="button" className="penalis-button penalis-button--ghost" onClick={() => setPreviewOpen(true)}>
            Pratinjau
          </button>
          <button
            type="button"
            className="penalis-button penalis-button--ghost"
            onClick={() => void save(status === 'publish' ? 'publish' : 'draft')}
            disabled={saving}
          >
            {saving ? 'Menyimpan…' : status === 'publish' ? 'Simpan perubahan' : 'Simpan draf'}
          </button>
          {status !== 'publish' ? (
            <button
              type="button"
              className="penalis-button penalis-button--primary"
              onClick={() => void save('publish')}
              disabled={saving}
            >
              Terbitkan
            </button>
          ) : null}
        </div>
      </div>

      {error ? <ErrorBanner message={error} /> : null}
      {notice ? <Notice tone="success">{notice}</Notice> : null}

      <div className="penalis-editor">
        <div className="penalis-editor__main">
          <label className="penalis-field">
            <span>Judul</span>
            <input
              type="text"
              value={title}
              onChange={(event) => setTitle(event.target.value)}
              maxLength={200}
              placeholder="Judul artikel Anda"
              aria-invalid={Boolean(errors.title)}
            />
            <FieldError errors={errors} name="title" />
          </label>

          <div className="penalis-field">
            {/* A contenteditable div cannot be labelled with htmlFor, so the
                visible label is referenced through aria-labelledby instead. */}
            <span className="penalis-field-label" id="penalis-content-label">
              Isi artikel
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
                    aria-label="Isi artikel dalam HTML"
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
