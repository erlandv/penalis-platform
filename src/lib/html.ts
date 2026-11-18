/**
 * Small HTML helpers for article content.
 *
 * Kept out of the editor component so they can be imported without pulling in
 * TipTap and ProseMirror.
 */

import { decodeEntities } from './format';

/**
 * Remove trailing empty paragraphs.
 *
 * TipTap appends an empty paragraph after a block node (for example after an
 * image) so the writer can keep typing. That node is useful while editing but
 * it is noise in stored `post_content`, so it is trimmed before saving.
 *
 * Only paragraphs that are empty or contain a single `<br>` are removed, and
 * only from the end, so intentional spacing in the middle of an article is
 * preserved.
 *
 * @param html Serialized editor HTML.
 * @return HTML without trailing empty paragraphs.
 */
export function trimTrailingEmptyParagraphs(html: string): string {
  // `<br>` may carry attributes, e.g. `<br class="ProseMirror-trailingBreak">`.
  return html.replace(/(?:<p>\s*(?:<br[^>]*>)?\s*<\/p>\s*)+$/i, '').trim();
}

/**
 * Reduce editor HTML to readable plain text.
 *
 * Two callers need this and neither wants to parse HTML: the autosave
 * threshold (is there anything worth persisting yet?) and the "copy the
 * manuscript" fallback offered when the session ends. A full DOM parser is not
 * worth the weight for either, so block boundaries become newlines and tags
 * are dropped — the result is what the writer would paste into a text file.
 *
 * @param html Serialized editor HTML.
 * @return Plain text with block-level line breaks.
 */
export function htmlToPlainText(html: string): string {
  if (html === '') {
    return '';
  }

  const withoutTags = html
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<\/(?:p|div|li|h[1-6]|blockquote|figure|figcaption|tr)>/gi, '\n')
    .replace(/<[^>]*>/g, '');

  return decodeEntities(withoutTags)
    .replace(/\u00a0/g, ' ')
    .replace(/[ \t]+\n/g, '\n')
    .replace(/\n{3,}/g, '\n\n')
    .trim();
}

/**
 * Count the visible characters in editor HTML.
 *
 * Whitespace and markup do not count, so an empty paragraph or a lone image
 * never passes for written content.
 *
 * @param html Serialized editor HTML.
 */
export function plainTextLength(html: string): number {
  return htmlToPlainText(html).replace(/\s+/g, ' ').trim().length;
}
