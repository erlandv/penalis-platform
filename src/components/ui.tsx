/**
 * Shared UI primitives.
 */

import type { ReactNode } from 'react';

import type { FieldErrors } from '../lib/types';
import { statusLabel, statusTone } from '../lib/format';

export function Spinner({ label = 'Loading…' }: { label?: string }) {
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
          Try again
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
    <nav className="penalis-pagination" aria-label="Pagination">
      <button
        type="button"
        className="penalis-button penalis-button--ghost"
        onClick={() => onChange(page - 1)}
        disabled={page <= 1}
      >
        Previous
      </button>
      <span>
        Page {page} of {totalPages}
      </span>
      <button
        type="button"
        className="penalis-button penalis-button--ghost"
        onClick={() => onChange(page + 1)}
        disabled={page >= totalPages}
      >
        Next
      </button>
    </nav>
  );
}
