# eBay Seller Operations Platform — V1 Architecture

---

## 1. High-Level Architecture

```
┌─────────────────────────────────────────────────────────┐
│                    Browser                              │
│              Next.js (App Router)                       │
│  Dashboard │ Orders │ Listings │ Reports │ Settings     │
└──────────────────────┬──────────────────────────────────┘
                       │ fetch() / REST
                       ▼
┌─────────────────────────────────────────────────────────┐
│              PHP REST API  (public/index.php)           │
│                                                         │
│  Router → Controllers → Services → Repository Layer     │
│                              │                          │
│              ┌───────────────┼────────────────┐         │
│              ▼               ▼                ▼         │
│         eBay Client     PDF Engine        JSON Store    │
│        (Guzzle HTTP)   (DomPDF)         data/*.json     │
└──────────────┬──────────────────────────────────────────┘
               │ HTTPS
               ▼
          eBay REST API
         (Buy/Sell APIs)
```

**Deployment model (V1):** single server, Next.js on port 3000, PHP served via Apache/Nginx on port 8080 (or same domain under `/api` via reverse proxy).

---

## 2. Folder Structure

```
sellerapp/
├── frontend/                        # Next.js app
│   ├── app/
│   │   ├── layout.tsx
│   │   ├── page.tsx                 # redirect → /dashboard
│   │   ├── dashboard/
│   │   │   └── page.tsx
│   │   ├── orders/
│   │   │   ├── page.tsx             # order list
│   │   │   └── [id]/page.tsx        # order detail + invoice download
│   │   ├── listings/
│   │   │   ├── page.tsx
│   │   │   └── [id]/page.tsx
│   │   ├── reports/
│   │   │   └── page.tsx
│   │   └── settings/
│   │       └── page.tsx             # eBay OAuth connect/disconnect
│   ├── components/
│   │   ├── ui/                      # shadcn or your own primitives
│   │   ├── OrderTable.tsx
│   │   ├── ListingGrid.tsx
│   │   ├── SalesChart.tsx
│   │   └── SyncButton.tsx
│   ├── lib/
│   │   ├── api.ts                   # typed fetch wrapper → PHP backend
│   │   └── formatters.ts
│   ├── types/
│   │   └── index.ts                 # shared TS types mirroring PHP models
│   ├── .env.local                   # NEXT_PUBLIC_API_BASE_URL
│   └── next.config.ts
│
└── backend/                         # PHP REST API
    ├── public/
    │   └── index.php                # sole entry point, boots router
    ├── src/
    │   ├── Router.php               # lightweight regex router
    │   ├── Controllers/
    │   │   ├── AuthController.php   # OAuth flow
    │   │   ├── OrderController.php
    │   │   ├── ListingController.php
    │   │   ├── DashboardController.php
    │   │   └── ReportController.php
    │   ├── Services/
    │   │   ├── EbayAuthService.php  # token management
    │   │   ├── EbaySyncService.php  # pulls data from eBay
    │   │   ├── OrderService.php
    │   │   ├── ListingService.php
    │   │   ├── AnalyticsService.php
    │   │   ├── InvoiceService.php
    │   │   └── SalesReportService.php
    │   ├── Storage/
    │   │   ├── Contracts/
    │   │   │   └── RepositoryInterface.php  # the migration seam
    │   │   ├── Json/
    │   │   │   ├── JsonRepository.php       # base R/W implementation
    │   │   │   ├── OrderRepository.php
    │   │   │   ├── ListingRepository.php
    │   │   │   └── TokenRepository.php
    │   │   └── Database/                    # empty now, fill later
    │   │       └── .gitkeep
    │   ├── eBay/
    │   │   ├── EbayClient.php       # Guzzle wrapper + auto token refresh
    │   │   ├── OrderMapper.php      # eBay response → internal Order model
    │   │   └── ListingMapper.php
    │   ├── PDF/
    │   │   ├── InvoicePdf.php
    │   │   └── SalesReportPdf.php
    │   └── Helpers/
    │       └── Response.php         # json(), error(), stream() helpers
    ├── data/                        # JSON file store (git-ignored)
    │   ├── orders/
    │   │   ├── index.json           # lightweight index for list queries
    │   │   └── {orderId}.json       # one file per order
    │   ├── listings/
    │   │   ├── index.json
    │   │   └── {itemId}.json
    │   ├── sync_state.json          # last-synced cursors / timestamps
    │   └── tokens.json              # encrypted OAuth tokens
    ├── templates/
    │   ├── invoice.html             # DomPDF HTML template
    │   └── sales_report.html
    ├── vendor/                      # Composer
    ├── composer.json
    └── .env                        # EBAY_CLIENT_ID, SECRET, etc.
```

---

## 3. Internal Data Models

