'use client';

import { useCallback, useEffect, useState } from 'react';
import { monitor as monitorApi, listings as listingsApi } from '@/lib/api';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { useFormatMoney } from '@/components/PreferencesProvider';
import { formatDateTime } from '@/lib/formatters';
import type { CompetitorCheck, MonitorItem } from '@/types';
import {
  Activity,
  AlertTriangle,
  CheckCircle2,
  ChevronDown,
  ChevronRight,
  ExternalLink,
  Loader2,
  RefreshCw,
  Search,
  TrendingDown,
  TrendingUp,
} from 'lucide-react';

export default function MonitorPage() {
  const formatMoney = useFormatMoney();

  const [items, setItems]             = useState<MonitorItem[]>([]);
  const [loading, setLoading]         = useState(true);
  const [error, setError]             = useState<string | null>(null);
  const [checkingId, setCheckingId]   = useState<string | null>(null);
  const [checkingAll, setCheckingAll] = useState(false);
  const [rowErrors, setRowErrors]     = useState<Record<string, string>>({});

  // Competitor state per row
  const [compChecking, setCompChecking]   = useState<string | null>(null);   // id being checked
  const [compOpen, setCompOpen]           = useState<string | null>(null);   // id whose panel is open
  const [compResults, setCompResults]     = useState<Record<string, CompetitorCheck>>({});
  const [compErrors, setCompErrors]       = useState<Record<string, string>>({});

  const loadStatus = useCallback(() => {
    return monitorApi.status()
      .then(res => setItems(res.data as unknown as MonitorItem[]))
      .catch(err => setError(err instanceof Error ? err.message : 'Failed to load monitor status'));
  }, []);

  useEffect(() => {
    loadStatus().finally(() => setLoading(false));
  }, [loadStatus]);

  async function handleToggle(id: string, enabled: boolean) {
    try {
      const res = await monitorApi.toggle(id, enabled);
      setItems(prev => prev.map(item => item.id === id ? (res.data as unknown as MonitorItem) : item));
    } catch (err) {
      setRowErrors(prev => ({ ...prev, [id]: err instanceof Error ? err.message : 'Toggle failed' }));
    }
  }

  async function handleCheckOne(id: string) {
    setCheckingId(id);
    setRowErrors(prev => { const n = { ...prev }; delete n[id]; return n; });
    try {
      const res = await monitorApi.checkOne(id);
      setItems(prev => prev.map(item => item.id === id ? (res.data as unknown as MonitorItem) : item));
    } catch (err) {
      setRowErrors(prev => ({ ...prev, [id]: err instanceof Error ? err.message : 'Check failed' }));
    } finally {
      setCheckingId(null);
    }
  }

  async function handleCheckAll() {
    setCheckingAll(true);
    setRowErrors({});
    try {
      const res = await monitorApi.checkAll();
      const updated = res.data as unknown as MonitorItem[];
      setItems(prev => {
        const map = new Map(updated.map(i => [i.id, i]));
        return prev.map(item => map.get(item.id) ?? item);
      });
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Check all failed');
    } finally {
      setCheckingAll(false);
    }
  }

  async function handleApply(id: string, newPrice: number) {
    try {
      await monitorApi.applyUpdate(id, newPrice);
      await loadStatus();
    } catch (err) {
      setRowErrors(prev => ({ ...prev, [id]: err instanceof Error ? err.message : 'Apply failed' }));
    }
  }

  async function handleCheckCompetitors(id: string) {
    setCompChecking(id);
    setCompErrors(prev => { const n = { ...prev }; delete n[id]; return n; });
    try {
      const res = await listingsApi.checkCompetitors(id);
      const data = res.data as unknown as CompetitorCheck;
      setCompResults(prev => ({ ...prev, [id]: data }));
      setCompOpen(id);
      // Update the summary in the items list
      await loadStatus();
    } catch (err) {
      setCompErrors(prev => ({ ...prev, [id]: err instanceof Error ? err.message : 'Competitor check failed' }));
    } finally {
      setCompChecking(null);
    }
  }

  function toggleCompPanel(id: string) {
    setCompOpen(prev => prev === id ? null : id);
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center py-24 text-gray-400">
        <Loader2 className="h-8 w-8 animate-spin" />
      </div>
    );
  }

  const activeCount  = items.filter(i => i.monitor.enabled).length;
  const pendingCount = items.filter(i => i.monitor.pending_change && !i.monitor.pending_change.applied).length;

  return (
    <div className="space-y-5 w-full">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-bold text-gray-900">Price Monitor</h1>
          <p className="text-sm text-gray-500 mt-0.5">
            Track your source costs and check what competitors charge on eBay Germany.
          </p>
        </div>
        <button
          onClick={handleCheckAll}
          disabled={checkingAll || activeCount === 0}
          className="inline-flex items-center gap-2 px-4 py-2 bg-[#0f3460] text-white text-sm font-medium rounded-lg
            hover:bg-[#0a2444] transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {checkingAll ? <Loader2 className="h-4 w-4 animate-spin" /> : <RefreshCw className="h-4 w-4" />}
          Check All Active
        </button>
      </div>

      {error && (
        <div className="text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg px-4 py-3">{error}</div>
      )}

      {/* Summary cards */}
      <div className="grid grid-cols-3 gap-4">
        <Card>
          <CardBody className="flex items-center gap-3">
            <Activity className="h-8 w-8 text-[#0f3460] flex-shrink-0" />
            <div>
              <p className="text-2xl font-bold text-gray-900">{items.length}</p>
              <p className="text-xs text-gray-500">Listings with source URL</p>
            </div>
          </CardBody>
        </Card>
        <Card>
          <CardBody className="flex items-center gap-3">
            <CheckCircle2 className="h-8 w-8 text-green-500 flex-shrink-0" />
            <div>
              <p className="text-2xl font-bold text-gray-900">{activeCount}</p>
              <p className="text-xs text-gray-500">Monitoring enabled</p>
            </div>
          </CardBody>
        </Card>
        <Card>
          <CardBody className="flex items-center gap-3">
            <AlertTriangle className="h-8 w-8 text-amber-500 flex-shrink-0" />
            <div>
              <p className="text-2xl font-bold text-gray-900">{pendingCount}</p>
              <p className="text-xs text-gray-500">Pending price changes</p>
            </div>
          </CardBody>
        </Card>
      </div>

      {/* Main table */}
      {items.length === 0 ? (
        <Card>
          <CardBody className="py-12 text-center text-gray-400 text-sm">
            No listings with a source URL found. Add a source URL on the listing edit page to enable monitoring.
          </CardBody>
        </Card>
      ) : (
        <Card>
          <CardHeader>
            <h2 className="font-semibold text-sm">Monitored Listings</h2>
          </CardHeader>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-gray-100 text-left">
                  <th className="px-4 py-3 text-xs text-gray-500 font-semibold uppercase">Listing</th>
                  <th className="px-4 py-3 text-xs text-gray-500 font-semibold uppercase text-right">Your eBay Price</th>
                  <th className="px-4 py-3 text-xs text-gray-500 font-semibold uppercase text-right">Source Cost</th>
                  <th className="px-4 py-3 text-xs text-gray-500 font-semibold uppercase text-right">Lowest Competitor</th>
                  <th className="px-4 py-3 text-xs text-gray-500 font-semibold uppercase">Cost Change</th>
                  <th className="px-4 py-3 text-xs text-gray-500 font-semibold uppercase">Actions</th>
                </tr>
              </thead>
              <tbody>
                {items.map(item => {
                  const pending    = item.monitor.pending_change;
                  const hasPending = pending && !pending.applied;
                  const cc         = item.competitor_check;
                  const isOpen     = compOpen === item.id;
                  const cachedComp = compResults[item.id];
                  const rowErr     = rowErrors[item.id];
                  const compErr    = compErrors[item.id];
                  const isCheckingComp = compChecking === item.id;

                  // Competitor vs your price comparison
                  const yourPrice  = parseFloat(item.ebay_price.value);
                  const lowestComp = cc?.lowest_total ? parseFloat(cc.lowest_total) : null;
                  const priceDiff  = lowestComp !== null ? yourPrice - lowestComp : null;

                  return (
                    <>
                      <tr
                        key={item.id}
                        className={`border-b border-gray-50 hover:bg-gray-50/60
                          ${hasPending ? 'bg-amber-50/30' : ''}
                          ${isOpen ? 'bg-blue-50/30' : ''}`}
                      >
                        {/* Listing */}
                        <td className="px-4 py-3">
                          <div className="flex items-center gap-3">
                            {/* Thumbnail */}
                            <div className="flex-shrink-0 h-14 w-14 rounded-lg border border-gray-100 bg-gray-50 overflow-hidden">
                              {item.image ? (
                                <img
                                  src={item.image}
                                  alt=""
                                  className="h-full w-full object-contain"
                                />
                              ) : (
                                <div className="h-full w-full flex items-center justify-center text-gray-300 text-xs">
                                  No img
                                </div>
                              )}
                            </div>
                            {/* Title + badges */}
                            <div className="space-y-1 min-w-0">
                              <p className="font-medium text-gray-900 line-clamp-2 text-xs leading-5">{item.title}</p>
                              <div className="flex items-center gap-2 flex-wrap">
                                <Badge variant={item.status === 'ACTIVE' ? 'success' : 'default'}>{item.status}</Badge>
                                {item.source_url && (
                                  <a href={item.source_url} target="_blank" rel="noopener noreferrer"
                                    className="inline-flex items-center gap-0.5 text-xs text-blue-500 hover:text-blue-700">
                                    Source <ExternalLink className="h-3 w-3" />
                                  </a>
                                )}
                                {item.listing_url && (
                                  <a href={item.listing_url} target="_blank" rel="noopener noreferrer"
                                    className="inline-flex items-center gap-0.5 text-xs text-blue-500 hover:text-blue-700">
                                    eBay <ExternalLink className="h-3 w-3" />
                                  </a>
                                )}
                              </div>
                            </div>
                          </div>
                        </td>

                        {/* Your eBay price */}
                        <td className="px-4 py-3 text-right font-semibold text-gray-900">
                          {formatMoney(item.ebay_price)}
                        </td>

                        {/* Source cost */}
                        <td className="px-4 py-3 text-right">
                          <div className="text-gray-700">
                            {item.monitor.source_price
                              ? `${item.monitor.source_price} ${item.monitor.source_currency ?? ''}`
                              : <span className="text-gray-400">—</span>
                            }
                          </div>
                          {item.monitor.last_checked_at && (
                            <div className="text-xs text-gray-400 mt-0.5">
                              {formatDateTime(item.monitor.last_checked_at)}
                            </div>
                          )}
                        </td>

                        {/* Lowest competitor */}
                        <td className="px-4 py-3 text-right">
                          {cc?.lowest_total ? (
                            <div>
                              <div className={`font-semibold ${
                                priceDiff !== null && priceDiff > 2
                                  ? 'text-red-600'
                                  : priceDiff !== null && priceDiff < -2
                                    ? 'text-green-600'
                                    : 'text-gray-700'
                              }`}>
                                €{cc.lowest_total}
                              </div>
                              {priceDiff !== null && (
                                <div className={`text-xs flex items-center justify-end gap-0.5 mt-0.5 ${
                                  priceDiff > 0 ? 'text-red-500' : 'text-green-500'
                                }`}>
                                  {priceDiff > 0
                                    ? <><TrendingDown className="h-3 w-3" />€{Math.abs(priceDiff).toFixed(2)} cheaper</>
                                    : <><TrendingUp className="h-3 w-3" />€{Math.abs(priceDiff).toFixed(2)} more expensive</>
                                  }
                                </div>
                              )}
                              {cc.last_checked_at && (
                                <div className="text-xs text-gray-400 mt-0.5">
                                  {formatDateTime(cc.last_checked_at)}
                                </div>
                              )}
                            </div>
                          ) : (
                            <span className="text-gray-400 text-xs">Not checked yet</span>
                          )}
                        </td>

                        {/* Source cost pending change */}
                        <td className="px-4 py-3">
                          {hasPending ? (
                            <div className="space-y-1">
                              <div className="flex items-center gap-1 text-xs">
                                {parseFloat(pending.new_source_price) > parseFloat(pending.old_source_price)
                                  ? <TrendingUp className="h-3.5 w-3.5 text-red-500 flex-shrink-0" />
                                  : <TrendingDown className="h-3.5 w-3.5 text-green-500 flex-shrink-0" />
                                }
                                <span className="text-gray-500 line-through">{pending.old_source_price}</span>
                                <span className="font-medium">{pending.new_source_price}</span>
                              </div>
                              <p className="text-xs text-gray-500">
                                Suggested: <strong>€{pending.suggested_ebay_price}</strong>
                              </p>
                              <button
                                onClick={() => handleApply(item.id, parseFloat(pending.suggested_ebay_price))}
                                className="text-xs px-2 py-1 bg-[#0f3460] text-white rounded hover:bg-[#0a2444] transition-colors"
                              >
                                Apply
                              </button>
                            </div>
                          ) : pending?.applied ? (
                            <span className="text-xs text-green-600 flex items-center gap-1">
                              <CheckCircle2 className="h-3.5 w-3.5" /> Applied
                            </span>
                          ) : (
                            <span className="text-xs text-gray-400">No change</span>
                          )}
                        </td>

                        {/* Actions */}
                        <td className="px-4 py-3">
                          <div className="flex flex-col gap-1.5">
                            {/* Toggle + check source */}
                            <div className="flex items-center gap-2">
                              <div
                                onClick={() => handleToggle(item.id, !item.monitor.enabled)}
                                className={`relative inline-flex h-5 w-9 flex-shrink-0 items-center rounded-full transition-colors cursor-pointer
                                  ${item.monitor.enabled ? 'bg-[#0f3460]' : 'bg-gray-200'}`}
                              >
                                <span className={`inline-block h-3.5 w-3.5 transform rounded-full bg-white transition-transform
                                  ${item.monitor.enabled ? 'translate-x-5' : 'translate-x-0.5'}`} />
                              </div>
                              <button
                                onClick={() => handleCheckOne(item.id)}
                                disabled={checkingId === item.id}
                                className="text-xs px-2 py-1 border border-gray-200 rounded text-gray-600
                                  hover:border-[#0f3460] hover:text-[#0f3460] transition-colors disabled:opacity-50"
                              >
                                {checkingId === item.id
                                  ? <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                  : 'Check Cost'
                                }
                              </button>
                            </div>
                            {/* Check competitors */}
                            <div className="flex items-center gap-1.5">
                              <button
                                onClick={() => handleCheckCompetitors(item.id)}
                                disabled={isCheckingComp}
                                className="text-xs px-2 py-1 border border-blue-200 rounded text-blue-600
                                  hover:border-blue-500 hover:bg-blue-50 transition-colors disabled:opacity-50 flex items-center gap-1"
                              >
                                {isCheckingComp
                                  ? <><Loader2 className="h-3.5 w-3.5 animate-spin" />Searching…</>
                                  : <><Search className="h-3.5 w-3.5" />Check eBay</>
                                }
                              </button>
                              {(cachedComp || cc?.result_count) && (
                                <button
                                  onClick={() => toggleCompPanel(item.id)}
                                  className="text-xs px-1.5 py-1 border border-gray-200 rounded text-gray-500
                                    hover:border-gray-400 transition-colors flex items-center gap-0.5"
                                >
                                  {isOpen ? <ChevronDown className="h-3.5 w-3.5" /> : <ChevronRight className="h-3.5 w-3.5" />}
                                  {cachedComp?.result_count ?? cc?.result_count ?? 0}
                                </button>
                              )}
                            </div>
                          </div>
                          {(rowErr || compErr) && (
                            <p className="mt-1 text-xs text-red-500">{rowErr ?? compErr}</p>
                          )}
                        </td>
                      </tr>

                      {/* Competitor results panel */}
                      {isOpen && cachedComp && (
                        <tr key={`${item.id}-comp`} className="bg-blue-50/40 border-b border-blue-100">
                          <td colSpan={6} className="px-4 py-3">
                            <div className="space-y-2">
                              <div className="flex items-center justify-between">
                                <p className="text-xs font-semibold text-gray-700">
                                  eBay Germany competitors — searched for: <em className="font-normal">"{cachedComp.keywords_used}"</em>
                                </p>
                                <span className="text-xs text-gray-400">{cachedComp.result_count} results</span>
                              </div>
                              <div className="overflow-x-auto rounded-lg border border-blue-100">
                                <table className="w-full text-xs bg-white">
                                  <thead>
                                    <tr className="border-b border-gray-100 text-left">
                                      <th className="px-3 py-2 text-gray-500 font-semibold uppercase">Title</th>
                                      <th className="px-3 py-2 text-gray-500 font-semibold uppercase">Condition</th>
                                      <th className="px-3 py-2 text-gray-500 font-semibold uppercase text-right">Price</th>
                                      <th className="px-3 py-2 text-gray-500 font-semibold uppercase text-right">Shipping</th>
                                      <th className="px-3 py-2 text-gray-500 font-semibold uppercase text-right">Total</th>
                                      <th className="px-3 py-2 text-gray-500 font-semibold uppercase">Location</th>
                                      <th className="px-3 py-2"></th>
                                    </tr>
                                  </thead>
                                  <tbody className="divide-y divide-gray-50">
                                    {cachedComp.results.map((r, i) => {
                                      const compTotal = parseFloat(r.total_price);
                                      const isCheaper = compTotal < yourPrice;
                                      return (
                                        <tr key={i} className={`hover:bg-gray-50 ${i === 0 ? 'bg-green-50/50' : ''}`}>
                                          <td className="px-3 py-2 max-w-xs">
                                            <div className="flex items-center gap-2">
                                              {r.image && (
                                                <img src={r.image} alt="" className="h-8 w-8 object-contain flex-shrink-0 rounded" />
                                              )}
                                              <span className="line-clamp-2 leading-4">{r.title}</span>
                                            </div>
                                          </td>
                                          <td className="px-3 py-2 text-gray-500 whitespace-nowrap">{r.condition}</td>
                                          <td className="px-3 py-2 text-right">€{r.price}</td>
                                          <td className="px-3 py-2 text-right text-gray-500">
                                            {r.shipping === 'free' ? (
                                              <span className="text-green-600">Free</span>
                                            ) : `€${r.shipping}`}
                                          </td>
                                          <td className={`px-3 py-2 text-right font-semibold ${isCheaper ? 'text-red-600' : 'text-gray-700'}`}>
                                            €{r.total_price}
                                          </td>
                                          <td className="px-3 py-2 text-gray-500">{r.location}</td>
                                          <td className="px-3 py-2">
                                            <a href={r.url} target="_blank" rel="noopener noreferrer"
                                              className="text-blue-500 hover:text-blue-700">
                                              <ExternalLink className="h-3.5 w-3.5" />
                                            </a>
                                          </td>
                                        </tr>
                                      );
                                    })}
                                  </tbody>
                                </table>
                              </div>
                            </div>
                          </td>
                        </tr>
                      )}
                    </>
                  );
                })}
              </tbody>
            </table>
          </div>
        </Card>
      )}
    </div>
  );
}
