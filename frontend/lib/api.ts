import type {
  ApiResponse,
  Order,
  Listing,
  DashboardData,
  SalesReport,
  SyncState,
  AuthStatus,
  User,
  Profile,
} from '@/types';

const API_BASE = process.env.NEXT_PUBLIC_API_BASE_URL ?? 'http://localhost:8080';
const API_KEY  = process.env.NEXT_PUBLIC_API_KEY ?? '';

// ─── Session token helpers ──────────────────────────────────────────────────

const TOKEN_KEY = 'session_token';

export function getSessionToken(): string | null {
  if (typeof window === 'undefined') return null;
  return localStorage.getItem(TOKEN_KEY);
}

export function setSessionToken(token: string): void {
  localStorage.setItem(TOKEN_KEY, token);
}

export function clearSessionToken(): void {
  localStorage.removeItem(TOKEN_KEY);
}

// ─── Core fetch wrapper ─────────────────────────────────────────────────────

async function apiFetch<T>(
  path: string,
  options: RequestInit = {},
): Promise<ApiResponse<T>> {
  const token = getSessionToken();
  const authHeader: Record<string, string> = token
    ? { Authorization: `Bearer ${token}` }
    : {};

  const res = await fetch(`${API_BASE}${path}`, {
    ...options,
    headers: {
      'Content-Type': 'application/json',
      'X-API-Key': API_KEY,
      ...authHeader,
      ...(options.headers ?? {}),
    },
  });

  const json = await res.json().catch(() => ({ data: null, meta: {}, error: 'Invalid JSON response' }));

  if (!res.ok) {
    throw new Error(json.error ?? `HTTP ${res.status}`);
  }

  return json as ApiResponse<T>;
}

// ─── Auth ───────────────────────────────────────────────────────────────────

export const auth = {
  status: (): Promise<ApiResponse<AuthStatus>> =>
    apiFetch('/api/auth/ebay'),

  connectUrl: (): string =>
    `${API_BASE}/api/auth/ebay/connect`,

  disconnect: (): Promise<ApiResponse<{ disconnected: boolean }>> =>
    apiFetch('/api/auth/ebay', { method: 'DELETE' }),
};

// ─── Sync ───────────────────────────────────────────────────────────────────

export const sync = {
  orders: (): Promise<ApiResponse<{ synced: number; total_available: number; error?: string }>> =>
    apiFetch('/api/sync/orders', { method: 'POST' }),

  listings: (): Promise<ApiResponse<{ synced: number; total_available: number; error?: string }>> =>
    apiFetch('/api/sync/listings', { method: 'POST' }),

  status: (): Promise<ApiResponse<SyncState>> =>
    apiFetch('/api/sync/status'),
};

// ─── Orders ─────────────────────────────────────────────────────────────────

export const orders = {
  list: (params: {
    page?: number;
    limit?: number;
    status?: string;
    search?: string;
  } = {}): Promise<ApiResponse<Order[]>> => {
    const query = new URLSearchParams();
    if (params.page)   query.set('page',   String(params.page));
    if (params.limit)  query.set('limit',  String(params.limit));
    if (params.status) query.set('status', params.status);
    if (params.search) query.set('search', params.search);
    const qs = query.toString() ? '?' + query.toString() : '';
    return apiFetch('/api/orders' + qs);
  },

  get: (id: string): Promise<ApiResponse<Order>> =>
    apiFetch(`/api/orders/${id}`),

  invoiceUrl: (id: string): string =>
    `${API_BASE}/api/orders/${id}/invoice?key=${encodeURIComponent(API_KEY)}`,
};

// ─── Listings ────────────────────────────────────────────────────────────────

export const listings = {
  list: (params: {
    page?: number;
    limit?: number;
    status?: string;
    search?: string;
  } = {}): Promise<ApiResponse<Listing[]>> => {
    const query = new URLSearchParams();
    if (params.page)   query.set('page',   String(params.page));
    if (params.limit)  query.set('limit',  String(params.limit));
    if (params.status) query.set('status', params.status);
    if (params.search) query.set('search', params.search);
    const qs = query.toString() ? '?' + query.toString() : '';
    return apiFetch('/api/listings' + qs);
  },

  get: (id: string): Promise<ApiResponse<Listing>> =>
    apiFetch(`/api/listings/${id}`),
};

// ─── Dashboard ───────────────────────────────────────────────────────────────

export const dashboard = {
  get: (): Promise<ApiResponse<DashboardData>> =>
    apiFetch('/api/dashboard'),
};

// ─── Reports ─────────────────────────────────────────────────────────────────

export const reports = {
  sales: (from: string, to: string): Promise<ApiResponse<SalesReport>> =>
    apiFetch(`/api/reports/sales?from=${from}&to=${to}`),

  salesPdfUrl: (from: string, to: string): string =>
    `${API_BASE}/api/reports/sales/pdf?from=${from}&to=${to}&key=${encodeURIComponent(API_KEY)}`,
};

// ─── User auth ───────────────────────────────────────────────────────────────

export const userAuth = {
  register: (data: {
    email: string;
    password: string;
    full_name: string;
  }): Promise<ApiResponse<User>> =>
    apiFetch('/api/auth/register', { method: 'POST', body: JSON.stringify(data) }),

  login: (email: string, password: string): Promise<ApiResponse<{ token: string; user: User }>> =>
    apiFetch('/api/auth/login', { method: 'POST', body: JSON.stringify({ email, password }) }),

  logout: (): Promise<ApiResponse<{ logged_out: boolean }>> =>
    apiFetch('/api/auth/logout', { method: 'POST' }),

  me: (): Promise<ApiResponse<User>> =>
    apiFetch('/api/auth/me'),
};

// ─── Profile ─────────────────────────────────────────────────────────────────

export const profile = {
  get: (): Promise<ApiResponse<Profile>> =>
    apiFetch('/api/profile'),

  update: (data: Partial<Profile>): Promise<ApiResponse<Profile>> =>
    apiFetch('/api/profile', { method: 'PUT', body: JSON.stringify(data) }),
};
