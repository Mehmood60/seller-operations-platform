'use client';

import { usePathname, useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';
import { Menu } from 'lucide-react';
import { useAuth } from '@/components/AuthProvider';
import Sidebar from '@/components/Sidebar';
import Logo from '@/components/Logo';

// Routes that don't require authentication and have their own full-page layout.
const AUTH_ROUTES = ['/login', '/register'];

export default function AppShell({ children }: { children: React.ReactNode }) {
  const { isAuthenticated, isLoading } = useAuth();
  const pathname = usePathname();
  const router   = useRouter();
  const [sidebarOpen, setSidebarOpen] = useState(false);

  const isAuthRoute = AUTH_ROUTES.includes(pathname);

  // Close the mobile drawer whenever the route changes.
  useEffect(() => {
    setSidebarOpen(false);
  }, [pathname]);

  useEffect(() => {
    if (isLoading) return;

    if (isAuthRoute && isAuthenticated) {
      // Already logged in — send to dashboard.
      router.replace('/dashboard');
      return;
    }

    if (!isAuthRoute && !isAuthenticated) {
      // Not logged in — send to login.
      router.replace('/login');
    }
  }, [isAuthenticated, isLoading, isAuthRoute, router]);

  // ── Loading splash ─────────────────────────────────────────────────────────
  if (isLoading) {
    return (
      <div className="flex h-dvh items-center justify-center bg-gray-50">
        <div className="flex flex-col items-center gap-3 text-gray-500">
          <div className="h-8 w-8 animate-spin rounded-full border-4 border-[#0f3460] border-t-transparent" />
          <span className="text-sm">Loading…</span>
        </div>
      </div>
    );
  }

  // ── Auth pages (login / register) — full screen, no sidebar ───────────────
  if (isAuthRoute) {
    // If authenticated, we're redirecting — render nothing to avoid flicker.
    if (isAuthenticated) return null;

    return (
      <div className="min-h-dvh bg-gray-50 flex items-center justify-center p-4">
        {children}
      </div>
    );
  }

  // ── Protected app pages ────────────────────────────────────────────────────
  // If not authenticated, we're redirecting — render nothing.
  if (!isAuthenticated) return null;

  return (
    <div className="flex h-dvh overflow-hidden">
      <Sidebar open={sidebarOpen} onClose={() => setSidebarOpen(false)} />
      <div className="flex-1 flex flex-col overflow-hidden min-w-0">
        {/* Mobile top bar with hamburger — hidden on large screens */}
        <header className="lg:hidden flex flex-shrink-0 items-center gap-3 border-b border-gray-200 bg-white px-4 py-3">
          <button
            onClick={() => setSidebarOpen(true)}
            aria-label="Open menu"
            className="-ml-1 rounded-lg p-1.5 text-gray-600 hover:bg-gray-100"
          >
            <Menu className="h-6 w-6" />
          </button>
          <Logo size={26} tone="light" />
        </header>
        <main className="flex-1 overflow-y-auto overflow-x-hidden p-4 sm:p-6">{children}</main>
      </div>
    </div>
  );
}
