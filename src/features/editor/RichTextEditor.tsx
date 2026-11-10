/**
 * Rich text editor built on TipTap.
 *
 * TipTap is a headless ProseMirror wrapper: it renders HTML into an editable
 * area and hands the resulting HTML back. That matches how this plugin already
 * stores articles (`post_content`), so no content migration is needed and the
 * server-side `wp_kses` filtering stays the only authority on what is allowed.
 *
 * A raw HTML mode is provided as an escape hatch. It also keeps articles that
 * contain markup the toolbar cannot express from being silently rewritten.
 */

import { forwardRef, useCallback, useEffect, useImperativeHandle, useRef, useState } from 'react';
import { EditorContent, useEditor, useEditorState } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import Image from '@tiptap/extension-image';
import Placeholder from '@tiptap/extension-placeholder';

import { Icon } from '../../components/icons';
import { trimTrailingEmptyParagraphs } from '../../lib/html';
import { normalizeLinkHref } from '../../lib/validation';

export type RichTextEditorHandle = {
  /** Insert an image at the current caret position. */
  insertImage: (src: string, alt: string) => void;
};

export type RichTextEditorProps = {
  /** Current HTML value. */
  value: string;
  /** Called with the new HTML whenever the content changes. */
  onChange: (html: string) => void;
  /** Called when the writer asks to insert an image. */
  onRequestImage: () => void;
  /** ID of the visible label, for `aria-labelledby`. */
  labelledBy?: string;
  /** Whether the field is marked invalid. */
  invalid?: boolean;
  /** ID of the error element, for `aria-describedby`. */
  errorId?: string;
  /** Whether the editor is locked from modification. */
  readOnly?: boolean;
};

type ToolbarButtonProps = {
  label: string;
  title: string;
  /** Marks the button as a formatting toggle, which needs `aria-pressed`. */
  toggle?: boolean;
  active?: boolean;
  disabled?: boolean;
  onClick: () => void;
  children: React.ReactNode;
};

function ToolbarButton({
  label,
  title,
  toggle = false,
  active = false,
  disabled = false,
  onClick,
  children,
}: ToolbarButtonProps) {
  return (
    <button
      type="button"
      className={`penalis-editor-toolbar__button${active ? ' is-active' : ''}`}
      aria-label={label}
      // Only real toggles carry `aria-pressed`; an action button with a
      // permanent "pressed = false" would be announced as an unset toggle.
      {...(toggle ? { 'aria-pressed': active } : {})}
      title={title}
      disabled={disabled}
      onClick={onClick}
    >
      {children}
    </button>
  );
}