### Order
```json
{
  "id": "12-34567-89012",
  "ebay_order_id": "12-34567-89012",
  "status": "PAID | SHIPPED | DELIVERED | CANCELLED",
  "buyer": {
    "username": "buyer123",
    "email": "buyer@example.com",
    "shipping_address": {
      "name": "John Doe",
      "line1": "123 Main St",
      "line2": "",
      "city": "London",
      "state": "England",
      "postal_code": "EC1A 1BB",
      "country_code": "GB"
    }
  },
  "line_items": [
    {
      "ebay_item_id": "123456789012",
      "title": "Vintage Camera",
      "sku": "CAM-001",
      "quantity": 1,
      "unit_price": { "value": "49.99", "currency": "GBP" },
      "total_price": { "value": "49.99", "currency": "GBP" }
    }
  ],
  "payment": {
    "method": "PAYPAL | EBAY_MANAGED",
    "status": "PAID | PENDING",
    "amount": { "value": "53.99", "currency": "GBP" },
    "paid_at": "2026-04-10T14:30:00Z"
  },
  "shipping": {
    "service": "Royal Mail Tracked 48",
    "cost": { "value": "4.00", "currency": "GBP" },
    "tracking_number": "TT123456789GB",
    "shipped_at": null,
    "delivered_at": null
  },
  "totals": {
    "subtotal": { "value": "49.99", "currency": "GBP" },
    "shipping": { "value": "4.00", "currency": "GBP" },
    "grand_total": { "value": "53.99", "currency": "GBP" }
  },
  "notes": "",
  "created_at": "2026-04-10T13:00:00Z",
  "updated_at": "2026-04-10T14:35:00Z",
  "synced_at": "2026-04-15T09:00:00Z"
}
```

### Listing
```json
{
  "id": "123456789012",
  "ebay_item_id": "123456789012",
  "title": "Vintage Camera Body",
  "sku": "CAM-001",
  "status": "ACTIVE | ENDED | OUT_OF_STOCK",
  "category": {
    "ebay_category_id": "31388",
    "name": "Film Cameras"
  },
  "price": { "value": "49.99", "currency": "GBP" },
  "quantity": {
    "available": 3,
    "sold": 12
  },
  "images": [
    "https://i.ebayimg.com/images/g/xxx/s-l1600.jpg"
  ],
  "condition": "USED_EXCELLENT",
  "description_snippet": "First 300 chars...",
  "listing_url": "https://www.ebay.co.uk/itm/123456789012",
  "listed_at": "2026-01-15T10:00:00Z",
  "ends_at": "2026-07-15T10:00:00Z",
  "synced_at": "2026-04-15T09:00:00Z"
}
```

### Sync State
```json
{
  "orders": {
    "last_synced_at": "2026-04-15T09:00:00Z",
    "last_offset": 200,
    "total_synced": 200
  },
  "listings": {
    "last_synced_at": "2026-04-15T09:00:00Z",
    "total_synced": 47
  }
}
```

### Tokens
```json
{
  "access_token": "v^1.1...<encrypted at rest>",
  "refresh_token": "v^1.1...<encrypted at rest>",
  "token_type": "User Access Token",
  "expires_at": "2026-04-15T11:00:00Z",
  "refresh_expires_at": "2026-10-15T09:00:00Z",
  "scopes": [
    "https://api.ebay.com/oauth/api_scope/sell.fulfillment.readonly",
    "https://api.ebay.com/oauth/api_scope/sell.inventory.readonly"
  ]
}
```

---

## 4. JSON File Schema (Storage Layout)

| File | Purpose | Growth Pattern |
|---|---|---|
| `data/orders/index.json` | Array of `{id, status, buyer_username, grand_total, created_at}` | One entry per order |
| `data/orders/{id}.json` | Full order object | One file per order |
| `data/listings/index.json` | Array of `{id, title, status, price, quantity_available}` | One entry per listing |
| `data/listings/{id}.json` | Full listing object | One file per listing |
| `data/sync_state.json` | Sync cursors | Single file |
| `data/tokens.json` | OAuth tokens | Single file |

**Why split index + detail files:**
- List queries read only `index.json` — fast, single file
- Detail views read one `{id}.json` — O(1) lookup
- Avoids loading all data to serve a paginated list

---

## 5. REST API Routes

```
# Auth
GET    /api/auth/ebay            → Returns { connected: bool, expires_at }
GET    /api/auth/ebay/connect    → Redirects browser to eBay OAuth consent page
GET    /api/auth/ebay/callback   → Handles code exchange, stores tokens
DELETE /api/auth/ebay            → Revokes and deletes tokens

# Sync (trigger manually from UI)
POST   /api/sync/orders          → Pulls new/updated orders from eBay
POST   /api/sync/listings        → Pulls listings from eBay
GET    /api/sync/status          → Returns sync_state.json contents

# Orders
GET    /api/orders               → List  ?page=1&limit=25&status=PAID&search=
GET    /api/orders/{id}          → Single order detail
GET    /api/orders/{id}/invoice  → Streams invoice PDF (Content-Disposition: attachment)

# Listings
GET    /api/listings             → List  ?page=1&limit=25&status=ACTIVE
GET    /api/listings/{id}        → Single listing detail

# Dashboard
GET    /api/dashboard            → {revenue_30d, orders_30d, avg_order_value,
                                    top_listings[], revenue_by_day[]}

# Reports
GET    /api/reports/sales        → Sales data ?from=2026-01-01&to=2026-04-15
GET    /api/reports/sales/pdf    → Streams sales report PDF
```

