'use client';

import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import {
  Activity,
  BarChart2,
  LayoutDashboard,
  LogOut,
  MessageSquareReply,
  Settings,
  ShoppingCart,
  Store,
  Tag,
  TrendingUp,
  UserCircle,
} from 'lucide-react';
import { useAuth } from '@/components/AuthProvider';

const NAV_ITEMS = [
  { href: '/dashboard', label: 'Dashboard', Icon: LayoutDashboard },
  { href: '/orders',    label: 'Orders',    Icon: ShoppingCart },
  { href: '/listings',  label: 'Listings',  Icon: Tag },
  { href: '/monitor',    label: 'Monitor',    Icon: Activity },
  { href: '/repricing',  label: 'Repricing',  Icon: TrendingUp },
  { href: '/feedback',   label: 'Feedback',   Icon: MessageSquareReply },
  { href: '/reports',   label: 'Reports',   Icon: BarChart2 },
  { href: '/settings',  label: 'Settings',  Icon: Settings },
  { href: '/profile',   label: 'Profile',   Icon: UserCircle },
];

export default function Sidebar() {
  const pathname      = usePathname();
  const router        = useRouter();
  const { user, logout } = useAuth();

  async function handleLogout() {
    await logout();
    router.replace('/login');
  }

  return (
    <aside className="w-56 flex-shrink-0 bg-[#0f3460] text-white flex flex-col">
      {/* Logo */}
      <div className="flex items-center gap-2 px-5 py-5 border-b border-white/10">
        <Store className="h-6 w-6 text-blue-300" />
        <span className="font-bold text-base tracking-wide">eBay Seller</span>
      </div>

      {/* Navigation */}
      <nav className="flex-1 py-4 px-3 space-y-1">
        {NAV_ITEMS.map(({ href, label, Icon }) => {
          const active = pathname === href || pathname.startsWith(href + '/');
          return (
            <Link
              key={href}
              href={href}
              className={`flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors
                ${active
                  ? 'bg-white/15 text-white'
                  : 'text-white/70 hover:bg-white/10 hover:text-white'
                }`}
            >
              <Icon className="h-4 w-4 flex-shrink-0" />
              {label}
            </Link>
          );
        })}
      </nav>

      {/* User + Logout */}
      <div className="px-3 pb-3 border-t border-white/10 pt-3 space-y-1">
        {user && (
          <div className="px-3 py-2 text-xs text-white/50 truncate">
            {user.email}
          </div>
        )}
        <button
          onClick={handleLogout}
          className="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium
            text-white/70 hover:bg-white/10 hover:text-white transition-colors"
        >
          <LogOut className="h-4 w-4 flex-shrink-0" />
          Logout
        </button>
      </div>

      <div className="px-5 py-3 text-xs text-white/40 border-t border-white/10">
        V1 — Seller Platform
      </div>
    </aside>
  );
}
