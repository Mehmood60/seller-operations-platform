'use client';

import { useEffect, useState, type FormEvent } from 'react';
import type { Profile } from '@/types';
import { profile as profileApi } from '@/lib/api';

// ── Field helpers ─────────────────────────────────────────────────────────────

function Field({
  label,
  id,
  type = 'text',
  value,
  onChange,
  placeholder,
  maxLength,
  error,
}: {
  label: string;
  id: string;
  type?: string;
  value: string;
  onChange: (v: string) => void;
  placeholder?: string;
  maxLength?: number;
  error?: string;
}) {
  return (
    <div>
      <label className="block text-sm font-medium text-gray-700 mb-1" htmlFor={id}>
        {label}
      </label>
      <input
        id={id}
        type={type}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        maxLength={maxLength}
        className={`w-full rounded-lg border px-3 py-2.5 text-sm
          focus:outline-none focus:ring-2 focus:ring-[#0f3460]/30 focus:border-[#0f3460]
          transition-colors ${error ? 'border-red-400 bg-red-50' : 'border-gray-200'}`}
      />
      {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
    </div>
  );
}

function SectionTitle({ children }: { children: React.ReactNode }) {
  return (
    <h2 className="text-base font-semibold text-gray-900 border-b border-gray-100 pb-2 mb-4">
      {children}
    </h2>
  );
}

// ── Blank profile shape ───────────────────────────────────────────────────────

function blankProfile(): Omit<Profile, 'user_id' | 'created_at' | 'updated_at'> {
  return {
    full_name:  '',
    email:      '',
    phone:      '',
    avatar_url: null,
    address: { line1: '', line2: '', city: '', state: '', postal_code: '', country: '' },
    store: {
      name: '', phone: '', email: '', address: '', description: '',
      business_name: '', tax_number: null, vat_number: null,
    },
  };
}

// ── Page ─────────────────────────────────────────────────────────────────────

// ── Client-side validation ────────────────────────────────────────────────────

type FieldErrors = Record<string, string>;

// Letters (including accented), spaces, hyphens, apostrophes.
const NAME_RE  = /^[\p{L}\s'\-]+$/u;
// Digits, spaces, and common phone punctuation.
const PHONE_RE = /^[0-9\s+\-()\[\]]+$/;
// Alphanumeric, spaces, hyphens only.
const POST_RE  = /^[A-Z0-9\s\-]+$/i;
// Exactly 2 letters (ISO country code).
const CC_RE    = /^[A-Za-z]{2}$/;
// Alphanumeric, hyphens, slashes, spaces (tax / VAT identifiers).
const TAX_RE   = /^[A-Z0-9\s\-/]+$/i;

function validateForm(form: ReturnType<typeof blankProfile>): FieldErrors {
  const errs: FieldErrors = {};

  // ── Personal ──────────────────────────────────────────────────────────────
  if (form.full_name) {
    if (!NAME_RE.test(form.full_name))
      errs.full_name = 'Full name may only contain letters, spaces, hyphens, and apostrophes.';
    else if (form.full_name.length > 100)
      errs.full_name = 'Full name must not exceed 100 characters.';
  }
  if (form.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email))
    errs.email = 'Invalid email address.';

  if (form.phone) {
    if (!PHONE_RE.test(form.phone))
      errs.phone = 'Phone may only contain digits and + - ( ) characters.';
    else if (form.phone.length > 30)
      errs.phone = 'Phone number is too long.';
  }
  if (form.avatar_url && !/^https:\/\/.+/.test(form.avatar_url))
    errs.avatar_url = 'Avatar URL must start with https://';

  // ── Address ───────────────────────────────────────────────────────────────
  if (form.address.city && !NAME_RE.test(form.address.city))
    errs.city = 'City may only contain letters, spaces, and hyphens.';

  if (form.address.state && !NAME_RE.test(form.address.state))
    errs.state = 'State / County may only contain letters, spaces, and hyphens.';

  if (form.address.postal_code) {
    if (!POST_RE.test(form.address.postal_code))
      errs.postal_code = 'Postal code may only contain letters, digits, spaces, and hyphens.';
    else if (!/\d/.test(form.address.postal_code))
      errs.postal_code = 'Postal code must contain at least one number.';
  }
  if (form.address.country && !CC_RE.test(form.address.country))
    errs.country = 'Country must be a 2-letter code (e.g. GB, US).';

  // ── Store ─────────────────────────────────────────────────────────────────
  if (form.store.phone) {
    if (!PHONE_RE.test(form.store.phone))
      errs.store_phone = 'Store phone may only contain digits and + - ( ) characters.';
    else if (form.store.phone.length > 30)
      errs.store_phone = 'Store phone number is too long.';
  }
  if (form.store.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.store.email))
    errs.store_email = 'Invalid store email address.';

  if (form.store.tax_number && !TAX_RE.test(form.store.tax_number))
    errs.tax_number = 'Tax number may only contain letters, digits, hyphens, and slashes.';

  if (form.store.vat_number && !TAX_RE.test(form.store.vat_number))
    errs.vat_number = 'VAT number may only contain letters, digits, hyphens, and slashes.';

  if (form.store.description && form.store.description.length > 1000)
    errs.store_description = 'Description must not exceed 1000 characters.';

  return errs;
}