All responses: `Content-Type: application/json`, envelope:
```json
{ "data": {}, "meta": { "page": 1, "total": 200 }, "error": null }
```

---

## 6. Storage Abstraction (The Migration Seam)

### The Interface (`RepositoryInterface.php`)
```php
interface RepositoryInterface
{
    public function find(string $id): ?array;
    public function findAll(array $filters = [], int $page = 1, int $limit = 25): array;
    public function findBy(string $field, mixed $value): array;
    public function save(array $entity): array;   // insert or update by id
    public function delete(string $id): bool;
    public function count(array $filters = []): int;
}
```

### JSON Implementation (V1)
```php
class JsonRepository implements RepositoryInterface
{
    // Constructor takes $dataDir + $entityName
    // find()    → reads data/{entity}/{id}.json
    // findAll() → reads index.json, slices for pagination, loads full files
    // save()    → writes {id}.json + updates index.json with flock()
    // delete()  → unlinks file + removes from index
}
```

### Database Implementation (V2 — empty stub)
```php
class DatabaseRepository implements RepositoryInterface
{
    // Constructor takes PDO instance
    // Exact same interface, PDO queries inside
}
```

### Concrete Repositories
```php
class OrderRepository extends JsonRepository {
    // Adds order-specific filter helpers: filterByStatus(), filterByDateRange()
    // These become SQL WHERE clauses in DatabaseRepository
}
```

### Wiring (DI in `index.php`)
```php
$storage = getenv('STORAGE_DRIVER'); // 'json' or 'database'

$orderRepo = $storage === 'database'
    ? new DatabaseOrderRepository($pdo)
    : new JsonOrderRepository(__DIR__ . '/../data');

$orderService   = new OrderService($orderRepo);
$orderController = new OrderController($orderService);
```

**Migration path:** flip `STORAGE_DRIVER=database` in `.env`, run a one-time script that reads all JSON files and inserts rows — done. Zero changes to Controllers or Services.

---

## 7. Key Implementation Decisions

| Decision | Rationale |
|---|---|
| Slim 4 (not Laravel) | 10 routes don't need a full framework; less overhead |
| One file per entity + index.json | Avoids full-file rewrite on every save; O(1) reads |
| `flock()` on every JSON write | Prevents index corruption under concurrent PHP-FPM workers |
| Manual sync only (no cron/webhooks) | eBay webhooks need verified HTTPS; cron adds complexity |
| X-API-Key auth | Single-operator tool; 10-line solution that blocks external access |
| DomPDF + HTML templates | Full CSS control, no binary deps, easy to style |
| `openssl_encrypt` for tokens | Credentials at rest must not be plaintext |
| Incremental sync via `lastModifiedDate` filter | Only fetches changed records; safe to re-run (idempotent) |
| Mappers isolate eBay API shape | Only `OrderMapper`/`ListingMapper` need updating if eBay changes fields |

---

## 8. Risks and V1 Simplifications

### Simplifications Made Deliberately

| What | Why Acceptable in V1 |
|---|---|
| No user authentication | Single-operator tool |
| Manual sync only | eBay webhook setup requires verified HTTPS endpoint |
| No job queue | Sync is fast enough (<5s) for personal volume |
| Offset-based pagination | Personal shop won't hit offset scaling limits |
| PHP includes for PDF templates | No template engine dependency |

### Risks to Manage

**File concurrency** — `flock()` mitigates but doesn't eliminate races under high concurrency. Acceptable for V1; fix in V2 is switching to DB.

**eBay rate limits** — Full re-sync of 1000+ listings can hit per-app quotas. Implement exponential backoff in `EbayClient` and store partial progress in `sync_state.json` so a rate-limited sync can resume.

**Token security** — `tokens.json` contains credentials. Use `openssl_encrypt`, store the key in `.env`, add `data/` and `.env` to `.gitignore` immediately.

**eBay API version drift** — eBay deprecates API versions. Mappers isolate this — only two files need updating.

**JSON performance wall** — At ~2,000 orders the in-memory filter on `index.json` will noticeably slow down. This is the natural trigger for the MySQL migration.

**eBay OAuth callback in dev** — Callback URL must match exactly what's registered in eBay Developer Portal. Use a `.env` variable `EBAY_REDIRECT_URI` and point it to your ngrok URL during local dev.
