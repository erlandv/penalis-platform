import { describe, expect, it } from 'vitest';
import { getSchema } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import type { Mark } from '@tiptap/pm/model';

import { plainTextToSlice } from './pasteHardBreak';

const schema = getSchema([StarterKit.configure({ heading: { levels: [2, 3] }, codeBlock: false })]);

function summarize(text: string, marks: readonly Mark[] = []): string[] {
  const slice = plainTextToSlice(text, schema, marks);
  const paragraphs: string[] = [];

  slice.content.forEach((paragraph) => {
    let line = '';
    paragraph.forEach((child) => {
      line += child.type.name === 'hardBreak' ? '<br>' : (child.text ?? '');
    });
    paragraphs.push(line);
  });

  return paragraphs;
}

describe('plainTextToSlice', () => {
  it('keeps single newlines as hard breaks inside one paragraph', () => {
    const text = [
      'Ini baris pertama dari paragraf pertama',
      'Ini baris kedua dari paragraf pertama',
      'Ini baris ketiga dari paragraf pertama',
      'Ini baris pertama dari paragraf kedua',
      'Ini baris kedua dari paragraf kedua',
      'Ini baris ketiga dari paragraf kedua',
    ].join('\n');

    const paragraphs = summarize(text);

    expect(paragraphs).toHaveLength(1);
    expect(paragraphs[0]).toBe(
      'Ini baris pertama dari paragraf pertama' +
        '<br>Ini baris kedua dari paragraf pertama' +
        '<br>Ini baris ketiga dari paragraf pertama' +
        '<br>Ini baris pertama dari paragraf kedua' +
        '<br>Ini baris kedua dari paragraf kedua' +
        '<br>Ini baris ketiga dari paragraf kedua',
    );
  });

  it('starts a new paragraph on a blank line', () => {
    const paragraphs = summarize('a\nb\nc\n\nd\ne\nf');

    expect(paragraphs).toEqual(['a<br>b<br>c', 'd<br>e<br>f']);
  });

  it('treats several blank lines as one paragraph break', () => {
    expect(summarize('a\n\n\nb')).toEqual(['a', 'b']);
  });

  it('normalizes CRLF line endings', () => {
    expect(summarize('satu\r\ndua')).toEqual(['satu<br>dua']);
  });

  it('drops a trailing line terminator instead of adding an empty line', () => {
    expect(summarize('satu\ndua\n')).toEqual(['satu<br>dua']);
    expect(summarize('satu\n\n')).toEqual(['satu']);
  });

  it('keeps marks active at the caret position', () => {
    const bold = schema.marks.bold!.create();
    const slice = plainTextToSlice('satu\ndua', schema, [bold]);
    const paragraph = slice.content.firstChild!;

    expect(paragraph.firstChild!.marks.map((mark) => mark.type.name)).toEqual(['bold']);
    expect(paragraph.lastChild!.marks.map((mark) => mark.type.name)).toEqual(['bold']);
  });

  it('returns an empty slice for empty input', () => {
    expect(plainTextToSlice('', schema).content.size).toBe(0);
  });
});
