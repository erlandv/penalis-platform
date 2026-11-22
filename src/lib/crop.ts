/**
 * Math, geometry, and client-side validation helpers for avatar cropping.
 */

export const CROP_VIEWPORT_SIZE = 280;
export const CROP_OUTPUT_SIZE = 256;
export const AVATAR_MIN_EDGE = 100;
export const AVATAR_MAX_BYTES = 5 * 1024 * 1024;
export const AVATAR_ACCEPT = 'image/jpeg,image/png,image/gif,image/webp';

const AVATAR_ALLOWED_TYPES = new Set([
  'image/jpeg',
  'image/png',
  'image/gif',
  'image/webp',
]);

export interface CropPoint {
  x: number;
  y: number;
}

export interface CropSize {
  width: number;
  height: number;
}

/**
 * Fast client-side check before opening the cropper.
 *
 * The server remains authoritative and re-validates MIME, dimensions, and
 * image bytes. An empty `type` is passed through because some OS file pickers
 * omit MIME metadata.
 */
export function validateAvatarFile(file: Pick<File, 'type' | 'size'>): string {
  const type = file.type.toLowerCase();
  if (type && !AVATAR_ALLOWED_TYPES.has(type)) {
    return 'Format file tidak didukung. Gunakan JPEG, PNG, GIF, atau WebP.';
  }

  if (file.size > AVATAR_MAX_BYTES) {
    return 'Ukuran gambar terlalu besar. Maksimal 5 MB.';
  }

  return '';
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
