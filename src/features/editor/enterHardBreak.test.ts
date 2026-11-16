import { describe, expect, it } from 'vitest';
import { getSchema } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import { EditorState, TextSelection } from '@tiptap/pm/state';

import { resolveEnterAction } from './enterHardBreak';

const schema = getSchema([StarterKit.configure({ heading: { levels: [2, 3] }, codeBlock: false })]);

describe('resolveEnterAction', () => {
  it('inserts a hardBreak on first Enter inside a non-empty paragraph', () => {
    const doc = schema.node('doc', null, [
      schema.node('paragraph', null, [schema.text('Baris pertama')]),
    ]);
    const state = EditorState.create({
      doc,
      selection: TextSelection.create(doc, doc.content.size - 1),
    });

    expect(resolveEnterAction(state)).toEqual({ type: 'hardBreak' });
  });

  it('splits into a new paragraph on second Enter right after a hardBreak', () => {
    const doc = schema.node('doc', null, [
      schema.node('paragraph', null, [
        schema.text('Baris pertama'),
        schema.nodes.hardBreak!.create(),
      ]),
    ]);
    const endPos = doc.content.size - 1;
    const state = EditorState.create({
      doc,
      selection: TextSelection.create(doc, endPos),
    });

    expect(resolveEnterAction(state)).toEqual({
      type: 'splitParagraph',
      deleteFrom: endPos - 1,
      deleteTo: endPos,
    });
  });

  it('passes through inside an empty paragraph', () => {
    const doc = schema.node('doc', null, [schema.node('paragraph')]);
    const state = EditorState.create({
      doc,
      selection: TextSelection.create(doc, 1),
    });

    expect(resolveEnterAction(state)).toEqual({ type: 'passthrough' });
  });

  it('passes through inside headings', () => {
    const doc = schema.node('doc', null, [
      schema.node('heading', { level: 2 }, [schema.text('Judul')]),
    ]);
    const state = EditorState.create({
      doc,
      selection: TextSelection.create(doc, doc.content.size - 1),
    });

    expect(resolveEnterAction(state)).toEqual({ type: 'passthrough' });
  });

  it('passes through inside list items', () => {
    const doc = schema.node('doc', null, [
      schema.node('bulletList', null, [
        schema.node('listItem', null, [
          schema.node('paragraph', null, [schema.text('Butir satu')]),
        ]),
      ]),
    ]);
    const state = EditorState.create({
      doc,
      selection: TextSelection.create(doc, 13),
    });

    expect(resolveEnterAction(state)).toEqual({ type: 'passthrough' });
  });

  it('inserts a hardBreak inside a blockquote paragraph with text', () => {
    const doc = schema.node('doc', null, [
      schema.node('blockquote', null, [
        schema.node('paragraph', null, [schema.text('Bait satu')]),
      ]),
    ]);
    const state = EditorState.create({
      doc,
      selection: TextSelection.create(doc, 11),
    });

    expect(resolveEnterAction(state)).toEqual({ type: 'hardBreak' });
  });

  it('inserts a hardBreak instead of splitting when selection is non-empty', () => {
    const doc = schema.node('doc', null, [
      schema.node('paragraph', null, [
        schema.text('Halo'),
        schema.nodes.hardBreak!.create(),
      ]),
    ]);
    const state = EditorState.create({
      doc,
      selection: TextSelection.create(doc, 2, doc.content.size - 1),
    });

    expect(resolveEnterAction(state)).toEqual({ type: 'hardBreak' });
  });
});
