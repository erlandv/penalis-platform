import { describe, expect, it } from 'vitest';

import { PASSWORD_STRENGTH_LABELS, PASSWORD_STRONG_LENGTH, passwordStrength } from './passwordStrength';
import { PASSWORD_MIN_LENGTH } from './validation';

describe('passwordStrength scoring', () => {
  it('treats an empty password as score 0 with no label', () => {
    const result = passwordStrength('');

    expect(result.score).toBe(0);
    expect(result.label).toBe('');
    expect(result.hints).toContain(`tambahkan hingga minimal ${PASSWORD_MIN_LENGTH} karakter`);
  });

  it('keeps a short password at the lowest score', () => {
    expect(passwordStrength('abc').score).toBe(0);
    expect(passwordStrength('abc').label).toBe('Sangat lemah');
  });

  it('rewards reaching the minimum length even without variety', () => {
    const result = passwordStrength('abcdefghijkl');

    expect(result.score).toBe(2);
    expect(result.label).toBe('Cukup');
  });

  it('scores a minimum-length password with mixed case and a digit as strong', () => {
    const result = passwordStrength('Abcdefgh1234');

    expect(result.score).toBe(3);
    expect(result.label).toBe('Kuat');
  });

  it('reaches the top score once every requirement is met', () => {
    const result = passwordStrength('Abcdefgh1234!xyz');

    expect(result.score).toBe(4);
    expect(result.label).toBe('Sangat kuat');
  });

  it('accepts a long passphrase with spaces as strong', () => {
    expect(passwordStrength('kopi susu enak sekali').score).toBeGreaterThanOrEqual(3);
  });

  it('never exceeds the highest label', () => {
    const result = passwordStrength('Abcdefgh1234!xyzAbcdefgh1234!xyz');

    expect(result.score).toBe(4);
    expect(result.label).toBe(PASSWORD_STRENGTH_LABELS[4]);
  });
});

describe('passwordStrength weak shapes', () => {
  it('caps a digits-only password', () => {
    const result = passwordStrength('123456789012');

    expect(result.score).toBeLessThanOrEqual(1);
    expect(result.label).toBe('Lemah');
  });

  it('caps a password built from one repeated character', () => {
    expect(passwordStrength('aaaaaaaaaaaa').score).toBeLessThanOrEqual(1);
    expect(passwordStrength('!!!!!!!!!!!!!!!!').score).toBeLessThanOrEqual(1);
  });

  it('caps a common word decorated with digits and symbols', () => {
    expect(passwordStrength('Password1234!').score).toBeLessThanOrEqual(1);
    expect(passwordStrength('P@ssword2024').score).toBeLessThanOrEqual(1);
    expect(passwordStrength('katasandi!123').score).toBeLessThanOrEqual(1);
  });

  it('does not cap a password that starts with an unrelated word', () => {
    expect(passwordStrength('TembokBiru#2024x').score).toBeGreaterThanOrEqual(3);
  });
});

describe('passwordStrength requirements', () => {
  it('reports which requirements are met', () => {
    const byId = Object.fromEntries(
      passwordStrength('Abcdefgh1234').requirements.map((requirement) => [requirement.id, requirement.met]),
    );

    expect(byId).toEqual({
      length: true,
      long: false,
      mixedCase: true,
      digit: true,
      symbol: false,
      varied: true,
    });
  });

  it('lists hints in requirement order and only for unmet requirements', () => {
    const result = passwordStrength('Abcdefgh1234');

    expect(result.hints).toEqual([
      `perpanjang menjadi ${PASSWORD_STRONG_LENGTH} karakter`,
      'tambahkan simbol',
    ]);
  });

  it('counts a space as a symbol', () => {
    const symbol = passwordStrength('kopi susu enak').requirements.find((item) => item.id === 'symbol');

    expect(symbol?.met).toBe(true);
  });

  it('flags repeated characters as not varied', () => {
    const varied = passwordStrength('aaaaaaaaaaaa').requirements.find((item) => item.id === 'varied');

    expect(varied?.met).toBe(false);
  });

  it('does not award variety to a three-character password', () => {
    const varied = passwordStrength('Aa1').requirements.find((item) => item.id === 'varied');

    expect(varied?.met).toBe(false);
  });
});
