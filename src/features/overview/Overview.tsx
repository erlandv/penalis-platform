/**
 * Overview: account status, article counts, and recent activity.
 */

import { useEffect, useState } from 'react';

import { articlesApi, ApiError } from '../../lib/api';
import type { Account, ArticleList } from '../../lib/types';
import { formatDate } from '../../lib/format';
import { EmptyState, ErrorBanner, Spinner, StatusBadge } from '../../components/ui';

export function Overview({
  account,
  onOpenEditor,
  onOpenArticles,
}: {
  account: Account;
  onOpenEditor: () => void;
  onOpenArticles: () => void;
}) {
  const [data, setData] = useState<ArticleList | null>(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(account.canWrite);

  useEffect(() => {
    if (!account.canWrite) {
      return;
    }

    let cancelled = false;
    setLoading(true);
    setError('');

    articlesApi
      .list(1, 'all')
      .then((result) => {
        if (!cancelled) {
          setData(result);
        }
      })
      .catch((caught: unknown) => {
        if (!cancelled) {
          setError(caught instanceof ApiError ? caught.message : 'Your articles could not be loaded.');
        }
      })
      .finally(() => {
        if (!cancelled) {
          setLoading(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [account.canWrite]);

  const counts = data?.counts ?? account.counts;

  return (
    <div className="penalis-stack">
      <section className="penalis-hero">
        <div>
          <p className="penalis-eyebrow">Welcome back</p>
          <h2>{account.displayName}</h2>
          <p className="penalis-muted">
            {account.canWrite
              ? 'Your writing workspace is ready. Draft an article, add a featured image, and publish when you are happy with it.'
              : 'Your account is not yet able to publish. You can review your account details below.'}
          </p>
        </div>
        <div className="penalis-hero__status">
          <StatusBadge status={account.status} label={account.statusLabel} />
        </div>
      </section>

      <section className="penalis-cards" aria-label="Article summary">
        <StatCard label="Total articles" value={counts.all} />
        <StatCard label="Published" value={counts.publish} />
        <StatCard label="Drafts" value={counts.draft} />
        <StatCard label="Pending review" value={counts.pending} />
      </section>

      {account.canWrite ? (
        <div className="penalis-actions-row">
          <button type="button" className="penalis-button penalis-button--primary" onClick={onOpenEditor}>
            Write a new article
          </button>
          <button type="button" className="penalis-button penalis-button--ghost" onClick={onOpenArticles}>
            View all articles
          </button>
        </div>
      ) : null}

      <section className="penalis-panel">
        <div className="penalis-panel__header">
          <h3>Recent articles</h3>
        </div>

        {!account.canWrite ? (
          <p className="penalis-muted">
            Article management becomes available after your email is confirmed and an administrator approves your
            account.
          </p>
        ) : loading ? (
          <Spinner label="Loading your articles…" />
        ) : error ? (
          <ErrorBanner message={error} />
        ) : data && data.items.length === 0 ? (
          <EmptyState
            title="No articles yet"
            description="Your first article starts here. You can save a draft and come back to it at any time."
            action={
              <button type="button" className="penalis-button penalis-button--primary" onClick={onOpenEditor}>
                Write your first article
              </button>
            }
          />
        ) : (
          <ul className="penalis-article-list">
            {data?.items.slice(0, 5).map((article) => (
              <li key={article.id} className="penalis-article-row">
                <div>
                  <strong>{article.title || 'Untitled'}</strong>
                  <p className="penalis-muted">{article.excerpt}</p>
                </div>
                <div className="penalis-article-row__meta">
                  <StatusBadge status={article.status} />
                  <span>{formatDate(article.modified)}</span>
                </div>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}

function StatCard({ label, value }: { label: string; value: number }) {
  return (
    <div className="penalis-card">
      <span className="penalis-card__value">{value}</span>
      <span className="penalis-card__label">{label}</span>
    </div>
  );
}
