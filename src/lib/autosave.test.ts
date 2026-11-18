import { describe, expect, it } from 'vitest';

import {
  AUTOSAVE_IDLE_MS,
  AUTOSAVE_INTERVAL_MS,
  AUTOSAVE_MIN_GAP_MS,
  autosaveDelay,
  hasAutosaveContent,
} from './autosave';

describe('autosaveDelay', () => {
  it('waits for the idle window before the first save', () => {
    expect(autosaveDelay({ now: 1_000, dirtySince: 1_000, lastSaveAt: 0 })).toBe(AUTOSAVE_IDLE_MS);
  });

  it('falls back to the idle window when there is no anchor yet', () => {
    expect(autosaveDelay({ now: 5_000, dirtySince: 0, lastSaveAt: 0 })).toBe(AUTOSAVE_IDLE_MS);
  });

  it('keeps the idle window while the writer is still typing', () => {
    // 20 seconds into a batch of changes, the interval is nowhere near spent.
    expect(autosaveDelay({ now: 21_000, dirtySince: 1_000, lastSaveAt: 0 })).toBe(AUTOSAVE_IDLE_MS);
  });

  it('shortens the delay once the interval is about to run out', () => {
    // 59 seconds after the first change: one second of interval is left.
    expect(autosaveDelay({ now: AUTOSAVE_INTERVAL_MS, dirtySince: 1_000, lastSaveAt: 0 })).toBe(
      1_000,
    );
  });

  it('saves immediately when the interval has already passed', () => {
    // Continuous typing for over a minute with no save: the cap is overdue.
    expect(
      autosaveDelay({ now: AUTOSAVE_INTERVAL_MS + 10_000, dirtySince: 1_000, lastSaveAt: 0 }),
    ).toBe(0);
  });

  it('holds the next write until the minimum gap has passed', () => {
    // One second after a save the idle window would fire; the gap will not.
    expect(autosaveDelay({ now: 26_000, dirtySince: 26_000, lastSaveAt: 25_000 })).toBe(
      AUTOSAVE_MIN_GAP_MS - 1_000,
    );
  });

  it('allows the next write once the minimum gap has passed', () => {
    expect(autosaveDelay({ now: 46_000, dirtySince: 44_000, lastSaveAt: 25_000 })).toBe(
      AUTOSAVE_IDLE_MS,
    );
  });

  it('respects the interval when typing continues after a save', () => {
    expect(autosaveDelay({ now: 84_000, dirtySince: 26_000, lastSaveAt: 25_000 })).toBe(1_000);
  });

  it('lets a failure backoff push the next attempt further out', () => {
    expect(
      autosaveDelay({ now: 30_000, dirtySince: 26_000, lastSaveAt: 25_000, retryNotBefore: 60_000 }),
    ).toBe(30_000);
  });

  it('never shortens the delay because of a backoff', () => {
    expect(
      autosaveDelay({ now: 60_000, dirtySince: 59_000, lastSaveAt: 25_000, retryNotBefore: 61_000 }),
    ).toBe(AUTOSAVE_IDLE_MS);
  });

  it('never returns a negative delay', () => {
    expect(
      autosaveDelay({ now: 10_000_000, dirtySince: 1, lastSaveAt: 0, retryNotBefore: 1 }),
    ).toBe(0);
  });
});

describe('hasAutosaveContent', () => {
  it('always saves an article that already exists on the server', () => {
    expect(hasAutosaveContent({ title: '', content: '', hasDraft: true })).toBe(true);
  });

  it('accepts a new article once the title is usable', () => {
    expect(hasAutosaveContent({ title: 'Puisi', content: '', hasDraft: false })).toBe(true);
  });

  it('ignores a title that is too short to be intentional', () => {
    expect(hasAutosaveContent({ title: 'Pu', content: '', hasDraft: false })).toBe(false);
  });

  it('accepts a new article once a short paragraph exists', () => {
    const content = `<p>${'a'.repeat(40)}</p>`;
    expect(hasAutosaveContent({ title: '', content, hasDraft: false })).toBe(true);
  });

  it('does not count markup or whitespace as content', () => {
    expect(
      hasAutosaveContent({ title: '', content: '<p><br></p><p>&nbsp;</p>', hasDraft: false }),
    ).toBe(false);
  });
});
