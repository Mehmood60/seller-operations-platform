/**
 * Turning files picked from the seller's computer into listing images.
 *
 * eBay Picture Services only hosts JPG/PNG/GIF/BMP/TIFF and rejects everything
 * else as "corrupt image data", and a full-resolution phone photo base64-encodes
 * to several times the size the listing API will take in one request. Re-drawing
 * the file through a canvas fixes both at once: anything the browser can decode
 * (HEIC on Safari, WebP/AVIF on Chrome) comes back out as a plain JPEG at
 * listing resolution. Images added by URL are left completely alone — they stay
 * strings that eBay fetches itself.
 */

/** eBay shows pictures up to 1600px on the long edge; larger is wasted upload. */
export const MAX_IMAGE_EDGE = 1600;

const JPEG_QUALITY = 0.9;

/** Formats eBay hosts as-is, so a failed canvas decode can still be passed through. */
const EBAY_NATIVE_TYPES = /^image\/(jpe?g|pjpeg|png|gif|bmp|x-ms-bmp|tiff?)$/i;
const EBAY_NATIVE_EXTENSIONS = /\.(jpe?g|png|gif|bmp|tiff?)$/i;

/** Guard against someone picking a 100 MB RAW file and waiting forever. */
const MAX_SOURCE_BYTES = 30 * 1024 * 1024;

export function isEbayNativeFormat(file: File): boolean {
  return EBAY_NATIVE_TYPES.test(file.type) || EBAY_NATIVE_EXTENSIONS.test(file.name);
}

/**
 * Read one picked file as a data: URL the backend can upload to eBay.
 * Rejects with a message naming the file when it cannot be used.
 */
export async function fileToListingImage(file: File): Promise<string> {
  if (!file.type.startsWith('image/') && !EBAY_NATIVE_EXTENSIONS.test(file.name)) {
    throw new Error(`"${file.name}" is not an image.`);
  }
  if (file.size > MAX_SOURCE_BYTES) {
    throw new Error(`"${file.name}" is too large (${(file.size / 1048576).toFixed(0)} MB, limit 30 MB).`);
  }

  try {
    return await reencodeAsJpeg(file);
  } catch {
    // Older browsers cannot decode every format eBay itself accepts (BMP and
    // TIFF especially). Those can go up untouched; anything else would only
    // reach eBay to be rejected, so say so here where the file name is known.
    if (isEbayNativeFormat(file)) {
      return readAsDataUrl(file);
    }
    throw new Error(`"${file.name}" could not be read. Please save it as JPG or PNG and try again.`);
  }
}

/** Draw the file on a canvas at listing size and export it as JPEG. */
async function reencodeAsJpeg(file: File): Promise<string> {
  const source = await decode(file);
  const { width, height } = fitWithin(source.width, source.height, MAX_IMAGE_EDGE);

  const canvas = document.createElement('canvas');
  canvas.width  = width;
  canvas.height = height;

  const ctx = canvas.getContext('2d');
  if (!ctx) throw new Error('Canvas is unavailable.');

  // JPEG has no alpha channel; without this, transparent PNGs turn black.
  ctx.fillStyle = '#ffffff';
  ctx.fillRect(0, 0, width, height);
  ctx.drawImage(source as CanvasImageSource, 0, 0, width, height);

  if ('close' in source && typeof source.close === 'function') source.close();

  const dataUrl = canvas.toDataURL('image/jpeg', JPEG_QUALITY);
  if (!dataUrl.startsWith('data:image/jpeg;base64,') || dataUrl.length < 'data:image/jpeg;base64,'.length + 64) {
    throw new Error('Canvas produced no image data.');
  }
  return dataUrl;
}

type DecodedImage = (ImageBitmap | HTMLImageElement) & { width: number; height: number };

/** Decode with createImageBitmap where available, else via an <img> element. */
async function decode(file: File): Promise<DecodedImage> {
  if (typeof createImageBitmap === 'function') {
    try {
      return (await createImageBitmap(file)) as DecodedImage;
    } catch {
      // Fall through — Safari refuses some types here that <img> handles.
    }
  }

  const url = URL.createObjectURL(file);
  try {
    return await new Promise<DecodedImage>((resolve, reject) => {
      const img = new Image();
      img.onload  = () => resolve(img as DecodedImage);
      img.onerror = () => reject(new Error('Browser could not decode the image.'));
      img.src = url;
    });
  } finally {
    URL.revokeObjectURL(url);
  }
}

/** Scale down to fit a square of `max` px, never scale up, never go below 1px. */
export function fitWithin(width: number, height: number, max: number): { width: number; height: number } {
  if (!width || !height) throw new Error('Image has no dimensions.');

  const scale = Math.min(1, max / Math.max(width, height));
  return {
    width:  Math.max(1, Math.round(width * scale)),
    height: Math.max(1, Math.round(height * scale)),
  };
}

function readAsDataUrl(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload  = () => resolve(reader.result as string);
    reader.onerror = () => reject(new Error(`Could not read "${file.name}".`));
    reader.readAsDataURL(file);
  });
}
