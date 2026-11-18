/**
 * Toast queue rules.
 *
 * The provider in `components/Toast.tsx` owns the timers and the DOM; what
 * happens to the list when one is added or dismissed is pure data, so it lives
 * here and is tested without React.
 */

export type ToastTone = 'success' | 'info' | 'danger';

export interface Toast {
  id: number;
  tone: ToastTone;
  message: string;
}

/**
 * How many toasts may stack before the oldest is dropped.
 *
 * Three is the most that stays readable above the fold on a phone; beyond that
 * the stack becomes a wall of text covering the page it is reporting on.
 */
export const MAX_TOASTS = 3;

/** How long a success or info toast stays on screen. */
export const TOAST_LIFETIME_MS = 6000;

/**
 * How long a toast of this tone should live.
 *
 * `0` means "until dismissed". Errors stay: a message that disappears after a
 * few seconds is a message a writer may never read, and the action that failed
 * is usually the one they were waiting for. Success is self-evident once seen,
 * so it leaves on its own.
 *
 * @param tone Toast tone.
 * @return Lifetime in milliseconds, or 0 for sticky.
 */
export function toastLifetime(tone: ToastTone): number {
  return tone === 'danger' ? 0 : TOAST_LIFETIME_MS;
}

/**
 * Append a toast, dropping the oldest when the stack is full.
 *
 * The newest message wins: it describes the action the writer just took, while
 * the one at the bottom of the stack describes something they have already
 * read.
 *
 * @param toasts Current stack.
 * @param toast Toast to append.
 * @return New stack.
 */
export function pushToast(toasts: Toast[], toast: Toast): Toast[] {
  const next = [...toasts, toast];

  return next.length > MAX_TOASTS ? next.slice(next.length - MAX_TOASTS) : next;
}

/**
 * Remove one toast.
 *
 * @param toasts Current stack.
 * @param id Identifier of the toast to remove.
 * @return New stack, unchanged when the id is not present.
 */
export function dismissToast(toasts: Toast[], id: number): Toast[] {
  return toasts.filter((toast) => toast.id !== id);
}
