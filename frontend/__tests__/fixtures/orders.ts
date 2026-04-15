import type { Order } from '@/types';

export const mockOrder: Order = {
  id:            '12-34567-89012',
  ebay_order_id: '12-34567-89012',
  status:        'PAID',
  buyer: {
    username: 'vintage_collector_uk',
    email:    '',
    shipping_address: {
      name:         'James Hargreaves',
      line1:        '42 Pemberton Road',
      line2:        '',
      city:         'Manchester',
      state:        'Greater Manchester',
      postal_code:  'M14 7PL',
      country_code: 'GB',
    },
  },
  line_items: [
    {
      ebay_item_id: '123456789012',
      title:        'Vintage Canon AE-1 35mm Film Camera Body',
      sku:          'CAM-AE1-001',
      quantity:     1,
      unit_price:   { value: '49.99', currency: 'GBP' },
      total_price:  { value: '49.99', currency: 'GBP' },
    },
  ],
  payment: {
    method:  'EBAY_MANAGED',
    status:  'PAID',
    amount:  { value: '53.99', currency: 'GBP' },
    paid_at: '2026-04-10T14:30:00Z',
  },
  shipping: {
    service:         'Royal Mail Tracked 48',
    cost:            { value: '4.00', currency: 'GBP' },
    tracking_number: 'TT123456789GB',
    shipped_at:      '2026-04-11T09:00:00Z',
    delivered_at:    null,
  },
  totals: {
    subtotal:    { value: '49.99', currency: 'GBP' },
    shipping:    { value: '4.00',  currency: 'GBP' },
    grand_total: { value: '53.99', currency: 'GBP' },
  },
  notes:      '',
  created_at: '2026-04-10T13:00:00+00:00',
  updated_at: '2026-04-10T14:35:00+00:00',
  synced_at:  '2026-04-15T09:00:00+00:00',
};

export const mockOrder2: Order = {
  id:            '12-34567-89013',
  ebay_order_id: '12-34567-89013',
  status:        'DELIVERED',
  buyer: {
    username: 'photo_enthusiast_99',
    email:    '',
    shipping_address: {
      name:         'Sarah Bellingham',
      line1:        '7 Elm Street',
      line2:        'Flat 2',
      city:         'Bristol',
      state:        'Bristol',
      postal_code:  'BS1 4RQ',
      country_code: 'GB',
    },
  },
  line_items: [
    {
      ebay_item_id: '123456789013',
      title:        'Olympus OM-10 35mm SLR Camera',
      sku:          'CAM-OM10-001',
      quantity:     1,
      unit_price:   { value: '24.99', currency: 'GBP' },
      total_price:  { value: '24.99', currency: 'GBP' },
    },
  ],
  payment: {
    method:  'EBAY_MANAGED',
    status:  'PAID',
    amount:  { value: '24.99', currency: 'GBP' },
    paid_at: '2026-04-14T09:45:00Z',
  },
  shipping: {
    service:         'Royal Mail 1st Class',
    cost:            { value: '0.00', currency: 'GBP' },
    tracking_number: null,
    shipped_at:      '2026-04-14T18:00:00Z',
    delivered_at:    '2026-04-15T12:00:00Z',
  },
  totals: {
    subtotal:    { value: '24.99', currency: 'GBP' },
    shipping:    { value: '0.00',  currency: 'GBP' },
    grand_total: { value: '24.99', currency: 'GBP' },
  },
  notes:      '',
  created_at: '2026-04-14T09:20:00+00:00',
  updated_at: '2026-04-14T09:45:00+00:00',
  synced_at:  '2026-04-15T09:00:00+00:00',
};

export const mockOrders: Order[] = [mockOrder, mockOrder2];

export const mockCancelledOrder: Order = {
  ...mockOrder,
  id:     '12-34567-99999',
  status: 'CANCELLED',
};
