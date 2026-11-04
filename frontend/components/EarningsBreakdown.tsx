'use client';

import { Wallet } from 'lucide-react';
import { Card, CardHeader, CardBody } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { useFormatAmount } from '@/components/PreferencesProvider';
import { EarningsValue } from '@/components/EarningsValue';
import type { Order, OrderEarnings } from '@/types';

const SOURCE_NOTE: Record<OrderEarnings['source'], string> = {
  finances: 'Actual payout from eBay, including ad fees and refunds.',
  order:    'Estimate from the order’s eBay fees. Promoted Listings ad fees are not included until eBay’s finance data is synced.',
  unknown:  'eBay has not reported fees for this order yet. Sync orders to fetch them.',
};

/** Seller Hub's "Ihr Verkaufserlös" panel: sale price, each fee, what you keep. */
export function EarningsBreakdown({ order, onChange }: { order: Order; onChange: (order: Order) => void }) {
  const formatAmount = useFormatAmount();
  const earnings     = order.earnings as OrderEarnings;
  const override     = order.earnings_override;
  const refunds      = parseFloat(earnings.refunds ?? '0');

  return (
    <Card>
      <CardHeader className="flex items-center gap-2">
        <Wallet className="h-4 w-4 text-[#0f3460]" />
        <h2 className="font-semibold text-sm">Your eBay Earnings</h2>
        {override ? (
          <Badge variant="info">Corrected by you</Badge>
        ) : (
          <Badge variant={earnings.source === 'finances' ? 'success' : 'warning'}>
            {earnings.source === 'finances' ? 'Exact' : earnings.source === 'order' ? 'Estimate' : 'Pending'}
          </Badge>
        )}
        {earnings.on_hold && <Badge variant="info">Funds on hold</Badge>}
      </CardHeader>
      <CardBody className="text-sm space-y-2">
        <div className="flex justify-between">
          <span className="text-gray-600">Paid by buyer</span>
          <span className="font-medium">{formatAmount(earnings.gross)}</span>
        </div>

        {earnings.fees.map((fee) => (
          <div key={fee.type} className="flex justify-between pl-3">
            <span className="text-gray-500">{fee.label}</span>
            <span className="text-red-600">{formatAmount(-parseFloat(fee.amount))}</span>
          </div>
        ))}

        {refunds !== 0 && (
          <div className="flex justify-between pl-3">
            <span className="text-gray-500">Refunds</span>
            <span className="text-red-600">{formatAmount(-refunds)}</span>
          </div>
        )}

        <div className="flex justify-between border-t border-gray-100 pt-2">
          <span className="font-semibold">You receive</span>
          <EarningsValue order={order} onChange={onChange} className="text-base" />
        </div>

        <p className="text-xs text-gray-400 pt-1">
          {override
            ? `You set this by hand. eBay's figure: ${formatAmount(earnings.net)}.`
            : SOURCE_NOTE[earnings.source]}
        </p>
      </CardBody>
    </Card>
  );
}
