'use client';

import { useEffect, useState } from 'react';
import { AlertCircle, CheckCircle, Loader2, Lock, Megaphone } from 'lucide-react';
import { listings as listingsApi } from '@/lib/api';

export interface PromotionChoice {
  enabled: boolean;
  ad_rate: string;
}

export const DEFAULT_PROMOTION: PromotionChoice = { enabled: false, ad_rate: '5.0' };

interface Props {
  /**
   * Our listing id. Absent on the new-listing page, where there is nothing on eBay to
   * sync with yet — the choice travels with the draft and is applied at publish.
   */
  listingId?: string;
  value: PromotionChoice;
  onChange: (next: PromotionChoice) => void;
  /** Item price, used only to show what the ad rate works out to per sale. */
  priceValue?: string;
}

// ─── Switch ──────────────────────────────────────────────────────────────────

function Switch({
  checked, onClick, disabled, label,
}: {
  checked: boolean;
  onClick?: () => void;
  disabled?: boolean;
  label: string;
}) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      aria-label={label}
      disabled={disabled}
      onClick={onClick}
      className={`relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-blue-300 focus:ring-offset-2 ${
        checked ? 'bg-blue-600' : 'bg-gray-200'
      } ${disabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'}`}
    >
      <span
        className={`inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform ${
          checked ? 'translate-x-6' : 'translate-x-1'
        }`}
      />
    </button>
  );
}

// ─── Card ────────────────────────────────────────────────────────────────────

