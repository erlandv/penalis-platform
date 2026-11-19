import { describe, expect, it } from 'vitest';

import { DECISION_OPTIONS, canSubmitDecision, decisionOption } from './decision';

describe('DECISION_OPTIONS', () => {
  it('offers exactly the three reviewer actions', () => {
    expect(DECISION_OPTIONS.map((option) => option.value)).toEqual(['approve', 'request_changes', 'reject']);
  });

  it('requires feedback only where the workflow does', () => {
    expect(decisionOption('approve').requiresFeedback).toBe(false);
    expect(decisionOption('request_changes').requiresFeedback).toBe(true);
    expect(decisionOption('reject').requiresFeedback).toBe(true);
  });
});

describe('canSubmitDecision', () => {
  it('allows approving without a note', () => {
    expect(canSubmitDecision('approve', '')).toBe(true);
  });

  it('blocks a required note that is empty or only whitespace', () => {
    expect(canSubmitDecision('request_changes', '')).toBe(false);
    expect(canSubmitDecision('request_changes', '   ')).toBe(false);
    expect(canSubmitDecision('reject', '\n\t ')).toBe(false);
  });

  it('allows a required note once something is typed', () => {
    expect(canSubmitDecision('request_changes', 'Perbaiki paragraf kedua.')).toBe(true);
    expect(canSubmitDecision('reject', 'Di luar tema komunitas.')).toBe(true);
  });
});
