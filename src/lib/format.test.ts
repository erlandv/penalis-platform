import { describe, expect, it } from 'vitest';

import { decodeEntities, editorialStateLabel, statusLabel, statusTone } from './format';

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

describe('editorialStateLabel and statusTone', () => {
  it('returns Indonesian labels for every V2 editorial state', () => {
    expect(editorialStateLabel('draft')).toBe('Draft');
    expect(editorialStateLabel('pending_review')).toBe('Menunggu review');
    expect(editorialStateLabel('changes_requested')).toBe('Perlu revisi');
    expect(editorialStateLabel('rejected')).toBe('Ditolak');
    expect(editorialStateLabel('published')).toBe('Terbit');
    expect(editorialStateLabel('unknown')).toBe('Tidak diketahui');
  });

  it('maps editorial and post statuses to badge tones and Indonesian labels', () => {
    expect(statusLabel('published')).toBe('Terbit');
    expect(statusLabel('pending_review')).toBe('Menunggu review');
    expect(statusLabel('changes_requested')).toBe('Perlu revisi');
    expect(statusLabel('draft')).toBe('Draft');
    expect(statusLabel('rejected')).toBe('Ditolak');

    expect(statusTone('published')).toBe('success');
    expect(statusTone('publish')).toBe('success');
    expect(statusTone('approve')).toBe('success');
    expect(statusTone('pending_review')).toBe('warning');
    expect(statusTone('changes_requested')).toBe('warning');
    expect(statusTone('submit')).toBe('warning');
    expect(statusTone('resubmit')).toBe('warning');
    expect(statusTone('request_changes')).toBe('warning');
    expect(statusTone('reopen')).toBe('warning');
    expect(statusTone('rejected')).toBe('danger');
    expect(statusTone('reject')).toBe('danger');
    expect(statusTone('draft')).toBe('neutral');
  });
});
