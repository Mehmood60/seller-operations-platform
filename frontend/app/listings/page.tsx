'use client';

import { useEffect, useState, useCallback, useRef } from 'react';
import Link from 'next/link';
import { listings as listingsApi, sync as syncApi } from '@/lib/api';
import { ListingGrid } from '@/components/ListingGrid';
import { SyncButton } from '@/components/SyncButton';
import type { Listing } from '@/types';
import { CheckCircle2, Loader2, Plus, Search } from 'lucide-react';

const STATUS_OPTIONS = [
  { value: '',             label: 'All' },
  { value: 'ACTIVE',       label: 'Active' },
  { value: 'DRAFT',        label: 'Draft' },
  { value: 'ENDED',        label: 'Ended' },
  { value: 'OUT_OF_STOCK', label: 'Out of Stock' },
];

export default function ListingsPage() {
  const [listingList, setListingList] = useState<Listing[]>([]);
  const [loading, setLoading]         = useState(true);
  const [error, setError]             = useState<string | null>(null);
  const [search, setSearch]           = useState('');
  const [status, setStatus]           = useState('ACTIVE');
  const [page, setPage]               = useState(1);
  const [total, setTotal]             = useState(0);
  const limit = 50;

  // Auto-sync state
  const [autoSyncing, setAutoSyncing]     = useState(false);
  const [autoSyncDone, setAutoSyncDone]   = useState(false);
  const [autoSyncCount, setAutoSyncCount] = useState<number | null>(null);
  const hasSynced = useRef(false);

  const fetchListings = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await listingsApi.list({ page, limit, status: status || undefined, search: search || undefined });
      setListingList(res.data as unknown as Listing[]);
      setTotal(res.meta.total ?? 0);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to load listings');
    } finally {
      setLoading(false);
    }
  }, [page, status, search]);

  useEffect(() => {
    fetchListings();
  }, [fetchListings]);

  // Auto-sync on first mount — runs in background, refreshes list when done
  useEffect(() => {
    if (hasSynced.current) return;
    hasSynced.current = true;

    setAutoSyncing(true);
    syncApi.listings()
      .then(res => {
        const count = res.data.synced ?? 0;
        setAutoSyncCount(count);
        setAutoSyncDone(true);
        if (count > 0) fetchListings();
        setTimeout(() => setAutoSyncDone(false), 4000);
      })
      .catch(() => { /* silent — manual sync button still works */ })
      .finally(() => setAutoSyncing(false));
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const totalPages = Math.ceil(total / limit);

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <h1 className="text-2xl font-bold text-gray-900">Listings</h1>
          {autoSyncing && (
            <span className="inline-flex items-center gap-1.5 text-xs text-gray-400">
              <Loader2 className="h-3.5 w-3.5 animate-spin" />
              Syncing…
            </span>
          )}
          {autoSyncDone && !autoSyncing && (
            <span className="inline-flex items-center gap-1.5 text-xs text-green-600">
              <CheckCircle2 className="h-3.5 w-3.5" />
              {autoSyncCount === 0 ? 'Up to date' : `${autoSyncCount} updated`}
            </span>
          )}
        </div>
        <div className="flex items-center gap-2">
          <SyncButton type="listings" onComplete={fetchListings} />
          <Link
            href="/listings/new"
            className="flex items-center gap-1.5 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition-colors"
          >
            <Plus className="h-4 w-4" />
            New Listing
          </Link>
        </div>
      </div>


      <div className="flex items-center gap-3 flex-wrap">
        <div className="relative">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
          <input
            type="text"
            placeholder="Search listings…"
            value={search}
            maxLength={100}
            onChange={(e) => { setSearch(e.target.value); setPage(1); }}
            className="pl-9 pr-4 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-200 w-56"
          />
        </div>
        <select
          value={status}
          onChange={(e) => { setStatus(e.target.value); setPage(1); }}
          className="border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-200"
        >
          {STATUS_OPTIONS.map((o) => (
            <option key={o.value} value={o.value}>{o.label}</option>
          ))}
        </select>
      </div>

      {error ? (
        <p className="text-red-500">{error}</p>
      ) : loading ? (
        <p className="text-gray-400 text-center py-12">Loading…</p>
      ) : (
        <ListingGrid listings={listingList} />
      )}

      {totalPages > 1 && (
        <div className="flex items-center justify-between text-sm text-gray-500">
          <span>{total} listings total</span>
          <div className="flex gap-2">
            <button
              onClick={() => setPage(p => Math.max(1, p - 1))}
              disabled={page === 1}
              className="px-3 py-1.5 border border-gray-200 rounded-lg disabled:opacity-40 hover:bg-gray-50"
            >
              Previous
            </button>
            <button
              onClick={() => setPage(p => Math.min(totalPages, p + 1))}
              disabled={page === totalPages}
              className="px-3 py-1.5 border border-gray-200 rounded-lg disabled:opacity-40 hover:bg-gray-50"
            >
              Next
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
