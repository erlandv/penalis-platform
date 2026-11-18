/**
 * Toast context and the hook that reads it.
 *
 * Kept out of `Toast.tsx` for the same reason `lib/a11y.ts` is kept out of
 * `components/ui.tsx`: a module that exports React components should export
 * only components, or Fast Refresh turns every edit into a full reload and the
 * dashboard loses the state being debugged.
 */

import { createContext, useContext } from 'react';

import type { ToastTone } from '../lib/toasts';

export type ShowToast = (message: string, tone?: ToastTone) => void;

export const ToastContext = createContext<ShowToast | null>(null);

/**
 * Show a toast from anywhere inside `<ToastProvider>`.
 *
 * @return Function that queues a toast.
 * @throws When called outside the provider — a silent no-op would hide the
 *   wiring mistake until someone noticed the message never appeared.
 */
export function useToast(): ShowToast {
  const show = useContext(ToastContext);

  if (!show) {
    throw new Error('useToast() harus dipakai di dalam <ToastProvider>.');
  }

  return show;
}
