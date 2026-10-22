/**
 * Paginated list of the authenticated writer's own articles.
 */

import { useCallback, useEffect, useState } from 'react';

import { ApiError, articlesApi } from '../../lib/api';
import type { ArticleList } from '../../lib/types';
import { formatDate, truncate } from '../../lib/format';
import {
  EmptyState,
  ErrorBanner,
  Notice,
  Pagination,
  Spinner,
  StatusBadge,
} from '../../components/ui';

const FILTERS = [
  { value: 'all', label: 'All' },
  { value: 'publish', label: 'Published' },
  { value: 'draft', label: 'Drafts' },
  { value: 'pending', label: 'Pending review' },
];

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

  const load = useCallback(async () => {
    setLoading(true);
    setError('');

    try {
      const result = await articlesApi.list(page, status);
      setData(result);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Your articles could not be loaded.');
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

  const publish = async (id: number) => {
    setBusyId(id);
    setError('');
    setNotice('');
    try {
      await articlesApi.publish(id);
      setNotice('Article published.');
      await load();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'The article could not be published.');
    } finally {
      setBusyId(null);
    }
  };

  const unpublish = async (id: number) => {
    setBusyId(id);
    setError('');
    setNotice('');
    try {
      await articlesApi.unpublish(id);
      setNotice('Article returned to drafts.');
      await load();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'The article could not be unpublished.');
    } finally {
      setBusyId(null);
    }
  };

  const remove = async (id: number) => {
    if (!window.confirm('Delete this article permanently? This cannot be undone.')) {
      return;
    }

    setBusyId(id);
    setError('');
    setNotice('');
    try {
      await articlesApi.remove(id);
      setNotice('Article deleted.');
      await load();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'The article could not be deleted.');
    } finally {
      setBusyId(null);
    }
  };

  return (
    <div className="penalis-stack">
      <div className="penalis-page-heading">
        <div>
          <h2>My articles</h2>
          <p className="penalis-muted">Only your own articles appear here.</p>
        </div>
        <button type="button" className="penalis-button penalis-button--primary" onClick={onCreate}>
          New article
        </button>
      </div>

      <div className="penalis-tabs" role="tablist" aria-label="Filter by status">
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
        <Spinner label="Loading articles…" />
      ) : data && data.items.length === 0 ? (
        <EmptyState
          title="Nothing here yet"
          description={
            status === 'all'
              ? 'You have not written any articles yet.'
              : `You have no articles with the “${status}” status.`
          }
          action={
            <button type="button" className="penalis-button penalis-button--primary" onClick={onCreate}>
              Write an article
            </button>
          }
        />
      ) : (
        <div className="penalis-table-wrap">
          <table className="penalis-table">
            <thead>
              <tr>
                <th scope="col">Title</th>
                <th scope="col">Status</th>
                <th scope="col">Modified</th>
                <th scope="col">Actions</th>
              </tr>
            </thead>
            <tbody>
              {data?.items.map((article) => (
                <tr key={article.id}>
                  <td>
                    <strong>{article.title || 'Untitled'}</strong>
                    <p className="penalis-muted">{truncate(article.excerpt, 100)}</p>
                  </td>
                  <td>
                    <StatusBadge status={article.status} />
                  </td>
                  <td>{formatDate(article.modified)}</td>
                  <td>
                    <div className="penalis-row-actions">
                      <button
                        type="button"
                        className="penalis-button penalis-button--ghost"
                        onClick={() => onEdit(article.id)}
                        disabled={busyId === article.id}
                      >
                        Edit
                      </button>

                      {article.link ? (
                        <a
                          className="penalis-button penalis-button--ghost"
                          href={article.link}
                          target="_blank"
                          rel="noreferrer noopener"
                        >
                          View
                        </a>
                      ) : null}

                      {article.status === 'publish' ? (
                        <button
                          type="button"
                          className="penalis-button penalis-button--ghost"
                          onClick={() => void unpublish(article.id)}
                          disabled={busyId === article.id}
                        >
                          Unpublish
                        </button>
                      ) : (
                        <button
                          type="button"
                          className="penalis-button penalis-button--ghost"
                          onClick={() => void publish(article.id)}
                          disabled={busyId === article.id}
                        >
                          Publish
                        </button>
                      )}

                      <button
                        type="button"
                        className="penalis-button penalis-button--danger"
                        onClick={() => void remove(article.id)}
                        disabled={busyId === article.id}
                      >
                        Delete
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {data ? <Pagination page={data.page} totalPages={data.totalPages} onChange={setPage} /> : null}
    </div>
  );
}