export const RichTextEditor = forwardRef<RichTextEditorHandle, RichTextEditorProps>(function RichTextEditor(
  { value, onChange, onRequestImage, labelledBy, invalid = false, errorId, readOnly = false },
  ref,
) {
  const [htmlMode, setHtmlMode] = useState(false);
  const [linkOpen, setLinkOpen] = useState(false);
  const [linkHref, setLinkHref] = useState('');
  const [linkError, setLinkError] = useState('');
  const lastEmitted = useRef(value);
  const linkInputRef = useRef<HTMLInputElement>(null);

  const editor = useEditor({
    editable: !readOnly,
    extensions: [
      StarterKit.configure({
        // StarterKit v3 bundles Link and Underline. Configure them here instead
        // of registering the extensions again, which TipTap rejects as
        // duplicates.
        heading: { levels: [2, 3] },
        // The dashboard never needs raw HTML blocks; writers cannot publish
        // unfiltered markup anyway.
        codeBlock: false,
        link: {
          openOnClick: false,
          autolink: true,
          protocols: ['http', 'https', 'mailto'],
          HTMLAttributes: { rel: 'noopener noreferrer nofollow' },
        },
      }),
      Image.configure({ inline: false, allowBase64: false }),
      Placeholder.configure({
        placeholder: 'Mulai tulis karya kamu di sini…',
      }),
    ],
    content: value,
    editorProps: {
      attributes: {
        class: 'penalis-editor-content',
        role: 'textbox',
        'aria-multiline': 'true',
        ...(readOnly ? { 'aria-readonly': 'true' } : {}),
        ...(labelledBy ? { 'aria-labelledby': labelledBy } : {}),
        ...(errorId ? { 'aria-describedby': errorId } : {}),
        ...(invalid ? { 'aria-invalid': 'true' } : {}),
      },
    },
    onUpdate: ({ editor: instance }) => {
      if (readOnly) {
        return;
      }
      // TipTap keeps an empty paragraph after a block node so the writer can
      // keep typing there. It is useful in the editor but noise in stored
      // `post_content`, so it is trimmed before leaving the component.
      const html = trimTrailingEmptyParagraphs(instance.getHTML());
      lastEmitted.current = html;
      onChange(html);
    },
  });

  useEffect(() => {
    if (!editor) {
      return;
    }

    editor.setEditable(!readOnly);
    const element = editor.view.dom;
    if (readOnly) {
      element.setAttribute('aria-readonly', 'true');
      setLinkOpen(false);
    } else {
      element.removeAttribute('aria-readonly');
    }
  }, [editor, readOnly]);

  // Keep the editor in sync when the parent replaces the value, for example
  // after loading an article. Skipping identical content avoids resetting the
  // cursor while the writer is typing.
  useEffect(() => {
    if (!editor || htmlMode) {
      return;
    }

    if (value === lastEmitted.current) {
      return;
    }

    lastEmitted.current = value;

    // `emitUpdate: false` prevents an onChange loop.
    editor.commands.setContent(value, { emitUpdate: false });
  }, [editor, value, htmlMode]);

  // Reflect validity changes on the editable element.
  useEffect(() => {
    if (!editor) {
      return;
    }

    const element = editor.view.dom;
    element.setAttribute('aria-invalid', invalid ? 'true' : 'false');

    if (errorId) {
      element.setAttribute('aria-describedby', errorId);
    } else {
      element.removeAttribute('aria-describedby');
    }
  }, [editor, invalid, errorId]);

  // `aria-labelledby` is set once at creation because the label is stable.
  useEffect(() => {
    if (!editor || !labelledBy) {
      return;
    }

    editor.view.dom.setAttribute('aria-labelledby', labelledBy);
  }, [editor, labelledBy]);

  // Move focus into the link field as soon as the popover opens.
  useEffect(() => {
    if (linkOpen) {
      linkInputRef.current?.focus();
    }
  }, [linkOpen]);

  const state = useEditorState({
    editor,
    selector: ({ editor: instance }) => {
      if (!instance) {
        return null;
      }

      return {
        bold: instance.isActive('bold'),
        italic: instance.isActive('italic'),
        underline: instance.isActive('underline'),
        strike: instance.isActive('strike'),
        bulletList: instance.isActive('bulletList'),
        orderedList: instance.isActive('orderedList'),
        blockquote: instance.isActive('blockquote'),
        heading2: instance.isActive('heading', { level: 2 }),
        heading3: instance.isActive('heading', { level: 3 }),
        link: instance.isActive('link'),
        canUndo: instance.can().undo(),
        canRedo: instance.can().redo(),
      };
    },
  });

  /**
   * Leave raw HTML mode and push the latest value into the visual editor.
   *
   * The sync effect deliberately skips values it has already emitted, so
   * edits made in the textarea would otherwise be ignored on the way back.
   */
  const exitHtmlMode = useCallback(() => {
    setHtmlMode(false);

    if (!editor) {
      return;
    }

    lastEmitted.current = value;
    editor.commands.setContent(value, { emitUpdate: false });
  }, [editor, value]);

  const openLinkDialog = useCallback(() => {
    if (!editor) {
      return;
    }

    setLinkHref((editor.getAttributes('link').href as string | undefined) ?? '');
    setLinkError('');
    setLinkOpen(true);
  }, [editor]);

  const closeLinkDialog = useCallback(() => {
    setLinkOpen(false);
    editor?.commands.focus();
  }, [editor]);

  const applyLink = useCallback(() => {
    if (!editor) {
      return;
    }

    const typed = linkHref.trim();

    // An empty value is an explicit request to drop the link.
    if (typed === '') {
      editor.chain().focus().extendMarkRange('link').unsetLink().run();
      setLinkOpen(false);
      return;
    }

    const href = normalizeLinkHref(typed);
    if (href === null) {
      setLinkError('Gunakan link lengkap https://…, alamat email mailto:, atau jalur yang diawali /.');
      return;
    }

    editor.chain().focus().extendMarkRange('link').setLink({ href }).run();
    setLinkOpen(false);
  }, [editor, linkHref]);

  const removeLink = useCallback(() => {
    editor?.chain().focus().extendMarkRange('link').unsetLink().run();
    setLinkOpen(false);
  }, [editor]);

  useImperativeHandle(
    ref,
    () => ({
      insertImage: (src: string, alt: string) => {
        if (!editor || readOnly) {
          return;
        }

        // Leaving raw HTML mode must go through `exitHtmlMode()`: the sync
        // effect skips values this component already emitted, so otherwise the
        // image lands in the stale visual document and the edits made in the
        // textarea are silently discarded.
        if (htmlMode) {
          exitHtmlMode();
        }

        editor.chain().focus().setImage({ src, alt }).run();
      },
    }),
    [editor, htmlMode, exitHtmlMode, readOnly],
  );

  if (readOnly) {
    return (
      <div className="penalis-editor-shell is-readonly">
        <div className="penalis-editor-toolbar" aria-label="Status isi karya tulis">
          <span className="penalis-editor-toolbar__hint">
            Read-only mode — isi karya tulis tidak dapat diubah pada status saat ini.
          </span>
        </div>
        <EditorContent editor={editor} />
      </div>
    );
  }

  if (htmlMode) {
    return (
      <div className={`penalis-editor-shell${invalid ? ' is-invalid' : ''}`}>
        <div className="penalis-editor-toolbar">
          <ToolbarButton
            label="Kembali ke editor visual"
            title="Kembali ke editor visual"
            onClick={exitHtmlMode}
          >
            <Icon name="code" />
          </ToolbarButton>
          <span className="penalis-editor-toolbar__hint">
            Mode HTML. Perubahan di sini langsung tersimpan saat kamu menyimpan karya tulis.
          </span>
        </div>

        <textarea
          className="penalis-editor-html"
          value={value}
          onChange={(event) => {
            lastEmitted.current = event.target.value;
            onChange(event.target.value);
          }}
          rows={18}
          spellCheck={false}
          aria-label="Isi karya tulis dalam HTML"
          aria-invalid={invalid}
          aria-describedby={errorId}
        />
      </div>
    );
  }

  return (
    <div className={`penalis-editor-shell${invalid ? ' is-invalid' : ''}`}>
      <div className="penalis-editor-toolbar" role="toolbar" aria-label="Pemformatan teks">
        <ToolbarButton
          label="Tebal"
          title="Tebal (Ctrl+B)"
          toggle
          active={state?.bold}
          onClick={() => editor?.chain().focus().toggleBold().run()}
        >
          <Icon name="bold" />
        </ToolbarButton>

        <ToolbarButton
          label="Miring"
          title="Miring (Ctrl+I)"
          toggle
          active={state?.italic}
          onClick={() => editor?.chain().focus().toggleItalic().run()}
        >
          <Icon name="italic" />
        </ToolbarButton>

        <ToolbarButton
          label="Garis bawah"
          title="Garis bawah (Ctrl+U)"
          toggle
          active={state?.underline}
          onClick={() => editor?.chain().focus().toggleUnderline().run()}
        >
          <Icon name="underline" />
        </ToolbarButton>

        <ToolbarButton
          label="Coret"
          title="Coret"
          toggle
          active={state?.strike}
          onClick={() => editor?.chain().focus().toggleStrike().run()}
        >
          <Icon name="strike" />
        </ToolbarButton>

        <span className="penalis-editor-toolbar__divider" aria-hidden="true" />

        <ToolbarButton
          label="Judul bagian"
          title="Judul bagian"
          toggle
          active={state?.heading2}
          onClick={() => editor?.chain().focus().toggleHeading({ level: 2 }).run()}
        >
          <Icon name="heading2" />
        </ToolbarButton>

        <ToolbarButton
          label="Subjudul"
          title="Subjudul"
          toggle
          active={state?.heading3}
          onClick={() => editor?.chain().focus().toggleHeading({ level: 3 }).run()}
        >
          <Icon name="heading3" />
        </ToolbarButton>

        <span className="penalis-editor-toolbar__divider" aria-hidden="true" />

        <ToolbarButton
          label="Daftar berpoin"
          title="Daftar berpoin"
          toggle
          active={state?.bulletList}
          onClick={() => editor?.chain().focus().toggleBulletList().run()}
        >
          <Icon name="bulletList" />
        </ToolbarButton>

        <ToolbarButton
          label="Daftar bernomor"
          title="Daftar bernomor"
          toggle
          active={state?.orderedList}
          onClick={() => editor?.chain().focus().toggleOrderedList().run()}
        >
          <Icon name="orderedList" />
        </ToolbarButton>

        <ToolbarButton
          label="Kutipan"
          title="Kutipan"
          toggle
          active={state?.blockquote}
          onClick={() => editor?.chain().focus().toggleBlockquote().run()}
        >
          <Icon name="quote" />
        </ToolbarButton>

        <ToolbarButton
          label="Link"
          title="Tambah atau ubah link"
          toggle
          active={state?.link}
          onClick={openLinkDialog}
        >
          <Icon name="link" />
        </ToolbarButton>

        <ToolbarButton label="Sisipkan gambar" title="Sisipkan gambar" onClick={onRequestImage}>
          <Icon name="image" />
        </ToolbarButton>

        <span className="penalis-editor-toolbar__divider" aria-hidden="true" />

        <ToolbarButton
          label="Urungkan"
          title="Urungkan (Ctrl+Z)"
          disabled={!state?.canUndo}
          onClick={() => editor?.chain().focus().undo().run()}
        >
          <Icon name="undo" />
        </ToolbarButton>

        <ToolbarButton
          label="Ulangi"
          title="Ulangi (Ctrl+Shift+Z)"
          disabled={!state?.canRedo}
          onClick={() => editor?.chain().focus().redo().run()}
        >
          <Icon name="redo" />
        </ToolbarButton>

        <span className="penalis-editor-toolbar__spacer" />

        <ToolbarButton
          label="Lihat kode HTML"
          title="Lihat kode HTML"
          onClick={() => {
            setLinkOpen(false);
            setHtmlMode(true);
          }}
        >
          <Icon name="code" />
        </ToolbarButton>
      </div>

      {linkOpen ? (
        <div className="penalis-editor-linkpop">
          <label className="penalis-editor-linkpop__label" htmlFor="penalis-link-href">
            URL
          </label>
          <div className="penalis-editor-linkpop__row">
            <input
              ref={linkInputRef}
              id="penalis-link-href"
              type="url"
              value={linkHref}
              placeholder="https://contoh.com/tulisan"
              onChange={(event) => {
                setLinkHref(event.target.value);
                setLinkError('');
              }}
              onKeyDown={(event) => {
                if (event.key === 'Enter') {
                  event.preventDefault();
                  applyLink();
                }

                if (event.key === 'Escape') {
                  event.preventDefault();
                  closeLinkDialog();
                }
              }}
              aria-invalid={Boolean(linkError)}
              aria-describedby={linkError ? 'penalis-link-error' : undefined}
            />
            <button type="button" className="penalis-button" onClick={applyLink}>
              Pasang
            </button>
            {state?.link ? (
              <button type="button" className="penalis-button penalis-button--ghost" onClick={removeLink}>
                Hapus link
              </button>
            ) : null}
            <button type="button" className="penalis-button penalis-button--ghost" onClick={closeLinkDialog}>
              Batal
            </button>
          </div>
          {linkError ? (
            <p className="penalis-field-error" id="penalis-link-error">
              {linkError}
            </p>
          ) : (
            <p className="penalis-editor-linkpop__hint">
              Kosongkan kolom untuk menghapus link. Enter memasang, Esc membatalkan.
            </p>
          )}
        </div>
      ) : null}

      <EditorContent editor={editor} />
    </div>
  );
});
