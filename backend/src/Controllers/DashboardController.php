<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AnalyticsService;
use App\Helpers\Response;

class DashboardController
{
    public function __construct(private readonly AnalyticsService $analytics) {}

    public function index(array $params): void
    {
        Response::json($this->analytics->getDashboard());
    }
}
