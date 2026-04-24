'use client';

import { useEffect } from 'react';
import { AlertCircle } from 'lucide-react';

export default function EditListingError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  useEffect(() => {
    console.error('Edit listing page error:', error);
  }, [error]);

  return (
    <div className="min-h-screen bg-gray-50 flex items-center justify-center p-6">
      <div className="bg-white rounded-xl border border-red-200 p-8 max-w-lg w-full space-y-4">
        <div className="flex items-center gap-3 text-red-600">
          <AlertCircle className="h-6 w-6 flex-shrink-0" />
          <h2 className="text-lg font-semibold">Fehler beim Laden</h2>
        </div>
        <p className="text-sm text-gray-600">
          {error.message || 'Ein unerwarteter Fehler ist aufgetreten.'}
        </p>
        <button
          onClick={reset}
          className="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors"
        >
          Erneut versuchen
        </button>
      </div>
    </div>
  );
}
