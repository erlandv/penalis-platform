/**
 * Accessible modal dialog wrapper.
 *
 * Supplies the pieces a hand-rolled modal was missing: focus moves into the
 * dialog when it opens, Tab and Shift+Tab stay inside it, Escape closes it,
 * and focus returns to the element that opened it when it unmounts. Screen
 * readers get `role="dialog"` with `aria-modal="true"` and a label.
 *
 * The dialog renders the dimmed overlay itself; callers pass the content box
 * (`penalis-modal__content`) as children. A mouse-down that starts on the
 * overlay closes the dialog; clicks inside the content never do.
 */

import { useCallback, useEffect, useRef, type MouseEvent, type ReactNode } from 'react';

/** Selectors matching everything that can receive focus inside the dialog. */
const FOCUSABLE_SELECTOR =
  'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';

export function Dialog({
  label,
  onClose,
  children,
}: {
  label: string;
  onClose: () => void;
  children: ReactNode;
}) {
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const dialog = ref.current;
    if (!dialog) {
      return;
    }

    const previouslyFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null;

    const focusables = () =>
      Array.from(dialog.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR)).filter(
        // `display: none` controls (the hidden file input behind the upload
        // dropzone) match the selector but cannot take focus. Leaving them in
        // the list would make the trap treat an unfocusable element as the
        // first or last stop, so Tab would appear to do nothing.
        (element) => !element.hasAttribute('disabled') && element.getClientRects().length > 0,
      );

    (focusables()[0] ?? dialog).focus();

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        onClose();
        return;
      }

      if (event.key !== 'Tab') {
        return;
      }

      const items = focusables();
      if (items.length === 0) {
        event.preventDefault();
        return;
      }

      const first = items[0];
      const last = items[items.length - 1];
      if (!first || !last) {
        event.preventDefault();
        return;
      }

      const active = document.activeElement;

      if (event.shiftKey && (active === first || !dialog.contains(active))) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && active === last) {
        event.preventDefault();
        first.focus();
      }
    };

    document.addEventListener('keydown', onKeyDown, true);
    return () => {
      document.removeEventListener('keydown', onKeyDown, true);
      previouslyFocused?.focus();
    };
  }, [onClose]);

  const onOverlayMouseDown = useCallback(
    (event: MouseEvent<HTMLDivElement>) => {
      // Only a press that starts on the backdrop itself closes the dialog.
      if (event.target === event.currentTarget) {
        onClose();
      }
    },
    [onClose],
  );

  return (
    <div
      className="penalis-modal"
      role="dialog"
      aria-modal="true"
      aria-label={label}
      ref={ref}
      tabIndex={-1}
      onMouseDown={onOverlayMouseDown}
    >
      {children}
    </div>
  );
}
