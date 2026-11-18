/**
 * Keyboard shortcut reference for the editor.
 *
 * The shortcuts work whether or not this dialog is open, so the dialog's job is
 * discoverability: the `?` key alone is invisible knowledge, and the sticky bar
 * button that opens it is the only way to find out it exists.
 *
 * Rendered through `Dialog` for the focus trap, Escape handling, and focus
 * restore. It is not a `ConfirmDialog`: nothing here can be confirmed or
 * cancelled, so it gets a single "Tutup" action instead of a cancel/confirm
 * pair that would imply a consequence.
 */

import { Dialog } from '../../components/Dialog';

interface ShortcutRow {
  keys: string[];
  label: string;
}

const SHORTCUTS: ShortcutRow[] = [
  { keys: ['Ctrl', 'S'], label: 'Simpan draft' },
  { keys: ['Ctrl', 'Enter'], label: 'Submit karya tulis untuk review' },
  { keys: ['?'], label: 'Buka daftar pintasan ini' },
  { keys: ['Esc'], label: 'Tutup dialog' },
];

export function EditorShortcutsDialog({ onClose }: { onClose: () => void }) {
  return (
    <Dialog label="Pintasan keyboard" onClose={onClose}>
      <div className="penalis-modal__content penalis-modal__content--confirm">
        <div className="penalis-modal__header">
          <h3>Pintasan keyboard</h3>
        </div>

        <dl className="penalis-shortcuts">
          {SHORTCUTS.map((shortcut) => (
            <div className="penalis-shortcuts__row" key={shortcut.label}>
              <dt>
                {shortcut.keys.map((name, index) => (
                  <span className="penalis-shortcuts__chord" key={name}>
                    {index > 0 ? (
                      <span className="penalis-shortcuts__plus" aria-hidden="true">
                        +
                      </span>
                    ) : null}
                    <kbd>{name}</kbd>
                  </span>
                ))}
              </dt>
              <dd>{shortcut.label}</dd>
            </div>
          ))}
        </dl>

        <p className="penalis-fineprint">
          Di macOS, gunakan Command (⌘) sebagai pengganti Ctrl. Pintasan tidak aktif saat kursor berada di
          kolom teks — kecuali Ctrl + S dan Ctrl + Enter.
        </p>

        <div className="penalis-confirm__actions">
          <button type="button" className="penalis-button penalis-button--primary" onClick={onClose}>
            Tutup
          </button>
        </div>
      </div>
    </Dialog>
  );
}
