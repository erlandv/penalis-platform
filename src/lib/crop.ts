/**
 * Math and geometry helpers for avatar cropping.
 */

export const CROP_VIEWPORT_SIZE = 280;
export const CROP_OUTPUT_SIZE = 500;

export interface CropPoint {
  x: number;
  y: number;
}

export interface CropSize {
  width: number;
  height: number;
}

/**
 * Clamp drag offsets so the circular crop area is always 100% covered by the image.
 */
export function clampCropOffset(
  offsetX: number,
  offsetY: number,
  naturalWidth: number,
  naturalHeight: number,
  zoomLevel: number,
  viewportSize: number = CROP_VIEWPORT_SIZE,
): CropPoint {
  if (naturalWidth <= 0 || naturalHeight <= 0) {
    return { x: 0, y: 0 };
  }

  const baseScale = Math.max(viewportSize / naturalWidth, viewportSize / naturalHeight);
  const currentScale = baseScale * zoomLevel;

  const scaledWidth = naturalWidth * currentScale;
  const scaledHeight = naturalHeight * currentScale;

  const maxOffsetX = Math.max(0, (scaledWidth - viewportSize) / 2);
  const maxOffsetY = Math.max(0, (scaledHeight - viewportSize) / 2);

  const clampedX = Math.max(-maxOffsetX, Math.min(maxOffsetX, offsetX));
  const clampedY = Math.max(-maxOffsetY, Math.min(maxOffsetY, offsetY));

  return { x: clampedX, y: clampedY };
}

