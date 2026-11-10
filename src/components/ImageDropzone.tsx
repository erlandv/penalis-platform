/**
 * Styled image upload target: drag and drop, or click to browse.
 *
 * Replaces the bare `<input type="file">` the browser renders, which looked
 * like a form control from 1998 and gave no hint that dropping a file works.
 *
 * Accessibility notes:
 * - The visible box is a real `<button>`, so it is reachable with Tab and
 *   activated with Enter/Space without any custom key handling.
 * - The file input is `display: none` and only opened programmatically, so it
 *   never becomes a second, invisible tab stop.
 * - While an upload is running the button stays focusable and uses
 *   `aria-disabled` instead of `disabled`: disabling a focused element drops
 *   focus to `<body>`, which loses the keyboard user's place mid-flow.
 */

import { useId, useRef, useState, type DragEvent } from 'react';

import { Icon } from './icons';

/** MIME types the server accepts for writer uploads. */
const DEFAULT_ACCEPT = 'image/jpeg,image/png,image/gif,image/webp';

export function ImageDropzone({
  onFile,
  onReject,
  busy = false,
  disabled = false,
  title = 'Letakkan gambar di sini',
  subtitle = 'atau klik untuk memilih file',
  hint,
  accept = DEFAULT_ACCEPT,
  invalidMessage = 'Format file tidak didukung. Gunakan JPEG, PNG, GIF, atau WebP.',
}: {
  /** Called with the first dropped or chosen file. */
  onFile: (file: File) => void;
  /** Called when the file is not an accepted image type. */
  onReject?: (message: string) => void;
  busy?: boolean;
  disabled?: boolean;
  title?: string;
  subtitle?: string;
  /** Persistent helper text rendered under the box. */
  hint?: string;
  accept?: string;
  invalidMessage?: string;
}) {
  const inputRef = useRef<HTMLInputElement>(null);
  const [dragging, setDragging] = useState(false);
  const hintId = useId();
  const inactive = busy || disabled;

  const allowed = accept
    .split(',')
    .map((type) => type.trim().toLowerCase())
    .filter(Boolean);

  const handleFiles = (files: FileList | null) => {
    const file = files?.[0];
    if (!file) {
      return;
    }

    // Client-side check only for fast feedback; the server re-validates the
    // MIME type and the image contents. An empty `type` (some file managers and
    // drag sources do not report one) is passed through rather than rejected,
    // because guessing wrong here would block a perfectly valid image.
    const type = file.type.toLowerCase();
    if (type && allowed.length > 0 && !allowed.includes(type)) {
      onReject?.(invalidMessage);
      return;
    }

    onFile(file);
  };

  const onDragOver = (event: DragEvent<HTMLButtonElement>) => {
    if (inactive) {
      return;
    }
    // Without `preventDefault` the browser refuses the drop and may navigate
    // away to the dropped file instead.
    event.preventDefault();
    event.dataTransfer.dropEffect = 'copy';
    setDragging(true);
  };

  const onDragLeave = (event: DragEvent<HTMLButtonElement>) => {
    // `dragleave` also fires when the pointer crosses into a child element
    // (the icon or the label), which would flicker the highlight off and on.
    // Only clear the state when the pointer actually left the button.
    const next = event.relatedTarget;
    if (next instanceof Node && event.currentTarget.contains(next)) {
      return;
    }
    setDragging(false);
  };

  const onDrop = (event: DragEvent<HTMLButtonElement>) => {
    event.preventDefault();
    setDragging(false);
    if (inactive) {
      return;
    }
    handleFiles(event.dataTransfer.files);
  };

  return (
    <div className="penalis-dropzone-field">
      <button
        type="button"
        className={`penalis-dropzone${dragging ? ' is-dragging' : ''}`}
        aria-disabled={inactive}
        aria-busy={busy}
        aria-describedby={hint ? hintId : undefined}
        onClick={() => {
          if (inactive) {
            return;
          }
          inputRef.current?.click();
        }}
        onDragEnter={onDragOver}
        onDragOver={onDragOver}
        onDragLeave={onDragLeave}
        onDrop={onDrop}
      >
        <span className="penalis-dropzone__icon" aria-hidden="true">
          {busy ? <span className="penalis-spinner" /> : <Icon name="image" />}
        </span>
        <span className="penalis-dropzone__title">{busy ? 'Uploading…' : title}</span>
        {busy ? null : <span className="penalis-dropzone__subtitle">{subtitle}</span>}
      </button>

      <input
        ref={inputRef}
        type="file"
        accept={accept}
        className="penalis-dropzone__input"
        tabIndex={-1}
        disabled={inactive}
        onChange={(event) => {
          handleFiles(event.target.files);
          // Reset so choosing the same file twice still fires `change`.
          event.target.value = '';
        }}
      />

      {hint ? (
        <small className="penalis-dropzone__hint" id={hintId}>
          {hint}
        </small>
      ) : null}
    </div>
  );
}
