'use client';

import { Download } from 'lucide-react';

export default function InvoiceDownloadButton({ invoiceUrl }: { invoiceUrl: string }) {
  return (
    <a
      href={invoiceUrl}
      target="_blank"
      rel="noopener noreferrer"
      className="inline-flex items-center gap-2 px-4 py-2 bg-[#0f3460] text-white text-sm font-medium rounded-lg hover:bg-[#0a2444] transition-colors"
    >
      <Download className="h-4 w-4" />
      Download Invoice
    </a>
  );
}
