/**
 * Global toast stack.
 *
 * Toast rather than inline banner for the one-line confirmations — "Karya tulis
 * dihapus", "Foto profil diperbarui". An inline banner at the top of the page
 * reports an action the writer took further down, and inserting it moves
 * everything below it, which is exactly the moment the writer is looking at
 * what changed.
 *
 * The region is always mounted, even when empty: assistive technology only
 * announces changes inside a live region that already existed, so a container
 * rendered together with its first toast would be silent.
 *
 * Errors never expire (see `toastLifetime`), and every toast can be dismissed
 * by hand.
 */

import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';

import { dismissToast, pushToast, toastLifetime, type Toast } from '../lib/toasts';
import { Icon } from './icons';
import { ToastContext, type ShowToast } from './toastContext';

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([]);
  const nextIdRef = useRef(0);
  const timersRef = useRef(new Map<number, number>());

  const dismiss = useCallback((id: number) => {
    const timer = timersRef.current.get(id);

    if (timer !== undefined) {
      window.clearTimeout(timer);
      timersRef.current.delete(id);
    }

    setToasts((current) => dismissToast(current, id));
  }, []);

  const show = useCallback<ShowToast>(
    (message, tone = 'success') => {
      nextIdRef.current += 1;
      const id = nextIdRef.current;

      setToasts((current) => pushToast(current, { id, tone, message }));

      const lifetime = toastLifetime(tone);

      if (lifetime > 0) {
        timersRef.current.set(
          id,
          window.setTimeout(() => dismiss(id), lifetime),
        );
      }
    },
    [dismiss],
  );

  // Timers outlive the component only if we let them; clearing on unmount keeps
  // a signed-out dashboard from holding a pending callback.
  useEffect(
    () => () => {
      timersRef.current.forEach((timer) => window.clearTimeout(timer));
      timersRef.current.clear();
    },
    [],
  );

  return (
    <ToastContext.Provider value={show}>
      {children}

      <div className="penalis-toasts" aria-live="polite" aria-atomic="false">
        {toasts.map((toast) => (
          <div key={toast.id} className={`penalis-toast penalis-toast--${toast.tone}`}>
            <span className="penalis-toast__icon" aria-hidden="true">
              <Icon name={toast.tone === 'danger' ? 'close' : 'check'} />
            </span>
            <p className="penalis-toast__message">{toast.message}</p>
            <button
              type="button"
              className="penalis-toast__close"
              onClick={() => dismiss(toast.id)}
              aria-label="Tutup pemberitahuan"
            >
              <Icon name="close" />
            </button>
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  );
}
