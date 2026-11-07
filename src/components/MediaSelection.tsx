/**
 * Shared image selection controls for the media picker dialogs.
 *
 * Used by the article editor (featured image / insert image) and the profile
 * avatar picker, so all three dialogs behave the same way: the whole card is
 * the target, the selection is a native radio group, and a single "Pilih"
 * button in a sticky footer applies it.
 *
 * The component is presentational on purpose — it owns no data fetching and no
 * upload logic, because each caller has a different source and a different
 * meaning for "confirm".
 */

import { useId } from 'react';

import type { MediaItem } from '../lib/types';

export function MediaSelection({
  items,
  selectedId,
  onSelect,
  onConfirm,
  onCancel,
  busy = false,
  emptyMessage = 'Belum ada gambar. Unggah gambar terlebih dahulu.',
}: {
  items: MediaItem[];
  selectedId: number | null;
  onSelect: (id: number) => void;
  onConfirm: () => void;
  onCancel: () => void;
  busy?: boolean;
  emptyMessage?: string;
}) {
  // A unique radio group name per instance, so two pickers on one page cannot
  // steal each other's selection.
  const groupName = useId();
  const selected = items.find((item) => item.id === selectedId) ?? null;

  return (
    <>
      {items.length === 0 ? (
        <p className="penalis-muted">{emptyMessage}</p>
      ) : (
        <div className="penalis-media-grid" role="radiogroup" aria-label="Pilih gambar">
          {items.map((item) => {
            const isSelected = item.id === selectedId;
            const title = item.title || 'Tanpa judul';

            return (
              <label
                key={item.id}
                className={`penalis-media-card penalis-media-card--selectable ${isSelected ? 'is-selected' : ''}`}
                title={title}
              >
                <input
                  type="radio"
                  name={groupName}
                  className="penalis-media-card__radio"
                  checked={isSelected}
                  disabled={busy}
                  onChange={() => onSelect(item.id)}
                />
                <img src={item.thumbnail || item.url} alt="" />
                <span className="penalis-media-card__caption">{title}</span>
              </label>
            );
          })}
        </div>
      )}

      <div className="penalis-media-picker__footer">
        <p className="penalis-muted penalis-media-picker__selection" role="status" aria-live="polite">
          {selected ? `Dipilih: ${selected.title || 'Tanpa judul'}` : 'Belum ada gambar yang dipilih.'}
        </p>
        <div className="penalis-media-picker__actions">
          <button type="button" className="penalis-button penalis-button--ghost" onClick={onCancel} disabled={busy}>
            Batal
          </button>
          <button
            type="button"
            className="penalis-button penalis-button--primary"
            onClick={onConfirm}
            disabled={busy || !selected}
          >
            {busy ? 'Memproses…' : 'Pilih'}
          </button>
        </div>
      </div>
    </>
  );
}
