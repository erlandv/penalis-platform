import { Extension } from '@tiptap/react';
import { Fragment, Slice } from '@tiptap/pm/model';
import type { Mark, Node as ProseMirrorNode, Schema } from '@tiptap/pm/model';
import { Plugin } from '@tiptap/pm/state';

/**
 * Build a ProseMirror slice from plain clipboard text using the same rules as
 * the `Enter` key in this editor: a single newline becomes a `<br>` (hard
 * break) and a blank line starts a new `<p>`.
 *
 * ProseMirror's built-in plain-text parser makes a paragraph out of every
 * newline, which is why copying a six-line stanza produces six paragraphs.
 * A trailing line terminator is dropped because copying a line from most text
 * editors includes one even though it is not a visible line break.
 */
export function plainTextToSlice(text: string, schema: Schema, marks: readonly Mark[] = []): Slice {
  const normalized = text.replace(/\r\n?/g, '\n').replace(/\n+$/, '');

  if (!normalized) {
    return Slice.empty;
  }

  const hardBreak = schema.nodes.hardBreak;
  const paragraph = schema.nodes.paragraph;
  if (!hardBreak || !paragraph) {
    throw new Error('pasteHardBreak requires the paragraph and hardBreak node types');
  }

  const paragraphs = normalized.split(/\n{2,}/).map((block) => {
    const children: ProseMirrorNode[] = [];

    block.split('\n').forEach((line, index) => {
      if (index > 0) {
        children.push(hardBreak.create());
      }
      if (line) {
        children.push(schema.text(line, marks));
      }
    });

    return paragraph.create(null, children);
  });

  return new Slice(Fragment.from(paragraphs), 0, 0);
}

/**
 * TipTap extension that routes plain-text paste through `plainTextToSlice`.
 *
 * `clipboardTextParser` is only consulted for `text/plain` clipboard content
 * (and "paste as plain text"), so HTML/rich-text paste keeps TipTap's default
 * parsing and preserves the formatting the writer copied.
 */
export const PasteHardBreak = Extension.create({
  name: 'pasteHardBreak',

  addProseMirrorPlugins() {
    return [
      new Plugin({
        props: {
          clipboardTextParser: (text, $context, _plain, view) => {
            if ($context.parent.type.spec.code) {
              // Code nodes keep ProseMirror's literal-text handling.
              return new Slice(
                Fragment.from(view.state.schema.text(text.replace(/\r\n?/g, '\n'))),
                0,
                0,
              );
            }

            return plainTextToSlice(text, view.state.schema, $context.marks());
          },
        },
      }),
    ];
  },
});
