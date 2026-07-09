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
  Tag,
  TrendingUp,
  UserCircle,
  X,
} from 'lucide-react';
import Logo from '@/components/Logo';
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

interface SidebarProps {
  /** Whether the mobile drawer is open. Ignored on large screens (always visible). */
  open?: boolean;
  /** Called to close the mobile drawer (backdrop click, nav tap, close button). */
  onClose?: () => void;
}

export default function Sidebar({ open = false, onClose }: SidebarProps) {
  const pathname      = usePathname();
  const router        = useRouter();
  const { user, logout } = useAuth();

  async function handleLogout() {
    onClose?.();
    await logout();
    router.replace('/login');
  }

  return (
    <>
      {/* Backdrop — mobile only, when the drawer is open */}
      {open && (
        <div
          className="fixed inset-0 z-30 bg-black/50 lg:hidden"
          onClick={onClose}
          aria-hidden="true"
        />
      )}

      <aside
        className={`fixed inset-y-0 left-0 z-40 flex w-64 flex-shrink-0 flex-col bg-[#0f3460] text-white
          transform transition-transform duration-200 ease-in-out
          lg:static lg:w-56 lg:translate-x-0
          ${open ? 'translate-x-0' : '-translate-x-full'}`}
      >
        {/* Logo + close (close shown on mobile only) */}
        <div className="flex items-center justify-between px-5 py-5 border-b border-white/10">
          <Logo size={30} tone="dark" />
          <button
            onClick={onClose}
            aria-label="Close menu"
            className="lg:hidden -mr-2 rounded-lg p-1.5 text-white/70 hover:bg-white/10 hover:text-white"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        {/* Navigation */}
        <nav className="flex-1 overflow-y-auto py-4 px-3 space-y-1">
          {NAV_ITEMS.map(({ href, label, Icon }) => {
            const active = pathname === href || pathname.startsWith(href + '/');
            return (
              <Link
                key={href}
                href={href}
                onClick={onClose}
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
          SellSmart · v1
        </div>
      </aside>
    </>
  );
}
