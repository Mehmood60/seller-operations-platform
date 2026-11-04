'use client';

import { useState } from 'react';
import { Check, Loader2, Pencil, RotateCcw, X } from 'lucide-react';
import { orders as ordersApi } from '@/lib/api';
import { useFormatAmount } from '@/components/PreferencesProvider';
import type { Order } from '@/types';

/** What the seller receives for an order, using their own correction if they made one. */
export function effectiveNet(order: Order): string | null {
  return order.earnings_override?.net ?? order.earnings?.net ?? null;
}

interface EarningsValueProps {
  order: Order;
  onChange: (order: Order) => void;
  className?: string;
}

/**
 * "You receive" with a small pencil to correct it by hand when eBay's figure is
 * wrong or missing. A correction can be reset to go back to eBay's number.
 */
export function EarningsValue({ order, onChange, className = '' }: EarningsValueProps) {
  const formatAmount = useFormatAmount();
  const [editing, setEditing] = useState(false);
  const [value, setValue]     = useState('');
  const [saving, setSaving]   = useState(false);
  const [error, setError]     = useState<string | null>(null);

  const net        = effectiveNet(order);
  const isManual   = !!order.earnings_override;
  const isEstimate = !isManual && order.earnings?.source !== 'finances';

  async function save(next: string | null) {
    setSaving(true);
    setError(null);
    try {
      const res = await ordersApi.setEarnings(order.id, next);
      onChange(res.data as unknown as Order);
      setEditing(false);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not save');
    } finally {
      setSaving(false);
    }
  }

  if (editing) {
    return (
      <span className="inline-flex flex-col gap-1">
        <form
          className="inline-flex items-center gap-1"
          onSubmit={(e) => { e.preventDefault(); if (value.trim() !== '') save(value.trim()); }}
        >
          <input
            autoFocus
            inputMode="decimal"
            value={value}
            onChange={(e) => setValue(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Escape') setEditing(false); }}
            className="w-20 border border-gray-300 rounded px-1.5 py-0.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-200"
            aria-label="Amount you receive"
          />
          <button type="submit" disabled={saving} aria-label="Save" className="p-1 text-green-700 hover:bg-green-50 rounded">
            {saving ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Check className="h-3.5 w-3.5" />}
          </button>
          <button type="button" onClick={() => setEditing(false)} aria-label="Cancel" className="p-1 text-gray-400 hover:bg-gray-100 rounded">
            <X className="h-3.5 w-3.5" />
          </button>
        </form>
        {error && <span className="text-xs text-red-600">{error}</span>}
      </span>
    );
  }

  return (
    <span className={`inline-flex items-center gap-1 group ${className}`}>
      <span
        className={`font-semibold ${net !== null && parseFloat(net) < 0 ? 'text-red-600' : 'text-green-700'}`}
        title={isManual ? 'Corrected by you' : isEstimate ? 'Estimate — ad fees not included' : 'Actual eBay payout'}
      >
        {formatAmount(net)}
      </span>
      {isManual && <span className="text-[10px] uppercase tracking-wide text-blue-600 font-medium">edited</span>}
      {isEstimate && net !== null && <span className="text-gray-400">≈</span>}
      <button
        onClick={() => { setValue(net ?? ''); setEditing(true); setError(null); }}
        aria-label="Edit amount you receive"
        title="Correct this amount"
        className="p-1 rounded text-gray-300 hover:text-gray-700 hover:bg-gray-100"
      >
        <Pencil className="h-3 w-3" />
      </button>
      {isManual && (
        <button
          onClick={() => save(null)}
          disabled={saving}
          aria-label="Reset to eBay's amount"
          title="Reset to eBay's amount"
          className="p-1 rounded text-gray-300 hover:text-gray-700 hover:bg-gray-100"
        >
          {saving ? <Loader2 className="h-3 w-3 animate-spin" /> : <RotateCcw className="h-3 w-3" />}
        </button>
      )}
    </span>
  );
}
