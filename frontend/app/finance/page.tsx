'use client';

import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { AlertTriangle, Loader2, Pencil, Plus, Trash2, Wallet, X } from 'lucide-react';
import { finance as financeApi, sync as syncApi, type ExpenseInput } from '@/lib/api';
import { Card, CardHeader, CardBody } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { useFormatAmount } from '@/components/PreferencesProvider';
import { firstOfMonthISO, formatDate, todayISO } from '@/lib/formatters';
import type { Expense, FinanceSummary } from '@/types';

// ── Period presets ───────────────────────────────────────────────────────────

const iso = (d: Date) =>
  `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

type Preset = 'this_month' | 'last_month' | 'this_year' | 'last_year' | 'all' | 'custom';

function presetRange(preset: Exclude<Preset, 'custom'>): [string, string] {
  const now = new Date();
  const y   = now.getFullYear();
  const m   = now.getMonth();
  switch (preset) {
    case 'this_month': return [firstOfMonthISO(), todayISO()];
    case 'last_month': return [iso(new Date(y, m - 1, 1)), iso(new Date(y, m, 0))];
    case 'this_year':  return [`${y}-01-01`, todayISO()];
    case 'last_year':  return [`${y - 1}-01-01`, `${y - 1}-12-31`];
    case 'all':        return ['2000-01-01', todayISO()];
  }
}

const PRESETS: Array<{ value: Exclude<Preset, 'custom'>; label: string }> = [
  { value: 'this_month', label: 'This month' },
  { value: 'last_month', label: 'Last month' },
  { value: 'this_year',  label: 'This year' },
  { value: 'last_year',  label: 'Last year' },
  { value: 'all',        label: 'All time' },
];

const emptyForm = (): ExpenseInput => ({ date: todayISO(), amount: '', category: 'other', description: '' });

const inputCls =
  'border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-200';

export default function FinancePage() {
  const formatAmount = useFormatAmount();

  const [preset, setPreset] = useState<Preset>('this_month');
  const [from, setFrom]     = useState(firstOfMonthISO());
  const [to, setTo]         = useState(todayISO());

  const [summary, setSummary]   = useState<FinanceSummary | null>(null);
  const [expenses, setExpenses] = useState<Expense[]>([]);
  const [categories, setCategories] = useState<Array<{ value: string; label: string }>>([]);
  const [syncError, setSyncError]   = useState<string | null>(null);
  const [loading, setLoading]   = useState(true);
  const [error, setError]       = useState<string | null>(null);

  // Expense form — editingId set means the form edits that expense.
  const [form, setForm]           = useState<ExpenseInput>(emptyForm);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [saving, setSaving]       = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (from > to) { setError('"From" must be on or before "To".'); return; }
    setLoading(true);
    setError(null);
    try {
      const [s, e] = await Promise.all([financeApi.summary(from, to), financeApi.expenses(from, to)]);
      setSummary(s.data);
      setExpenses(e.data);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load finance data');
    } finally {
      setLoading(false);
    }
  }, [from, to]);

  useEffect(() => { load(); }, [load]);

  useEffect(() => {
    financeApi.categories().then((r) => setCategories(r.data)).catch(() => {});
    syncApi.status().then((r) => setSyncError(r.data.earnings?.error ?? null)).catch(() => {});
  }, []);

  const categoryLabel = (value: string) => categories.find((c) => c.value === value)?.label ?? value;

  function choosePreset(p: Exclude<Preset, 'custom'>) {
    const [f, t] = presetRange(p);
    setPreset(p);
    setFrom(f);
    setTo(t);
  }

  function startEdit(expense: Expense) {
    setEditingId(expense.id);
    setForm({ date: expense.date, amount: expense.amount, category: expense.category, description: expense.description });
    setFormError(null);
  }

  function cancelEdit() {
    setEditingId(null);
    setForm(emptyForm());
    setFormError(null);
  }

  async function handleSave(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setFormError(null);
    try {
      if (editingId) {
        await financeApi.updateExpense(editingId, form);
      } else {
        await financeApi.createExpense(form);
      }
      cancelEdit();
      await load();
    } catch (err) {
      setFormError(err instanceof Error ? err.message : 'Could not save the expense');
    } finally {
      setSaving(false);
    }
  }

  async function handleDelete(expense: Expense) {
    if (!window.confirm(`Delete "${expense.description}" (${formatAmount(expense.amount)})?`)) return;
    try {
      await financeApi.deleteExpense(expense.id);
      if (editingId === expense.id) cancelEdit();
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not delete the expense');
    }
  }

  const profit   = summary ? parseFloat(summary.profit) : 0;
  const orders   = summary?.orders;
  const inexact  = orders ? orders.estimated_count + orders.unknown_count : 0;

  return (
    <div className="space-y-5 max-w-5xl">
      <h1 className="text-2xl font-bold text-gray-900">Finance</h1>

      {/* Period */}
      <Card>
        <CardBody className="flex flex-wrap items-end gap-3">
          <div className="flex flex-wrap gap-2">
            {PRESETS.map((p) => (
              <button
                key={p.value}
                onClick={() => choosePreset(p.value)}
                className={`px-3 py-2 rounded-lg text-sm font-medium border transition-colors ${
                  preset === p.value
                    ? 'bg-[#0f3460] text-white border-[#0f3460]'
                    : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50'
                }`}
              >
                {p.label}
              </button>
            ))}
          </div>
          <div className="flex items-end gap-2 ml-auto">
            <div>
              <label className="block text-xs text-gray-500 mb-1">From</label>
              <input type="date" value={from} className={inputCls}
                onChange={(e) => { setPreset('custom'); setFrom(e.target.value); }} />
            </div>
            <div>
              <label className="block text-xs text-gray-500 mb-1">To</label>
              <input type="date" value={to} className={inputCls}
                onChange={(e) => { setPreset('custom'); setTo(e.target.value); }} />
            </div>
          </div>
        </CardBody>
      </Card>

      {error && <p className="text-sm text-red-600">{error}</p>}

      {syncError && (
        <div className="flex gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
          <AlertTriangle className="h-4 w-4 flex-shrink-0 mt-0.5" />
          <div>
            <p className="font-medium">Exact eBay payouts could not be loaded — order earnings below are estimates.</p>
            <p className="text-xs mt-0.5">{syncError}</p>
          </div>
        </div>
      )}

      {loading && !summary ? (
        <div className="flex justify-center py-16">
          <div className="h-8 w-8 animate-spin rounded-full border-4 border-[#0f3460] border-t-transparent" />
        </div>
      ) : summary && orders && (
        <>
          {/* Result */}
          <Card>
            <CardBody className="space-y-3">
              <div className="flex items-baseline justify-between gap-4 flex-wrap">
                <div>
                  <p className="text-sm text-gray-500 font-medium">Net result</p>
                  <p className={`text-3xl font-bold ${profit < 0 ? 'text-red-600' : 'text-green-700'}`}>
                    {profit > 0 ? '+' : ''}{formatAmount(summary.profit)}
                  </p>
                </div>
                {loading && <Loader2 className="h-5 w-5 animate-spin text-gray-400" />}
              </div>
              <div className="text-sm divide-y divide-gray-100 border-t border-gray-100">
                <div className="flex justify-between py-2">
                  <span className="text-gray-600">Received from eBay for {orders.count} order{orders.count !== 1 ? 's' : ''}</span>
                  <span className="font-medium">{formatAmount(orders.net)}</span>
                </div>
                <div className="flex justify-between py-2">
                  <span className="text-gray-600">eBay account fees (insertion fees, subscription, …)</span>
                  <span className="font-medium text-red-600">{formatAmount(-parseFloat(summary.ebay_charges.total))}</span>
                </div>
                <div className="flex justify-between py-2">
                  <span className="text-gray-600">Business expenses</span>
                  <span className="font-medium text-red-600">{formatAmount(-parseFloat(summary.expenses.total))}</span>
                </div>
              </div>
              {inexact > 0 && (
                <p className="text-xs text-gray-400">
                  {orders.estimated_count > 0 && `${orders.estimated_count} order${orders.estimated_count !== 1 ? 's use' : ' uses'} an estimate without ad fees. `}
                  {orders.unknown_count > 0 && `${orders.unknown_count} order${orders.unknown_count !== 1 ? 's have' : ' has'} no fee data yet and ${orders.unknown_count !== 1 ? 'are' : 'is'} counted at the sale price. `}
                  Sync orders to refresh.
                </p>
              )}
            </CardBody>
          </Card>

          {/* Order totals */}
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <Card><CardBody>
              <p className="text-xs text-gray-500 mb-1">Paid by buyers</p>
              <p className="text-xl font-bold text-gray-900">{formatAmount(orders.gross)}</p>
            </CardBody></Card>
            <Card><CardBody>
              <p className="text-xs text-gray-500 mb-1">eBay fees on orders{parseFloat(orders.refunds) > 0 ? ' + refunds' : ''}</p>
              <p className="text-xl font-bold text-red-600">
                {formatAmount(-(parseFloat(orders.fees) + parseFloat(orders.refunds)))}
              </p>
            </CardBody></Card>
            <Card><CardBody>
              <p className="text-xs text-gray-500 mb-1">You received</p>
              <p className="text-xl font-bold text-green-700">{formatAmount(orders.net)}</p>
            </CardBody></Card>
          </div>

          {/* Expenses */}
          <Card>
            <CardHeader className="flex items-center gap-2">
              <Wallet className="h-4 w-4 text-[#0f3460]" />
              <h2 className="font-semibold text-sm">Business Expenses</h2>
              <span className="ml-auto text-sm font-semibold text-red-600">{formatAmount(-parseFloat(summary.expenses.total))}</span>
            </CardHeader>
            <CardBody className="space-y-4">
              <form onSubmit={handleSave} className="grid grid-cols-1 md:grid-cols-[140px_200px_1fr_120px_auto] gap-2 items-start">
                <input type="date" required value={form.date} className={inputCls}
                  onChange={(e) => setForm({ ...form, date: e.target.value })} />
                <select value={form.category} className={inputCls}
                  onChange={(e) => setForm({ ...form, category: e.target.value })}>
                  {categories.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
                </select>
                <input type="text" required maxLength={200} placeholder="Description, e.g. Gewerbeanmeldung" value={form.description}
                  className={inputCls} onChange={(e) => setForm({ ...form, description: e.target.value })} />
                <input type="text" inputMode="decimal" required placeholder="Amount €" value={form.amount}
                  className={inputCls} onChange={(e) => setForm({ ...form, amount: e.target.value })} />
                <div className="flex gap-2">
                  <button type="submit" disabled={saving}
                    className="inline-flex items-center gap-1.5 px-4 py-2 bg-[#0f3460] text-white text-sm font-medium rounded-lg hover:bg-[#0a2444] disabled:opacity-50">
                    {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Plus className="h-4 w-4" />}
                    {editingId ? 'Save' : 'Add'}
                  </button>
                  {editingId && (
                    <button type="button" onClick={cancelEdit} aria-label="Cancel editing"
                      className="px-2 py-2 rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-50">
                      <X className="h-4 w-4" />
                    </button>
                  )}
                </div>
              </form>
              {formError && <p className="text-sm text-red-600">{formError}</p>}

              {summary.expenses.by_category.length > 0 && (
                <div className="flex flex-wrap gap-2">
                  {summary.expenses.by_category.map((c) => (
                    <span key={c.category} className="text-xs bg-gray-100 text-gray-700 rounded px-2 py-1">
                      {c.label}: <strong>{formatAmount(c.total)}</strong>
                    </span>
                  ))}
                </div>
              )}

              {expenses.length === 0 ? (
                <p className="text-sm text-gray-400 py-4 text-center">No expenses in this period.</p>
              ) : (
                <table className="w-full text-sm">
                  <tbody className="divide-y divide-gray-50">
                    {expenses.map((exp) => (
                      <tr key={exp.id} className={editingId === exp.id ? 'bg-blue-50' : ''}>
                        <td className="py-2 pr-3 text-gray-500 text-xs whitespace-nowrap">{formatDate(exp.date)}</td>
                        <td className="py-2 pr-3"><Badge>{categoryLabel(exp.category)}</Badge></td>
                        <td className="py-2 pr-3 text-gray-700">{exp.description}</td>
                        <td className="py-2 pr-3 text-right font-medium whitespace-nowrap">{formatAmount(exp.amount)}</td>
                        <td className="py-2 text-right whitespace-nowrap">
                          <button onClick={() => startEdit(exp)} aria-label="Edit expense"
                            className="p-1.5 text-gray-400 hover:text-gray-700"><Pencil className="h-3.5 w-3.5" /></button>
                          <button onClick={() => handleDelete(exp)} aria-label="Delete expense"
                            className="p-1.5 text-gray-400 hover:text-red-600"><Trash2 className="h-3.5 w-3.5" /></button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </CardBody>
          </Card>

          {/* eBay account charges */}
          {summary.ebay_charges.items.length > 0 && (
            <Card>
              <CardHeader className="flex items-center">
                <h2 className="font-semibold text-sm">eBay Account Fees</h2>
                <span className="ml-1.5 text-xs text-gray-400">imported from eBay — don’t add these as expenses too</span>
                <span className="ml-auto text-sm font-semibold text-red-600">{formatAmount(-parseFloat(summary.ebay_charges.total))}</span>
              </CardHeader>
              <CardBody>
                <table className="w-full text-sm">
                  <tbody className="divide-y divide-gray-50">
                    {summary.ebay_charges.items.map((c) => (
                      <tr key={c.id}>
                        <td className="py-2 pr-3 text-gray-500 text-xs whitespace-nowrap">{formatDate(c.date)}</td>
                        <td className="py-2 pr-3 text-gray-700">{c.label}{c.memo ? <span className="text-gray-400"> · {c.memo}</span> : null}</td>
                        <td className="py-2 text-right font-medium whitespace-nowrap">{formatAmount(-parseFloat(c.amount))}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </CardBody>
            </Card>
          )}

          {/* Per-order earnings */}
          <Card>
            <CardHeader><h2 className="font-semibold text-sm">Earnings per Order</h2></CardHeader>
            <CardBody>
              {summary.order_rows.length === 0 ? (
                <p className="text-sm text-gray-400 py-4 text-center">No orders in this period.</p>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-500">
                        <th className="pb-2 pr-3 font-semibold">Date</th>
                        <th className="pb-2 pr-3 font-semibold">Item</th>
                        <th className="pb-2 pr-3 font-semibold text-right">Paid</th>
                        <th className="pb-2 pr-3 font-semibold text-right">eBay fees</th>
                        <th className="pb-2 font-semibold text-right">You receive</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-50">
                      {summary.order_rows.map((r) => (
                        <tr key={r.id} className="hover:bg-gray-50">
                          <td className="py-2 pr-3 text-gray-500 text-xs whitespace-nowrap">{formatDate(r.date)}</td>
                          <td className="py-2 pr-3 max-w-xs truncate">
                            <Link href={`/orders/${r.id}`} className="text-blue-600 hover:underline">{r.title || r.id}</Link>
                          </td>
                          <td className="py-2 pr-3 text-right whitespace-nowrap">{formatAmount(r.gross)}</td>
                          <td className="py-2 pr-3 text-right whitespace-nowrap text-red-600">
                            {r.source === 'unknown' ? '—' : formatAmount(-parseFloat(r.total_fees))}
                          </td>
                          <td className="py-2 text-right whitespace-nowrap font-semibold">
                            {formatAmount(r.net)}
                            {r.source !== 'finances' && (
                              <span className="text-gray-400 font-normal" title={r.source === 'order' ? 'Estimate — ad fees not included' : 'No fee data yet'}> ≈</span>
                            )}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </CardBody>
          </Card>
        </>
      )}
    </div>
  );
}
