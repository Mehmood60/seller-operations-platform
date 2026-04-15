<?php

declare(strict_types=1);

namespace Tests\Integration\Api;

use App\Helpers\Response;
use App\Helpers\ResponseSentException;
use App\Kernel;
use Tests\TestCase;

class OrdersEndpointTest extends TestCase
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

    // ── Helper ────────────────────────────────────────────────────────────────

    private function request(string $method, string $uri, array $get = []): array
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI']    = $uri;
        $_SERVER['HTTP_X_API_KEY'] = 'test-api-key';
        $_GET = $get;

        Response::reset();

        try {
            $this->kernel->handle($method, $uri);
        } catch (ResponseSentException) {
            // Response has been captured
        }

        return [
            'status' => Response::getCapturedStatus(),
            'body'   => Response::getCaptured(),
        ];
    }

    // ── GET /api/orders ───────────────────────────────────────────────────────

    public function testListOrdersReturns200(): void
    {
        $response = $this->request('GET', '/api/orders');
        $this->assertEquals(200, $response['status']);
    }

    public function testListOrdersResponseHasCorrectEnvelope(): void
    {
        $response = $this->request('GET', '/api/orders');

        $this->assertNull($response['body']['error']);
        $this->assertIsArray($response['body']['data']);
        $this->assertIsArray($response['body']['meta']);
    }

    public function testListOrdersReturnsFixtureOrders(): void
    {
        $response = $this->request('GET', '/api/orders');

        // Fixtures contain 2 orders
        $this->assertCount(2, $response['body']['data']);
        $this->assertEquals(2, $response['body']['meta']['total']);
    }

    public function testListOrdersMetaIncludesPageAndLimit(): void
    {
        $response = $this->request('GET', '/api/orders');
        $meta     = $response['body']['meta'];

        $this->assertArrayHasKey('page',  $meta);
        $this->assertArrayHasKey('limit', $meta);
        $this->assertArrayHasKey('total', $meta);
    }

    public function testListOrdersFiltersByStatus(): void
    {
        $response = $this->request('GET', '/api/orders', ['status' => 'PAID']);

        foreach ($response['body']['data'] as $order) {
            $this->assertEquals('PAID', $order['status']);
        }
    }

    public function testListOrdersPaginationWorks(): void
    {
        $response = $this->request('GET', '/api/orders', ['page' => '1', 'limit' => '1']);

        $this->assertCount(1, $response['body']['data']);
        $this->assertEquals(2, $response['body']['meta']['total']);
    }

    // ── GET /api/orders/{id} ──────────────────────────────────────────────────

    public function testGetOrderByIdReturns200(): void
    {
        $response = $this->request('GET', '/api/orders/order-001');
        $this->assertEquals(200, $response['status']);
    }

    public function testGetOrderByIdReturnsCorrectOrder(): void
    {
        $response = $this->request('GET', '/api/orders/order-001');

        $this->assertEquals('order-001', $response['body']['data']['id']);
        $this->assertEquals('PAID', $response['body']['data']['status']);
    }

    public function testGetOrderByIdReturnsFullOrderShape(): void
    {
        $response = $this->request('GET', '/api/orders/order-001');
        $order    = $response['body']['data'];

        $this->assertArrayHasKey('buyer',      $order);
        $this->assertArrayHasKey('line_items', $order);
        $this->assertArrayHasKey('payment',    $order);
        $this->assertArrayHasKey('shipping',   $order);
        $this->assertArrayHasKey('totals',     $order);
    }

    public function testGetOrderByIdReturns404ForUnknownId(): void
    {
        $response = $this->request('GET', '/api/orders/does-not-exist');

        $this->assertEquals(404, $response['status']);
        $this->assertNotNull($response['body']['error']);
    }

    public function testUnknownRouteReturns404(): void
    {
        $response = $this->request('GET', '/api/orders/order-001/nonexistent-sub-route');
        $this->assertEquals(404, $response['status']);
    }
}
