'use client';

import { Wallet } from 'lucide-react';
import { Card, CardHeader, CardBody } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { useFormatAmount } from '@/components/PreferencesProvider';
import type { OrderEarnings } from '@/types';

const SOURCE_NOTE: Record<OrderEarnings['source'], string> = {
  finances: 'Actual payout from eBay, including ad fees and refunds.',
  order:    'Estimate from the order’s eBay fees. Promoted Listings ad fees are not included until eBay’s finance data is synced.',
  unknown:  'eBay has not reported fees for this order yet. Sync orders to fetch them.',
};

/** Seller Hub's "Ihr Verkaufserlös" panel: sale price, each fee, what you keep. */
export function EarningsBreakdown({ earnings }: { earnings: OrderEarnings }) {
  const formatAmount = useFormatAmount();
  const refunds      = parseFloat(earnings.refunds ?? '0');

  return (
    <Card>
      <CardHeader className="flex items-center gap-2">
        <Wallet className="h-4 w-4 text-[#0f3460]" />
        <h2 className="font-semibold text-sm">Your eBay Earnings</h2>
        <Badge variant={earnings.source === 'finances' ? 'success' : 'warning'}>
          {earnings.source === 'finances' ? 'Exact' : earnings.source === 'order' ? 'Estimate' : 'Pending'}
        </Badge>
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
          <span className={`font-bold text-base ${earnings.net !== null && parseFloat(earnings.net) < 0 ? 'text-red-600' : 'text-green-700'}`}>
            {formatAmount(earnings.net)}
          </span>
        </div>

        <p className="text-xs text-gray-400 pt-1">{SOURCE_NOTE[earnings.source]}</p>
      </CardBody>
    </Card>
  );
}
