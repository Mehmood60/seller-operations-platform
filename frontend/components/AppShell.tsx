'use client';

import { usePathname, useRouter } from 'next/navigation';
import { useEffect } from 'react';
import { useAuth } from '@/components/AuthProvider';
import Sidebar from '@/components/Sidebar';

// Routes that don't require authentication and have their own full-page layout.
const AUTH_ROUTES = ['/login', '/register'];

export default function AppShell({ children }: { children: React.ReactNode }) {
  const { isAuthenticated, isLoading } = useAuth();
  const pathname = usePathname();
  const router   = useRouter();

  const isAuthRoute = AUTH_ROUTES.includes(pathname);

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
      <div className="flex h-screen items-center justify-center bg-gray-50">
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
      <div className="min-h-screen bg-gray-50 flex items-center justify-center">
        {children}
      </div>
    );
  }

  // ── Protected app pages ────────────────────────────────────────────────────
  // If not authenticated, we're redirecting — render nothing.
  if (!isAuthenticated) return null;

  return (
    <div className="flex h-screen overflow-hidden">
      <Sidebar />
      <div className="flex-1 flex flex-col overflow-hidden">
        <main className="flex-1 overflow-y-auto p-6">{children}</main>
      </div>
    </div>
  );
}
