/**
 * Small HTML helpers for article content.
 *
 * Kept out of the editor component so they can be imported without pulling in
 * TipTap and ProseMirror.
 */

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