// ── Page ─────────────────────────────────────────────────────────────────────

export default function ProfilePage() {
  const [form,       setForm]       = useState(blankProfile());
  const [fieldErrs,  setFieldErrs]  = useState<FieldErrors>({});
  const [loading,    setLoading]    = useState(true);
  const [saving,     setSaving]     = useState(false);
  const [success,    setSuccess]    = useState(false);
  const [error,      setError]      = useState('');

  useEffect(() => {
    profileApi
      .get()
      .then((res) => {
        const p = res.data;
        setForm({
          full_name:  p.full_name  ?? '',
          email:      p.email      ?? '',
          phone:      p.phone      ?? '',
          avatar_url: p.avatar_url ?? null,
          address:    p.address    ?? blankProfile().address,
          store:      p.store      ?? blankProfile().store,
        });
      })
      .catch(() => setError('Failed to load profile.'))
      .finally(() => setLoading(false));
  }, []);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError('');
    setSuccess(false);

    const errs = validateForm(form);
    setFieldErrs(errs);
    if (Object.keys(errs).length > 0) return;

    setSaving(true);
    try {
      await profileApi.update(form);
      setSuccess(true);
      setTimeout(() => setSuccess(false), 3000);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Save failed.');
    } finally {
      setSaving(false);
    }
  }

  // Helpers to reduce boilerplate in the form.
  function field(key: keyof typeof form) {
    return {
      value: (form[key] as string) ?? '',
      onChange: (v: string) => setForm((f) => ({ ...f, [key]: v })),
    };
  }
  function addrField(key: keyof NonNullable<typeof form.address>) {
    return {
      value: form.address?.[key] ?? '',
      onChange: (v: string) =>
        setForm((f) => ({ ...f, address: { ...f.address!, [key]: v } })),
    };
  }
  function storeField(key: keyof typeof form.store) {
    return {
      value: (form.store[key] as string) ?? '',
      onChange: (v: string) =>
        setForm((f) => ({ ...f, store: { ...f.store, [key]: v } })),
    };
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64 text-gray-400 text-sm">
        Loading profile…
      </div>
    );
  }

  return (
    <div className="max-w-2xl mx-auto">
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-900">Profile</h1>
        <p className="text-sm text-gray-500 mt-1">Your personal and store information.</p>
      </div>

      {error && (
        <div className="mb-4 rounded-lg bg-red-50 border border-red-100 px-4 py-3 text-sm text-red-700">
          {error}
        </div>
      )}
      {success && (
        <div className="mb-4 rounded-lg bg-green-50 border border-green-100 px-4 py-3 text-sm text-green-700">
          Profile saved successfully.
        </div>
      )}

      <form onSubmit={handleSubmit} className="space-y-8">

        {/* Personal information */}
        <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
          <SectionTitle>Personal information</SectionTitle>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field label="Full name"  id="full_name"  {...field('full_name')}  placeholder="Jane Smith"           maxLength={100} error={fieldErrs.full_name} />
            <Field label="Email"      id="email"      {...field('email')}      placeholder="you@example.com"      type="email" maxLength={254} error={fieldErrs.email} />
            <Field label="Phone"      id="phone"      {...field('phone')}      placeholder="+44 7700 900000"      type="tel"   maxLength={30}  error={fieldErrs.phone} />
            <Field label="Avatar URL" id="avatar_url" value={form.avatar_url ?? ''} onChange={(v) => setForm((f) => ({ ...f, avatar_url: v || null }))} placeholder="https://…" maxLength={500} error={fieldErrs.avatar_url} />
          </div>
        </div>

        {/* Address */}
        <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
          <SectionTitle>Address</SectionTitle>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div className="sm:col-span-2">
              <Field label="Line 1" id="addr_line1" {...addrField('line1')} placeholder="123 Main Street" />
            </div>
            <div className="sm:col-span-2">
              <Field label="Line 2" id="addr_line2" {...addrField('line2')} placeholder="Apartment, suite, etc." />
            </div>
            <Field label="City"           id="addr_city"    {...addrField('city')}        placeholder="London"   error={fieldErrs.city} />
            <Field label="State / County" id="addr_state"  {...addrField('state')}      placeholder="England"  error={fieldErrs.state} />
            <Field label="Postal code"    id="addr_postal"  {...addrField('postal_code')} placeholder="SW1A 1AA" error={fieldErrs.postal_code} />
            <Field label="Country"        id="addr_country" {...addrField('country')}    placeholder="GB"       error={fieldErrs.country} />
          </div>
        </div>

        {/* Store information */}
        <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
          <SectionTitle>Store information</SectionTitle>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field label="Store name"    id="store_name"  {...storeField('name')}          placeholder="My eBay Store"          maxLength={100} />
            <Field label="Store phone"   id="store_phone" {...storeField('phone')}         placeholder="+44 7700 900001"        type="tel"   maxLength={30} error={fieldErrs.store_phone} />
            <Field label="Store email"   id="store_email" {...storeField('email')}         placeholder="store@example.com"      type="email" maxLength={254} error={fieldErrs.store_email} />
            <Field label="Business name" id="store_biz"   {...storeField('business_name')} placeholder="Jane Smith Trading Ltd" maxLength={100} />
            <Field label="Tax number"    id="store_tax"   value={form.store.tax_number ?? ''} onChange={(v) => setForm((f) => ({ ...f, store: { ...f.store, tax_number: v || null } }))} placeholder="UTR / EIN"     maxLength={50} error={fieldErrs.tax_number} />
            <Field label="VAT number"    id="store_vat"   value={form.store.vat_number ?? ''} onChange={(v) => setForm((f) => ({ ...f, store: { ...f.store, vat_number: v || null } }))} placeholder="GB123456789" maxLength={50} error={fieldErrs.vat_number} />
            <div className="sm:col-span-2">
              <Field label="Store address" id="store_addr" {...storeField('address')} placeholder="123 Main Street, London" maxLength={255} />
            </div>
            <div className="sm:col-span-2">
              <label className="block text-sm font-medium text-gray-700 mb-1" htmlFor="store_desc">
                Store description
              </label>
              <textarea
                id="store_desc"
                rows={3}
                maxLength={1000}
                value={form.store.description}
                onChange={(e) => setForm((f) => ({ ...f, store: { ...f.store, description: e.target.value } }))}
                placeholder="Vintage cameras and film photography equipment…"
                className={`w-full rounded-lg border px-3 py-2.5 text-sm
                  focus:outline-none focus:ring-2 focus:ring-[#0f3460]/30 focus:border-[#0f3460]
                  transition-colors resize-none
                  ${fieldErrs.store_description ? 'border-red-400 bg-red-50' : 'border-gray-200'}`}
              />
              {fieldErrs.store_description && (
                <p className="mt-1 text-xs text-red-600">{fieldErrs.store_description}</p>
              )}
              <p className="mt-1 text-xs text-gray-400 text-right">
                {form.store.description.length}/1000
              </p>
            </div>
          </div>
        </div>

        <div className="flex items-center justify-end gap-4">
          {error && (
            <p className="text-sm text-red-600">{error}</p>
          )}
          {success && (
            <p className="text-sm text-green-600 font-medium">Profile saved successfully.</p>
          )}
          <button
            type="submit"
            disabled={saving}
            className="rounded-lg bg-[#0f3460] text-white px-6 py-2.5 text-sm font-medium
              hover:bg-[#0f3460]/90 transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
          >
            {saving ? 'Saving…' : 'Save changes'}
          </button>
        </div>
      </form>
    </div>
  );
}
