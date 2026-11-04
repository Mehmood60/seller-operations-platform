'use client';

import Link from 'next/link';
import type { Order, OrderStatus } from '@/types';
import { formatDate } from '@/lib/formatters';
import { useFormatAmount, useFormatMoney } from '@/components/PreferencesProvider';
import { Badge } from '@/components/ui/Badge';

const STATUS_VARIANT: Record<OrderStatus, 'success' | 'info' | 'default' | 'danger' | 'warning'> = {
  PAID:      'info',
  SHIPPED:   'warning',
  DELIVERED: 'success',
  CANCELLED: 'danger',
};

interface OrderTableProps {
  orders: Order[];
}

export function OrderTable({ orders }: OrderTableProps) {
  const formatMoney  = useFormatMoney();
  const formatAmount = useFormatAmount();

  if (orders.length === 0) {
    return (
      <div className="text-center py-12 text-gray-400">
        No orders found.
      </div>
    );
  }

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b border-gray-100 text-left">
            <th className="pb-3 pr-4 font-semibold text-gray-500 text-xs uppercase tracking-wide">Order ID</th>
            <th className="pb-3 pr-4 font-semibold text-gray-500 text-xs uppercase tracking-wide">Buyer</th>
            <th className="pb-3 pr-4 font-semibold text-gray-500 text-xs uppercase tracking-wide">Items</th>
            <th className="pb-3 pr-4 font-semibold text-gray-500 text-xs uppercase tracking-wide">Total</th>
            <th className="pb-3 pr-4 font-semibold text-gray-500 text-xs uppercase tracking-wide">You receive</th>
            <th className="pb-3 pr-4 font-semibold text-gray-500 text-xs uppercase tracking-wide">Status</th>
            <th className="pb-3 pr-4 font-semibold text-gray-500 text-xs uppercase tracking-wide">Date</th>
            <th className="pb-3 font-semibold text-gray-500 text-xs uppercase tracking-wide">Actions</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-gray-50">
          {orders.map((order) => (
            <tr key={order.id} className="hover:bg-gray-50 transition-colors">
              <td className="py-3 pr-4 font-mono text-xs text-gray-600">
                {order.id.slice(-10)}
              </td>
              <td className="py-3 pr-4 font-medium">
                {order.buyer.username}
              </td>
              <td className="py-3 pr-4 text-gray-500">
                {order.line_items.length} item{order.line_items.length !== 1 ? 's' : ''}
              </td>
              <td className="py-3 pr-4 font-semibold">
                {formatMoney(order.totals.grand_total)}
              </td>
              <td className="py-3 pr-4">
                {order.earnings?.net != null ? (
                  <span
                    className="font-semibold text-green-700"
                    title={order.earnings.source === 'finances' ? 'Actual eBay payout' : 'Estimate — ad fees not included'}
                  >
                    {formatAmount(order.earnings.net)}
                    {order.earnings.source !== 'finances' && <span className="text-gray-400 font-normal"> ≈</span>}
                  </span>
                ) : (
                  <span className="text-gray-400">—</span>
                )}
              </td>
              <td className="py-3 pr-4">
                <Badge variant={STATUS_VARIANT[order.status] ?? 'default'}>
                  {order.status}
                </Badge>
              </td>
              <td className="py-3 pr-4 text-gray-500 text-xs">
                {formatDate(order.created_at)}
              </td>
              <td className="py-3 flex items-center gap-2">
                <Link
                  href={`/orders/${order.id}`}
                  className="text-xs text-blue-600 hover:underline"
                >
                  View
                </Link>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
