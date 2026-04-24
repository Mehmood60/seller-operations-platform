'use client';

import { useState, useEffect, useCallback } from 'react';
import { ai as aiApi, listings as listingsApi } from '@/lib/api';
import {
  TrendingUp, Loader2, AlertCircle, RefreshCw, Sparkles, Check,
  ExternalLink, ChevronDown, ChevronUp, ArrowDown, ArrowUp, Minus,
} from 'lucide-react';
import type { Listing, CompetitorCheck, PriceSuggestion, RepricingStrategy } from '@/types';

// ─── Per-row state ────────────────────────────────────────────────────────────

interface RowState {
  competitor:   CompetitorCheck | null;
  checking:     boolean;
  suggestion:   PriceSuggestion | null;
  suggesting:   boolean;
  applying:     boolean;
  applied:      boolean;
  expanded:     boolean;
  error:        string | null;
}

const defaultRow = (): RowState => ({
  competitor: null, checking: false, suggestion: null,
  suggesting: false, applying: false, applied: false,
  expanded: false, error: null,
});

// ─── Helpers ──────────────────────────────────────────────────────────────────

const STRATEGY_META: Record<RepricingStrategy, { label: string; color: string; Icon: React.FC<{ className?: string }> }> = {
  undercut:  { label: 'Undercut',   color: 'bg-blue-100 text-blue-700 border-blue-200',   Icon: ArrowDown },
  match:     { label: 'Match',      color: 'bg-gray-100 text-gray-700 border-gray-200',   Icon: Minus },
  premium:   { label: 'Premium',    color: 'bg-green-100 text-green-700 border-green-200', Icon: ArrowUp },
  no_change: { label: 'No change',  color: 'bg-gray-100 text-gray-600 border-gray-200',   Icon: Minus },
};

