import { describe, it, expect } from 'vitest';
import { extractMissingFields, hasMissingFieldError } from '@/lib/ebayErrors';

describe('extractMissingFields', () => {
  it('reads the structured-field phrasing (code 21919301)', () => {
    const msg = 'eBay listing failed: (code 21919301) The EAN field is missing. '
      + 'Please add EAN to the listing and try again.';
    expect(extractMissingFields(msg)).toEqual(['EAN']);
  });

  it('reads the item-specific phrasing', () => {
    const msg = 'The item specific Farbe is missing. Add Farbe to this listing.';
    expect(extractMissingFields(msg)).toEqual(['Farbe']);
  });

  it('collects several distinct fields from one message', () => {
    const msg = 'The item specific Marke is missing. | (code 21919301) The EAN field is missing.';
    expect(extractMissingFields(msg)).toEqual(['Marke', 'EAN']);
  });

  it('splits comma- and and-separated field lists', () => {
    expect(extractMissingFields('Missing required fields: EAN, Marke and Farbe'))
      .toEqual(['EAN', 'Marke', 'Farbe']);
  });

  it('keeps German field names with umlauts intact', () => {
    expect(extractMissingFields('The item specific Größe is missing')).toEqual(['Größe']);
  });

  it('de-duplicates case-insensitively', () => {
    const msg = 'The EAN field is missing. Please add ean to the listing.';
    expect(extractMissingFields(msg)).toEqual(['EAN']);
  });

  it('ignores sentence-length greedy matches', () => {
    const msg = 'The request could not be completed because something entirely '
      + 'unrelated and very long indeed is missing';
    expect(extractMissingFields(msg)).toEqual([]);
  });

  it('returns nothing for unrelated errors and empty input', () => {
    expect(extractMissingFields('Auth token expired.')).toEqual([]);
    expect(extractMissingFields('')).toEqual([]);
  });

  it('is not affected by regex lastIndex state across calls', () => {
    const msg = 'The EAN field is missing.';
    expect(extractMissingFields(msg)).toEqual(['EAN']);
    expect(extractMissingFields(msg)).toEqual(['EAN']);
  });
});

describe('hasMissingFieldError', () => {
  it('detects fixable errors', () => {
    expect(hasMissingFieldError('(code 21919301) The EAN field is missing.')).toBe(true);
  });

  it('rejects other errors and nullish input', () => {
    expect(hasMissingFieldError('Category ID is invalid.')).toBe(false);
    expect(hasMissingFieldError(null)).toBe(false);
    expect(hasMissingFieldError(undefined)).toBe(false);
  });
});
