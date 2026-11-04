<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\FinanceService;
use App\Helpers\Response;

class FinanceController
{
    public function __construct(private readonly FinanceService $financeService) {}

    public function summary(array $params): void
    {
        [$from, $to] = $this->parseDates();
        Response::json($this->financeService->summary($from, $to));
    }

    public function categories(array $params): void
    {
        $list = [];
        foreach (FinanceService::CATEGORIES as $value => $label) {
            $list[] = ['value' => $value, 'label' => $label];
        }
        Response::json($list);
    }

    public function listExpenses(array $params): void
    {
        [$from, $to] = $this->parseDates();
        Response::json($this->financeService->listExpenses($from, $to));
    }

    public function createExpense(array $params): void
    {
        try {
            Response::json($this->financeService->createExpense($this->body()), [], 201);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        }
    }

    public function updateExpense(array $params): void
    {
        try {
            $expense = $this->financeService->updateExpense($params['id'] ?? '', $this->body());
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
            return;
        }
        if ($expense === null) {
            Response::error('Expense not found.', 404);
            return;
        }
        Response::json($expense);
    }

    public function deleteExpense(array $params): void
    {
        if (!$this->financeService->deleteExpense($params['id'] ?? '')) {
            Response::error('Expense not found.', 404);
            return;
        }
        Response::json(['deleted' => true]);
    }

    private function body(): array
    {
        $body = json_decode((string)file_get_contents('php://input'), true);
        return is_array($body) ? $body : [];
    }

    /**
     * [from, to] as YYYY-MM-DD. Unlike sales reports there is no range cap —
     * "all time" is the question this page exists to answer.
     *
     * @return array{string, string}
     */
    private function parseDates(): array
    {
        $from = trim($_GET['from'] ?? date('Y-m-01'));
        $to   = trim($_GET['to']   ?? date('Y-m-d'));

        foreach (['from' => $from, 'to' => $to] as $name => $value) {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if ($d === false || $d->format('Y-m-d') !== $value) {
                Response::error('"' . $name . '" must be a valid date in YYYY-MM-DD format.', 422);
            }
        }
        if ($from > $to) {
            Response::error('"from" date must be before or equal to "to" date.', 422);
        }

        return [$from, $to];
    }
}