function fmt(val: string | number | null | undefined): string {
  if (val === null || val === undefined || val === '') return '—';
  const n = typeof val === 'number' ? val : parseFloat(val);
  if (isNaN(n)) return String(val);
  return `€${n.toFixed(2)}`;
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function RepricingPage() {
  const [listings, setListings]   = useState<Listing[]>([]);
  const [loading, setLoading]     = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [rows, setRows]           = useState<Record<string, RowState>>({});

  const patchRow = (id: string, patch: Partial<RowState>) =>
    setRows(prev => ({ ...prev, [id]: { ...(prev[id] ?? defaultRow()), ...patch } }));

  const fetchListings = useCallback(async () => {
    setLoading(true);
    setLoadError(null);
    try {
      const res = await listingsApi.list({ status: 'ACTIVE', limit: 100 });
      const items = res.data as unknown as Listing[];
      setListings(items);
      const init: Record<string, RowState> = {};
      items.forEach(l => { init[l.id] = defaultRow(); });
      setRows(init);
    } catch (err: unknown) {
      setLoadError(err instanceof Error ? err.message : 'Failed to load listings');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { fetchListings(); }, [fetchListings]);

  // ── Check competitors for one listing ──────────────────────────────────────

  const handleCheck = async (listing: Listing) => {
    patchRow(listing.id, { checking: true, error: null, suggestion: null, applied: false });
    try {
      const res = await listingsApi.checkCompetitors(listing.id);
      patchRow(listing.id, {
        competitor: res.data,
        checking: false,
        expanded: true,
      });
    } catch (err: unknown) {
      patchRow(listing.id, {
        checking: false,
        error: err instanceof Error ? err.message : 'Competitor check failed.',
      });
    }
  };

  // ── AI price suggestion ────────────────────────────────────────────────────

  const handleSuggest = async (listing: Listing) => {
    const row = rows[listing.id];
    if (!row?.competitor) return;
    patchRow(listing.id, { suggesting: true, error: null, suggestion: null, applied: false });
    try {
      const c = row.competitor;
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      const raw = listing as any;
      const shippingType = raw.shipping?.type ?? 'free';
      const shippingCost = parseFloat(raw.shipping?.cost ?? '0') || 0;

      const res = await aiApi.suggestPrice({
        title:             listing.title,
        current_price:     parseFloat(listing.price.value) || 0,
        shipping_type:     shippingType,
        shipping_cost:     shippingCost,
        competitor_lowest: c.lowest_total ? parseFloat(c.lowest_total) : null,
        competitor_count:  c.result_count,
        top_competitors:   (c.results ?? []).slice(0, 5).map(r => ({
          title:       r.title,
          total_price: r.total_price,
          condition:   r.condition,
          location:    r.location,
        })),
      });
      patchRow(listing.id, { suggestion: res.data, suggesting: false });
    } catch (err: unknown) {
      patchRow(listing.id, {
        suggesting: false,
        error: err instanceof Error ? err.message : 'AI suggestion failed.',
      });
    }
  };

  // ── Apply suggested price ──────────────────────────────────────────────────

  const handleApply = async (listing: Listing) => {
    const row = rows[listing.id];
    if (!row?.suggestion) return;
    patchRow(listing.id, { applying: true, error: null });
    const newPrice = String(row.suggestion.suggested_price.toFixed(2));
    try {
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      const raw = listing as any;
      await listingsApi.updateDraft(listing.id, { price: newPrice });
      await listingsApi.revise(listing.id, {
        title:       listing.title,
        condition:   raw.condition   ?? 'Neu',
        description: raw.description ?? '',
        price:       newPrice,
        quantity:    String(listing.quantity?.available ?? 1),
        sku:         listing.sku ?? '',
        category:    listing.category?.name ?? '',
        category_id: listing.category?.ebay_category_id ?? '',
        keywords:    raw.keywords    ?? [],
        item_specifics: raw.item_specifics ?? {},
        images:      listing.images  ?? [],
        shipping:    raw.shipping    ?? {},
      });
      patchRow(listing.id, { applying: false, applied: true });
      // refresh listing list so the price updates
      setListings(prev => prev.map(l =>
        l.id === listing.id
          ? { ...l, price: { value: newPrice, currency: l.price.currency } }
          : l
      ));
    } catch (err: unknown) {
      patchRow(listing.id, {
        applying: false,
        error: err instanceof Error ? err.message : 'Failed to apply price.',
      });
    }
  };

  // ── Render ─────────────────────────────────────────────────────────────────

  if (loading) {
    return (
      <div className="flex items-center justify-center py-24 text-gray-400">
        <Loader2 className="h-7 w-7 animate-spin" />
      </div>
    );
  }

  if (loadError) {
    return (
      <div className="flex items-center gap-2 text-red-500 text-sm py-8">
        <AlertCircle className="h-4 w-4 shrink-0" />{loadError}
      </div>
    );
  }

  return (
    <div className="space-y-5 w-full">

      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <div className="flex items-center gap-2 mb-1">
            <TrendingUp className="h-6 w-6 text-blue-600" />
            <h1 className="text-2xl font-bold text-gray-900">Smart Repricing</h1>
          </div>
          <p className="text-sm text-gray-500">
            Check live competitor prices and get AI-powered pricing recommendations for your active listings.
          </p>
        </div>
        <button
          onClick={fetchListings}
          className="flex items-center gap-1.5 px-3 py-2 border border-gray-200 hover:bg-gray-50 text-gray-600 rounded-lg text-sm font-medium transition-colors"
        >
          <RefreshCw className="h-4 w-4" /> Refresh
        </button>
      </div>

      {listings.length === 0 ? (
        <div className="text-center py-16 text-gray-400">
          <TrendingUp className="h-10 w-10 mx-auto mb-3 opacity-30" />
          <p className="text-sm">No active listings found.</p>
        </div>
      ) : (
        <div className="space-y-3">
          {listings.map(listing => {
            const row  = rows[listing.id] ?? defaultRow();
            const meta = row.suggestion ? STRATEGY_META[row.suggestion.strategy] : null;
            const currentPrice = parseFloat(listing.price.value) || 0;
            const priceDiff = row.suggestion
              ? row.suggestion.suggested_price - currentPrice
              : null;

            return (
              <div key={listing.id} className="bg-white border border-gray-200 rounded-xl overflow-hidden shadow-sm">

                {/* Row header */}
                <div className="flex items-center gap-3 px-4 py-3">
                  {/* Thumbnail */}
                  <div className="w-12 h-12 rounded-lg overflow-hidden bg-gray-100 flex-shrink-0">
                    {listing.images?.[0] ? (
                      <img
                        src={listing.images[0]}
                        alt=""
                        className="w-full h-full object-cover"
                        onError={e => { (e.target as HTMLImageElement).style.display = 'none'; }}
                      />
                    ) : (
                      <div className="w-full h-full flex items-center justify-center text-gray-300 text-xs">No img</div>
                    )}
                  </div>

                  {/* Title + price */}
                  <div className="flex-1 min-w-0">
                    <p className="text-sm font-medium text-gray-900 truncate">{listing.title}</p>
                    <div className="flex items-center gap-2 mt-0.5 flex-wrap">
                      <span className="text-sm font-semibold text-gray-700">{fmt(listing.price.value)}</span>
                      {listing.listing_url && (
                        <a href={listing.listing_url} target="_blank" rel="noopener noreferrer"
                          className="text-xs text-blue-500 hover:underline flex items-center gap-0.5"
                        >
                          eBay <ExternalLink className="h-3 w-3" />
                        </a>
                      )}
                      {/* Competitor badge */}
                      {row.competitor && (
                        <span className="text-xs text-gray-500">
                          {row.competitor.result_count} competitor{row.competitor.result_count !== 1 ? 's' : ''} · lowest {fmt(row.competitor.lowest_total)}
                        </span>
                      )}
                    </div>
                  </div>

                  {/* Actions */}
                  <div className="flex items-center gap-2 shrink-0">
                    <button
                      onClick={() => handleCheck(listing)}
                      disabled={row.checking}
                      className="flex items-center gap-1.5 px-3 py-1.5 border border-gray-200 hover:bg-gray-50 disabled:opacity-50 text-gray-600 rounded-lg text-xs font-medium transition-colors"
                    >
                      {row.checking
                        ? <><Loader2 className="h-3.5 w-3.5 animate-spin" />Checking…</>
                        : <><RefreshCw className="h-3.5 w-3.5" />Check Competitors</>}
                    </button>

                    {row.competitor && (
                      <button
                        onClick={() => handleSuggest(listing)}
                        disabled={row.suggesting || row.suggesting}
                        className="flex items-center gap-1.5 px-3 py-1.5 bg-purple-600 hover:bg-purple-700 disabled:opacity-50 text-white rounded-lg text-xs font-medium transition-colors"
                      >
                        {row.suggesting
                          ? <><Loader2 className="h-3.5 w-3.5 animate-spin" />Analyzing…</>
                          : <><Sparkles className="h-3.5 w-3.5" />AI Suggest Price</>}
                      </button>
                    )}

                    {(row.competitor || row.suggestion) && (
                      <button
                        onClick={() => patchRow(listing.id, { expanded: !row.expanded })}
                        className="p-1.5 text-gray-400 hover:text-gray-600 transition-colors"
                      >
                        {row.expanded
                          ? <ChevronUp className="h-4 w-4" />
                          : <ChevronDown className="h-4 w-4" />}
                      </button>
                    )}
                  </div>
                </div>

                {/* Error */}
                {row.error && (
                  <div className="mx-4 mb-3 flex items-start gap-2 text-red-600 text-xs bg-red-50 border border-red-200 rounded-lg px-3 py-2">
                    <AlertCircle className="h-3.5 w-3.5 mt-0.5 shrink-0" />{row.error}
                  </div>
                )}

                {/* AI Suggestion card (always visible when present) */}
                {row.suggestion && meta && (
                  <div className="mx-4 mb-3 bg-gradient-to-br from-purple-50 to-indigo-50 border border-purple-200 rounded-xl p-4 space-y-3">
                    <div className="flex items-center justify-between flex-wrap gap-2">
                      <div className="flex items-center gap-2">
                        <Sparkles className="h-4 w-4 text-purple-600" />
                        <span className="text-sm font-semibold text-purple-800">AI Price Suggestion</span>
                        <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full border text-xs font-medium ${meta.color}`}>
                          <meta.Icon className="h-3 w-3" />
                          {meta.label}
                        </span>
                      </div>
                      <div className="flex items-center gap-3">
                        <div className="text-right">
                          <span className="text-xl font-bold text-gray-900">{fmt(row.suggestion.suggested_price)}</span>
                          {priceDiff !== null && Math.abs(priceDiff) >= 0.01 && (
                            <span className={`ml-2 text-xs font-medium ${priceDiff < 0 ? 'text-red-500' : 'text-green-600'}`}>
                              {priceDiff > 0 ? '+' : ''}{fmt(priceDiff)}
                            </span>
                          )}
                        </div>
                        {row.applied ? (
                          <span className="inline-flex items-center gap-1 px-3 py-1.5 bg-green-100 text-green-700 rounded-lg text-xs font-medium">
                            <Check className="h-3.5 w-3.5" /> Applied
                          </span>
                        ) : (
                          <button
                            onClick={() => handleApply(listing)}
                            disabled={row.applying}
                            className="flex items-center gap-1.5 px-3 py-1.5 bg-purple-600 hover:bg-purple-700 disabled:opacity-50 text-white rounded-lg text-xs font-medium transition-colors"
                          >
                            {row.applying
                              ? <><Loader2 className="h-3.5 w-3.5 animate-spin" />Applying…</>
                              : 'Apply Price'}
                          </button>
                        )}
                      </div>
                    </div>
                    <p className="text-xs text-gray-600 leading-relaxed">{row.suggestion.reasoning}</p>
                  </div>
                )}

                {/* Expanded competitor results */}
                {row.expanded && row.competitor && (
                  <div className="border-t border-gray-100 px-4 py-3 space-y-2">
                    <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide">
                      Competitor Results ({row.competitor.result_count})
                      {row.competitor.keywords_used && (
                        <span className="ml-2 font-normal normal-case text-gray-400">
                          searched: "{row.competitor.keywords_used}"
                        </span>
                      )}
                    </p>
                    <div className="space-y-1.5 max-h-64 overflow-y-auto pr-1">
                      {(row.competitor.results ?? []).map((r, i) => (
                        <div key={i} className="flex items-center gap-3 text-xs py-1.5 border-b border-gray-50 last:border-0">
                          <span className="text-gray-400 shrink-0 w-4 text-right">{i + 1}.</span>
                          <p className="flex-1 text-gray-700 truncate">{r.title}</p>
                          <span className="text-gray-500 shrink-0">{r.condition}</span>
                          <span className="font-semibold text-gray-800 shrink-0">{fmt(r.total_price)}</span>
                          {r.url && (
                            <a href={r.url} target="_blank" rel="noopener noreferrer"
                              className="text-blue-400 hover:text-blue-600 shrink-0"
                            >
                              <ExternalLink className="h-3 w-3" />
                            </a>
                          )}
                        </div>
                      ))}
                      {(row.competitor.results ?? []).length === 0 && (
                        <p className="text-gray-400 text-xs py-2">No competitor results returned.</p>
                      )}
                    </div>
                  </div>
                )}

              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
