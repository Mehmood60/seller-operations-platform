'use client';

import { useState } from 'react';
import { reports as reportsApi } from '@/lib/api';
import { SalesChart } from '@/components/SalesChart';
import { Card, CardHeader, CardBody } from '@/components/ui/Card';
import { formatMoney, firstOfMonthISO, todayISO } from '@/lib/formatters';
import type { SalesReport } from '@/types';
import { FileDown, BarChart2 } from 'lucide-react';

export default function ReportsPage() {
  const [from, setFrom]           = useState(firstOfMonthISO());
  const [to, setTo]               = useState(todayISO());
  const [report, setReport]       = useState<SalesReport | null>(null);
  const [loading, setLoading]     = useState(false);
  const [error, setError]         = useState<string | null>(null);

  function validateDates(): string | null {
    if (!from) return 'Please select a start date.';
    if (!to)   return 'Please select an end date.';
    if (from > to) return '"From" date must be before or equal to "To" date.';
    const days = (new Date(to).getTime() - new Date(from).getTime()) / 86_400_000;
    if (days > 366) return 'Date range must not exceed 366 days.';
    return null;
  }

  const fetchReport = async () => {
    const dateErr = validateDates();
    if (dateErr) { setError(dateErr); return; }

    setLoading(true);
    setError(null);
    try {
      const res = await reportsApi.sales(from, to);
      setReport(res.data);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to load report');
    } finally {
      setLoading(false);
    }
  };

  const pdfUrl = reportsApi.salesPdfUrl(from, to);

  return (
    <div className="space-y-5">
      <h1 className="text-2xl font-bold text-gray-900">Sales Reports</h1>

      {/* Controls */}
      <Card>
        <CardBody>
          <div className="flex items-end gap-4 flex-wrap">
            <div>
              <label className="block text-xs text-gray-500 mb-1">From</label>
              <input
                type="date"
                value={from}
                onChange={(e) => setFrom(e.target.value)}
                className="border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-200"
              />
            </div>
            <div>
              <label className="block text-xs text-gray-500 mb-1">To</label>
              <input
                type="date"
                value={to}
                onChange={(e) => setTo(e.target.value)}
                className="border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-200"
              />
            </div>
            <button
              onClick={fetchReport}
              disabled={loading}
              className="inline-flex items-center gap-2 px-4 py-2 bg-[#0f3460] text-white text-sm font-medium rounded-lg hover:bg-[#0a2444] disabled:opacity-50 transition-colors"
            >
              <BarChart2 className="h-4 w-4" />
              {loading ? 'Loading…' : 'Generate Report'}
            </button>
            {report && !validateDates() && (
              <a
                href={pdfUrl}
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center gap-2 px-4 py-2 border border-gray-200 rounded-lg text-sm hover:bg-gray-50 transition-colors"
              >
                <FileDown className="h-4 w-4" />
                Download PDF
              </a>
            )}
          </div>
        </CardBody>
      </Card>

      {error && <p className="text-red-500 text-sm">{error}</p>}

      {report && (
        <>
          {/* Summary */}
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <Card>
              <CardBody>
                <p className="text-xs text-gray-500 mb-1">Total Orders</p>
                <p className="text-2xl font-bold">{report.total_orders}</p>
              </CardBody>
            </Card>
            <Card>
              <CardBody>
                <p className="text-xs text-gray-500 mb-1">Total Revenue</p>
                <p className="text-2xl font-bold">{formatMoney(report.total_revenue)}</p>
              </CardBody>
            </Card>
            <Card>
              <CardBody>
                <p className="text-xs text-gray-500 mb-1">Avg Order Value</p>
                <p className="text-2xl font-bold">{formatMoney(report.avg_order_value)}</p>
              </CardBody>
            </Card>
          </div>

          {/* Chart */}
          <Card>
            <CardHeader><h2 className="font-semibold text-gray-800">Revenue by Day</h2></CardHeader>
            <CardBody>
              <SalesChart data={report.revenue_by_day} />
            </CardBody>
          </Card>

          {/* Top Listings */}
          {report.top_listings.length > 0 && (
            <Card>
              <CardHeader><h2 className="font-semibold text-gray-800">Top Listings</h2></CardHeader>
              <CardBody className="p-0">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-gray-100">
                      <th className="px-5 py-3 text-left text-xs text-gray-500 font-semibold uppercase">Listing</th>
                      <th className="px-5 py-3 text-center text-xs text-gray-500 font-semibold uppercase">Sold</th>
                      <th className="px-5 py-3 text-right text-xs text-gray-500 font-semibold uppercase">Revenue</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-50">
                    {report.top_listings.map((l) => (
                      <tr key={l.listing_id} className="hover:bg-gray-50">
                        <td className="px-5 py-3 font-medium">{l.title || l.listing_id}</td>
                        <td className="px-5 py-3 text-center">{l.total_sold}</td>
                        <td className="px-5 py-3 text-right font-semibold">{formatMoney(l.total_revenue)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </CardBody>
            </Card>
          )}
        </>
      )}

      {!report && !loading && (
        <div className="text-center py-16 text-gray-400">
          <BarChart2 className="h-12 w-12 mx-auto mb-3 opacity-30" />
          <p>Select a date range and click Generate Report to view sales data.</p>
        </div>
      )}
    </div>
  );
}
