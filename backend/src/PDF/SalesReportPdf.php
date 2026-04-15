<?php

declare(strict_types=1);

namespace App\PDF;

use Dompdf\Dompdf;
use Dompdf\Options;

class SalesReportPdf
{
    private string $templateDir;

    public function __construct(string $templateDir)
    {
        $this->templateDir = rtrim($templateDir, '/\\');
    }

    public function generate(array $reportData): string
    {
        $html = $this->renderTemplate($reportData);

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

    private function renderTemplate(array $data): string
    {
        $templateFile = $this->templateDir . DIRECTORY_SEPARATOR . 'sales_report.html';
        if (!file_exists($templateFile)) {
            throw new \RuntimeException('Sales report template not found: ' . $templateFile);
        }

        $template = file_get_contents($templateFile);

        // Top listings rows
        $topListingsHtml = '';
        foreach ($data['top_listings'] ?? [] as $i => $listing) {
            $topListingsHtml .= '<tr>
                <td>' . ($i + 1) . '</td>
                <td>' . htmlspecialchars($listing['title'] ?? '') . '</td>
                <td style="text-align:center">' . (int)($listing['total_sold'] ?? 0) . '</td>
                <td style="text-align:right">' . $this->formatMoney($listing['total_revenue'] ?? []) . '</td>
            </tr>';
        }

        // Revenue by day rows
        $revenueByDayHtml = '';
        foreach ($data['revenue_by_day'] ?? [] as $day) {
            $revenueByDayHtml .= '<tr>
                <td>' . htmlspecialchars($day['date'] ?? '') . '</td>
                <td style="text-align:center">' . (int)($day['orders'] ?? 0) . '</td>
                <td style="text-align:right">' . $this->formatMoney(['value' => $day['revenue'] ?? 0, 'currency' => 'GBP']) . '</td>
            </tr>';
        }

        $dateRange = $data['date_range'] ?? [];

        $replacements = [
            '{{REPORT_TITLE}}'    => 'Sales Report',
            '{{DATE_FROM}}'       => $dateRange['from'] ?? '',
            '{{DATE_TO}}'         => $dateRange['to'] ?? '',
            '{{GENERATED_AT}}'    => date('d M Y H:i'),
            '{{TOTAL_ORDERS}}'    => (string)($data['total_orders'] ?? 0),
            '{{TOTAL_REVENUE}}'   => $this->formatMoney($data['total_revenue'] ?? []),
            '{{AVG_ORDER_VALUE}}' => $this->formatMoney($data['avg_order_value'] ?? []),
            '{{TOP_LISTINGS}}'    => $topListingsHtml,
            '{{REVENUE_BY_DAY}}'  => $revenueByDayHtml,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    private function formatMoney(array $money): string
    {
        $value    = number_format((float)($money['value'] ?? 0), 2);
        $currency = $money['currency'] ?? 'GBP';
        $symbols  = ['GBP' => '£', 'USD' => '$', 'EUR' => '€'];
        return ($symbols[$currency] ?? $currency . ' ') . $value;
    }
}
