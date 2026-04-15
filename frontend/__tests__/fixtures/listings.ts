import type { Listing } from '@/types';

export const mockListing: Listing = {
  id:            '123456789012',
  ebay_item_id:  '123456789012',
  title:         'Vintage Canon AE-1 35mm Film Camera Body',
  sku:           'CAM-AE1-001',
  status:        'ACTIVE',
  category: {
    ebay_category_id: '31388',
    name:             'Film Cameras',
  },
  price:    { value: '49.99', currency: 'GBP' },
  quantity: { available: 2, sold: 12 },
  images:   ['https://i.ebayimg.com/images/g/placeholder/s-l1600.jpg'],
  condition:            'USED_EXCELLENT',
  description_snippet:  'Fully working Canon AE-1 35mm film SLR camera body.',
  listing_url:          'https://www.ebay.co.uk/itm/123456789012',
  listed_at:  '2026-01-15T10:00:00+00:00',
  ends_at:    '2026-07-15T10:00:00+00:00',
  synced_at:  '2026-04-15T09:00:00+00:00',
};

export const mockListingOutOfStock: Listing = {
  ...mockListing,
  id:           '123456789013',
  ebay_item_id: '123456789013',
  title:        'Minolta X-700 Camera Body',
  status:       'OUT_OF_STOCK',
  quantity:     { available: 0, sold: 3 },
};

export const mockListings: Listing[] = [mockListing, mockListingOutOfStock];
