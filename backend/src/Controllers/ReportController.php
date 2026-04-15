<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\SalesReportService;
use App\Helpers\Response;

class ReportController
{
    public function __construct(private readonly SalesReportService $reportService) {}

    // ── Public endpoints ──────────────────────────────────────────────────────

    public function salesData(array $params): void
    {
        [$from, $to] = $this->parseDates();
        Response::json($this->reportService->getData($from, $to));
    }

    public function salesPdf(array $params): void
    {
        [$from, $to] = $this->parseDates();
        $pdf      = $this->reportService->generatePdf($from, $to);
        // Build filename from already-validated date strings (YYYY-MM-DD only).
        $filename = 'sales-report-' . $from . '-to-' . $to . '.pdf';
        Response::stream($pdf, $filename);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Parse, validate, and return [from, to] date strings.
     * Calls Response::error(422) and exits on any failure.
     *
     * @return array{string, string}
     */
    private function parseDates(): array
    {
        $from = trim($_GET['from'] ?? date('Y-m-01'));
        $to   = trim($_GET['to']   ?? date('Y-m-d'));

        if (!$this->isValidDate($from)) {
            Response::error('"from" must be a valid date in YYYY-MM-DD format.', 422);
        }
        if (!$this->isValidDate($to)) {
            Response::error('"to" must be a valid date in YYYY-MM-DD format.', 422);
        }
        if ($from > $to) {
            Response::error('"from" date must be before or equal to "to" date.', 422);
        }

        $days = (strtotime($to) - strtotime($from)) / 86400;
        if ($days > 366) {
            Response::error('Date range must not exceed 366 days.', 422);
        }

        return [$from, $to];
    }

    /** Check that a string is a valid YYYY-MM-DD calendar date. */
    private function isValidDate(string $value): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $value);
        return $d !== false && $d->format('Y-m-d') === $value;
    }
}
