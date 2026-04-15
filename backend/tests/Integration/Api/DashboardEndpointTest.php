<?php

declare(strict_types=1);

namespace Tests\Integration\Api;

use App\Helpers\Response;
use App\Helpers\ResponseSentException;
use App\Kernel;
use Tests\TestCase;

class DashboardEndpointTest extends TestCase
{
    private Kernel $kernel;

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['API_KEY'] = 'test-api-key';

        $this->kernel = new Kernel(
            $this->fixturesDir,
            __DIR__ . '/../../../templates',
        );
    }

    private function request(string $method, string $uri): array
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI']    = $uri;
        $_SERVER['HTTP_X_API_KEY'] = 'test-api-key';
        $_GET = [];

        Response::reset();

        try {
            $this->kernel->handle($method, $uri);
        } catch (ResponseSentException) {
            // Captured
        }

        return [
            'status' => Response::getCapturedStatus(),
            'body'   => Response::getCaptured(),
        ];
    }

    public function testDashboardReturns200(): void
    {
        $response = $this->request('GET', '/api/dashboard');
        $this->assertEquals(200, $response['status']);
    }

    public function testDashboardResponseHasNoError(): void
    {
        $response = $this->request('GET', '/api/dashboard');
        $this->assertNull($response['body']['error']);
    }

    public function testDashboardReturnsAllRequiredKeys(): void
    {
        $response = $this->request('GET', '/api/dashboard');
        $data     = $response['body']['data'];

        $this->assertArrayHasKey('revenue_30d',     $data);
        $this->assertArrayHasKey('orders_30d',       $data);
        $this->assertArrayHasKey('avg_order_value',  $data);
        $this->assertArrayHasKey('top_listings',     $data);
        $this->assertArrayHasKey('revenue_by_day',   $data);
    }

    public function testDashboardRevenue30dHasMoneyShape(): void
    {
        $response = $this->request('GET', '/api/dashboard');
        $revenue  = $response['body']['data']['revenue_30d'];

        $this->assertArrayHasKey('value',    $revenue);
        $this->assertArrayHasKey('currency', $revenue);
        $this->assertIsNumeric($revenue['value']);
    }

    public function testDashboardOrders30dIsInteger(): void
    {
        $response = $this->request('GET', '/api/dashboard');
        $this->assertIsInt($response['body']['data']['orders_30d']);
    }

    public function testDashboardRevenueByDayIsArray(): void
    {
        $response = $this->request('GET', '/api/dashboard');
        $byDay    = $response['body']['data']['revenue_by_day'];

        $this->assertIsArray($byDay);

        if (!empty($byDay)) {
            $day = $byDay[0];
            $this->assertArrayHasKey('date',    $day);
            $this->assertArrayHasKey('revenue', $day);
            $this->assertArrayHasKey('orders',  $day);
        }
    }

    public function testDashboardTopListingsIsArray(): void
    {
        $response = $this->request('GET', '/api/dashboard');
        $this->assertIsArray($response['body']['data']['top_listings']);
    }
}
