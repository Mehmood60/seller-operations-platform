// ─── Money ─────────────────────────────────────────────────────────────────

export interface Money {
  value: string;
  currency: string;
}

// ─── Order ─────────────────────────────────────────────────────────────────

export interface ShippingAddress {
  name: string;
  line1: string;
  line2: string;
  city: string;
  state: string;
  postal_code: string;
  country_code: string;
}

export interface Buyer {
  username: string;
  email: string;
  shipping_address: ShippingAddress;
}

export interface LineItem {
  ebay_item_id: string;
  title: string;
  sku: string;
  quantity: number;
  unit_price: Money;
  total_price: Money;
}

export interface Payment {
  method: string;
  status: string;
  amount: Money;
  paid_at: string | null;
}

export interface Shipping {
  service: string;
  cost: Money;
  tracking_number: string | null;
  shipped_at: string | null;
  delivered_at: string | null;
}

export interface Totals {
  subtotal: Money;
  shipping: Money;
  grand_total: Money;
}

export type OrderStatus = 'PAID' | 'SHIPPED' | 'DELIVERED' | 'CANCELLED';

export interface Order {
  id: string;
  ebay_order_id: string;
  status: OrderStatus;
  buyer: Buyer;
  line_items: LineItem[];
  payment: Payment;
  shipping: Shipping;
  totals: Totals;
  notes: string;
  created_at: string;
  updated_at: string;
  synced_at: string;
}

// ─── Listing ────────────────────────────────────────────────────────────────

export interface ListingCategory {
  ebay_category_id: string;
  name: string;
}

export interface ListingQuantity {
  available: number;
  sold: number;
}

export type ListingStatus = 'ACTIVE' | 'ENDED' | 'OUT_OF_STOCK';

export interface Listing {
  id: string;
  ebay_item_id: string;
  title: string;
  sku: string;
  status: ListingStatus;
  category: ListingCategory;
  price: Money;
  quantity: ListingQuantity;
  images: string[];
  condition: string;
  description_snippet: string;
  listing_url: string;
  listed_at: string;
  ends_at: string;
  synced_at: string;
}

// ─── Analytics / Dashboard ──────────────────────────────────────────────────

export interface TopListing {
  listing_id: string;
  title: string;
  total_sold: number;
  total_revenue: Money;
}

export interface RevenueByDay {
  date: string;
  revenue: number;
  orders: number;
}

export interface DashboardData {
  revenue_30d: Money;
  orders_30d: number;
  avg_order_value: Money;
  top_listings: TopListing[];
  revenue_by_day: RevenueByDay[];
}

// ─── Sales Report ───────────────────────────────────────────────────────────

export interface SalesReport {
  date_range: { from: string; to: string };
  total_orders: number;
  total_revenue: Money;
  avg_order_value: Money;
  top_listings: TopListing[];
  revenue_by_day: RevenueByDay[];
  orders: Array<{
    id: string;
    buyer: string;
    grand_total: Money;
    status: string;
    created_at: string;
  }>;
}

// ─── Sync ───────────────────────────────────────────────────────────────────

export interface SyncState {
  orders: {
    last_synced_at: string | null;
    last_offset: number;
    total_synced: number;
  };
  listings: {
    last_synced_at: string | null;
    total_synced: number;
  };
}

export interface AuthStatus {
  connected: boolean;
  expires_at: string | null;
  refresh_expires_at: string | null;
  scopes: string[];
}

// ─── User / Auth ────────────────────────────────────────────────────────────

export interface User {
  id: string;
  email: string;
  full_name: string;
  role: string;
  status: string;
  created_at: string;
  updated_at: string;
  last_login_at: string | null;
}

// ─── Profile ────────────────────────────────────────────────────────────────

export interface ProfileAddress {
  line1: string;
  line2: string;
  city: string;
  state: string;
  postal_code: string;
  country: string;
}

export interface StoreInfo {
  name: string;
  phone: string;
  email: string;
  address: string;
  description: string;
  business_name: string;
  tax_number: string | null;
  vat_number: string | null;
}

export interface Profile {
  user_id: string;
  full_name: string;
  email: string;
  phone: string;
  address: ProfileAddress | null;
  avatar_url: string | null;
  store: StoreInfo;
  created_at: string;
  updated_at: string;
}

// ─── API Response wrapper ───────────────────────────────────────────────────

export interface ApiResponse<T> {
  data: T;
  meta: {
    page?: number;
    total?: number;
    limit?: number;
  };
  error: string | null;
}
