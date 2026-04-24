<?php

declare(strict_types=1);

namespace App;

use App\Controllers\AiController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\ListingController;
use App\Controllers\MonitorController;
use App\Controllers\OrderController;
use App\Controllers\ProfileController;
use App\Controllers\ReportController;
use App\Controllers\SyncController;
use App\Controllers\UserController;
use App\eBay\EbayClient;
use App\eBay\EbayClientInterface;
use App\PDF\InvoicePdf;
use App\PDF\SalesReportPdf;
use App\Services\AiListingService;
use App\Services\AnalyticsService;
use App\Services\EbayCompetitorService;
use App\Services\EbayAuthService;
use App\Services\EbaySyncService;
use App\Services\InvoiceService;
use App\Services\ListingHealthService;
use App\Services\ListingService;
use App\Services\OrderFulfillmentService;
use App\Services\OrderService;
use App\Services\PriceMonitorService;
use App\Services\ProductScraperService;
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
        $userAuthService      = new UserAuthService($userRepo, $sessionRepo);
        $profileService       = new ProfileService($profileRepo);
        $ebayAuthService      = new EbayAuthService($tokenRepo, $ebayClient);
        $ebaySyncService      = new EbaySyncService($ebayClient, $orderRepo, $listingRepo, $tokenRepo, $this->dataDir);
        $orderService         = new OrderService($orderRepo);
        $listingService       = new ListingService($listingRepo, $ebayClient);
        $analyticsService     = new AnalyticsService($orderRepo, $listingRepo);
        $invoicePdf           = new InvoicePdf($this->templateDir);
        $salesReportPdf       = new SalesReportPdf($this->templateDir);
        $invoiceService       = new InvoiceService($orderRepo, $invoicePdf, $profileService);
        $salesReportService   = new SalesReportService($analyticsService, $salesReportPdf);
        $scraperService       = new ProductScraperService();
        $aiListingService     = new AiListingService();
        $priceMonitorService  = new PriceMonitorService($scraperService, $listingRepo, $ebayClient);
        $competitorService    = new EbayCompetitorService($listingRepo, $ebayClient);
        $fulfillmentService   = new OrderFulfillmentService($orderRepo, $ebayClient);
        $healthService        = new ListingHealthService();

        // ── Controllers ──────────────────────────────────────────────────────
        $userController      = new UserController($userAuthService, $profileService);
        $profileController   = new ProfileController($userAuthService, $profileService);
        $authController      = new AuthController($ebayAuthService);
        $orderController     = new OrderController($orderService, $invoiceService, $fulfillmentService);
        $listingController   = new ListingController($listingService, $competitorService, $healthService);
        $dashboardController = new DashboardController($analyticsService);
        $reportController    = new ReportController($salesReportService);
        $syncController      = new SyncController($ebaySyncService);
        $aiController        = new AiController($scraperService, $aiListingService);
        $monitorController   = new MonitorController($priceMonitorService);

        // ── Router ───────────────────────────────────────────────────────────
        $this->router = new Router();

        // User auth
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
        $this->router->get('/api/orders',                     [$orderController, 'index']);
        $this->router->get('/api/orders/{id}',                [$orderController, 'show']);
        $this->router->get('/api/orders/{id}/invoice',        [$orderController, 'invoice']);
        $this->router->post('/api/orders/{id}/fulfill',       [$orderController, 'fulfill']);
        $this->router->post('/api/orders/{id}/track',         [$orderController, 'track']);

        // Listings
        $this->router->get('/api/listings',                      [$listingController, 'index']);
        $this->router->post('/api/listings',                     [$listingController, 'create']);
        $this->router->get('/api/listings/category-suggest',     [$listingController, 'suggestCategories']);
        $this->router->get('/api/listings/{id}/health',          [$listingController, 'healthScore']);
        $this->router->get('/api/listings/{id}',                 [$listingController, 'show']);
        $this->router->put('/api/listings/{id}',                 [$listingController, 'update']);
        $this->router->delete('/api/listings/{id}',              [$listingController, 'destroy']);
        $this->router->post('/api/listings/{id}/publish',           [$listingController, 'publish']);
        $this->router->post('/api/listings/{id}/revise',            [$listingController, 'revise']);
        $this->router->post('/api/listings/{id}/check-competitors', [$listingController, 'checkCompetitors']);
        $this->router->post('/api/listings/check-all-competitors',  [$listingController, 'checkAllCompetitors']);

        // Monitor
        $this->router->get('/api/monitor',                    [$monitorController, 'status']);
        $this->router->post('/api/monitor/check-all',         [$monitorController, 'checkAll']);
        $this->router->post('/api/monitor/{id}/check',        [$monitorController, 'checkOne']);
        $this->router->post('/api/monitor/{id}/toggle',       [$monitorController, 'toggle']);
        $this->router->post('/api/monitor/{id}/apply',        [$monitorController, 'applyUpdate']);

        // AI listing analysis + translation
        $this->router->post('/api/ai/analyze',             [$aiController, 'analyze']);
        $this->router->post('/api/ai/translate',           [$aiController, 'translate']);
        $this->router->post('/api/ai/suggest-specifics',   [$aiController, 'suggestSpecifics']);
        $this->router->post('/api/ai/feedback-response',   [$aiController, 'respondFeedback']);
        $this->router->post('/api/ai/improve-listing',     [$aiController, 'improveListing']);
        $this->router->post('/api/ai/suggest-price',       [$aiController, 'suggestPrice']);

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