export function PromotedListing({ listingId, value, onChange, priceValue }: Props) {
  const [loading, setLoading]   = useState(Boolean(listingId));
  const [saving, setSaving]     = useState(false);
  const [error, setError]       = useState<string | null>(null);
  const [live, setLive]         = useState(false);
  const [syncedAt, setSyncedAt] = useState('');
  // Editing buffer so a half-typed rate does not fight the controlled value.
  const [rateInput, setRateInput] = useState(value.ad_rate);

  // Read the real state from eBay on open — the ad may have been changed in Seller Hub.
  useEffect(() => {
    if (!listingId) return;
    let cancelled = false;

    listingsApi.getPromotion(listingId)
      .then(res => {
        if (cancelled || !res.data) return;
        const p = res.data;
        setLive(p.live);
        setSyncedAt(p.synced_at);
        setRateInput(p.ad_rate);
        setError(p.sync_error || null);
        onChange({ enabled: p.enabled, ad_rate: p.ad_rate });
      })
      .catch(err => {
        if (!cancelled) setError(err instanceof Error ? err.message : 'Could not read the ad status.');
      })
      .finally(() => { if (!cancelled) setLoading(false); });

    return () => { cancelled = true; };
    // onChange is a fresh closure each render; re-running on it would loop.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [listingId]);

  /** Push a change to eBay, or just keep it locally when there is no listing yet. */
  const commit = async (next: PromotionChoice) => {
    if (!listingId) {
      onChange(next);
      return;
    }

    setSaving(true);
    setError(null);
    try {
      const res = await listingsApi.setPromotion(listingId, {
        enabled: next.enabled,
        ad_rate: next.ad_rate,
      });
      const p = res.data;
      setLive(p.live);
      setSyncedAt(p.synced_at);
      setRateInput(p.ad_rate);
      if (p.sync_error) setError(p.sync_error);
      onChange({ enabled: p.enabled, ad_rate: p.ad_rate });
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'eBay did not accept the change.');
      setRateInput(value.ad_rate);
    } finally {
      setSaving(false);
    }
  };

  const commitRate = () => {
    const trimmed = rateInput.trim();
    if (trimmed === '' || trimmed === value.ad_rate) {
      setRateInput(value.ad_rate);
      return;
    }
    commit({ enabled: value.enabled, ad_rate: trimmed });
  };

  const price   = Number.parseFloat(priceValue ?? '');
  const rate    = Number.parseFloat(value.ad_rate);
  const feeHint = Number.isFinite(price) && price > 0 && Number.isFinite(rate)
    ? `≈ €${((price * rate) / 100).toFixed(2)} per sale at the current price`
    : null;

  return (
    <div className="border border-gray-200 rounded-xl p-5 space-y-4">
      <div className="flex items-start gap-2">
        <Megaphone className="h-4 w-4 text-gray-400 mt-0.5 shrink-0" />
        <div>
          <p className="text-sm font-semibold text-gray-800">Advertising (Promoted Listings)</p>
          <p className="text-xs text-gray-500">
            Off by default. Switching it on or off here changes the ad on eBay straight away.
          </p>
        </div>
        {(loading || saving) && <Loader2 className="h-4 w-4 animate-spin text-gray-400 ml-auto mt-0.5" />}
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        {/* ── Basis ───────────────────────────────────────────────────────── */}
        <div className={`rounded-xl border p-4 transition-colors ${
          value.enabled ? 'border-blue-300 bg-blue-50/60' : 'border-gray-200'
        }`}>
          <div className="flex items-center justify-between mb-3">
            <span className="text-sm font-semibold text-gray-900">Basis</span>
            <Switch
              checked={value.enabled}
              disabled={loading || saving}
              label="Promote this listing with the Basis campaign strategy"
              onClick={() => commit({ enabled: !value.enabled, ad_rate: value.ad_rate })}
            />
          </div>

          <ul className="space-y-1.5 text-xs text-gray-600">
            <li className="flex gap-1.5">
              <CheckCircle className="h-3.5 w-3.5 text-green-600 shrink-0 mt-px" />
              You only pay if the item sells
            </li>
            <li className="flex gap-1.5">
              <CheckCircle className="h-3.5 w-3.5 text-green-600 shrink-0 mt-px" />
              Clicks that lead to nothing are free
            </li>
            <li className="flex gap-1.5">
              <CheckCircle className="h-3.5 w-3.5 text-green-600 shrink-0 mt-px" />
              Basic access to ad placements
            </li>
          </ul>

          {value.enabled && (
            <div className="mt-3 pt-3 border-t border-blue-200/70">
              <label className="block text-xs font-medium text-gray-700 mb-1.5">Ad rate</label>
              <div className="flex items-center gap-1.5">
                <input
                  type="number"
                  min={2}
                  max={100}
                  step={0.1}
                  value={rateInput}
                  disabled={saving}
                  onChange={e => setRateInput(e.target.value)}
                  onBlur={commitRate}
                  onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); e.currentTarget.blur(); } }}
                  className="w-20 px-2 py-1 border border-gray-200 rounded text-sm tabular-nums focus:outline-none focus:ring-2 focus:ring-blue-200 disabled:opacity-50"
                />
                <span className="text-sm text-gray-500">%</span>
              </div>
              <p className="mt-1 text-xs text-gray-400">
                2–100%, one decimal. {feeHint}
              </p>
            </div>
          )}
        </div>

        {/* ── Premium ─────────────────────────────────────────────────────── */}
        <div className="rounded-xl border border-gray-200 bg-gray-50/70 p-4">
          <div className="flex items-center justify-between mb-3">
            <span className="text-sm font-semibold text-gray-500">Premium</span>
            <Switch checked={false} disabled label="Premium campaign strategy (not available)" />
          </div>

          <ul className="space-y-1.5 text-xs text-gray-500">
            <li className="flex gap-1.5">
              <Lock className="h-3.5 w-3.5 shrink-0 mt-px" />
              Charged per click, whether or not it sells
            </li>
            <li className="flex gap-1.5">
              <Lock className="h-3.5 w-3.5 shrink-0 mt-px" />
              Needs a daily budget and a bid, so it cannot be a plain switch
            </li>
          </ul>

          <p className="mt-3 pt-3 border-t border-gray-200 text-xs text-gray-400">
            Set this one up in eBay Seller Hub.
          </p>
        </div>
      </div>

      {/* ── Status line ───────────────────────────────────────────────────── */}
      {error && (
        <p className="text-xs text-red-600 flex items-start gap-1.5">
          <AlertCircle className="h-3.5 w-3.5 shrink-0 mt-px" />
          {error}
        </p>
      )}

      {!error && value.enabled && listingId && !live && (
        <p className="text-xs text-amber-600 flex items-start gap-1.5">
          <AlertCircle className="h-3.5 w-3.5 shrink-0 mt-px" />
          Saved — the ad starts on eBay as soon as this draft is published.
        </p>
      )}

      {!error && value.enabled && !listingId && (
        <p className="text-xs text-amber-600 flex items-start gap-1.5">
          <AlertCircle className="h-3.5 w-3.5 shrink-0 mt-px" />
          The ad starts on eBay once this listing is published.
        </p>
      )}

      {!error && live && syncedAt && (
        <p className="text-xs text-gray-400">
          In sync with eBay · {new Date(syncedAt).toLocaleString()}
        </p>
      )}
    </div>
  );
}
