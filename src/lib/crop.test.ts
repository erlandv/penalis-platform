import { describe, expect, it } from 'vitest';
import { clampCropOffset } from './crop';

describe('clampCropOffset', () => {
  it('returns zero point when dimensions are zero or invalid', () => {
    expect(clampCropOffset(50, 50, 0, 0, 1)).toEqual({ x: 0, y: 0 });
    expect(clampCropOffset(50, 50, -100, 100, 1)).toEqual({ x: 0, y: 0 });
  });

  it('keeps offset centered when image is square and zoom is 1', () => {
    // 500x500 image scaled to 280x280 viewport at zoom=1: maxOffset is 0
    expect(clampCropOffset(50, 50, 500, 500, 1)).toEqual({ x: 0, y: 0 });
  });

  it('allows horizontal panning for landscape images', () => {
    // 1000x500 image: baseScale = 280/500 = 0.56
    // scaledWidth = 560, scaledHeight = 280
    // maxOffsetX = (560 - 280) / 2 = 140
    // maxOffsetY = (280 - 280) / 2 = 0
    expect(clampCropOffset(50, 0, 1000, 500, 1)).toEqual({ x: 50, y: 0 });
    expect(clampCropOffset(200, 0, 1000, 500, 1)).toEqual({ x: 140, y: 0 });
    expect(clampCropOffset(-200, 0, 1000, 500, 1)).toEqual({ x: -140, y: 0 });
  });

  it('allows vertical panning for portrait images', () => {
    // 500x1000 image: baseScale = 280/500 = 0.56
    // scaledWidth = 280, scaledHeight = 560
    // maxOffsetX = 0, maxOffsetY = 140
    expect(clampCropOffset(0, 75, 500, 1000, 1)).toEqual({ x: 0, y: 75 });
    expect(clampCropOffset(0, 300, 500, 1000, 1)).toEqual({ x: 0, y: 140 });
    expect(clampCropOffset(0, -300, 500, 1000, 1)).toEqual({ x: 0, y: -140 });
  });

  it('expands pan bounds proportionally when zoomed in', () => {
    // 500x500 square at zoom=2:
    // scaledWidth = 560, scaledHeight = 560
    // maxOffsetX = 140, maxOffsetY = 140
    expect(clampCropOffset(100, 100, 500, 500, 2)).toEqual({ x: 100, y: 100 });
    expect(clampCropOffset(200, 200, 500, 500, 2)).toEqual({ x: 140, y: 140 });
  });
});

