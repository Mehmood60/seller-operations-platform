<?php

declare(strict_types=1);

namespace App\Services;

use App\PDF\SalesReportPdf;

class SalesReportService
{
    public function __construct(
        private readonly AnalyticsService $analytics,
        private readonly SalesReportPdf $reportPdf,
    ) {}

    public function getData(string $from, string $to): array
    {
        return $this->analytics->getSalesReport($from, $to);
    }

    public function generatePdf(string $from, string $to): string
    {
        $data = $this->getData($from, $to);
        return $this->reportPdf->generate($data);
    }
}
