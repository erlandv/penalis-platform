import { describe, expect, it } from 'vitest';

import { trimTrailingEmptyParagraphs } from './html';

describe('trimTrailingEmptyParagraphs', () => {
  it('removes a single trailing empty paragraph', () => {
    expect(trimTrailingEmptyParagraphs('<p>Isi.</p><p></p>')).toBe('<p>Isi.</p>');
  });

  it('removes a trailing paragraph that only holds a line break', () => {
    // TipTap emits <p><br class="ProseMirror-trailingBreak"></p> after blocks.
    expect(
      trimTrailingEmptyParagraphs('<p>Isi.</p><p><br class="ProseMirror-trailingBreak"></p>'),
    ).toBe('<p>Isi.</p>');
  });

  it('removes several trailing empty paragraphs', () => {
    expect(trimTrailingEmptyParagraphs('<p>Isi.</p><p></p><p><br></p>')).toBe('<p>Isi.</p>');
  });

  it('keeps the image that precedes the empty paragraph', () => {
    const html = '<p>Isi.</p><img src="/foto.jpg" alt="Foto"><p></p>';
    expect(trimTrailingEmptyParagraphs(html)).toBe('<p>Isi.</p><img src="/foto.jpg" alt="Foto">');
  });

  it('preserves intentional spacing in the middle', () => {
    const html = '<p>Satu.</p><p></p><p>Dua.</p>';
    expect(trimTrailingEmptyParagraphs(html)).toBe(html);
  });

  it('preserves paragraphs that only contain a non-breaking space', () => {
    // A &nbsp; paragraph is a deliberate blank line, not editor noise.
    const html = '<p>Satu.</p><p>&nbsp;</p>';
    expect(trimTrailingEmptyParagraphs(html)).toBe(html);
  });

  it('returns an empty string for empty input', () => {
    expect(trimTrailingEmptyParagraphs('')).toBe('');
    expect(trimTrailingEmptyParagraphs('<p></p>')).toBe('');
  });

  it('leaves content without trailing paragraphs untouched', () => {
    const html = '<h2>Judul</h2><p>Isi.</p>';
    expect(trimTrailingEmptyParagraphs(html)).toBe(html);
  });
});
