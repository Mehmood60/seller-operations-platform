'use client';

import {
  LineChart,
  Line,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
} from 'recharts';
import type { RevenueByDay } from '@/types';

interface SalesChartProps {
  data: RevenueByDay[];
}

function formatCurrency(v: number) {
  return '€' + v.toFixed(2);
}

function shortDate(iso: string) {
  const d = new Date(iso);
  return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
}

export function SalesChart({ data }: SalesChartProps) {
  const chartData = data.map((d) => ({
    date:    shortDate(d.date),
    revenue: d.revenue,
    orders:  d.orders,
  }));

  return (
    <ResponsiveContainer width="100%" height={280}>
      <LineChart data={chartData} margin={{ top: 8, right: 16, left: 0, bottom: 0 }}>
        <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
        <XAxis
          dataKey="date"
          tick={{ fontSize: 11, fill: '#888' }}
          tickLine={false}
          axisLine={false}
          interval="preserveStartEnd"
        />
        <YAxis
          tickFormatter={formatCurrency}
          tick={{ fontSize: 11, fill: '#888' }}
          tickLine={false}
          axisLine={false}
          width={60}
        />
        <Tooltip
          formatter={(value: number) => ['€' + value.toFixed(2), 'Revenue']}
          labelStyle={{ fontWeight: 600 }}
          contentStyle={{ border: '1px solid #e5e7eb', borderRadius: 8, fontSize: 12 }}
        />
        <Line
          type="monotone"
          dataKey="revenue"
          stroke="#0f3460"
          strokeWidth={2}
          dot={false}
          activeDot={{ r: 4, fill: '#0f3460' }}
        />
      </LineChart>
    </ResponsiveContainer>
  );
}
