import { describe, expect, it } from 'vitest';

import { automaticSource, displayNameOptions, normalizePersonName, resolveDisplayName } from './profile';

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

describe('automaticSource', () => {
  it('describes the fields used by the automatic option', () => {
    expect(automaticSource('Siti', 'Sukentari')).toBe('First + Last name');
    expect(automaticSource('Siti', '')).toBe('First name');
    expect(automaticSource('', 'Sukentari')).toBe('Last name');
    expect(automaticSource('', '')).toBe('Username');
  });
});

describe('displayNameOptions', () => {
  it('offers every option when both names are filled in', () => {
    const modes = displayNameOptions('Siti', 'Sukentari', 'sukentari').map((option) => option.mode);
    expect(modes).toEqual(['auto', 'first', 'last', 'username', 'custom']);
  });

  it('labels the automatic option with the derived value and its source', () => {
    const [automatic] = displayNameOptions('Siti', 'Sukentari', 'sukentari');
    expect(automatic?.label).toBe('Siti Sukentari (First + Last name)');
  });

  it('omits options whose field is empty', () => {
    const modes = displayNameOptions('', '', 'sukentari').map((option) => option.mode);
    // Automatic already resolves to the username here, so no duplicate entry.
    expect(modes).toEqual(['auto', 'custom']);

    const [automatic] = displayNameOptions('', '', 'sukentari');
    expect(automatic?.label).toBe('sukentari (Username)');
  });

  it('always keeps the custom option available', () => {
    const options = displayNameOptions('', '', '');
    expect(options.map((option) => option.mode)).toContain('custom');
  });
});
