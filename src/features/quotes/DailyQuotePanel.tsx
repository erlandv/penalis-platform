/**
 * #KalimatHariIni composer and active-quote card on the writer Overview.
 *
 * Active writers may keep one short sentence (up to 200 characters, 3 lines)
 * live for 24 hours on their author archive and on `/kalimat-hari-ini/`. They
 * may delete and republish within 24 hours up to a rolling limit of 3 publishes.
 */

import { useCallback, useEffect, useId, useRef, useState, type FormEvent } from 'react';

import { ApiError, quotesApi } from '../../lib/api';
import { getBootstrap } from '../../lib/bootstrap';
import {
  buildQuoteShareText,
  copyToClipboard,
  countCodePoints,
  DAILY_QUOTE_MAX_LENGTH,
  DAILY_QUOTE_MAX_LINES,
  dailyQuoteLineCount,
  formatQuoteRemainingTime,
  normalizeDailyQuote,
  validateDailyQuoteInput,
} from '../../lib/dailyQuote';
import { formatDateTime } from '../../lib/format';
import type { Account, WriterDailyQuoteState } from '../../lib/types';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { Icon } from '../../components/icons';
import { useToast } from '../../components/toastContext';
import { ErrorBanner, Notice, Panel, Spinner, StatusBadge, VerifiedWriterBadge } from '../../components/ui';

