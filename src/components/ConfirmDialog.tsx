/**
 * Confirmation dialog for destructive or hard-to-reverse actions.
 *
 * Replaces `window.confirm`, which the browser renders as an unstyled OS
 * prompt that cannot be labelled, cannot show which article is affected, and
 * blocks the whole tab. This dialog reuses `Dialog` for the accessibility
 * plumbing (focus in, focus trap, Escape, focus restore) and adds the parts a
 * confirmation needs: a title, a description, a cancel-first button order, and
 * a busy state so the confirm button cannot be pressed twice.
 *
 * The confirm button is never focused by default. `Dialog` focuses the first
 * focusable element, which is the cancel button, so pressing Enter on a
 * destructive prompt does not destroy anything.
 */

import type { ReactNode } from 'react';

import { Dialog } from './Dialog';

export function ConfirmDialog({
  title,
  description,
  confirmLabel,
  cancelLabel = 'Batal',
  tone = 'danger',
  busy = false,
  onConfirm,
  onCancel,
}: {
  title: string;
  description: ReactNode;
  confirmLabel: string;
  cancelLabel?: string;
  /** `danger` for irreversible actions, `warning` for reversible ones. */
  tone?: 'danger' | 'warning';
  busy?: boolean;
  onConfirm: () => void;
  onCancel: () => void;
}) {
  return (
    <Dialog label={title} onClose={busy ? () => undefined : onCancel}>
      <div className="penalis-modal__content penalis-modal__content--confirm">
        <div className="penalis-modal__header">
          <h3>{title}</h3>
        </div>

        <div className="penalis-confirm__body">{description}</div>

        <div className="penalis-confirm__actions">
          <button
            type="button"
            className="penalis-button penalis-button--ghost"
            onClick={onCancel}
            disabled={busy}
          >
            {cancelLabel}
          </button>
          <button
            type="button"
            className={`penalis-button penalis-button--${tone === 'danger' ? 'danger-solid' : 'primary'}`}
            onClick={onConfirm}
            disabled={busy}
          >
            {busy ? 'Memproses…' : confirmLabel}
          </button>
        </div>
      </div>
    </Dialog>
  );
}
