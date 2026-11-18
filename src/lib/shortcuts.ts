/**
 * Keyboard shortcuts for the article editor.
 *
 * Kept as pure functions so the two decisions that matter — which chord is
 * which shortcut, and whether a keystroke belongs to a text field — can be
 * tested without a DOM.
 *
 * The editor is a form: `Ctrl+S` would otherwise open the browser's "save page"
 * dialog and `Ctrl+Enter` would do nothing, so both are intercepted and mapped
 * onto the same actions as the sticky bar buttons.
 */

/** Actions the editor can run from the keyboard. */
export type EditorShortcut = 'save' | 'submit' | 'help';

/** The subset of `KeyboardEvent` this module needs. */
export interface ShortcutEventLike {
  key: string;
  ctrlKey: boolean;
  metaKey: boolean;
  altKey: boolean;
  shiftKey: boolean;
}

/**
 * Map a keydown event to an editor shortcut.
 *
 * `Ctrl` and `Command` are treated as the same modifier so the editor behaves
 * identically on Windows, Linux, and macOS. `Alt` is never part of a shortcut:
 * on Windows it opens the menu bar, and `AltGr` combinations must keep
 * producing characters.
 *
 * `Shift` is excluded from the modified chords so `Ctrl+Shift+S` keeps its
 * "save as" meaning instead of silently becoming a second save. The help
 * shortcut is the bare `?` character, which already implies Shift on most
 * layouts.
 *
 * @param event Keydown event (or a stand-in for one).
 * @return The matching shortcut, or null when the editor should ignore the key.
 */
export function matchEditorShortcut(event: ShortcutEventLike): EditorShortcut | null {
  if (event.altKey) {
    return null;
  }

  const modifier = event.ctrlKey || event.metaKey;

  if (modifier) {
    if (event.shiftKey) {
      return null;
    }

    if (event.key === 'Enter') {
      return 'submit';
    }

    if (event.key.toLowerCase() === 's') {
      return 'save';
    }

    return null;
  }

  if (event.key === '?') {
    return 'help';
  }

  return null;
}

/** The shape of a DOM node this module inspects. */
interface EditableCandidate {
  tagName?: string;
  isContentEditable?: boolean;
  closest?: (selector: string) => unknown;
}

/**
 * Whether a keystroke is going into text the writer is typing.
 *
 * `?` is a printable character, so the help shortcut must stay out of the way
 * while the writer is writing — inside the article body (a `contenteditable`
 * element), the title field, or any other form control. Without this check the
 * first question mark in a manuscript would open a dialog instead of appearing
 * on the page.
 *
 * Written against a structural type rather than `HTMLElement` so the logic can
 * be unit-tested in a Node environment with plain objects.
 *
 * @param target Event target, usually an element.
 * @return True when the target accepts typed text.
 */
export function isEditableTarget(target: unknown): boolean {
  if (typeof target !== 'object' || target === null) {
    return false;
  }

  const candidate = target as EditableCandidate;
  const tag = typeof candidate.tagName === 'string' ? candidate.tagName.toUpperCase() : '';

  if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') {
    return true;
  }

  if (candidate.isContentEditable === true) {
    return true;
  }

  // A node inside the editor may not report `isContentEditable` itself.
  if (typeof candidate.closest === 'function') {
    return Boolean(candidate.closest('[contenteditable="true"]'));
  }

  return false;
}
