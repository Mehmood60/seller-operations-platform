'use client';

import { useState, type FormEvent } from 'react';
import Link from 'next/link';
import { Store } from 'lucide-react';
import { userAuth } from '@/lib/api';
import { useAuth } from '@/components/AuthProvider';
import PasswordInput from '@/components/ui/PasswordInput';

type FieldErrors = Record<string, string>;

function validate(fullName: string, email: string, password: string, password2: string): FieldErrors {
  const errs: FieldErrors = {};

  // Full name
  if (!fullName.trim())
    errs.fullName = 'Full name is required.';
  else if (!/^[\p{L}\s'\-]+$/u.test(fullName.trim()))
    errs.fullName = 'Full name may only contain letters, spaces, hyphens, and apostrophes.';
  else if (fullName.trim().length > 100)
    errs.fullName = 'Full name must not exceed 100 characters.';

  // Email
  if (!email.trim())
    errs.email = 'Email is required.';
  else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim()))
    errs.email = 'Enter a valid email address.';

  // Password
  if (!password)
    errs.password = 'Password is required.';
  else if (password.length < 8)
    errs.password = 'Password must be at least 8 characters.';
  else if (password.length > 72)
    errs.password = 'Password must not exceed 72 characters.';
  else if (!/[A-Z]/.test(password))
    errs.password = 'Password must contain at least one uppercase letter.';
  else if (!/[0-9]/.test(password))
    errs.password = 'Password must contain at least one number.';

  // Confirm password
  if (!password2)
    errs.password2 = 'Please confirm your password.';
  else if (password2 !== password)
    errs.password2 = 'Passwords do not match.';

  return errs;
}

export default function RegisterPage() {
  const { login } = useAuth();

  const [fullName,  setFullName]  = useState('');
  const [email,     setEmail]     = useState('');
  const [password,  setPassword]  = useState('');
  const [password2, setPassword2] = useState('');
  const [fieldErrs, setFieldErrs] = useState<FieldErrors>({});
  const [error,     setError]     = useState('');
  const [loading,   setLoading]   = useState(false);

  function clearErr(key: string) {
    setFieldErrs((f) => ({ ...f, [key]: '' }));
  }

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError('');

    const errs = validate(fullName, email, password, password2);
    setFieldErrs(errs);
    if (Object.keys(errs).length > 0) return;

    setLoading(true);
    try {
      await userAuth.register({ email, password, full_name: fullName });
      const loginRes = await userAuth.login(email, password);
      login(loginRes.data.token, loginRes.data.user);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Registration failed.');
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
        <h1 className="text-xl font-semibold text-gray-900 mb-1">Create account</h1>
        <p className="text-sm text-gray-500 mb-6">Fill in the details below to get started.</p>

        {error && (
          <div className="mb-4 rounded-lg bg-red-50 border border-red-100 px-4 py-3 text-sm text-red-700">
            {error}
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-4" noValidate>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1" htmlFor="full_name">
              Full name
            </label>
            <input
              id="full_name"
              type="text"
              autoComplete="name"
              maxLength={100}
              value={fullName}
              onChange={(e) => { setFullName(e.target.value); clearErr('fullName'); }}
              className={inputCls('fullName')}
              placeholder="Jane Smith"
            />
            {fieldErrs.fullName && <p className="mt-1 text-xs text-red-600">{fieldErrs.fullName}</p>}
          </div>

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
              onChange={(e) => { setEmail(e.target.value); clearErr('email'); }}
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
              autoComplete="new-password"
              maxLength={72}
              placeholder="Min. 8 chars, 1 uppercase, 1 number"
              error={fieldErrs.password}
              onClearError={() => clearErr('password')}
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1" htmlFor="password2">
              Confirm password
            </label>
            <PasswordInput
              id="password2"
              value={password2}
              onChange={setPassword2}
              autoComplete="new-password"
              maxLength={72}
              error={fieldErrs.password2}
              onClearError={() => clearErr('password2')}
            />
          </div>

          <button
            type="submit"
            disabled={loading}
            className="w-full rounded-lg bg-[#0f3460] text-white py-2.5 text-sm font-medium
              hover:bg-[#0f3460]/90 transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
          >
            {loading ? 'Creating account…' : 'Create account'}
          </button>
        </form>

        <p className="mt-5 text-center text-sm text-gray-500">
          Already have an account?{' '}
          <Link href="/login" className="text-[#0f3460] font-medium hover:underline">
            Sign in
          </Link>
        </p>
      </div>
    </div>
  );
}
