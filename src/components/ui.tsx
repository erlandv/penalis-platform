/**
 * Shared UI primitives.
 */

import { forwardRef, type ReactNode } from 'react';

import { Icon, VerifiedBadgeIcon } from './icons';
import type { Account, FieldErrors } from '../lib/types';
import { statusLabel, statusTone } from '../lib/format';

export function Spinner({ label = 'Memuat…' }: { label?: string }) {
  return (
    <div className="penalis-loading" role="status" aria-live="polite">
      <span className="penalis-spinner" aria-hidden="true" />
      <span>{label}</span>
    </div>
  );
}

export function ErrorBanner({
  message,
  onRetry,
}: {
  message: string;
  onRetry?: () => void;
}) {
  return (
    <div className="penalis-banner penalis-banner--error" role="alert">
      <span>{message}</span>
      {onRetry ? (
        <button type="button" className="penalis-button penalis-button--ghost" onClick={onRetry}>
          Coba lagi
        </button>
      ) : null}
    </div>
  );
}

export function Notice({
  tone = 'info',
  children,
}: {
  tone?: 'info' | 'success' | 'warning' | 'danger';
  children: ReactNode;
}) {
  return (
    <div className={`penalis-banner penalis-banner--${tone}`} role={tone === 'danger' ? 'alert' : 'status'}>
      {children}
    </div>
  );
}

/**
 * Standard page intro for dashboard screens. Keeping the title, description,
 * and action alignment in one place prevents screens from drifting apart.
 */
export function PageHeading({
  title,
  description,
  action,
}: {
  title: string;
  description?: ReactNode;
  action?: ReactNode;
}) {
  return (
    <div className="penalis-page-heading">
      <div>
        <h2>{title}</h2>
        {description ? <p className="penalis-muted">{description}</p> : null}
      </div>
      {action}
    </div>
  );
}

type PanelProps = {
  title?: string;
  /** Small element next to the heading, usually a status badge. */
  aside?: ReactNode;
  /**
   * Replaces the default heading row when a panel needs a grouped heading or
   * an action that does not fit the `aside` slot.
   */
  header?: ReactNode;
  label?: string;
  className?: string;
  tabIndex?: number;
  children: ReactNode;
};

export const Panel = forwardRef<HTMLElement, PanelProps>(function Panel(
  { title, aside, header, label, className, tabIndex, children },
  ref,
) {
  return (
    <section
      ref={ref}
      className={className ? `penalis-panel ${className}` : 'penalis-panel'}
      aria-label={label}
      tabIndex={tabIndex}
    >
      <div className="penalis-panel__header">
        {header ?? (
          <>
            {title ? <h3>{title}</h3> : null}
            {aside}
          </>
        )}
      </div>
      {children}
    </section>
  );
});

export function StatusBadge({ status, label }: { status: string; label?: string }) {
  return (
    <span className={`penalis-badge penalis-badge--${statusTone(status)}`}>
      {label ?? statusLabel(status)}
    </span>
  );
}

export function VerifiedWriterBadge({
  account,
  size = 15,
}: {
  account: Pick<Account, 'status' | 'canWrite' | 'isReviewer'>;
  size?: number;
}) {
  if (account.status !== 'active' || !account.canWrite || account.isReviewer) {
    return null;
  }

  return (
    <span
      className="penalis-verified-badge"
      title="Penulis Aktif terverifikasi"
      aria-label="Penulis Aktif terverifikasi"
    >
      <VerifiedBadgeIcon size={size} />
    </span>
  );
}

/**
 * Persistent hint text for a field.
 *
 * Unlike a placeholder, a hint stays visible after the user starts typing and
 * is announced by screen readers when the field receives focus, because the
 * input points at this element through `aria-describedby`.
 */
export function FieldHint({ id, children }: { id: string; children: ReactNode }) {
  return (
    <small className="penalis-field-hint" id={id}>
      {children}
    </small>
  );
}

export function FieldError({ errors, name }: { errors: FieldErrors; name: string }) {
  const message = errors[name];
  if (!message) {
    return null;
  }
  return (
    <p className="penalis-field-error" id={`${name}-error`}>
      {message}
    </p>
  );
}

/**
 * Hidden honeypot field for public forms. Must remain empty for human visitors.
 */
export function HoneypotField({
  value,
  onChange,
}: {
  value: string;
  onChange: (value: string) => void;
}) {
  return (
    <div className="penalis-honeypot" aria-hidden="true">
      <label>
        Website
        <input
          type="text"
          tabIndex={-1}
          autoComplete="off"
          value={value}
          onChange={(event) => onChange(event.target.value)}
        />
      </label>
    </div>
  );
}

export function EmptyState({
  title,
  description,
  action,
}: {
  title: string;
  description: string;
  action?: ReactNode;
}) {
  return (
    <div className="penalis-empty">
      <h3>{title}</h3>
      <p>{description}</p>
      {action}
    </div>
  );
}

/**
 * Shared batch read-receipt control. `markingId === 'all'` is the busy state
 * reported by the notifications controller while the request is in flight.
 */
export function MarkAllReadButton({
  markingId,
  onMarkRead,
  label = 'Tandai semua dibaca',
}: {
  markingId: string | null;
  onMarkRead: () => void;
  label?: string;
}) {
  return (
    <button
      type="button"
      className="penalis-notification-action"
      onClick={onMarkRead}
      disabled={markingId !== null}
    >
      {markingId === 'all' ? 'Memperbarui…' : label}
    </button>
  );
}

export function Pagination({
  page,
  totalPages,
  onChange,
}: {
  page: number;
  totalPages: number;
  onChange: (page: number) => void;
}) {
  if (totalPages <= 1) {
    return null;
  }

  return (
    <nav className="penalis-pagination" aria-label="Navigasi halaman">
      <button
        type="button"
        className="penalis-pagination__button"
        onClick={() => onChange(page - 1)}
        disabled={page <= 1}
        aria-label="Sebelumnya"
        title="Sebelumnya"
      >
        <Icon name="chevronLeft" />
      </button>
      <span className="penalis-pagination__status">
        Halaman {page} dari {totalPages}
      </span>
      <button
        type="button"
        className="penalis-pagination__button"
        onClick={() => onChange(page + 1)}
        disabled={page >= totalPages}
        aria-label="Berikutnya"
        title="Berikutnya"
      >
        <Icon name="chevronRight" />
      </button>
    </nav>
  );
}
