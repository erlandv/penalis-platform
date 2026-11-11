/**
 * Interactive avatar cropper modal using native HTML5 Canvas.
 *
 * Allows writers to pan and zoom any image (landscape, portrait, or square)
 * to produce a crisp 1:1 circular/square profile photo with zero external dependencies.
 */

import { useCallback, useEffect, useRef, useState, type PointerEvent, type WheelEvent } from 'react';
import {
  CROP_OUTPUT_SIZE,
  CROP_VIEWPORT_SIZE,
  clampCropOffset,
  type CropPoint,
  type CropSize,
} from '../lib/crop';
import { Dialog } from './Dialog';
import { ErrorBanner, Spinner } from './ui';

export function AvatarCropper({
  imageSource,
  onCrop,
  onCancel,
  busy = false,
}: {
  imageSource: File | string;
  onCrop: (file: File) => Promise<void> | void;
  onCancel: () => void;
  busy?: boolean;
}) {
  const [resolvedUrl, setResolvedUrl] = useState('');
  const [naturalSize, setNaturalSize] = useState<CropSize>({ width: 0, height: 0 });
  const [offset, setOffset] = useState<CropPoint>({ x: 0, y: 0 });
  const [zoom, setZoom] = useState(1);
  const [loading, setLoading] = useState(true);
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState('');

  const imgRef = useRef<HTMLImageElement>(null);
  const isDraggingRef = useRef(false);
  const dragStartRef = useRef<CropPoint>({ x: 0, y: 0 });
  const offsetStartRef = useRef<CropPoint>({ x: 0, y: 0 });

  useEffect(() => {
    let active = true;
    let objectUrlToRevoke = '';

    const img = new Image();
    if (
      typeof imageSource === 'string' &&
      !imageSource.startsWith('blob:') &&
      !imageSource.startsWith('data:')
    ) {
      img.crossOrigin = 'anonymous';
    }

    const src =
      typeof imageSource === 'string'
        ? imageSource
        : (objectUrlToRevoke = URL.createObjectURL(imageSource));

    setResolvedUrl(src);
    setLoading(true);
    setError('');

    img.onload = () => {
      if (!active) {
        return;
      }
      setNaturalSize({ width: img.naturalWidth, height: img.naturalHeight });
      setOffset({ x: 0, y: 0 });
      setZoom(1);
      setLoading(false);
    };

    img.onerror = () => {
      if (!active) {
        return;
      }
      setError('Gambar tidak dapat dimuat. Coba gunakan gambar lain.');
      setLoading(false);
    };

    img.src = src;

    return () => {
      active = false;
      if (objectUrlToRevoke) {
        URL.revokeObjectURL(objectUrlToRevoke);
      }
    };
  }, [imageSource]);

  const handlePointerDown = (e: PointerEvent<HTMLDivElement>) => {
    if (loading || busy || processing || Boolean(error)) {
      return;
    }
    try {
      e.currentTarget.setPointerCapture(e.pointerId);
    } catch {
      // Ignored if capture unsupported
    }
    isDraggingRef.current = true;
    dragStartRef.current = { x: e.clientX, y: e.clientY };
    offsetStartRef.current = { ...offset };
  };

  const handlePointerMove = (e: PointerEvent<HTMLDivElement>) => {
    if (!isDraggingRef.current) {
      return;
    }
    const dx = e.clientX - dragStartRef.current.x;
    const dy = e.clientY - dragStartRef.current.y;
    setOffset(
      clampCropOffset(
        offsetStartRef.current.x + dx,
        offsetStartRef.current.y + dy,
        naturalSize.width,
        naturalSize.height,
        zoom,
      ),
    );
  };

  const handlePointerUp = (e: PointerEvent<HTMLDivElement>) => {
    if (isDraggingRef.current) {
      try {
        e.currentTarget.releasePointerCapture(e.pointerId);
      } catch {
        // Ignored if capture already lost
      }
      isDraggingRef.current = false;
    }
  };

  const handleWheel = (e: WheelEvent<HTMLDivElement>) => {
    e.preventDefault();
    const delta = e.deltaY < 0 ? 0.08 : -0.08;
    setZoom((prev) => {
      const next = Math.max(1, Math.min(3, prev + delta));
      setOffset((prevOffset) =>
        clampCropOffset(prevOffset.x, prevOffset.y, naturalSize.width, naturalSize.height, next),
      );
      return next;
    });
  };

  const handleZoomChange = (newZoom: number) => {
    setZoom(newZoom);
    setOffset((prevOffset) =>
      clampCropOffset(prevOffset.x, prevOffset.y, naturalSize.width, naturalSize.height, newZoom),
    );
  };

  const handleConfirm = useCallback(async () => {
    if (
      loading ||
      busy ||
      processing ||
      !imgRef.current ||
      naturalSize.width === 0 ||
      naturalSize.height === 0
    ) {
      return;
    }

    setProcessing(true);
    setError('');

    try {
      const img = imgRef.current;
      const canvas = document.createElement('canvas');
      canvas.width = CROP_OUTPUT_SIZE;
      canvas.height = CROP_OUTPUT_SIZE;
      const ctx = canvas.getContext('2d');

      if (!ctx) {
        setError('Gagal membuat kanvas gambar.');
        setProcessing(false);
        return;
      }

      const baseScale = Math.max(
        CROP_VIEWPORT_SIZE / naturalSize.width,
        CROP_VIEWPORT_SIZE / naturalSize.height,
      );
      const currentScale = baseScale * zoom;

      const cropNaturalSize = CROP_VIEWPORT_SIZE / currentScale;
      const centerNaturalX = naturalSize.width / 2 - offset.x / currentScale;
      const centerNaturalY = naturalSize.height / 2 - offset.y / currentScale;

      const sx = centerNaturalX - cropNaturalSize / 2;
      const sy = centerNaturalY - cropNaturalSize / 2;
      const sWidth = cropNaturalSize;
      const sHeight = cropNaturalSize;

      ctx.imageSmoothingEnabled = true;
      ctx.imageSmoothingQuality = 'high';

      ctx.drawImage(img, sx, sy, sWidth, sHeight, 0, 0, CROP_OUTPUT_SIZE, CROP_OUTPUT_SIZE);

      const blob = await new Promise<Blob | null>((resolve) => {
        canvas.toBlob((b) => resolve(b), 'image/jpeg', 0.92);
      });

      if (!blob) {
        setError('Gagal memproses potongan gambar.');
        setProcessing(false);
        return;
      }

      const croppedFile = new File([blob], 'avatar-profile.jpg', {
        type: 'image/jpeg',
        lastModified: Date.now(),
      });

      await onCrop(croppedFile);
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Gagal memotong gambar.');
    } finally {
      setProcessing(false);
    }
  }, [loading, busy, processing, naturalSize, zoom, offset, onCrop]);

  const baseScale =
    naturalSize.width > 0 && naturalSize.height > 0
      ? Math.max(CROP_VIEWPORT_SIZE / naturalSize.width, CROP_VIEWPORT_SIZE / naturalSize.height)
      : 1;
  const scaledBaseWidth = naturalSize.width * baseScale;
  const scaledBaseHeight = naturalSize.height * baseScale;

  return (
    <Dialog label="Sesuaikan Foto Profil" onClose={busy || processing ? () => {} : onCancel}>
      <div className="penalis-modal__content penalis-modal__content--cropper">
        <div className="penalis-modal__header">
          <h3>Sesuaikan Foto Profil</h3>
        </div>

        {error ? <ErrorBanner message={error} /> : null}

        {loading ? (
          <Spinner label="Memuat gambar…" />
        ) : (
          <div className="penalis-cropper">
            <div
              className="penalis-cropper__viewport"
              onPointerDown={handlePointerDown}
              onPointerMove={handlePointerMove}
              onPointerUp={handlePointerUp}
              onPointerCancel={handlePointerUp}
              onWheel={handleWheel}
              role="region"
              aria-label="Area pemotongan foto. Geser untuk mengatur posisi."
            >
              <img
                ref={imgRef}
                src={resolvedUrl}
                alt=""
                className="penalis-cropper__image"
                style={{
                  width: `${scaledBaseWidth}px`,
                  height: `${scaledBaseHeight}px`,
                  transform: `translate(-50%, -50%) translate(${offset.x}px, ${offset.y}px) scale(${zoom})`,
                  transformOrigin: 'center center',
                }}
                draggable={false}
              />
              <div className="penalis-cropper__mask" aria-hidden="true" />
            </div>

            <p className="penalis-cropper__hint">
              Geser foto untuk menyesuaikan posisi dan atur zoom di bawah.
            </p>

            <div className="penalis-cropper__controls">
              <span className="penalis-cropper__zoom-label" id="zoom-slider-label">
                Zoom
              </span>
              <input
                type="range"
                min="1"
                max="3"
                step="0.01"
                value={zoom}
                onChange={(e) => handleZoomChange(parseFloat(e.target.value))}
                aria-labelledby="zoom-slider-label"
                className="penalis-cropper__slider"
                disabled={busy || processing}
              />
              <span className="penalis-cropper__zoom-value">{Math.round(zoom * 100)}%</span>
            </div>

            <div className="penalis-cropper__actions">
              <button
                type="button"
                className="penalis-button penalis-button--ghost"
                onClick={onCancel}
                disabled={busy || processing}
              >
                Batal
              </button>
              <button
                type="button"
                className="penalis-button penalis-button--primary"
                onClick={() => void handleConfirm()}
                disabled={busy || processing || Boolean(error)}
              >
                {busy || processing ? 'Menyimpan…' : 'Gunakan Foto Ini'}
              </button>
            </div>
          </div>
        )}
      </div>
    </Dialog>
  );
}
