import { Tag, Sparkles } from 'lucide-react';

/**
 * SellSmart logo mark — a price tag (selling) with a sparkle (smart / AI),
 * on a navy→blue gradient badge. Reads well on both light and dark backgrounds.
 */
export function LogoMark({ size = 36 }: { size?: number }) {
  return (
    <div
      className="relative inline-flex flex-shrink-0 items-center justify-center rounded-xl
        bg-gradient-to-br from-[#0f3460] to-[#2563eb] shadow-sm ring-1 ring-white/10"
      style={{ width: size, height: size }}
      aria-hidden="true"
    >
      <Tag style={{ width: size * 0.5, height: size * 0.5 }} className="text-white" strokeWidth={2.2} />
      <Sparkles
        style={{ width: size * 0.4, height: size * 0.4 }}
        className="absolute -right-1 -top-1 text-amber-300"
        strokeWidth={2.5}
      />
    </div>
  );
}

/**
 * Full logo: mark + "SellSmart" wordmark.
 * @param tone  'light' for light backgrounds, 'dark' for dark/navy backgrounds.
 */
export default function Logo({
  size = 36,
  tone = 'light',
  showWordmark = true,
  className = '',
}: {
  size?: number;
  tone?: 'light' | 'dark';
  showWordmark?: boolean;
  className?: string;
}) {
  return (
    <div className={`flex items-center gap-2.5 ${className}`}>
      <LogoMark size={size} />
      {showWordmark && (
        <span
          className={`font-bold tracking-tight ${tone === 'dark' ? 'text-white' : 'text-gray-800'}`}
          style={{ fontSize: Math.round(size * 0.5) }}
        >
          Sell<span className={tone === 'dark' ? 'text-blue-300' : 'text-blue-600'}>Smart</span>
        </span>
      )}
    </div>
  );
}
