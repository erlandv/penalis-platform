import { describe, expect, it } from 'vitest';

import { displayNameOptions, normalizePersonName, resolveDisplayName } from './profile';

describe('normalizePersonName', () => {
  it('trims and collapses whitespace', () => {
    expect(normalizePersonName('  Siti   Sukentari  ')).toBe('Siti Sukentari');
    expect(normalizePersonName('Siti\tSukentari')).toBe('Siti Sukentari');
    expect(normalizePersonName('   ')).toBe('');
  });
});

describe('resolveDisplayName', () => {
  it('prefers first and last name in automatic mode', () => {
    expect(resolveDisplayName('auto', 'Siti', 'Sukentari', 'sukentari')).toBe('Siti Sukentari');
  });

  it('falls back to first name, then last name, then the username', () => {
    expect(resolveDisplayName('auto', 'Siti', '', 'sukentari')).toBe('Siti');
    expect(resolveDisplayName('auto', '', 'Sukentari', 'sukentari')).toBe('Sukentari');
    expect(resolveDisplayName('auto', '', '', 'sukentari')).toBe('sukentari');
  });

  it('honours explicit single-field modes', () => {
    expect(resolveDisplayName('first', 'Siti', 'Sukentari', 'sukentari')).toBe('Siti');
    expect(resolveDisplayName('last', 'Siti', 'Sukentari', 'sukentari')).toBe('Sukentari');
    expect(resolveDisplayName('username', 'Siti', 'Sukentari', 'sukentari')).toBe('sukentari');
  });

  it('normalises custom names', () => {
    expect(resolveDisplayName('custom', 'Siti', 'Sukentari', 'sukentari', '  Sang   Pujangga ')).toBe(
      'Sang Pujangga',
    );
  });
});

describe('displayNameOptions', () => {
  it('labels every option with the resulting public name only', () => {
    const options = displayNameOptions('Siti', 'Sukentari', 'sukentari');
    expect(options.map((option) => option.mode)).toEqual(['auto', 'first', 'last', 'username', 'custom']);
    expect(options.map((option) => option.label)).toEqual([
      'Siti Sukentari',
      'Siti',
      'Sukentari',
      'sukentari',
      'Custom',
    ]);
  });

  it('omits options whose field is empty', () => {
    const options = displayNameOptions('', '', 'sukentari');
    expect(options.map((option) => option.mode)).toEqual(['auto', 'custom']);
    expect(options[0]?.label).toBe('sukentari');
  });

  it('never repeats a label', () => {
    // The automatic option already resolves to the first name here.
    const options = displayNameOptions('Siti', '', 'sukentari');
    expect(options.map((option) => option.label)).toEqual(['Siti', 'sukentari', 'Custom']);

    // Username identical to the first name: only one entry survives.
    const sameName = displayNameOptions('sukentari', '', 'sukentari');
    expect(sameName.map((option) => option.label)).toEqual(['sukentari', 'Custom']);
  });

  it('always offers the custom option', () => {
    expect(displayNameOptions('', '', '').map((option) => option.mode)).toContain('custom');
  });
});
