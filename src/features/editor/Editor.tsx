/**
 * Article editor.
 *
 * Uses the native WordPress posts endpoint for persistence. The author is
 * always derived server-side. Content is stored as WordPress post content.
 */

import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from 'react';

import { ApiError, articlesApi, mediaApi, taxonomyApi } from '../../lib/api';
import type { Article, FieldErrors, MediaItem, Term } from '../../lib/types';
import { hasErrors, validateArticle } from '../../lib/validation';
import { ErrorBanner, FieldError, Notice, Spinner, StatusBadge } from '../../components/ui';

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
  const [excerpt, setExcerpt] = useState('');
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

  const textareaRef = useRef<HTMLTextAreaElement | null>(null);

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
      setExcerpt(article.excerpt);
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
      setLoadError(caught instanceof ApiError ? caught.message : 'This article could not be loaded.');
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
      setUploadError(caught instanceof ApiError ? caught.message : 'The image could not be uploaded.');
    } finally {
      setUploading(false);
    }
  };

  const insertMedia = (item: MediaItem) => {
    const textarea = textareaRef.current;
    const markup = `<img src="${item.url}" alt="${escapeAttribute(item.title)}" />`;

    if (!textarea) {
      setContent((current) => `${current}\n${markup}\n`);
      return;
    }

    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const next = `${content.slice(0, start)}${markup}${content.slice(end)}`;
    setContent(next);

    window.setTimeout(() => {
      textarea.focus();
      const caret = start + markup.length;
      textarea.setSelectionRange(caret, caret);
    }, 0);
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
      setError(caught instanceof ApiError ? caught.message : 'The tag could not be created.');
    }
  };

  const save = async (nextStatus: SaveStatus) => {
    setError('');
    setNotice('');

    const clientErrors = validateArticle({ title, content });
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
        content,
        excerpt,
        status: nextStatus,
        categories: selectedCategories,
        tags: selectedTags.map((term) => term.id),
        featured_media: featuredMedia,
      });

      setId(result.id);
      setStatus(result.status);
      setNotice(nextStatus === 'publish' ? 'Your article has been published.' : 'Your changes have been saved.');
      onSaved(result.id);
    } catch (caught) {
      if (caught instanceof ApiError) {
        setErrors(caught.fields);
        setError(caught.message);
      } else {
        setError('The article could not be saved.');
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
    </style></head><body><h1>${escapeHtml(title || 'Untitled')}</h1>${content}</body></html>`;
  }, [content, title]);

  if (loading) {
    return <Spinner label="Loading article…" />;
  }

  if (loadError) {
    return (
      <div className="penalis-stack">
        <ErrorBanner message={loadError} onRetry={() => void load()} />
        <button type="button" className="penalis-button penalis-button--ghost" onClick={onCancel}>
          Back to articles
        </button>
      </div>
    );
  }

  return (
    <form className="penalis-stack" onSubmit={submit} noValidate>
      <div className="penalis-page-heading">
        <div>
          <h2>{id ? 'Edit article' : 'New article'}</h2>
          <p className="penalis-muted">
            {id ? (
              <>
                Status: <StatusBadge status={status} />
              </>
            ) : (
              'Save a draft at any time. You can publish when the article is ready.'
            )}
          </p>
        </div>
        <div className="penalis-row-actions">
          <button type="button" className="penalis-button penalis-button--ghost" onClick={onCancel}>
            Cancel
          </button>
          <button type="button" className="penalis-button penalis-button--ghost" onClick={() => setPreviewOpen(true)}>
            Preview
          </button>
          <button
            type="button"
            className="penalis-button penalis-button--ghost"
            onClick={() => void save(status === 'publish' ? 'publish' : 'draft')}
            disabled={saving}
          >
            {saving ? 'Saving…' : status === 'publish' ? 'Save changes' : 'Save draft'}
          </button>
          {status !== 'publish' ? (
            <button
              type="button"
              className="penalis-button penalis-button--primary"
              onClick={() => void save('publish')}
              disabled={saving}
            >
              Publish
            </button>
          ) : null}
        </div>
      </div>

      {error ? <ErrorBanner message={error} /> : null}
      {notice ? <Notice tone="success">{notice}</Notice> : null}

      <div className="penalis-editor">
        <div className="penalis-editor__main">
          <label className="penalis-field">
            <span>Title</span>
            <input
              type="text"
              value={title}
              onChange={(event) => setTitle(event.target.value)}
              maxLength={200}
              placeholder="Your article title"
              aria-invalid={Boolean(errors.title)}
            />
            <FieldError errors={errors} name="title" />
          </label>

          <label className="penalis-field">
            <span>Article content</span>
            <textarea
              ref={textareaRef}
              value={content}
              onChange={(event) => setContent(event.target.value)}
              rows={18}
              placeholder="Write your article. Basic HTML is supported."
              aria-invalid={Boolean(errors.content)}
            />
            <FieldError errors={errors} name="content" />
            <small>
              Basic HTML is supported. Use the media panel to insert an image at the cursor.
            </small>
          </label>

          <label className="penalis-field">
            <span>Excerpt (optional)</span>
            <textarea
              value={excerpt}
              onChange={(event) => setExcerpt(event.target.value)}
              rows={3}
              maxLength={300}
              placeholder="A short summary used on archive pages."
            />
          </label>
        </div>

        <aside className="penalis-editor__side">
          <section className="penalis-panel">
            <div className="penalis-panel__header">
              <h3>Featured image</h3>
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
                  Remove
                </button>
              </div>
            ) : (
              <p className="penalis-muted">No featured image selected.</p>
            )}

            <button type="button" className="penalis-button penalis-button--ghost" onClick={() => void openMediaPicker()}>
              Choose from media library
            </button>
          </section>

          <section className="penalis-panel">
            <div className="penalis-panel__header">
              <h3>Categories</h3>
            </div>

            {categories.length === 0 ? (
              <p className="penalis-muted">No categories are available.</p>
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
              <h3>Tags</h3>
            </div>

            <div className="penalis-tag-list">
              {selectedTags.map((term) => (
                <span key={term.id} className="penalis-chip">
                  {term.name}
                  <button
                    type="button"
                    aria-label={`Remove ${term.name}`}
                    onClick={() => setSelectedTags((current) => current.filter((item) => item.id !== term.id))}
                  >
                    ×
                  </button>
                </span>
              ))}
            </div>

            <label className="penalis-field">
              <span>Add a tag</span>
              <input
                type="text"
                value={tagSearch}
                onChange={(event) => setTagSearch(event.target.value)}
                placeholder="Search or create a tag"
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
                Create “{tagSearch.trim()}”
              </button>
            ) : null}
          </section>
        </aside>
      </div>

      {mediaOpen ? (
        <div className="penalis-modal" role="dialog" aria-modal="true" aria-label="Media library">
          <div className="penalis-modal__content">
            <div className="penalis-modal__header">
              <h3>Media library</h3>
              <button type="button" className="penalis-button penalis-button--ghost" onClick={() => setMediaOpen(false)}>
                Close
              </button>
            </div>

            <label className="penalis-upload">
              <span>{uploading ? 'Uploading…' : 'Upload an image'}</span>
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
              <small>JPEG, PNG, GIF, or WebP. Other file types are rejected by the server.</small>
            </label>

            {uploadError ? <ErrorBanner message={uploadError} /> : null}

            {mediaLoading ? (
              <Spinner label="Loading media…" />
            ) : media.length === 0 ? (
              <p className="penalis-muted">
                You have not uploaded any media yet. Use the upload control above to add your first image.
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
                        Feature
                      </button>
                      <button
                        type="button"
                        className="penalis-button penalis-button--ghost"
                        onClick={() => insertMedia(item)}
                      >
                        Insert
                      </button>
                    </div>
                  </figure>
                ))}
              </div>
            )}
          </div>
        </div>
      ) : null}

      {previewOpen ? (
        <div className="penalis-modal" role="dialog" aria-modal="true" aria-label="Article preview">
          <div className="penalis-modal__content penalis-modal__content--wide">
            <div className="penalis-modal__header">
              <h3>Preview</h3>
              <div className="penalis-row-actions">
                {status === 'publish' ? (
                  <a
                    className="penalis-button penalis-button--ghost"
                    href={`${window.location.origin}/?p=${id ?? ''}`}
                    target="_blank"
                    rel="noreferrer noopener"
                  >
                    Open live article
                  </a>
                ) : null}
                <button
                  type="button"
                  className="penalis-button penalis-button--ghost"
                  onClick={() => setPreviewOpen(false)}
                >
                  Close
                </button>
              </div>
            </div>
            <iframe
              title="Article preview"
              className="penalis-preview-frame"
              sandbox=""
              srcDoc={previewDocument}
            />
          </div>
        </div>
      ) : null}

      {id ? (
        <p className="penalis-fineprint">Article ID {id}</p>
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

function escapeAttribute(value: string): string {
  return escapeHtml(value);
}
