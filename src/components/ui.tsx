/**
 * Shared UI primitives.
 */

import type { ReactNode } from 'react';

import { Icon } from './icons';
import type { FieldErrors } from '../lib/types';
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

export function StatusBadge({ status, label }: { status: string; label?: string }) {
  return (
    <span className={`penalis-badge penalis-badge--${statusTone(status)}`}>
      {label ?? statusLabel(status)}
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
