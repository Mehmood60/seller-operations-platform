import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { fileToListingImage, fitWithin, isEbayNativeFormat, MAX_IMAGE_EDGE } from '@/lib/images';

/** jsdom has no real canvas, so each test decides what the fake one produces. */
function stubCanvas(dataUrl: string | null) {
  const canvas = {
    width: 0,
    height: 0,
    getContext: () => ({ fillStyle: '', fillRect: vi.fn(), drawImage: vi.fn() }),
    toDataURL: () => {
      if (dataUrl === null) throw new Error('canvas unsupported');
      return dataUrl;
    },
  };
  vi.spyOn(document, 'createElement').mockImplementation(((tag: string) =>
    tag === 'canvas' ? canvas : ({} as HTMLElement)) as typeof document.createElement);
  return canvas;
}

/** A decoded bitmap of the given size, as createImageBitmap would return. */
function stubDecode(width: number, height: number) {
  vi.stubGlobal('createImageBitmap', vi.fn().mockResolvedValue({ width, height, close: vi.fn() }));
}

function stubDecodeFailure() {
  vi.stubGlobal('createImageBitmap', vi.fn().mockRejectedValue(new Error('unsupported type')));
  // The <img> fallback cannot decode it either.
  vi.stubGlobal('URL', { createObjectURL: () => 'blob:x', revokeObjectURL: vi.fn() });
  vi.stubGlobal('Image', class {
    onload: (() => void) | null = null;
    onerror: (() => void) | null = null;
    set src(_v: string) { setTimeout(() => this.onerror?.(), 0); }
  });
}

const JPEG = 'data:image/jpeg;base64,' + 'A'.repeat(128);

function file(name: string, type: string, size = 1024): File {
  const f = new File(['x'], name, { type });
  Object.defineProperty(f, 'size', { value: size });
  return f;
}

describe('fitWithin', () => {
  it('scales the long edge down to the limit and keeps the aspect ratio', () => {
    expect(fitWithin(4000, 3000, MAX_IMAGE_EDGE)).toEqual({ width: 1600, height: 1200 });
    expect(fitWithin(3000, 4000, MAX_IMAGE_EDGE)).toEqual({ width: 1200, height: 1600 });
  });

  it('never enlarges an image that is already small', () => {
    expect(fitWithin(800, 600, MAX_IMAGE_EDGE)).toEqual({ width: 800, height: 600 });
  });

  it('keeps extreme panoramas at least one pixel tall', () => {
    expect(fitWithin(20000, 5, MAX_IMAGE_EDGE).height).toBe(1);
  });

  it('rejects an image with no dimensions', () => {
    expect(() => fitWithin(0, 0, MAX_IMAGE_EDGE)).toThrow();
  });
});

describe('isEbayNativeFormat', () => {
  it('accepts the formats eBay hosts, by type or by extension', () => {
    expect(isEbayNativeFormat(file('a.jpg', 'image/jpeg'))).toBe(true);
    expect(isEbayNativeFormat(file('a.bmp', ''))).toBe(true);
    expect(isEbayNativeFormat(file('a.tiff', ''))).toBe(true);
  });

  it('rejects formats eBay reports as corrupt image data', () => {
    expect(isEbayNativeFormat(file('a.webp', 'image/webp'))).toBe(false);
    expect(isEbayNativeFormat(file('a.heic', 'image/heic'))).toBe(false);
  });
});

describe('fileToListingImage', () => {
  beforeEach(() => stubDecode(2400, 1800));
  afterEach(() => { vi.restoreAllMocks(); vi.unstubAllGlobals(); });

  it('re-encodes a picked file as JPEG at listing size', async () => {
    const canvas = stubCanvas(JPEG);

    await expect(fileToListingImage(file('photo.png', 'image/png'))).resolves.toBe(JPEG);
    expect(canvas.width).toBe(1600);
    expect(canvas.height).toBe(1200);
  });

  it('converts formats eBay cannot host, so WebP uploads stop failing', async () => {
    stubCanvas(JPEG);

    await expect(fileToListingImage(file('shot.webp', 'image/webp'))).resolves.toBe(JPEG);
  });

  it('passes a JPEG/BMP/TIFF through untouched when the browser cannot decode it', async () => {
    stubDecodeFailure();
    stubCanvas(JPEG);

    const result = await fileToListingImage(file('scan.bmp', 'image/bmp'));
    expect(result.startsWith('data:')).toBe(true);
  });

  it('names the file when it cannot be used at all', async () => {
    stubDecodeFailure();
    stubCanvas(JPEG);

    await expect(fileToListingImage(file('iphone.heic', 'image/heic')))
      .rejects.toThrow(/"iphone\.heic" could not be read/);
  });

  it('refuses a non-image file', async () => {
    await expect(fileToListingImage(file('invoice.pdf', 'application/pdf')))
      .rejects.toThrow(/is not an image/);
  });

  it('refuses a file too large to send', async () => {
    await expect(fileToListingImage(file('raw.jpg', 'image/jpeg', 40 * 1024 * 1024)))
      .rejects.toThrow(/too large/);
  });

  it('treats a truncated canvas result as a failure rather than sending it', async () => {
    stubCanvas('data:,');

    await expect(fileToListingImage(file('photo.webp', 'image/webp'))).rejects.toThrow();
  });
});
