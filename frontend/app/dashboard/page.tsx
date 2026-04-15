import { dashboard } from '@/lib/api';
import { SalesChart } from '@/components/SalesChart';
import { StatCard } from '@/components/ui/StatCard';
import { Card, CardHeader, CardBody } from '@/components/ui/Card';
import { formatMoney } from '@/lib/formatters';
import { TrendingUp, ShoppingCart, DollarSign } from 'lucide-react';

export const dynamic = 'force-dynamic';
export const revalidate = 0;

export default async function DashboardPage() {
  let data;
  try {
    const res = await dashboard.get();
    data = res.data;
  } catch {
    return (
      <div className="text-red-500 p-6">
        Failed to load dashboard. Make sure the PHP backend is running at {process.env.NEXT_PUBLIC_API_BASE_URL}.
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-bold text-gray-900">Dashboard</h1>

      {/* Stat Cards */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <StatCard
          label="Revenue (30 days)"
          value={formatMoney(data.revenue_30d)}
          icon={<TrendingUp className="h-5 w-5" />}
        />
        <StatCard
          label="Orders (30 days)"
          value={String(data.orders_30d)}
          icon={<ShoppingCart className="h-5 w-5" />}
        />
        <StatCard
          label="Avg Order Value"
          value={formatMoney(data.avg_order_value)}
          icon={<DollarSign className="h-5 w-5" />}
        />
      </div>

      {/* Revenue Chart */}
      <Card>
        <CardHeader>
          <h2 className="font-semibold text-gray-800">Revenue — Last 30 Days</h2>
        </CardHeader>
        <CardBody>
          <SalesChart data={data.revenue_by_day} />
        </CardBody>
      </Card>

      {/* Top Listings */}
      <Card>
        <CardHeader>
          <h2 className="font-semibold text-gray-800">Top Listings</h2>
        </CardHeader>
        <CardBody className="p-0">
          {data.top_listings.length === 0 ? (
            <p className="text-center py-8 text-gray-400">No listing data yet. Sync listings to get started.</p>
          ) : (
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-gray-100 text-left">
                  <th className="px-5 py-3 text-xs text-gray-500 font-semibold uppercase tracking-wide">Listing</th>
                  <th className="px-5 py-3 text-xs text-gray-500 font-semibold uppercase tracking-wide text-center">Units Sold</th>
                  <th className="px-5 py-3 text-xs text-gray-500 font-semibold uppercase tracking-wide text-right">Revenue</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {data.top_listings.map((listing) => (
                  <tr key={listing.listing_id} className="hover:bg-gray-50">
                    <td className="px-5 py-3 font-medium">{listing.title || listing.listing_id}</td>
                    <td className="px-5 py-3 text-center text-gray-600">{listing.total_sold}</td>
                    <td className="px-5 py-3 text-right font-semibold">{formatMoney(listing.total_revenue)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </CardBody>
      </Card>
    </div>
  );
}
