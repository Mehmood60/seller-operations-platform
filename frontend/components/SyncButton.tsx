'use client';

import { useState } from 'react';
import { RefreshCw } from 'lucide-react';
import { sync } from '@/lib/api';

interface SyncButtonProps {
  type: 'orders' | 'listings';
  onComplete?: () => void;
}

export function SyncButton({ type, onComplete }: SyncButtonProps) {
  const [loading, setLoading]   = useState(false);
  const [message, setMessage]   = useState<string | null>(null);
  const [isError, setIsError]   = useState(false);

  const handleSync = async () => {
    setLoading(true);
    setMessage(null);
    setIsError(false);

    try {
      const res = type === 'orders'
        ? await sync.orders()
        : await sync.listings();

      if (res.data.error) {
        setIsError(true);
        setMessage(res.data.error);
      } else {
        setMessage(`Synced ${res.data.synced} ${type} from eBay.`);
        onComplete?.();
      }
    } catch (err: unknown) {
      setIsError(true);
      setMessage(err instanceof Error ? err.message : 'Sync failed');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="flex items-center gap-3">
      <button
        onClick={handleSync}
        disabled={loading}
        className="inline-flex items-center gap-2 px-4 py-2 bg-[#0f3460] text-white text-sm font-medium rounded-lg hover:bg-[#0a2444] disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
      >
        <RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
        {loading ? 'Syncing…' : `Sync ${type === 'orders' ? 'Orders' : 'Listings'}`}
      </button>

      {message && (
        <span className={`text-sm ${isError ? 'text-red-600' : 'text-green-600'}`}>
          {message}
        </span>
      )}
    </div>
  );
}
