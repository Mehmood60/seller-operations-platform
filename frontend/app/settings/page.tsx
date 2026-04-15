'use client';

import { useEffect, useState } from 'react';
import { useSearchParams } from 'next/navigation';
import { auth, sync } from '@/lib/api';
import { Card, CardHeader, CardBody } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { formatDateTime, relativeTime } from '@/lib/formatters';
import type { AuthStatus, SyncState } from '@/types';
import { Link2, Link2Off } from 'lucide-react';

export default function SettingsPage() {
  const params = useSearchParams();
  const [authStatus, setAuthStatus]   = useState<AuthStatus | null>(null);
  const [syncState, setSyncState]     = useState<SyncState | null>(null);
  const [loading, setLoading]         = useState(true);
  const [feedback, setFeedback]       = useState<{ type: 'success' | 'error'; msg: string } | null>(null);

  const API_BASE = process.env.NEXT_PUBLIC_API_BASE_URL ?? 'http://localhost:8080';

  useEffect(() => {
    if (params.get('connected') === '1') {
      setFeedback({ type: 'success', msg: 'eBay account connected successfully!' });
    }
    if (params.get('error')) {
      setFeedback({ type: 'error', msg: 'eBay connection failed: ' + params.get('error') });
    }
  }, [params]);

  useEffect(() => {
    const load = async () => {
      setLoading(true);
      try {
        const [authRes, syncRes] = await Promise.all([auth.status(), sync.status()]);
        setAuthStatus(authRes.data);
        setSyncState(syncRes.data);
      } catch {
        // Backend might not be running
      } finally {
        setLoading(false);
      }
    };
    load();
  }, []);

  const handleDisconnect = async () => {
    if (!confirm('Disconnect your eBay account? This will delete stored tokens.')) return;
    try {
      await auth.disconnect();
      setAuthStatus(prev => prev ? { ...prev, connected: false } : null);
      setFeedback({ type: 'success', msg: 'eBay account disconnected.' });
    } catch (err: unknown) {
      setFeedback({ type: 'error', msg: err instanceof Error ? err.message : 'Disconnect failed' });
    }
  };

  const connectUrl = `${API_BASE}/api/auth/ebay/connect`;

  return (
    <div className="space-y-5 max-w-2xl">
      <h1 className="text-2xl font-bold text-gray-900">Settings</h1>

      {feedback && (
        <div className={`px-4 py-3 rounded-lg text-sm ${feedback.type === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'}`}>
          {feedback.msg}
        </div>
      )}

      {/* eBay Connection */}
      <Card>
        <CardHeader>
          <h2 className="font-semibold">eBay Account Connection</h2>
        </CardHeader>
        <CardBody className="space-y-4">
          {loading ? (
            <p className="text-gray-400 text-sm">Checking connection…</p>
          ) : (
            <>
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm font-medium">Status</p>
                  {authStatus?.connected ? (
                    <div className="flex items-center gap-2 mt-1">
                      <Badge variant="success">Connected</Badge>
                      {authStatus.expires_at && (
                        <span className="text-xs text-gray-500">
                          Token expires {relativeTime(authStatus.expires_at)}
                        </span>
                      )}
                    </div>
                  ) : (
                    <div className="mt-1">
                      <Badge variant="danger">Not connected</Badge>
                    </div>
                  )}
                </div>

                {authStatus?.connected ? (
                  <button
                    onClick={handleDisconnect}
                    className="inline-flex items-center gap-2 px-4 py-2 border border-red-200 text-red-600 text-sm font-medium rounded-lg hover:bg-red-50 transition-colors"
                  >
                    <Link2Off className="h-4 w-4" />
                    Disconnect
                  </button>
                ) : (
                  <a
                    href={connectUrl}
                    className="inline-flex items-center gap-2 px-4 py-2 bg-[#0f3460] text-white text-sm font-medium rounded-lg hover:bg-[#0a2444] transition-colors"
                  >
                    <Link2 className="h-4 w-4" />
                    Connect eBay Account
                  </a>
                )}
              </div>

              {authStatus?.scopes && authStatus.scopes.length > 0 && (
                <div>
                  <p className="text-xs text-gray-500 mb-1">Authorized scopes:</p>
                  <div className="flex flex-wrap gap-1">
                    {authStatus.scopes.map((s) => (
                      <span key={s} className="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded font-mono">
                        {s.split('/').pop()}
                      </span>
                    ))}
                  </div>
                </div>
              )}
            </>
          )}
        </CardBody>
      </Card>

      {/* Sync Status */}
      <Card>
        <CardHeader>
          <h2 className="font-semibold">Sync Status</h2>
        </CardHeader>
        <CardBody className="space-y-3 text-sm">
          {syncState ? (
            <>
              <div className="flex justify-between items-center py-2 border-b border-gray-50">
                <span className="text-gray-500">Orders last synced</span>
                <span className="font-medium">
                  {syncState.orders.last_synced_at
                    ? formatDateTime(syncState.orders.last_synced_at)
                    : 'Never'}
                </span>
              </div>
              <div className="flex justify-between items-center py-2 border-b border-gray-50">
                <span className="text-gray-500">Total orders synced</span>
                <span className="font-medium">{syncState.orders.total_synced}</span>
              </div>
              <div className="flex justify-between items-center py-2 border-b border-gray-50">
                <span className="text-gray-500">Listings last synced</span>
                <span className="font-medium">
                  {syncState.listings.last_synced_at
                    ? formatDateTime(syncState.listings.last_synced_at)
                    : 'Never'}
                </span>
              </div>
              <div className="flex justify-between items-center py-2">
                <span className="text-gray-500">Total listings synced</span>
                <span className="font-medium">{syncState.listings.total_synced}</span>
              </div>
            </>
          ) : (
            <p className="text-gray-400">Sync data unavailable.</p>
          )}
        </CardBody>
      </Card>

      {/* API Info */}
      <Card>
        <CardHeader><h2 className="font-semibold">Configuration</h2></CardHeader>
        <CardBody className="space-y-2 text-sm">
          <div className="flex justify-between">
            <span className="text-gray-500">API Backend</span>
            <span className="font-mono text-xs">{API_BASE}</span>
          </div>
          <div className="flex justify-between">
            <span className="text-gray-500">eBay OAuth Callback</span>
            <span className="font-mono text-xs">{API_BASE}/api/auth/ebay/callback</span>
          </div>
        </CardBody>
      </Card>
    </div>
  );
}
