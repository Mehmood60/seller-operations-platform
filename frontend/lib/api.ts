import type {
  AiAnalysisResult,
  ApiResponse,
  Order,
  Listing,
  DashboardData,
  SalesReport,
  SyncState,
  AuthStatus,
  User,
  Profile,
  MonitorItem,
  CompetitorCheck,
  HealthScore,
  FeedbackType,
  FeedbackTone,
  PriceSuggestion,
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

  fulfill: (
    id: string,
    aliexpressOrderId: string,
    sourceUrl?: string,
  ): Promise<ApiResponse<Order>> =>
    apiFetch(`/api/orders/${id}/fulfill`, {
      method: 'POST',
      body: JSON.stringify({ aliexpress_order_id: aliexpressOrderId, source_url: sourceUrl ?? '' }),
    }),

  track: (
    id: string,
    trackingNumber: string,
    carrier: string,
  ): Promise<ApiResponse<Order>> =>
    apiFetch(`/api/orders/${id}/track`, {
      method: 'POST',
      body: JSON.stringify({ tracking_number: trackingNumber, carrier }),
    }),
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

  createDraft: (data: Record<string, unknown>): Promise<ApiResponse<Listing>> =>
    apiFetch('/api/listings', { method: 'POST', body: JSON.stringify(data) }),

  updateDraft: (id: string, data: Record<string, unknown>): Promise<ApiResponse<Listing>> =>
    apiFetch(`/api/listings/${id}`, { method: 'PUT', body: JSON.stringify(data) }),

  deleteDraft: (id: string): Promise<ApiResponse<{ deleted: boolean }>> =>
    apiFetch(`/api/listings/${id}`, { method: 'DELETE' }),

  publish: (id: string): Promise<ApiResponse<Listing>> =>
    apiFetch(`/api/listings/${id}/publish`, { method: 'POST' }),

  revise: (id: string, data: Record<string, unknown>): Promise<ApiResponse<Listing>> =>
    apiFetch(`/api/listings/${id}/revise`, { method: 'POST', body: JSON.stringify(data) }),

  suggestCategories: (q: string): Promise<ApiResponse<Array<{ id: string; name: string; percent: number }>>> =>
    apiFetch(`/api/listings/category-suggest?q=${encodeURIComponent(q)}`),

  checkCompetitors: (id: string): Promise<ApiResponse<CompetitorCheck>> =>
    apiFetch(`/api/listings/${id}/check-competitors`, { method: 'POST' }),

  checkAllCompetitors: (): Promise<ApiResponse<Array<{ id: string; title: string; lowest_total?: string; result_count?: number; error?: string }>>> =>
    apiFetch('/api/listings/check-all-competitors', { method: 'POST' }),

  health: (id: string): Promise<ApiResponse<HealthScore>> =>
    apiFetch(`/api/listings/${id}/health`),
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

// ─── AI Listing Analysis ─────────────────────────────────────────────────────

export const ai = {
  analyze: (url: string): Promise<ApiResponse<AiAnalysisResult>> =>
    apiFetch('/api/ai/analyze', {
      method: 'POST',
      body: JSON.stringify({ url }),
    }),

  translate: (title: string, description: string): Promise<ApiResponse<{ title: string; description: string }>> =>
    apiFetch('/api/ai/translate', {
      method: 'POST',
      body: JSON.stringify({ title, description }),
    }),

  suggestSpecifics: (data: {
    title: string;
    description: string;
    item_specifics: Record<string, string>;
    missing_fields: string[];
  }): Promise<ApiResponse<Record<string, string>>> =>
    apiFetch('/api/ai/suggest-specifics', {
      method: 'POST',
      body: JSON.stringify(data),
    }),

  feedbackResponse: (
    feedbackText: string,
    type: FeedbackType,
    tone: FeedbackTone,
  ): Promise<ApiResponse<{ response: string }>> =>
    apiFetch('/api/ai/feedback-response', {
      method: 'POST',
      body: JSON.stringify({ feedback_text: feedbackText, type, tone }),
    }),

  improveListing: (data: {
    aspect: 'title' | 'description';
    title: string;
    description: string;
  }): Promise<ApiResponse<{ title?: string; description?: string }>> =>
    apiFetch('/api/ai/improve-listing', {
      method: 'POST',
      body: JSON.stringify(data),
    }),

  suggestPrice: (data: {
    title: string;
    current_price: number;
    shipping_type: string;
    shipping_cost: number;
    competitor_lowest: number | null;
    competitor_count: number;
    top_competitors: Array<{ title: string; total_price: string; condition: string; location: string }>;
  }): Promise<ApiResponse<PriceSuggestion>> =>
    apiFetch('/api/ai/suggest-price', {
      method: 'POST',
      body: JSON.stringify(data),
    }),
};

// ─── Price Monitor ────────────────────────────────────────────────────────────

export const monitor = {
  status: (): Promise<ApiResponse<MonitorItem[]>> =>
    apiFetch('/api/monitor'),

  checkOne: (id: string): Promise<ApiResponse<MonitorItem>> =>
    apiFetch(`/api/monitor/${id}/check`, { method: 'POST' }),

  checkAll: (): Promise<ApiResponse<MonitorItem[]>> =>
    apiFetch('/api/monitor/check-all', { method: 'POST' }),

  toggle: (id: string, enabled: boolean): Promise<ApiResponse<MonitorItem>> =>
    apiFetch(`/api/monitor/${id}/toggle`, {
      method: 'POST',
      body: JSON.stringify({ enabled }),
    }),

  applyUpdate: (id: string, newPrice: number): Promise<ApiResponse<{ updated: boolean }>> =>
    apiFetch(`/api/monitor/${id}/apply`, {
      method: 'POST',
      body: JSON.stringify({ new_price: newPrice }),
    }),
};

// ─── Profile ─────────────────────────────────────────────────────────────────

export const profile = {
  get: (): Promise<ApiResponse<Profile>> =>
    apiFetch('/api/profile'),

  update: (data: Partial<Profile>): Promise<ApiResponse<Profile>> =>
    apiFetch('/api/profile', { method: 'PUT', body: JSON.stringify(data) }),
};
