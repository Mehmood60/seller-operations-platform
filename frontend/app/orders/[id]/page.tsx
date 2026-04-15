import { orders as ordersApi } from '@/lib/api';
import { Card, CardHeader, CardBody } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { formatMoney, formatDate, formatDateTime } from '@/lib/formatters';
import { notFound } from 'next/navigation';
import Link from 'next/link';
import { ArrowLeft } from 'lucide-react';
import type { OrderStatus } from '@/types';
import InvoiceDownloadButton from './InvoiceDownloadButton';

export const dynamic = 'force-dynamic';

const STATUS_VARIANT: Record<OrderStatus, 'success' | 'info' | 'danger' | 'warning'> = {
  PAID:      'info',
  SHIPPED:   'warning',
  DELIVERED: 'success',
  CANCELLED: 'danger',
};

interface Props {
  params: { id: string };
}

export default async function OrderDetailPage({ params }: Props) {
  let order;
  try {
    const res = await ordersApi.get(params.id);
    order = res.data;
  } catch {
    notFound();
  }

  const addr = order.buyer.shipping_address;
  const invoiceUrl = ordersApi.invoiceUrl(order.id);

  return (
    <div className="space-y-5 max-w-4xl">
      <div className="flex items-center gap-3">
        <Link href="/orders" className="text-gray-400 hover:text-gray-600">
          <ArrowLeft className="h-5 w-5" />
        </Link>
        <h1 className="text-xl font-bold text-gray-900">Order #{order.id.slice(-10)}</h1>
        <Badge variant={STATUS_VARIANT[order.status] ?? 'default'}>
          {order.status}
        </Badge>
        <div className="ml-auto">
          <InvoiceDownloadButton invoiceUrl={invoiceUrl} />
        </div>
      </div>

      {/* Summary Row */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <Card>
          <CardBody>
            <p className="text-xs text-gray-500 mb-1">Order Date</p>
            <p className="font-semibold">{formatDateTime(order.created_at)}</p>
          </CardBody>
        </Card>
        <Card>
          <CardBody>
            <p className="text-xs text-gray-500 mb-1">Payment Date</p>
            <p className="font-semibold">{formatDate(order.payment.paid_at)}</p>
          </CardBody>
        </Card>
        <Card>
          <CardBody>
            <p className="text-xs text-gray-500 mb-1">Order Total</p>
            <p className="font-bold text-lg text-[#0f3460]">{formatMoney(order.totals.grand_total)}</p>
          </CardBody>
        </Card>
      </div>

      {/* Buyer + Payment */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <Card>
          <CardHeader><h2 className="font-semibold text-sm">Buyer</h2></CardHeader>
          <CardBody className="space-y-1 text-sm">
            <p><span className="text-gray-500">eBay Username:</span> <strong>{order.buyer.username}</strong></p>
            <p>{addr.name}</p>
            <p>{addr.line1}{addr.line2 ? ', ' + addr.line2 : ''}</p>
            <p>{addr.city}{addr.state ? ', ' + addr.state : ''} {addr.postal_code}</p>
            <p>{addr.country_code}</p>
          </CardBody>
        </Card>

        <Card>
          <CardHeader><h2 className="font-semibold text-sm">Payment &amp; Shipping</h2></CardHeader>
          <CardBody className="space-y-1 text-sm">
            <p><span className="text-gray-500">Method:</span> {order.payment.method}</p>
            <p><span className="text-gray-500">Payment status:</span> {order.payment.status}</p>
            <p><span className="text-gray-500">Shipping service:</span> {order.shipping.service || '—'}</p>
            <p><span className="text-gray-500">Tracking:</span> {order.shipping.tracking_number || '—'}</p>
            <p><span className="text-gray-500">Shipped:</span> {formatDate(order.shipping.shipped_at)}</p>
          </CardBody>
        </Card>
      </div>

      {/* Line Items */}
      <Card>
        <CardHeader><h2 className="font-semibold text-sm">Items</h2></CardHeader>
        <CardBody className="p-0">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-gray-100 text-left">
                <th className="px-5 py-3 text-xs text-gray-500 font-semibold uppercase">Item</th>
                <th className="px-5 py-3 text-xs text-gray-500 font-semibold uppercase">SKU</th>
                <th className="px-5 py-3 text-xs text-gray-500 font-semibold uppercase text-center">Qty</th>
                <th className="px-5 py-3 text-xs text-gray-500 font-semibold uppercase text-right">Unit</th>
                <th className="px-5 py-3 text-xs text-gray-500 font-semibold uppercase text-right">Total</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {order.line_items.map((item, i) => (
                <tr key={i} className="hover:bg-gray-50">
                  <td className="px-5 py-3 font-medium">{item.title}</td>
                  <td className="px-5 py-3 text-gray-500 font-mono text-xs">{item.sku || '—'}</td>
                  <td className="px-5 py-3 text-center">{item.quantity}</td>
                  <td className="px-5 py-3 text-right">{formatMoney(item.unit_price)}</td>
                  <td className="px-5 py-3 text-right font-semibold">{formatMoney(item.total_price)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </CardBody>
      </Card>

      {/* Totals */}
      <Card>
        <CardBody>
          <div className="flex justify-end">
            <table className="text-sm w-56">
              <tbody>
                <tr>
                  <td className="py-1 text-gray-500">Subtotal</td>
                  <td className="py-1 text-right">{formatMoney(order.totals.subtotal)}</td>
                </tr>
                <tr>
                  <td className="py-1 text-gray-500">Shipping</td>
                  <td className="py-1 text-right">{formatMoney(order.totals.shipping)}</td>
                </tr>
                <tr className="border-t border-gray-200 font-bold text-base">
                  <td className="pt-2 text-[#0f3460]">Total</td>
                  <td className="pt-2 text-right text-[#0f3460]">{formatMoney(order.totals.grand_total)}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </CardBody>
      </Card>
    </div>
  );
}
