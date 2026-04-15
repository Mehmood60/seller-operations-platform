'use client';

import { useState } from 'react';
import { Eye, EyeOff } from 'lucide-react';

interface PasswordInputProps {
  id: string;
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
  maxLength?: number;
  autoComplete?: string;
  error?: string;
  onClearError?: () => void;
}

export default function PasswordInput({
  id,
  value,
  onChange,
  placeholder = '••••••••',
  maxLength,
  autoComplete = 'current-password',
  error,
  onClearError,
}: PasswordInputProps) {
  const [visible, setVisible] = useState(false);

  return (
    <div>
      <div className="relative">
        <input
          id={id}
          type={visible ? 'text' : 'password'}
          value={value}
          autoComplete={autoComplete}
          maxLength={maxLength}
          placeholder={placeholder}
          onChange={(e) => {
            onChange(e.target.value);
            onClearError?.();
          }}
          className={`w-full rounded-lg border px-3 py-2.5 pr-10 text-sm
            focus:outline-none focus:ring-2 focus:ring-[#0f3460]/30 focus:border-[#0f3460]
            transition-colors ${error ? 'border-red-400 bg-red-50' : 'border-gray-200'}`}
        />
        <button
          type="button"
          onClick={() => setVisible((v) => !v)}
          aria-label={visible ? 'Hide password' : 'Show password'}
          className="absolute inset-y-0 right-0 flex items-center px-3 text-gray-400
            hover:text-gray-600 focus:outline-none focus:text-[#0f3460] transition-colors"
        >
          {visible
            ? <EyeOff className="h-4 w-4" aria-hidden="true" />
            : <Eye    className="h-4 w-4" aria-hidden="true" />}
        </button>
      </div>
      {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
    </div>
  );
}
