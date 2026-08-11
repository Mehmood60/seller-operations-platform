'use client';

import Link from 'next/link';
import Image from 'next/image';
import type { Listing, ListingStatus } from '@/types';
import { useFormatMoney } from '@/components/PreferencesProvider';
import { Badge } from '@/components/ui/Badge';
import { Package } from 'lucide-react';

const STATUS_VARIANT: Record<ListingStatus, 'success' | 'danger' | 'warning' | 'default'> = {
  ACTIVE:       'success',
  ENDED:        'danger',
  OUT_OF_STOCK: 'warning',
  DRAFT:        'default',
};

interface ListingGridProps {
  listings: Listing[];
}

export function ListingGrid({ listings }: ListingGridProps) {
  const formatMoney = useFormatMoney();

  if (listings.length === 0) {
    return (
      <div className="text-center py-12 text-gray-400">
        No listings found.
      </div>
    );
  }

  return (
    <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 xl:grid-cols-8 gap-3">
      {listings.map((listing) => (
        <Link
          key={listing.id}
          href={`/listings/${listing.id}/edit`}
          className="bg-white border border-gray-200 rounded-lg overflow-hidden hover:shadow-md transition-shadow group"
        >
          {/* Image */}
          <div className="aspect-square bg-gray-100 relative overflow-hidden">
            {listing.images[0] ? (
              <Image
                src={listing.images[0]}
                alt={listing.title}
                fill
                className="object-cover group-hover:scale-105 transition-transform"
                sizes="(max-width: 640px) 50vw, (max-width: 1024px) 25vw, 13vw"
              />
            ) : (
              <div className="flex items-center justify-center h-full text-gray-300">
                <Package className="h-8 w-8" />
              </div>
            )}
          </div>

          {/* Body */}
          <div className="p-2">
            <p className="text-xs font-medium text-gray-900 line-clamp-2 mb-1.5 leading-snug">
              {listing.title}
            </p>
            <div className="flex items-center justify-between gap-1">
              <span className="text-sm font-bold text-[#0f3460]">
                {formatMoney(listing.price)}
              </span>
              <Badge variant={STATUS_VARIANT[listing.status] ?? 'default'}>
                {listing.status === 'DRAFT' ? 'Draft'
                  : listing.status === 'ACTIVE' ? 'Active'
                  : listing.status === 'ENDED' ? 'Ended'
                  : listing.status === 'OUT_OF_STOCK' ? 'Out of stock'
                  : listing.status}
              </Badge>
            </div>
            <p className="text-xs text-gray-400 mt-1">
              {listing.quantity.available} available · {listing.quantity.sold} sold
            </p>
          </div>
        </Link>
      ))}
    </div>
  );
}
