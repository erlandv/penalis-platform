import { describe, expect, it } from 'vitest';

import { decodeEntities } from './format';

describe('decodeEntities', () => {
  it('decodes named entities', () => {
    expect(decodeEntities('Budaya &amp; Agama')).toBe('Budaya & Agama');
    expect(decodeEntities('A &lt; B &gt; C')).toBe('A < B > C');
    expect(decodeEntities('&quot;quoted&quot;')).toBe('"quoted"');
    expect(decodeEntities("R&amp;D&apos;s")).toBe("R&D's");
  });

  it('decodes decimal and hexadecimal entities', () => {
    expect(decodeEntities('don&#8217;t')).toBe('don\u2019t');
    expect(decodeEntities('caf&#xE9;')).toBe('caf\u00e9');
  });

  it('leaves plain text and bare ampersands alone', () => {
    expect(decodeEntities('Budaya & Agama')).toBe('Budaya & Agama');
    expect(decodeEntities('Penalis')).toBe('Penalis');
  });

  it('leaves unknown entities and invalid code points untouched', () => {
    expect(decodeEntities('&unknown;')).toBe('&unknown;');
    expect(decodeEntities('&#0;')).toBe('&#0;');
    expect(decodeEntities('&#xD800;')).toBe('&#xD800;');
  });

  it('decodes a single level only', () => {
    expect(decodeEntities('&amp;amp;')).toBe('&amp;');
  });
});
