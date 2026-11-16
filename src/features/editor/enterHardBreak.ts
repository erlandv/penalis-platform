import { Extension } from '@tiptap/react';
import type { EditorState } from '@tiptap/pm/state';

export type EnterAction =
  | { type: 'passthrough' }
  | { type: 'hardBreak' }
  | { type: 'splitParagraph'; deleteFrom: number; deleteTo: number };

/**
 * Decide how `Enter` should behave at the current selection.
 *
 * Inside a non-empty paragraph (outside lists), a single `Enter` inserts a
 * `<br>` (`hardBreak`), while a second `Enter` immediately after a `hardBreak`
 * removes that trailing `<br>` and splits into a new paragraph (`<p>`).
 * Headings, lists, and empty paragraphs fall through to TipTap's defaults so
 * writers can still exit lists/blockquotes and leave headings normally.
 */
export function resolveEnterAction(state: EditorState): EnterAction {
  const { $from, empty } = state.selection;

  if ($from.parent.type.name !== 'paragraph') {
    return { type: 'passthrough' };
  }

  for (let depth = $from.depth; depth > 0; depth--) {
    if ($from.node(depth).type.name === 'listItem') {
      return { type: 'passthrough' };
    }
  }

  if ($from.parent.content.size === 0) {
    return { type: 'passthrough' };
  }

  const nodeBefore = $from.nodeBefore;
  if (empty && nodeBefore?.type.name === 'hardBreak') {
    return {
      type: 'splitParagraph',
      deleteFrom: $from.pos - nodeBefore.nodeSize,
      deleteTo: $from.pos,
    };
  }

  return { type: 'hardBreak' };
}

/**
 * TipTap extension that maps single `Enter` to a line break (`<br>`) and
 * double `Enter` to a new paragraph (`<p>`).
 */
export const EnterHardBreak = Extension.create({
  name: 'enterHardBreak',

  // Run before Paragraph, ListItem, and Heading so we can intercept Enter in
  // plain paragraphs while delegating everything else by returning `false`.
  priority: 1000,

  addKeyboardShortcuts() {
    return {
      Enter: () => {
        const action = resolveEnterAction(this.editor.state);

        if (action.type === 'passthrough') {
          return false;
        }

        if (action.type === 'splitParagraph') {
          return this.editor
            .chain()
            .deleteRange({ from: action.deleteFrom, to: action.deleteTo })
            .splitBlock()
            .scrollIntoView()
            .run();
        }

        return this.editor.commands.setHardBreak();
      },
    };
  },
});
