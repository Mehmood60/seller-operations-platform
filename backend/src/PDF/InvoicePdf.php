<?php

declare(strict_types=1);

namespace App\PDF;

use Dompdf\Dompdf;
use Dompdf\Options;

class InvoicePdf
{
    private string $templateDir;

    public function __construct(string $templateDir)
    {
        $this->templateDir = rtrim($templateDir, '/\\');
    }

    /**
     * Generate a PDF invoice.
     *
     * @param  array $order   Normalised order array from OrderRepository.
     * @param  array $seller  Seller profile (from ProfileService::get()); blank array = fallbacks used.
     * @return string         Raw PDF bytes.
     */
    public function generate(array $order, array $seller = []): string
    {
        $html = $this->renderTemplate($order, $seller);

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    private function renderTemplate(array $order, array $seller): string
    {
        $templateFile = $this->templateDir . DIRECTORY_SEPARATOR . 'invoice.html';
        if (!file_exists($templateFile)) {
            throw new \RuntimeException('Invoice template not found: ' . $templateFile);
        }

        $template = file_get_contents($templateFile);

        $invoiceNumber = 'INV-' . strtoupper(substr($order['id'], -8));
        $lineItemsHtml = '';

        foreach ($order['line_items'] ?? [] as $item) {
            $lineItemsHtml .= '<tr>
                <td>' . htmlspecialchars($item['title'] ?? '') . '</td>
                <td>' . htmlspecialchars($item['sku'] ?? '') . '</td>
                <td style="text-align:center">' . (int)($item['quantity'] ?? 1) . '</td>
                <td style="text-align:right">' . $this->formatMoney($item['unit_price'] ?? []) . '</td>
                <td style="text-align:right">' . $this->formatMoney($item['total_price'] ?? []) . '</td>
            </tr>';
        }

        // ── Seller (from profile) ─────────────────────────────────────────────
        $store   = $seller['store']   ?? [];
        $address = $seller['address'] ?? [];

        $sellerName    = $store['name']          ?: ($seller['full_name'] ?? 'My eBay Shop');
        $sellerBiz     = $store['business_name'] ?: $sellerName;
        $sellerEmail   = $store['email']         ?: ($seller['email']    ?? '');
        $sellerPhone   = $store['phone']         ?: ($seller['phone']    ?? '');
        $sellerAddr    = $store['address']       ?: trim(implode(', ', array_filter([
            $address['line1']  ?? '',
            $address['line2']  ?? '',
            $address['city']   ?? '',
            $address['state']  ?? '',
        ])));
        $sellerPostal  = $address['postal_code'] ?? '';
        $sellerCountry = $address['country']     ?? '';
        $sellerTax     = $store['tax_number']    ?? '';
        $sellerVat     = $store['vat_number']    ?? '';

        // Build seller meta line (email / phone on same line, separated if both present)
        $sellerContact = implode(' · ', array_filter([$sellerEmail, $sellerPhone]));

        // Build tax/VAT line
        $taxLine = '';
        if ($sellerTax) $taxLine .= 'Tax: ' . htmlspecialchars($sellerTax) . '  ';
        if ($sellerVat) $taxLine .= 'VAT: ' . htmlspecialchars($sellerVat);
        $taxLine = trim($taxLine);

        $replacements = [
            // ── Seller ────────────────────────────────────────────────────────
            '{{SELLER_NAME}}'        => htmlspecialchars($sellerName),
            '{{SELLER_BIZ}}'         => htmlspecialchars($sellerBiz),
            '{{SELLER_ADDRESS}}'     => htmlspecialchars($sellerAddr),
            '{{SELLER_POSTAL_COUNTRY}}' => htmlspecialchars(trim($sellerPostal . ' ' . $sellerCountry)),
            '{{SELLER_CONTACT}}'     => htmlspecialchars($sellerContact),
            '{{SELLER_TAX_LINE}}'    => $taxLine,
            // ── Invoice meta ──────────────────────────────────────────────────
            '{{INVOICE_NUMBER}}'     => $invoiceNumber,
            '{{INVOICE_DATE}}'       => date('d M Y'),
            '{{ORDER_DATE}}'         => $this->formatDate($order['created_at'] ?? ''),
            '{{PAYMENT_DATE}}'       => $this->formatDate($order['payment']['paid_at'] ?? ''),
            '{{ORDER_ID}}'           => $order['id'] ?? '',
            // ── Buyer ─────────────────────────────────────────────────────────
            '{{BUYER_NAME}}'         => htmlspecialchars($order['buyer']['shipping_address']['name'] ?? $order['buyer']['username'] ?? ''),
            '{{BUYER_USERNAME}}'     => htmlspecialchars($order['buyer']['username'] ?? ''),
            '{{BUYER_LINE1}}'        => htmlspecialchars($order['buyer']['shipping_address']['line1'] ?? ''),
            '{{BUYER_LINE2}}'        => htmlspecialchars($order['buyer']['shipping_address']['line2'] ?? ''),
            '{{BUYER_CITY}}'         => htmlspecialchars($order['buyer']['shipping_address']['city'] ?? ''),
            '{{BUYER_STATE}}'        => htmlspecialchars($order['buyer']['shipping_address']['state'] ?? ''),
            '{{BUYER_POSTAL}}'       => htmlspecialchars($order['buyer']['shipping_address']['postal_code'] ?? ''),
            '{{BUYER_COUNTRY}}'      => htmlspecialchars($order['buyer']['shipping_address']['country_code'] ?? ''),
            // ── Financials ────────────────────────────────────────────────────
            '{{LINE_ITEMS}}'         => $lineItemsHtml,
            '{{SUBTOTAL}}'           => $this->formatMoney($order['totals']['subtotal']    ?? []),
            '{{SHIPPING_COST}}'      => $this->formatMoney($order['totals']['shipping']    ?? []),
            '{{GRAND_TOTAL}}'        => $this->formatMoney($order['totals']['grand_total'] ?? []),
            // ── Shipping ──────────────────────────────────────────────────────
            '{{PAYMENT_METHOD}}'     => htmlspecialchars($order['payment']['method']              ?? ''),
            '{{SHIPPING_SERVICE}}'   => htmlspecialchars($order['shipping']['service']            ?? ''),
            '{{TRACKING_NUMBER}}'    => htmlspecialchars($order['shipping']['tracking_number']    ?? 'N/A'),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * Always renders amounts in Euro (€) regardless of the stored currency code.
     * The numeric value from the order is used as-is — no conversion is performed.
     */
    private function formatMoney(array $money): string
    {
        $value = number_format((float)($money['value'] ?? 0), 2);
        return '&euro;' . $value;
    }

    private function formatDate(string $iso): string
    {
        if (empty($iso)) {
            return 'N/A';
        }
        $ts = strtotime($iso);
        return $ts !== false ? date('d M Y', $ts) : $iso;
    }
}
