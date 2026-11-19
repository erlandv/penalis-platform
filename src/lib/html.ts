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

/**
 * Count the readable words in serialized article HTML.
 *
 * @param html Serialized article HTML.
 */
export function countWords(html: string): number {
  const plain = htmlToPlainText(html).replace(/\s+/g, ' ').trim();
  return plain === '' ? 0 : plain.split(' ').length;
}

/**
 * Escape HTML special characters for plain-text interpolation.
 *
 * @param value Raw text.
 */
export function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

/**
 * Build a standalone sandboxed HTML document for previewing or reading an article.
 *
 * Kept pure so both the writer preview modal and the reviewer reading pane share
 * consistent literary typography inside `<iframe sandbox="" srcDoc={...} />`.
 *
 * @param content Serialized article HTML.
 * @param title   Optional heading to render above the body.
 */
export function buildPreviewDocument(content: string, title?: string): string {
  const heading = title ? `<h1>${escapeHtml(title)}</h1>` : '';

  return `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>
    body{font-family:Georgia,serif;line-height:1.75;color:#1f2933;max-width:720px;margin:0 auto;padding:1.75rem 1.5rem;background:#fff;overflow-wrap:break-word}
    h1{font-size:2rem;line-height:1.25;margin:0 0 1rem}
    h2{font-size:1.4rem;line-height:1.3;margin:1.5em 0 0.5em}
    h3{font-size:1.15rem;line-height:1.35;margin:1.35em 0 0.45em}
    p{margin:0 0 1em}
    ul,ol{margin:0 0 1em;padding-left:1.5rem}
    li{margin-bottom:0.35em}
    img{max-width:100%;height:auto;border-radius:6px}
    a{color:#7c3aed}
    blockquote{border-left:4px solid #ddd;margin:0 0 1em;padding-left:1rem;color:#52606d}
    hr{border:0;border-top:1px solid #e5e7eb;margin:1.5rem 0}
    pre{white-space:pre-wrap;font-family:monospace;background:#f8fafc;padding:0.75rem;border-radius:6px}
  </style></head><body>${heading}${content}</body></html>`;
}
