import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { orders, auth, sync } from '@/lib/api';
import type { Order, AuthStatus } from '@/types';
import { mockOrder, mockOrders } from '../fixtures/orders';

// ── Helpers ───────────────────────────────────────────────────────────────────

function mockFetch(data: unknown, ok = true, status = 200): void {
  global.fetch = vi.fn().mockResolvedValue({
    ok,
    status,
    json: async () => data,
  });
}

function mockFetchError(errorMessage: string, status = 400): void {
  global.fetch = vi.fn().mockResolvedValue({
    ok:     false,
    status,
    json:   async () => ({ data: null, meta: {}, error: errorMessage }),
  });
}

// ── orders.list() ─────────────────────────────────────────────────────────────

describe('orders.list()', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('calls /api/orders endpoint', async () => {
    mockFetch({ data: [], meta: { total: 0, page: 1, limit: 25 }, error: null });

    await orders.list();

    expect(global.fetch).toHaveBeenCalledWith(
      expect.stringContaining('/api/orders'),
      expect.any(Object),
    );
  });

  it('includes X-API-Key header', async () => {
    mockFetch({ data: [], meta: {}, error: null });

    await orders.list();

    const [, options] = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[0];
    expect(options.headers).toHaveProperty('X-API-Key');
  });

  it('includes page and limit as query params', async () => {
    mockFetch({ data: [], meta: {}, error: null });

    await orders.list({ page: 2, limit: 10 });

    const [url] = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[0];
    expect(url).toContain('page=2');
    expect(url).toContain('limit=10');
  });

  it('includes status filter when provided', async () => {
    mockFetch({ data: [], meta: {}, error: null });

    await orders.list({ status: 'PAID' });

    const [url] = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[0];
    expect(url).toContain('status=PAID');
  });

  it('does not include empty status in query string', async () => {
    mockFetch({ data: [], meta: {}, error: null });

    await orders.list({ status: '' });

    const [url] = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[0];
    expect(url).not.toContain('status=');
  });

  it('includes search when provided', async () => {
    mockFetch({ data: [], meta: {}, error: null });

    await orders.list({ search: 'camera' });

    const [url] = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[0];
    expect(url).toContain('search=camera');
  });

  it('returns the parsed JSON response', async () => {
    const responsePayload = {
      data:  mockOrders,
      meta:  { total: 2, page: 1, limit: 25 },
      error: null,
    };
    mockFetch(responsePayload);

    const result = await orders.list();

    expect(result).toEqual(responsePayload);
  });

  it('throws an Error when API returns a non-ok response', async () => {
    mockFetchError('Unauthorized', 401);

    await expect(orders.list()).rejects.toThrow('Unauthorized');
  });

  it('throws an Error with HTTP status when error message is missing', async () => {
    global.fetch = vi.fn().mockResolvedValue({
      ok:     false,
      status: 500,
      json:   async () => ({}),
    });

    await expect(orders.list()).rejects.toThrow('HTTP 500');
  });
});

// ── orders.get() ──────────────────────────────────────────────────────────────

describe('orders.get()', () => {
  beforeEach(() => vi.clearAllMocks());

  it('calls /api/orders/{id}', async () => {
    mockFetch({ data: mockOrder, meta: {}, error: null });

    await orders.get('12-34567-89012');

    const [url] = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[0];
    expect(url).toContain('/api/orders/12-34567-89012');
  });

  it('throws for a 404 response', async () => {
    mockFetchError('Order not found.', 404);

    await expect(orders.get('bad-id')).rejects.toThrow('Order not found.');
  });
});

// ── orders.invoiceUrl() ───────────────────────────────────────────────────────

describe('orders.invoiceUrl()', () => {
  it('returns a URL containing the order id', () => {
    const url = orders.invoiceUrl('order-123');
    expect(url).toContain('/api/orders/order-123/invoice');
  });
});

// ── auth.status() ─────────────────────────────────────────────────────────────

describe('auth.status()', () => {
  beforeEach(() => vi.clearAllMocks());

  it('calls /api/auth/ebay', async () => {
    const authPayload: AuthStatus = {
      connected:          false,
      expires_at:         null,
      refresh_expires_at: null,
      scopes:             [],
    };
    mockFetch({ data: authPayload, meta: {}, error: null });

    await auth.status();

    const [url] = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[0];
    expect(url).toContain('/api/auth/ebay');
  });
});

// ── sync.orders() ─────────────────────────────────────────────────────────────

describe('sync.orders()', () => {
  beforeEach(() => vi.clearAllMocks());

  it('sends a POST request', async () => {
    mockFetch({ data: { synced: 5, total_available: 5 }, meta: {}, error: null });

    await sync.orders();

    const [, options] = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[0];
    expect(options.method).toBe('POST');
  });

  it('calls /api/sync/orders', async () => {
    mockFetch({ data: { synced: 0, total_available: 0 }, meta: {}, error: null });

    await sync.orders();

    const [url] = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[0];
    expect(url).toContain('/api/sync/orders');
  });
});
