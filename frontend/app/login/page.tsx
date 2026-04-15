'use client';

import { useState, type FormEvent } from 'react';
import Link from 'next/link';
import { Store } from 'lucide-react';
import { useAuth } from '@/components/AuthProvider';
import { userAuth } from '@/lib/api';
import PasswordInput from '@/components/ui/PasswordInput';

type FieldErrors = Record<string, string>;

function validate(email: string, password: string): FieldErrors {
  const errs: FieldErrors = {};
  if (!email.trim())
    errs.email = 'Email is required.';
  else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim()))
    errs.email = 'Enter a valid email address.';

  if (!password)
    errs.password = 'Password is required.';

  return errs;
}

export default function LoginPage() {
  const { login } = useAuth();

  const [email,     setEmail]     = useState('');
  const [password,  setPassword]  = useState('');
  const [fieldErrs, setFieldErrs] = useState<FieldErrors>({});
  const [error,     setError]     = useState('');
  const [loading,   setLoading]   = useState(false);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError('');

    const errs = validate(email, password);
    setFieldErrs(errs);
    if (Object.keys(errs).length > 0) return;

    setLoading(true);
    try {
      const res = await userAuth.login(email, password);
      login(res.data.token, res.data.user);
      // AppShell will redirect to /dashboard once isAuthenticated becomes true.
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Login failed.');
    } finally {
      setLoading(false);
    }
  }

  const inputCls = (key: string) =>
    `w-full rounded-lg border px-3 py-2.5 text-sm
     focus:outline-none focus:ring-2 focus:ring-[#0f3460]/30 focus:border-[#0f3460]
     transition-colors ${fieldErrs[key] ? 'border-red-400 bg-red-50' : 'border-gray-200'}`;

  return (
    <div className="w-full max-w-sm">
      {/* Logo */}
      <div className="flex items-center justify-center gap-2 mb-8">
        <div className="bg-[#0f3460] p-2 rounded-lg">
          <Store className="h-6 w-6 text-blue-300" />
        </div>
        <span className="font-bold text-xl text-gray-800">eBay Seller</span>
      </div>

      <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-8">
        <h1 className="text-xl font-semibold text-gray-900 mb-1">Sign in</h1>
        <p className="text-sm text-gray-500 mb-6">Enter your credentials to continue.</p>

        {error && (
          <div className="mb-4 rounded-lg bg-red-50 border border-red-100 px-4 py-3 text-sm text-red-700">
            {error}
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-4" noValidate>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1" htmlFor="email">
              Email
            </label>
            <input
              id="email"
              type="email"
              autoComplete="email"
              maxLength={254}
              value={email}
              onChange={(e) => { setEmail(e.target.value); setFieldErrs((f) => ({ ...f, email: '' })); }}
              className={inputCls('email')}
              placeholder="you@example.com"
            />
            {fieldErrs.email && <p className="mt-1 text-xs text-red-600">{fieldErrs.email}</p>}
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1" htmlFor="password">
              Password
            </label>
            <PasswordInput
              id="password"
              value={password}
              onChange={setPassword}
              autoComplete="current-password"
              maxLength={72}
              error={fieldErrs.password}
              onClearError={() => setFieldErrs((f) => ({ ...f, password: '' }))}
            />
          </div>

          <button
            type="submit"
            disabled={loading}
            className="w-full rounded-lg bg-[#0f3460] text-white py-2.5 text-sm font-medium
              hover:bg-[#0f3460]/90 transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
          >
            {loading ? 'Signing in…' : 'Sign in'}
          </button>
        </form>

        <p className="mt-5 text-center text-sm text-gray-500">
          No account?{' '}
          <Link href="/register" className="text-[#0f3460] font-medium hover:underline">
            Register
          </Link>
        </p>
      </div>
    </div>
  );
}
