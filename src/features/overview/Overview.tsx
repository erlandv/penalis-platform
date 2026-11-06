/**
 * Overview: account status, article counts, and recent activity.
 */

import { useEffect, useState } from 'react';

import { articlesApi, ApiError } from '../../lib/api';
import type { Account, ArticleList } from '../../lib/types';
import { editorialStateLabel, formatDate } from '../../lib/format';
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
          setError(caught instanceof ApiError ? caught.message : 'Artikel Anda tidak dapat dimuat.');
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
          <p className="penalis-eyebrow">Selamat datang kembali</p>
          <h2>{account.displayName}</h2>
          <p className="penalis-muted">
            {account.canWrite
              ? 'Di dasbor ini kamu dapat menulis draf karya tulis, mengajukannya untuk ditinjau admin, dan memantau status penerbitan karyamu di satu tempat.'
              : 'Akun Penalis kamu saat ini belum dapat mengelola karya tulis. Kamu dapat meninjau detail akun di bawah ini. Jika ada pertanyaan, hubungi admin Penalis.'}
          </p>
        </div>
        <div className="penalis-hero__status">
          <StatusBadge status={account.status} label={account.statusLabel} />
        </div>
      </section>

      <section className="penalis-cards" aria-label="Ringkasan karya tulis">
        <StatCard label="Total karya tulis" value={counts.all} />
        <StatCard label="Diterbitkan" value={counts.publish} />
        <StatCard label="Menunggu tinjauan" value={counts.pending} />
        <StatCard label="Draf" value={counts.draft} />
      </section>

      {account.canWrite ? (
        <div className="penalis-actions-row">
          <button type="button" className="penalis-button penalis-button--primary" onClick={onOpenEditor}>
            Tulis karya tulis baru
          </button>
          <button type="button" className="penalis-button penalis-button--ghost" onClick={onOpenArticles}>
            Lihat semua karya tulis
          </button>
        </div>
      ) : null}

      <section className="penalis-panel">
        <div className="penalis-panel__header">
          <h3>Karya Tulis Terbaru</h3>
        </div>

        {!account.canWrite ? (
          <p className="penalis-muted">
            Kamu dapat mengelola karya tulismu setelah email kamu dikonfirmasi
            dan mengajuan akunmu disetujui admin.
          </p>
        ) : loading ? (
          <Spinner label="Memuat karya tulismu…" />
        ) : error ? (
          <ErrorBanner message={error} />
        ) : data && data.items.length === 0 ? (
          <EmptyState
            title="Belum ada karya"
            description="Karya tulis pertama kamu dimulai di sini. Kamu dapat menyimpan draf dan kembali kapan saja."
            action={
              <button type="button" className="penalis-button penalis-button--primary" onClick={onOpenEditor}>
                Buat karya tulis pertama
              </button>
            }
          />
        ) : (
          <ul className="penalis-article-list">
            {data?.items.slice(0, 5).map((article) => (
              <li key={article.id} className="penalis-article-row">
                <div>
                  <strong>{article.title || 'Tanpa judul'}</strong>
                  <p className="penalis-muted">{article.excerpt}</p>
                </div>
                <div className="penalis-article-row__meta">
                  <StatusBadge
                    status={article.editorialState}
                    label={article.editorialStateLabel || editorialStateLabel(article.editorialState)}
                  />
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
