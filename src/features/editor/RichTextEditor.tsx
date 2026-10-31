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

import { trimTrailingEmptyParagraphs } from '../../lib/html';

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
};

type ToolbarButtonProps = {
  label: string;
  title: string;
  active?: boolean;
  disabled?: boolean;
  onClick: () => void;
  children: React.ReactNode;
};

function ToolbarButton({ label, title, active = false, disabled = false, onClick, children }: ToolbarButtonProps) {
  return (
    <button
      type="button"
      className={`penalis-editor-toolbar__button${active ? ' is-active' : ''}`}
      aria-label={label}
      aria-pressed={active}
      title={title}
      disabled={disabled}
      onClick={onClick}
    >
      {children}
    </button>
  );
}

export const RichTextEditor = forwardRef<RichTextEditorHandle, RichTextEditorProps>(function RichTextEditor(
  { value, onChange, onRequestImage, labelledBy, invalid = false, errorId },
  ref,
) {
  const [htmlMode, setHtmlMode] = useState(false);
  const lastEmitted = useRef(value);

  const editor = useEditor({
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
        placeholder: 'Mulai tulis artikel kamu di sini…',
      }),
    ],
    content: value,
    editorProps: {
      attributes: {
        class: 'penalis-editor-content',
        role: 'textbox',
        'aria-multiline': 'true',
        ...(labelledBy ? { 'aria-labelledby': labelledBy } : {}),
        ...(errorId ? { 'aria-describedby': errorId } : {}),
        ...(invalid ? { 'aria-invalid': 'true' } : {}),
      },
    },
    onUpdate: ({ editor: instance }) => {
      // TipTap keeps an empty paragraph after a block node so the writer can
      // keep typing there. It is useful in the editor but noise in stored
      // `post_content`, so it is trimmed before leaving the component.
      const html = trimTrailingEmptyParagraphs(instance.getHTML());
      lastEmitted.current = html;
      onChange(html);
    },  });

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

  const setLink = useCallback(() => {
    if (!editor) {
      return;
    }

    const previous = editor.getAttributes('link').href as string | undefined;
    const url = window.prompt('Masukkan URL tautan', previous ?? 'https://');

    if (url === null) {
      return;
    }

    if (url.trim() === '') {
      editor.chain().focus().extendMarkRange('link').unsetLink().run();
      return;
    }

    editor.chain().focus().extendMarkRange('link').setLink({ href: url.trim() }).run();
  }, [editor]);

  useImperativeHandle(
    ref,
    () => ({
      insertImage: (src: string, alt: string) => {
        if (!editor) {
          return;
        }

        // Switching out of HTML mode first means the image lands in the rich
        // text document instead of being lost behind the raw textarea.
        setHtmlMode(false);
        editor.chain().focus().setImage({ src, alt }).run();
      },
    }),
    [editor],
  );

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

  if (htmlMode) {
    return (
      <div className={`penalis-editor-shell${invalid ? ' is-invalid' : ''}`}>
        <div className="penalis-editor-toolbar">
          <ToolbarButton
            label="Kembali ke editor visual"
            title="Kembali ke editor visual"
            onClick={exitHtmlMode}
          >
            Editor visual
          </ToolbarButton>
          <span className="penalis-editor-toolbar__hint">
            Mode HTML. Perubahan di sini langsung tersimpan saat kamu menyimpan artikel.
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
          aria-label="Isi artikel dalam HTML"
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
          active={state?.bold}
          onClick={() => editor?.chain().focus().toggleBold().run()}
        >
          <strong>B</strong>
        </ToolbarButton>

        <ToolbarButton
          label="Miring"
          title="Miring (Ctrl+I)"
          active={state?.italic}
          onClick={() => editor?.chain().focus().toggleItalic().run()}
        >
          <em>I</em>
        </ToolbarButton>

        <ToolbarButton
          label="Garis bawah"
          title="Garis bawah (Ctrl+U)"
          active={state?.underline}
          onClick={() => editor?.chain().focus().toggleUnderline().run()}
        >
          <span style={{ textDecoration: 'underline' }}>U</span>
        </ToolbarButton>

        <ToolbarButton
          label="Coret"
          title="Coret"
          active={state?.strike}
          onClick={() => editor?.chain().focus().toggleStrike().run()}
        >
          <span style={{ textDecoration: 'line-through' }}>S</span>
        </ToolbarButton>

        <span className="penalis-editor-toolbar__divider" aria-hidden="true" />

        <ToolbarButton
          label="Judul bagian"
          title="Judul bagian"
          active={state?.heading2}
          onClick={() => editor?.chain().focus().toggleHeading({ level: 2 }).run()}
        >
          H2
        </ToolbarButton>

        <ToolbarButton
          label="Subjudul"
          title="Subjudul"
          active={state?.heading3}
          onClick={() => editor?.chain().focus().toggleHeading({ level: 3 }).run()}
        >
          H3
        </ToolbarButton>

        <span className="penalis-editor-toolbar__divider" aria-hidden="true" />

        <ToolbarButton
          label="Daftar berpoin"
          title="Daftar berpoin"
          active={state?.bulletList}
          onClick={() => editor?.chain().focus().toggleBulletList().run()}
        >
          ••
        </ToolbarButton>

        <ToolbarButton
          label="Daftar bernomor"
          title="Daftar bernomor"
          active={state?.orderedList}
          onClick={() => editor?.chain().focus().toggleOrderedList().run()}
        >
          1.
        </ToolbarButton>

        <ToolbarButton
          label="Kutipan"
          title="Kutipan"
          active={state?.blockquote}
          onClick={() => editor?.chain().focus().toggleBlockquote().run()}
        >
          &ldquo;
        </ToolbarButton>

        <ToolbarButton label="Tautan" title="Tambah atau ubah tautan" active={state?.link} onClick={setLink}>
          🔗
        </ToolbarButton>

        <ToolbarButton label="Sisipkan gambar" title="Sisipkan gambar" onClick={onRequestImage}>
          🖼
        </ToolbarButton>

        <span className="penalis-editor-toolbar__divider" aria-hidden="true" />

        <ToolbarButton
          label="Urungkan"
          title="Urungkan (Ctrl+Z)"
          disabled={!state?.canUndo}
          onClick={() => editor?.chain().focus().undo().run()}
        >
          ↶
        </ToolbarButton>

        <ToolbarButton
          label="Ulangi"
          title="Ulangi (Ctrl+Shift+Z)"
          disabled={!state?.canRedo}
          onClick={() => editor?.chain().focus().redo().run()}
        >
          ↷
        </ToolbarButton>

        <span className="penalis-editor-toolbar__spacer" />

        <ToolbarButton label="Lihat kode HTML" title="Lihat kode HTML" onClick={() => setHtmlMode(true)}>
          &lt;/&gt;
        </ToolbarButton>
      </div>

      <EditorContent editor={editor} />
    </div>
  );
});
