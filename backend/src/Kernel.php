<?php

declare(strict_types=1);

namespace App;

use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\ListingController;
use App\Controllers\OrderController;
use App\Controllers\ProfileController;
use App\Controllers\ReportController;
use App\Controllers\SyncController;
use App\Controllers\UserController;
use App\eBay\EbayClient;
use App\eBay\EbayClientInterface;
use App\PDF\InvoicePdf;
use App\PDF\SalesReportPdf;
use App\Services\AnalyticsService;
use App\Services\EbayAuthService;
use App\Services\EbaySyncService;
use App\Services\InvoiceService;
use App\Services\ListingService;
use App\Services\OrderService;
use App\Services\ProfileService;
use App\Services\SalesReportService;
use App\Services\UserAuthService;
use App\Storage\Json\ListingRepository;
use App\Storage\Json\OrderRepository;
use App\Storage\Json\ProfileRepository;
use App\Storage\Json\SessionRepository;
use App\Storage\Json\TokenRepository;
use App\Storage\Json\UserRepository;

class Kernel
{
    private Router $router;

    public function __construct(
        private readonly string $dataDir,
        private readonly string $templateDir,
        private readonly ?EbayClientInterface $ebayClientOverride = null,
    ) {
        $this->boot();
    }

    private function boot(): void
    {
        // ── Repositories ─────────────────────────────────────────────────────
        $orderRepo     = new OrderRepository($this->dataDir);
        $listingRepo   = new ListingRepository($this->dataDir);
        $tokenRepo     = new TokenRepository($this->dataDir);
        $userRepo      = new UserRepository($this->dataDir);
        $sessionRepo   = new SessionRepository($this->dataDir);
        $profileRepo   = new ProfileRepository($this->dataDir);

        // ── eBay client (allow injection for testing / mocking) ───────────────
        $ebayClient = $this->ebayClientOverride ?? new EbayClient($tokenRepo);

        // ── Services ─────────────────────────────────────────────────────────
        $userAuthService    = new UserAuthService($userRepo, $sessionRepo);
        $profileService     = new ProfileService($profileRepo);
        $ebayAuthService    = new EbayAuthService($tokenRepo, $ebayClient);
        $ebaySyncService    = new EbaySyncService($ebayClient, $orderRepo, $listingRepo, $this->dataDir);
        $orderService       = new OrderService($orderRepo);
        $listingService     = new ListingService($listingRepo);
        $analyticsService   = new AnalyticsService($orderRepo, $listingRepo);
        $invoicePdf         = new InvoicePdf($this->templateDir);
        $salesReportPdf     = new SalesReportPdf($this->templateDir);
        $invoiceService     = new InvoiceService($orderRepo, $invoicePdf, $profileService);
        $salesReportService = new SalesReportService($analyticsService, $salesReportPdf);

        // ── Controllers ──────────────────────────────────────────────────────
        $userController      = new UserController($userAuthService, $profileService);
        $profileController   = new ProfileController($userAuthService, $profileService);
        $authController      = new AuthController($ebayAuthService);
        $orderController     = new OrderController($orderService, $invoiceService);
        $listingController   = new ListingController($listingService);
        $dashboardController = new DashboardController($analyticsService);
        $reportController    = new ReportController($salesReportService);
        $syncController      = new SyncController($ebaySyncService);

        // ── Router ───────────────────────────────────────────────────────────
        $this->router = new Router();

        // User auth (register / login are public; logout + me require Bearer token validated inside the controller)
        $this->router->post('/api/auth/register',        [$userController, 'register']);
        $this->router->post('/api/auth/login',           [$userController, 'login']);
        $this->router->post('/api/auth/logout',          [$userController, 'logout']);
        $this->router->get('/api/auth/me',               [$userController, 'me']);

        // Profile
        $this->router->get('/api/profile',               [$profileController, 'show']);
        $this->router->put('/api/profile',               [$profileController, 'update']);

        // eBay Auth
        $this->router->get('/api/auth/ebay',             [$authController, 'status']);
        $this->router->get('/api/auth/ebay/connect',     [$authController, 'connect']);
        $this->router->get('/api/auth/ebay/callback',    [$authController, 'callback']);
        $this->router->delete('/api/auth/ebay',          [$authController, 'disconnect']);

        // Sync
        $this->router->post('/api/sync/orders',          [$syncController, 'syncOrders']);
        $this->router->post('/api/sync/listings',        [$syncController, 'syncListings']);
        $this->router->get('/api/sync/status',           [$syncController, 'status']);

        // Orders
        $this->router->get('/api/orders',                [$orderController, 'index']);
        $this->router->get('/api/orders/{id}',           [$orderController, 'show']);
        $this->router->get('/api/orders/{id}/invoice',   [$orderController, 'invoice']);

        // Listings
        $this->router->get('/api/listings',              [$listingController, 'index']);
        $this->router->get('/api/listings/{id}',         [$listingController, 'show']);

        // Dashboard
        $this->router->get('/api/dashboard',             [$dashboardController, 'index']);

        // Reports
        $this->router->get('/api/reports/sales',         [$reportController, 'salesData']);
        $this->router->get('/api/reports/sales/pdf',     [$reportController, 'salesPdf']);
    }

    public function handle(string $method, string $uri): void
    {
        $this->router->dispatch($method, $uri);
    }
}