export function DailyQuotePanel({ account }: { account: Account }) {
  const showToast = useToast();
  const inputId = useId();
  const hintId = useId();
  const errorId = useId();
  const sectionRef = useRef<HTMLDivElement | null>(null);

  const [state, setState] = useState<WriterDailyQuoteState | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState('');

  const [draft, setDraft] = useState('');
  const [fieldError, setFieldError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const [confirmDelete, setConfirmDelete] = useState(false);
  const [deleting, setDeleting] = useState(false);

  const loadState = useCallback(async () => {
    setLoading(true);
    setLoadError('');
    try {
      const result = await quotesApi.me();
      setState(result);
    } catch (caught: unknown) {
      setLoadError(
        caught instanceof ApiError
          ? caught.message
          : '#KalimatHariIni tidak dapat dimuat saat ini.',
      );
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadState();
  }, [loadState]);

  // Honor `#kalimat-hari-ini` deep-links from the author profile or public showcase.
  useEffect(() => {
    if (!loading && window.location.hash === '#kalimat-hari-ini' && sectionRef.current) {
      sectionRef.current.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
  }, [loading]);

  const maxLength = state?.maxLength ?? DAILY_QUOTE_MAX_LENGTH;
  const maxLines = state?.maxLines ?? DAILY_QUOTE_MAX_LINES;
  const publicUrl = state?.publicUrl || getBootstrap().dailyQuotesUrl || '';

  const normalizedDraft = normalizeDailyQuote(draft);
  const charCount = countCodePoints(normalizedDraft);
  const lineCount = dailyQuoteLineCount(normalizedDraft);
  const isOverLength = charCount > maxLength;
  const isOverLines = lineCount > maxLines;

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault();
    if (submitting) {
      return;
    }

    const clientError = validateDailyQuoteInput(draft);
    if (clientError) {
      setFieldError(clientError);
      return;
    }

    setSubmitting(true);
    setFieldError('');

    try {
      const updated = await quotesApi.publish(draft);
      setState(updated);
      setDraft('');
      showToast('#KalimatHariIni kamu kini tayang selama 24 jam.');
    } catch (caught: unknown) {
      if (caught instanceof ApiError) {
        setFieldError(caught.fields.text ?? caught.message);
        if (caught.status === 409 || caught.status === 429) {
          void loadState();
        }
      } else {
        setFieldError('#KalimatHariIni tidak dapat diterbitkan. Silakan coba lagi.');
      }
    } finally {
      setSubmitting(false);
    }
  };

  const handleDelete = async () => {
    if (deleting) {
      return;
    }

    setDeleting(true);
    try {
      const updated = await quotesApi.remove();
      setState(updated);
      setConfirmDelete(false);
      showToast('#KalimatHariIni dihapus.');
    } catch (caught: unknown) {
      showToast(
        caught instanceof ApiError ? caught.message : '#KalimatHariIni tidak dapat dihapus.',
        'danger',
      );
    } finally {
      setDeleting(false);
    }
  };

  const handleCopy = async () => {
    if (!state?.quote) {
      return;
    }

    const copied = await copyToClipboard(
      buildQuoteShareText(state.quote.text, account.displayName, account.authorUrl || publicUrl),
    );
    showToast(copied ? 'Kutipan disalin ke clipboard.' : 'Gagal menyalin kutipan.', copied ? 'success' : 'danger');
  };

  const quota = state?.quota;
  const activeQuote = state?.quote ?? null;

  return (
    <div id="kalimat-hari-ini" ref={sectionRef}>
      <Panel
        label="Kalimat Hari Ini"
        header={
          <>
            <div className="penalis-daily-quote-panel__title-group">
              <h3>#KalimatHariIni</h3>
              {quota ? (
                <StatusBadge
                  status={activeQuote ? 'active' : quota.remaining > 0 ? 'draft' : 'pending_review'}
                  label={
                    activeQuote
                      ? 'Sedang tayang'
                      : `Kuota ${quota.remaining}/${quota.limit}`
                  }
                />
              ) : null}
            </div>
            {publicUrl ? (
              <a
                href={publicUrl}
                target="_blank"
                rel="noopener noreferrer"
                className="penalis-button penalis-button--ghost penalis-daily-quote-panel__link"
              >
                <span>Lihat semua</span>
                <Icon name="externalLink" />
              </a>
            ) : null}
          </>
        }
      >
        {loading ? (
          <Spinner label="Memuat #KalimatHariIni…" />
        ) : loadError ? (
          <ErrorBanner message={loadError} onRetry={() => void loadState()} />
        ) : activeQuote ? (
          <div className="penalis-daily-quote-active">
            <div className="penalis-daily-quote-note">
              <div className="penalis-daily-quote-note__head">
                {account.avatarUrl ? (
                  <img
                    src={account.avatarUrl}
                    alt=""
                    className="penalis-daily-quote-note__avatar"
                    width={34}
                    height={34}
                    aria-hidden="true"
                  />
                ) : null}
                <div className="penalis-daily-quote-note__identity">
                  <span className="penalis-daily-quote-note__name">
                    <span>{account.displayName}</span>
                    <VerifiedWriterBadge account={account} size={14} />
                  </span>
                  <span className="penalis-daily-quote-note__submeta">
                    @{account.username} · {formatQuoteRemainingTime(activeQuote.expiresAt)}
                  </span>
                </div>
              </div>
              <p className="penalis-daily-quote-note__text">{activeQuote.text}</p>
              <span className="penalis-daily-quote-hashtag">#KalimatHariIni</span>
            </div>

            <div className="penalis-daily-quote-active__meta">
              <span className="penalis-muted">Tayang di profilmu dan halaman publik selama 24 jam.</span>
              {quota ? (
                <span className="penalis-daily-quote-panel__quota-note">
                  Sisa kuota 24 jam: {quota.remaining} dari {quota.limit}
                </span>
              ) : null}
            </div>

            <div className="penalis-daily-quote-active__actions">
              <button
                type="button"
                className="penalis-button penalis-button--ghost"
                onClick={() => void handleCopy()}
              >
                Salin kutipan
              </button>
              <button
                type="button"
                className="penalis-button penalis-button--danger"
                onClick={() => setConfirmDelete(true)}
              >
                <Icon name="trash" />
                <span>Hapus kalimat</span>
              </button>
            </div>
          </div>
        ) : quota && quota.remaining <= 0 ? (
          <Notice tone="warning">
            Kamu sudah menggunakan seluruh kuota {quota.limit} kali menerbitkan #KalimatHariIni dalam 24
            jam terakhir.
            {quota.resetAt > 0
              ? ` Kuota berikutnya tersedia pada ${formatDateTime(quota.resetAt)}.`
              : ''}
          </Notice>
        ) : (
          <form className="penalis-daily-quote-form" onSubmit={(event) => void handleSubmit(event)} noValidate>
            <div className="penalis-field">
              <label htmlFor={inputId}>Bagikan satu kalimat hari ini</label>
              <textarea
                id={inputId}
                rows={3}
                value={draft}
                onChange={(event) => {
                  setDraft(event.target.value);
                  if (fieldError) {
                    setFieldError('');
                  }
                }}
                placeholder="Misalnya: Kita gak punya apa-apa selain semangat"
                disabled={submitting}
                aria-invalid={fieldError !== '' || isOverLength || isOverLines}
                aria-describedby={fieldError ? `${errorId} ${hintId}` : hintId}
              />

              <div className="penalis-daily-quote-form__bar">
                <p id={hintId} className="penalis-fineprint">
                  Tayang selama 24 jam di profilmu dan halaman #KalimatHariIni. Maksimal {maxLength}{' '}
                  karakter ({maxLines} baris), hingga {quota?.limit ?? 3} kali dalam 24 jam.
                </p>
                <span
                  className={`penalis-daily-quote-form__counter${isOverLength || isOverLines ? ' is-over' : ''}`}
                  aria-live="polite"
                >
                  {charCount}/{maxLength}
                  {isOverLines ? ` · ${lineCount}/${maxLines} baris` : ''}
                </span>
              </div>

              {fieldError ? (
                <p id={errorId} className="penalis-field-error" role="alert">
                  {fieldError}
                </p>
              ) : null}
            </div>

            {normalizedDraft !== '' ? (
              <div className="penalis-daily-quote-note penalis-daily-quote-note--preview" aria-label="Pratinjau catatan">
                <div className="penalis-daily-quote-note__head">
                  {account.avatarUrl ? (
                    <img
                      src={account.avatarUrl}
                      alt=""
                      className="penalis-daily-quote-note__avatar"
                      width={32}
                      height={32}
                      aria-hidden="true"
                    />
                  ) : null}
                  <div className="penalis-daily-quote-note__identity">
                    <span className="penalis-daily-quote-note__name">
                      <span>{account.displayName}</span>
                      <VerifiedWriterBadge account={account} size={14} />
                    </span>
                    <span className="penalis-daily-quote-note__submeta">@{account.username} · Preview</span>
                  </div>
                </div>
                <p className="penalis-daily-quote-note__text">{normalizedDraft}</p>
                <span className="penalis-daily-quote-hashtag">#KalimatHariIni</span>
              </div>
            ) : null}

            <div className="penalis-daily-quote-form__actions">
              <button
                type="submit"
                className="penalis-button penalis-button--primary"
                disabled={submitting || normalizedDraft === '' || isOverLength || isOverLines}
              >
                {submitting ? 'Menerbitkan…' : 'Terbitkan #KalimatHariIni'}
              </button>
            </div>
          </form>
        )}
      </Panel>

      {confirmDelete ? (
        <ConfirmDialog
          title="Hapus #KalimatHariIni?"
          description={
            <p>
              Kalimat ini akan langsung hilang dari halaman profilmu dan halaman publik #KalimatHariIni.
              {quota && quota.remaining > 0
                ? ` Kamu masih memiliki sisa ${quota.remaining} kuota untuk membuat kalimat baru dalam 24 jam.`
                : ' Kuota 3 kali penerbitan dalam 24 jam sudah terpakai; menghapus kalimat ini tidak mengembalikan kuota.'}
            </p>
          }
          confirmLabel="Ya, hapus kalimat"
          tone="danger"
          busy={deleting}
          onConfirm={() => void handleDelete()}
          onCancel={() => setConfirmDelete(false)}
        />
      ) : null}
    </div>
  );
}
