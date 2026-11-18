import { describe, expect, it } from 'vitest';

import { htmlToPlainText, plainTextLength, trimTrailingEmptyParagraphs } from './html';

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

describe('htmlToPlainText', () => {
  it('drops inline markup and keeps the words', () => {
    expect(htmlToPlainText('<p>Halo <strong>dunia</strong>.</p>')).toBe('Halo dunia.');
  });

  it('turns block boundaries into line breaks', () => {
    expect(htmlToPlainText('<p>Satu.</p><p>Dua.</p>')).toBe('Satu.\nDua.');
  });

  it('turns a line break into a newline', () => {
    expect(htmlToPlainText('<p>Satu.<br>Dua.</p>')).toBe('Satu.\nDua.');
  });

  it('decodes entities so the writer can paste readable text', () => {
    expect(htmlToPlainText('<p>Budaya &amp; Agama</p>')).toBe('Budaya & Agama');
  });

  it('keeps one blank line where the writer left an empty paragraph', () => {
    // An empty paragraph is deliberate spacing, so it survives as one blank
    // line; longer runs are collapsed so the text stays readable.
    expect(htmlToPlainText('<p>Satu.</p><p></p><p></p><p>Dua.</p>')).toBe('Satu.\n\nDua.');
  });

  it('returns an empty string when there is nothing written', () => {
    expect(htmlToPlainText('')).toBe('');
    expect(htmlToPlainText('<p><br></p>')).toBe('');
  });
});

describe('plainTextLength', () => {
  it('counts visible characters only', () => {
    expect(plainTextLength('<p>Halo <strong>dunia</strong>.</p>')).toBe(11);
  });

  it('does not count an image or empty paragraphs as content', () => {
    expect(plainTextLength('<p></p><img src="/foto.jpg" alt="Foto"><p></p>')).toBe(0);
  });

  it('treats a non-breaking space as whitespace', () => {
    expect(plainTextLength('<p>&nbsp;&nbsp;</p>')).toBe(0);
  });
});
