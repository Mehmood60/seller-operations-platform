import Link from 'next/link';
import Image from 'next/image';
import type { Listing, ListingStatus } from '@/types';
import { formatMoney } from '@/lib/formatters';
import { Badge } from '@/components/ui/Badge';
import { Package } from 'lucide-react';

const STATUS_VARIANT: Record<ListingStatus, 'success' | 'danger' | 'warning'> = {
  ACTIVE:       'success',
  ENDED:        'danger',
  OUT_OF_STOCK: 'warning',
};

interface ListingGridProps {
  listings: Listing[];
}

export function ListingGrid({ listings }: ListingGridProps) {
  if (listings.length === 0) {
    return (
      <div className="text-center py-12 text-gray-400">
        No listings found.
      </div>
    );
  }

  return (
    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
      {listings.map((listing) => (
        <Link
          key={listing.id}
          href={`/listings/${listing.id}`}
          className="bg-white border border-gray-200 rounded-xl overflow-hidden hover:shadow-md transition-shadow group"
        >
          {/* Image */}
          <div className="aspect-square bg-gray-100 relative overflow-hidden">
            {listing.images[0] ? (
              <Image
                src={listing.images[0]}
                alt={listing.title}
                fill
                className="object-cover group-hover:scale-105 transition-transform"
                sizes="(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 25vw"
              />
            ) : (
              <div className="flex items-center justify-center h-full text-gray-300">
                <Package className="h-12 w-12" />
              </div>
            )}
          </div>

          {/* Body */}
          <div className="p-3">
            <p className="text-sm font-medium text-gray-900 line-clamp-2 mb-2">
              {listing.title}
            </p>
            <div className="flex items-center justify-between">
              <span className="text-base font-bold text-[#0f3460]">
                {formatMoney(listing.price)}
              </span>
              <Badge variant={STATUS_VARIANT[listing.status] ?? 'default'}>
                {listing.status}
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
