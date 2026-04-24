'use client';

import { useEffect, useState } from 'react';
import { useParams } from 'next/navigation';
import { orders as ordersApi } from '@/lib/api';
import { Card, CardHeader, CardBody } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { useFormatMoney } from '@/components/PreferencesProvider';
import { formatDate, formatDateTime } from '@/lib/formatters';
import Link from 'next/link';
import { ArrowLeft, CheckCircle2, ExternalLink, Loader2, Package, Truck } from 'lucide-react';
import type { Order, OrderStatus } from '@/types';
import InvoiceDownloadButton from './InvoiceDownloadButton';

const STATUS_VARIANT: Record<OrderStatus, 'success' | 'info' | 'danger' | 'warning'> = {
  PAID:      'info',
  SHIPPED:   'warning',
  DELIVERED: 'success',
  CANCELLED: 'danger',
};

const CARRIERS = [
  'DHL', 'DHL Express', 'FedEx', 'UPS', 'Hermes', 'DPD', 'GLS',
  'China Post', 'ePacket', '4PX', 'Yanwen', 'Other',
];

export default function OrderDetailPage() {
  const params = useParams();
  const id     = params.id as string;
  const formatMoney = useFormatMoney();

  const [order, setOrder]   = useState<Order | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError]   = useState<string | null>(null);

  // Fulfillment form
  const [aliId, setAliId]             = useState('');
  const [srcUrl, setSrcUrl]           = useState('');
  const [fulfilling, setFulfilling]   = useState(false);
  const [fulfillErr, setFulfillErr]   = useState<string | null>(null);

  // Tracking form
  const [trackNum, setTrackNum]       = useState('');
  const [carrier, setCarrier]         = useState('DHL');
  const [tracking, setTracking]       = useState(false);
  const [trackErr, setTrackErr]       = useState<string | null>(null);

  useEffect(() => {
    if (!id) return;
    ordersApi.get(id)
      .then((res) => {
        const o = res.data as unknown as Order;
        setOrder(o);
        if (o.fulfillment?.aliexpress_order_id) setAliId(o.fulfillment.aliexpress_order_id);
        if (o.fulfillment?.source_url)          setSrcUrl(o.fulfillment.source_url);
        if (o.shipping.tracking_number)         setTrackNum(o.shipping.tracking_number);
        if (o.shipping.carrier)                 setCarrier(o.shipping.carrier);
      })
      .catch((err: unknown) => setError(err instanceof Error ? err.message : 'Failed to load order'))
      .finally(() => setLoading(false));
  }, [id]);

  async function handleFulfill(e: React.FormEvent) {
    e.preventDefault();
    if (!order || aliId.trim() === '') return;
    setFulfilling(true);
    setFulfillErr(null);
    try {
      const res = await ordersApi.fulfill(order.id, aliId.trim(), srcUrl.trim());
      setOrder(res.data as unknown as Order);
    } catch (err) {
      setFulfillErr(err instanceof Error ? err.message : 'Failed to record fulfillment');
    } finally {
      setFulfilling(false);
    }
  }

  async function handleAddTracking(e: React.FormEvent) {
    e.preventDefault();
    if (!order || trackNum.trim() === '' || carrier.trim() === '') return;
    setTracking(true);
    setTrackErr(null);
    try {
      const res = await ordersApi.track(order.id, trackNum.trim(), carrier.trim());
      setOrder(res.data as unknown as Order);
    } catch (err) {
      setTrackErr(err instanceof Error ? err.message : 'Failed to add tracking');
    } finally {
      setTracking(false);
    }
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center py-24 text-gray-400">
        <div className="h-8 w-8 animate-spin rounded-full border-4 border-[#0f3460] border-t-transparent" />
      </div>
    );
  }

  if (error || !order) {
    return (
      <div className="space-y-4">
        <Link href="/orders" className="inline-flex items-center gap-2 text-sm text-gray-400 hover:text-gray-600">
          <ArrowLeft className="h-4 w-4" /> Back to orders
        </Link>
        <p className="text-red-500">{error ?? 'Order not found.'}</p>
      </div>
    );
  }

  const addr       = order.buyer.shipping_address;
  const invoiceUrl = ordersApi.invoiceUrl(order.id);
  const canEdit    = order.status !== 'CANCELLED' && order.status !== 'DELIVERED';
  const isShipped  = order.status === 'SHIPPED' || order.status === 'DELIVERED';

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
            {order.shipping.carrier && (
              <p><span className="text-gray-500">Carrier:</span> {order.shipping.carrier}</p>
            )}
            <p><span className="text-gray-500">Shipped:</span> {formatDate(order.shipping.shipped_at)}</p>
          </CardBody>
        </Card>
      </div>

      {/* ── One-click Fulfillment ─────────────────────────────────────────────── */}
      {order.status !== 'CANCELLED' && (
        <Card>
          <CardHeader className="flex items-center gap-2">
            <Package className="h-4 w-4 text-[#0f3460]" />
            <h2 className="font-semibold text-sm">Order Fulfillment</h2>
            {order.fulfillment?.status && (
              <Badge variant={order.fulfillment.status === 'shipped' ? 'success' : 'warning'}>
                {order.fulfillment.status}
              </Badge>
            )}
          </CardHeader>
          <CardBody className="space-y-4">
            {/* Customer address for copy-paste when placing AliExpress order */}
            <div className="bg-gray-50 border border-gray-100 rounded-lg p-3 text-sm space-y-0.5">
              <p className="text-xs text-gray-500 font-semibold uppercase mb-1.5">Ship to (customer address)</p>
              <p className="font-medium text-gray-800">{addr.name}</p>
              <p className="text-gray-600">{addr.line1}{addr.line2 ? ', ' + addr.line2 : ''}</p>
              <p className="text-gray-600">{addr.city}{addr.state ? ', ' + addr.state : ''} {addr.postal_code}</p>
              <p className="text-gray-600">{addr.country_code}</p>
            </div>

            {/* Record AliExpress order */}
            <form onSubmit={handleFulfill} className="space-y-3">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">
                    AliExpress Order ID <span className="text-red-400">*</span>
                  </label>
                  <input
                    type="text"
                    value={aliId}
                    onChange={e => setAliId(e.target.value)}
                    placeholder="e.g. 8190000000000000"
                    disabled={!canEdit}
                    className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none
                      focus:ring-2 focus:ring-[#0f3460]/20 focus:border-[#0f3460] disabled:bg-gray-50 disabled:text-gray-400"
                  />
                </div>
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Source URL (optional)</label>
                  <input
                    type="url"
                    value={srcUrl}
                    onChange={e => setSrcUrl(e.target.value)}
                    placeholder="https://aliexpress.com/item/..."
                    disabled={!canEdit}
                    className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none
                      focus:ring-2 focus:ring-[#0f3460]/20 focus:border-[#0f3460] disabled:bg-gray-50 disabled:text-gray-400"
                  />
                </div>
              </div>

              {fulfillErr && (
                <p className="text-xs text-red-500">{fulfillErr}</p>
              )}

              {order.fulfillment?.ordered_at && (
                <p className="text-xs text-gray-400">
                  Last recorded: {formatDateTime(order.fulfillment.ordered_at)}
                </p>
              )}

              {canEdit && (
                <div className="flex items-center gap-3">
                  <button
                    type="submit"
                    disabled={fulfilling || aliId.trim() === ''}
                    className="inline-flex items-center gap-2 px-4 py-2 bg-[#0f3460] text-white text-sm
                      font-medium rounded-lg hover:bg-[#0a2444] transition-colors
                      disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    {fulfilling && <Loader2 className="h-4 w-4 animate-spin" />}
                    {order.fulfillment?.aliexpress_order_id ? 'Update Fulfillment' : 'Record Fulfillment'}
                  </button>
                  {order.fulfillment?.source_url && (
                    <a
                      href={order.fulfillment.source_url}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="inline-flex items-center gap-1.5 text-sm text-blue-500 hover:text-blue-700"
                    >
                      Open AliExpress listing <ExternalLink className="h-3.5 w-3.5" />
                    </a>
                  )}
                </div>
              )}
            </form>
          </CardBody>
        </Card>
      )}

      {/* ── Tracking ────────────────────────────────────────────────────────────── */}
      {order.status !== 'CANCELLED' && (
        <Card>
          <CardHeader className="flex items-center gap-2">
            <Truck className="h-4 w-4 text-[#0f3460]" />
            <h2 className="font-semibold text-sm">Tracking &amp; Shipment</h2>
            {isShipped && (
              <Badge variant="success">Shipped</Badge>
            )}
          </CardHeader>
          <CardBody className="space-y-4">
            {/* eBay push status */}
            {order.fulfillment?.tracking_pushed_at && (
              <div className="flex items-center gap-2 text-sm text-green-700 bg-green-50 border border-green-200 rounded-lg px-3 py-2">
                <CheckCircle2 className="h-4 w-4 flex-shrink-0" />
                <span>Tracking pushed to eBay on {formatDateTime(order.fulfillment.tracking_pushed_at)}</span>
              </div>
            )}
            {order.fulfillment?.tracking_push_error && !order.fulfillment.tracking_pushed_at && (
              <div className="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                eBay push failed: {order.fulfillment.tracking_push_error}
              </div>
            )}

            <form onSubmit={handleAddTracking} className="space-y-3">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">
                    Carrier <span className="text-red-400">*</span>
                  </label>
                  <select
                    value={carrier}
                    onChange={e => setCarrier(e.target.value)}
                    disabled={!canEdit}
                    className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none
                      focus:ring-2 focus:ring-[#0f3460]/20 focus:border-[#0f3460] disabled:bg-gray-50 disabled:text-gray-400"
                  >
                    {CARRIERS.map(c => <option key={c} value={c}>{c}</option>)}
                  </select>
                </div>
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">
                    Tracking Number <span className="text-red-400">*</span>
                  </label>
                  <input
                    type="text"
                    value={trackNum}
                    onChange={e => setTrackNum(e.target.value)}
                    placeholder="e.g. JD014600006599825310"
                    disabled={!canEdit}
                    className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none
                      focus:ring-2 focus:ring-[#0f3460]/20 focus:border-[#0f3460] disabled:bg-gray-50 disabled:text-gray-400"
                  />
                </div>
              </div>

              {trackErr && (
                <p className="text-xs text-red-500">{trackErr}</p>
              )}

              {canEdit && (
                <button
                  type="submit"
                  disabled={tracking || trackNum.trim() === '' || carrier.trim() === ''}
                  className="inline-flex items-center gap-2 px-4 py-2 bg-[#0f3460] text-white text-sm
                    font-medium rounded-lg hover:bg-[#0a2444] transition-colors
                    disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  {tracking && <Loader2 className="h-4 w-4 animate-spin" />}
                  <Truck className="h-4 w-4" />
                  Push Tracking to eBay
                </button>
              )}
            </form>
          </CardBody>
        </Card>
      )}

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
