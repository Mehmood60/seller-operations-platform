import type { ReactNode } from 'react';
import { Card } from './Card';

interface StatCardProps {
  label: string;
  value: string;
  sub?: string;
  icon?: ReactNode;
  color?: string;
}

export function StatCard({ label, value, sub, icon, color = 'text-brand' }: StatCardProps) {
  return (
    <Card className="flex items-center gap-4 p-5">
      {icon && (
        <div className={`p-3 rounded-lg bg-blue-50 ${color}`}>
          {icon}
        </div>
      )}
      <div>
        <p className="text-sm text-gray-500 font-medium">{label}</p>
        <p className="text-2xl font-bold text-gray-900">{value}</p>
        {sub && <p className="text-xs text-gray-400 mt-0.5">{sub}</p>}
      </div>
    </Card>
  );
}
