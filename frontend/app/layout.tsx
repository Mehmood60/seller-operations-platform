import type { Metadata } from 'next';
import './globals.css';
import AuthProvider from '@/components/AuthProvider';
import AppShell from '@/components/AppShell';
import { PreferencesProvider } from '@/components/PreferencesProvider';

export const metadata: Metadata = {
  title: 'SellSmart',
  description: 'Smart tools to manage and grow your online store',
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en" translate="no">
      <body className="bg-gray-50 text-gray-900 antialiased">
        <PreferencesProvider>
          <AuthProvider>
            <AppShell>{children}</AppShell>
          </AuthProvider>
        </PreferencesProvider>
      </body>
    </html>
  );
}
