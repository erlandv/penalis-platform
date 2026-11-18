import { describe, expect, it } from 'vitest';

import { MAX_TOASTS, TOAST_LIFETIME_MS, dismissToast, pushToast, toastLifetime } from './toasts';
import type { Toast } from './toasts';

function toast(id: number, tone: Toast['tone'] = 'success'): Toast {
  return { id, tone, message: `pesan ${id}` };
}

describe('pushToast', () => {
  it('appends to the stack', () => {
    const result = pushToast([toast(1)], toast(2));

    expect(result.map((item) => item.id)).toEqual([1, 2]);
  });

  it('keeps the stack at the maximum length', () => {
    const full = Array.from({ length: MAX_TOASTS }, (_, index) => toast(index + 1));
    const result = pushToast(full, toast(99));

    expect(result).toHaveLength(MAX_TOASTS);
  });

  it('drops the oldest toast, not the newest', () => {
    const full = Array.from({ length: MAX_TOASTS }, (_, index) => toast(index + 1));
    const result = pushToast(full, toast(99));

    expect(result.map((item) => item.id)).toEqual([...full.slice(1).map((item) => item.id), 99]);
  });

  it('does not mutate the stack it was given', () => {
    const original = [toast(1)];
    pushToast(original, toast(2));

    expect(original).toHaveLength(1);
  });
});

describe('dismissToast', () => {
  it('removes the matching toast', () => {
    expect(dismissToast([toast(1), toast(2)], 1).map((item) => item.id)).toEqual([2]);
  });

  it('ignores an unknown id', () => {
    expect(dismissToast([toast(1)], 42)).toHaveLength(1);
  });
});

describe('toastLifetime', () => {
  it('lets success and info expire', () => {
    expect(toastLifetime('success')).toBe(TOAST_LIFETIME_MS);
    expect(toastLifetime('info')).toBe(TOAST_LIFETIME_MS);
  });

  it('keeps errors until they are dismissed', () => {
    expect(toastLifetime('danger')).toBe(0);
  });
});
