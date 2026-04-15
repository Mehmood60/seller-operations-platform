import { describe, it, expect } from 'vitest';
import {
  formatMoney,
  formatDate,
  formatDateTime,
  relativeTime,
  todayISO,
  firstOfMonthISO,
} from '@/lib/formatters';

describe('formatMoney()', () => {
  it('formats GBP with £ symbol', () => {
    expect(formatMoney({ value: '49.99', currency: 'GBP' })).toBe('£49.99');
  });

  it('formats USD with $ symbol', () => {
    expect(formatMoney({ value: '10.00', currency: 'USD' })).toBe('$10.00');
  });

  it('formats EUR with € symbol', () => {
    expect(formatMoney({ value: '25.50', currency: 'EUR' })).toBe('€25.50');
  });

  it('pads to 2 decimal places', () => {
    expect(formatMoney({ value: '5', currency: 'GBP' })).toBe('£5.00');
  });

  it('returns — for null input', () => {
    expect(formatMoney(null)).toBe('—');
  });

  it('returns — for undefined input', () => {
    expect(formatMoney(undefined)).toBe('—');
  });
});

describe('formatDate()', () => {
  it('formats ISO date string to human-readable date', () => {
    const result = formatDate('2026-04-10T13:00:00+00:00');
    expect(result).toMatch(/\d{2} \w{3} \d{4}/); // e.g. "10 Apr 2026"
  });

  it('returns — for null', () => {
    expect(formatDate(null)).toBe('—');
  });

  it('returns — for undefined', () => {
    expect(formatDate(undefined)).toBe('—');
  });

  it('returns — for empty string', () => {
    expect(formatDate('')).toBe('—');
  });
});

describe('todayISO()', () => {
  it('returns a string in YYYY-MM-DD format', () => {
    const result = todayISO();
    expect(result).toMatch(/^\d{4}-\d{2}-\d{2}$/);
  });

  it('returns today\'s date', () => {
    const today = new Date().toISOString().slice(0, 10);
    expect(todayISO()).toBe(today);
  });
});

describe('firstOfMonthISO()', () => {
  it('returns a string in YYYY-MM-DD format', () => {
    expect(firstOfMonthISO()).toMatch(/^\d{4}-\d{2}-01$/);
  });

  it('returns the first day of the current month', () => {
    const d = new Date();
    const expected = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
    expect(firstOfMonthISO()).toBe(expected);
  });
});

describe('relativeTime()', () => {
  it('returns "Just now" for times within the last minute', () => {
    const now = new Date().toISOString();
    expect(relativeTime(now)).toBe('Just now');
  });

  it('returns — for null', () => {
    expect(relativeTime(null)).toBe('—');
  });

  it('returns time in days for old dates', () => {
    const threeDaysAgo = new Date(Date.now() - 3 * 24 * 60 * 60 * 1000).toISOString();
    expect(relativeTime(threeDaysAgo)).toMatch(/\dd ago/);
  });
});
