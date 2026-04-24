# eBay Seller Dashboard — Project Overview

A self-hosted web application for eBay sellers to manage their orders, listings, analytics, and automate listing creation with AI. Built with a Next.js frontend and a PHP REST API backend.

> **Language policy:** The app UI is fully in English. Listing content (titles, descriptions, item specifics) is generated and published in German for the eBay.de marketplace.

---

## Table of Contents

1. [Project Purpose](#1-project-purpose)
2. [Tech Stack Summary](#2-tech-stack-summary)
3. [Project Structure](#3-project-structure)
4. [Frontend](#4-frontend)
5. [Backend](#5-backend)
6. [Database & Storage](#6-database--storage)
7. [Authentication](#7-authentication)
8. [REST API Reference](#8-rest-api-reference)
9. [Features & Functionality](#9-features--functionality)
10. [AI Integration](#10-ai-integration)
11. [Testing](#11-testing)
12. [Deployment & DevOps](#12-deployment--devops)
13. [Environment Variables](#13-environment-variables)
14. [Data Models](#14-data-models)
15. [Roadmap (V2)](#15-roadmap-v2)

---

## 1. Project Purpose

This is a seller operations dashboard for eBay, specifically designed for dropshipping sellers on the German eBay marketplace (EBAY_GB / UK). It allows a seller to:

- Connect their eBay account via OAuth 2.0
- Sync and view all orders and listings from eBay
- Manage and download invoice PDFs for individual orders
- View revenue analytics and generate downloadable sales reports
- Create new eBay listings using AI — by pasting a product URL from AliExpress, DHgate, or Banggood, the app scrapes the product data and uses the Groq AI API to auto-generate an optimized eBay listing in German

The application is entirely self-hosted, designed to run on a VPS with Docker Compose.

---

## 2. Tech Stack Summary

| Layer | Technology |
|---|---|
| Frontend Framework | Next.js 14 (App Router) |
| Frontend Language | TypeScript 5 |
| UI Styling | Tailwind CSS 3 |
| UI Icons | Lucide React |
| Charts | Recharts |
| Backend Framework | PHP 8.2+ (custom lightweight REST API, no framework) |
| HTTP Client (Backend) | Guzzle 7 |
| PDF Generation | DomPDF 2 |
| Environment Config | vlucas/phpdotenv 5 |
| Storage (V1) | JSON flat files |
| Storage (V2, planned) | MySQL / PostgreSQL via PDO |
| AI API | Groq API (LLaMA 3.1) |
| Auth (eBay) | OAuth 2.0 |
| Auth (Users) | Session tokens (bcrypt + hex tokens) |
| Frontend Testing | Vitest 2, Testing Library |
| Backend Testing | PHPUnit 11 |
| Containerization | Docker (multi-stage builds) |
| CI/CD | GitLab CI/CD |

---

## 3. Project Structure

```
sellerapp/
├── frontend/                  # Next.js 14 application
│   ├── app/                   # App Router pages and layouts
│   ├── components/            # Reusable React components
│   ├── lib/                   # API client, utilities
│   ├── test/                  # Test setup and test files
│   ├── public/                # Static assets
│   ├── package.json
│   ├── tsconfig.json
│   ├── tailwind.config.ts
│   ├── next.config.js
│   └── vitest.config.ts
│
├── backend/                   # PHP REST API
│   ├── public/index.php       # Single entry point
│   ├── src/
│   │   ├── Controllers/       # Route handlers
│   │   ├── Services/          # Business logic
│   │   ├── Repositories/      # Data persistence layer
│   │   ├── Models/            # Data models (Order, Listing, User…)
│   │   ├── Mappers/           # eBay response → internal model
│   │   └── Kernel.php         # Dependency injection / bootstrapper
│   ├── templates/             # HTML templates for PDF generation
│   ├── data/                  # JSON file storage (git-ignored)
│   ├── tests/
│   │   ├── Unit/
│   │   ├── Integration/
│   │   └── fixtures/
│   ├── composer.json
│   ├── phpunit.xml
│   └── Dockerfile
│
├── docker-compose.yml         # Dev/production Docker setup
├── docker-compose.test.yml    # Test Docker setup
├── .gitlab-ci.yml             # GitLab CI/CD pipeline
├── ARCHITECTURE.md            # Detailed architecture notes
├── README.md                  # Setup and run guide
└── project.md                 # This file
```

---

## 4. Frontend

### Language & Framework

- **Language:** TypeScript 5 (strict mode)
- **Framework:** Next.js 14 with App Router
- **React version:** React 18.3.1

### npm Packages

**Runtime Dependencies:**

| Package | Version | Purpose |
|---|---|---|
| `next` | 14.2.5 | React framework (SSR, routing, builds) |
| `react` | ^18.3.1 | UI library |
| `react-dom` | ^18.3.1 | React DOM renderer |
| `recharts` | ^2.12.7 | Charts for revenue analytics |
| `lucide-react` | ^0.414.0 | SVG icon library |

**Dev Dependencies:**

| Package | Version | Purpose |
|---|---|---|
| `typescript` | ^5 | Type checking |
| `tailwindcss` | ^3.4.6 | Utility-first CSS |
| `autoprefixer` | ^10.4.19 | CSS autoprefixing |
| `postcss` | ^8.4.39 | CSS transforms |
| `@types/react` | ^18 | React TypeScript types |
| `@types/node` | ^20 | Node TypeScript types |
| `eslint` | ^8 | Linting |
| `eslint-config-next` | 14.2.5 | Next.js ESLint preset |
| `vitest` | ^2.0.5 | Frontend test runner |
| `@vitest/coverage-v8` | ^2.0.5 | Code coverage |
| `@testing-library/react` | ^16.0.0 | React component testing |
| `@testing-library/jest-dom` | ^6.4.6 | Custom DOM matchers |
| `@testing-library/user-event` | ^14.5.2 | User interaction simulation |
| `@vitejs/plugin-react` | ^4.3.1 | Vitest React plugin |
| `jsdom` | ^24.1.1 | Browser-like DOM for tests |

### Pages & Routes

| Route | Description |
|---|---|
| `/` | Redirects to `/dashboard` |
| `/login` | Login page |
| `/register` | User registration page |
| `/dashboard` | Sales analytics, KPI cards, revenue chart |
| `/orders` | Paginated order list with search and status filter |
| `/orders/[id]` | Order detail view with invoice download |
| `/listings` | Listing gallery with auto-sync and status filter |
| `/listings/[id]` | Listing detail view |
| `/listings/new` | AI-assisted listing creation form |
| `/listings/[id]/edit` | Edit/publish draft; health score panel with AI fix buttons |
| `/monitor` | Source price monitor — track supplier price changes |
| `/repricing` | Smart Repricing — competitor check + AI price suggestions |
| `/feedback` | AI Feedback Replies — generate professional buyer responses |
| `/reports` | Sales report generator with date range picker |
| `/settings` | eBay account connect / disconnect |
| `/profile` | User profile management |

### Key Components

| Component | Purpose |
|---|---|
| `AuthProvider.tsx` | Global auth context — stores session token in localStorage, checks TTL |
| `AppShell.tsx` | Main layout wrapper (sidebar + content area) |
| `Sidebar.tsx` | Navigation sidebar with route links |
| `PreferencesProvider.tsx` | Global preferences context (currency formatting) |
| `OrderTable.tsx` | Data table for the order list |
| `ListingGrid.tsx` | Card grid view for listings |
| `SalesChart.tsx` | Recharts revenue-by-day line chart |
| `SyncButton.tsx` | Triggers eBay sync and shows progress |
| `RichTextEditor.tsx` | HTML editor for listing descriptions |
| `ui/Card.tsx` | Reusable card container |
| `ui/Badge.tsx` | Status badge (PAID, ACTIVE, etc.) |
| `ui/StatCard.tsx` | KPI metric card (revenue, orders, AOV) |
| `ui/PasswordInput.tsx` | Password field with show/hide toggle |

### State Management

- **React Context API** — `AuthContext` (user + session), `PreferencesContext` (currency)
- **Component-level state** — `useState` / `useEffect` for data, loading, error per page
- **Session persistence** — token stored in `localStorage` under key `session_token`
- **No external state library** (Redux, Zustand, etc.)

### API Client (`lib/api.ts`)

A typed fetch wrapper that automatically injects:
- `X-API-Key` header (from `NEXT_PUBLIC_API_KEY`)
- `Authorization: Bearer <token>` header for authenticated requests

Organized into namespaces: `auth`, `sync`, `orders`, `listings`, `dashboard`, `reports`, `userAuth`, `ai`, `monitor`, `profile`

### Configuration Files

| File | Purpose |
|---|---|
| `tsconfig.json` | Strict TypeScript, path alias `@/*` → root |
| `tailwind.config.ts` | Custom brand colors (`#0f3460`, `#0a2444`, `#1a4a7a`) |
| `next.config.js` | Standalone output, allowed image domains (eBay, AliExpress, DHgate, Banggood) |
| `vitest.config.ts` | jsdom environment, v8 coverage, setup file |
| `.env.example` | `NEXT_PUBLIC_API_BASE_URL`, `NEXT_PUBLIC_API_KEY` |

---

## 5. Backend

### Language & Framework

- **Language:** PHP 8.2+
- **Architecture:** Custom lightweight REST API — no framework (no Laravel, Symfony, etc.)
- **Entry point:** `backend/public/index.php`
- **Routing:** Custom `Router` class with regex-based route matching
- **DI:** `Kernel.php` bootstraps all services and wires them to controllers

### Composer Packages

**Runtime Dependencies:**

| Package | Version | Purpose |
|---|---|---|
| `guzzlehttp/guzzle` | ^7.8 | HTTP client for eBay API calls |
| `dompdf/dompdf` | ^2.0 | HTML → PDF for invoices and reports |
| `vlucas/phpdotenv` | ^5.5 | `.env` file loading |

**Dev Dependencies:**

| Package | Version | Purpose |
|---|---|---|
| `phpunit/phpunit` | ^11.0 | Unit and integration testing |

### Services

| Service | Responsibility |
|---|---|
| `EbayAuthService` | eBay OAuth 2.0 connect, callback, disconnect |
| `EbaySyncService` | Incremental pull of orders and listings from eBay API |
| `EbayClient` | Guzzle-based eBay API wrapper with auto token refresh |
| `OrderService` | Retrieve, filter, and paginate orders |
| `OrderFulfillmentService` | AliExpress order fulfilment and tracking push to eBay |
| `ListingService` | Manage draft and synced listings |
| `ListingHealthService` | Rule-based listing quality scoring (6 dimensions, 100 pts, grades A–F) |
| `EbayCompetitorService` | Live eBay competitor price check per listing |
| `PriceMonitorService` | Supplier source-price monitoring with suggested eBay price recalculation |
| `AnalyticsService` | Compute dashboard KPIs (30-day revenue, top items, daily chart) |
| `InvoiceService` | Generate invoice PDFs via DomPDF |
| `SalesReportService` | Generate sales reports with date filtering |
| `UserAuthService` | User register, login (bcrypt), logout, session validation |
| `ProfileService` | CRUD for user profile and store info |
| `ProductScraperService` | Web scraping (JSON-LD, Open Graph, images, price extraction) |
| `AiListingService` | Groq API integration — listing generation, specifics suggestion, title/description improvement, price suggestion, feedback replies, translation |

### Controllers

| Controller | Routes handled |
|---|---|
| `AuthController` | eBay OAuth endpoints |
| `UserController` | Registration, login, logout, current user |
| `OrderController` | Order list, detail, invoice PDF, fulfil, track |
| `ListingController` | Listing CRUD, publish, revise, health score, category suggest, competitor check |
| `DashboardController` | Analytics summary |
| `ReportController` | Sales report data and PDF download |
| `SyncController` | Manual eBay sync trigger |
| `ProfileController` | Get / update user profile |
| `MonitorController` | Price monitor status, check, toggle, apply update |
| `AiController` | Analyze, translate, suggest-specifics, improve-listing, suggest-price, feedback-response |

### Mappers

| Mapper | Purpose |
|---|---|
| `OrderMapper` | Maps raw eBay Fulfillment API response to internal `Order` model |
| `ListingMapper` | Maps raw eBay Inventory API response to internal `Listing` model |

### Security

- All API routes require `X-API-Key` header (or `?key=` query param for browser downloads)
- Public routes (no API key): `/api/auth/ebay/callback`, `/api/auth/ebay/connect`
- eBay tokens encrypted with AES-256-CBC before being stored on disk
- User passwords hashed with bcrypt (cost 12)
- CORS headers restrict requests to `FRONTEND_URL`

---

## 6. Database & Storage

### Current Storage (V1) — JSON Files

All data is stored as JSON files on disk inside the `backend/data/` directory (git-ignored).

| File / Directory | Contents |
|---|---|
| `data/orders/index.json` | Lightweight order index for list/filter queries |
| `data/orders/{orderId}.json` | Full order detail per order |
| `data/listings/index.json` | Lightweight listing index |
| `data/listings/{itemId}.json` | Full listing detail per listing |
| `data/sync_state.json` | Last sync timestamps and eBay cursors |
| `data/tokens.json` | Encrypted eBay OAuth access + refresh tokens |
| `data/users/` | User accounts (email, bcrypt password hash) |
| `data/sessions/` | Active session tokens with expiry |
| `data/profiles/` | User profile and store info |

**Concurrency safety:** File-level locking with `flock()` to prevent race conditions on concurrent requests.

**ORM / ODM:** None — direct JSON serialization/deserialization.

### Future Storage (V2) — SQL Database

The `RepositoryInterface` abstraction already exists. Switching `STORAGE_DRIVER=database` in `.env` will activate the `DatabaseRepository` (currently a stub) which will use PDO to talk to MySQL or PostgreSQL. No application-layer code changes will be needed.

---

## 7. Authentication

### eBay OAuth 2.0

| Step | Detail |
|---|---|
| Grant type | `authorization_code` |
| Environments | Sandbox (`auth.sandbox.ebay.com`) / Production (`auth.ebay.com`) |
| Scopes | `sell.fulfillment.readonly`, `sell.inventory`, `commerce.identity.readonly` |
| Token storage | AES-256-CBC encrypted in `data/tokens.json` |
| Auto-refresh | Access token is refreshed automatically when expired; refresh token TTL ~180 days |

### User Session Authentication

| Step | Detail |
|---|---|
| Registration | `POST /api/auth/register` with `{ email, password, full_name }` |
| Login | `POST /api/auth/login` returns a session token |
| Password hashing | bcrypt (cost 12) |
| Session token | 64-character random hex string |
| Token TTL | 30 days |
| Token storage | Frontend: localStorage; Backend: `data/sessions/` |
| Logout | `POST /api/auth/logout` invalidates the token server-side |

---

## 8. REST API Reference

**Base URL:** `http://localhost:8080` (configurable)

**Required header on all protected routes:** `X-API-Key: <API_KEY>`

**Standard response envelope:**
```json
{
  "data": { ... },
  "meta": { "page": 1, "total": 100, "limit": 25 },
  "error": null
}
```

### eBay Auth

| Method | Route | Description |
|---|---|---|
| GET | `/api/auth/ebay` | eBay connection status |
| GET | `/api/auth/ebay/connect` | Redirect to eBay OAuth consent screen |
| GET | `/api/auth/ebay/callback` | OAuth callback (public route) |
| DELETE | `/api/auth/ebay` | Disconnect eBay account |

### User Auth

| Method | Route | Description |
|---|---|---|
| POST | `/api/auth/register` | Create a new user account |
| POST | `/api/auth/login` | Login and get session token |
| POST | `/api/auth/logout` | Invalidate session token |
| GET | `/api/auth/me` | Get currently authenticated user |

### Sync

| Method | Route | Description |
|---|---|---|
| POST | `/api/sync/orders` | Pull latest orders from eBay |
| POST | `/api/sync/listings` | Pull latest listings from eBay |
| GET | `/api/sync/status` | Sync state (last timestamps, cursors) |

### Orders

| Method | Route | Description |
|---|---|---|
| GET | `/api/orders?page=1&limit=25&status=PAID&search=buyer` | Paginated order list |
| GET | `/api/orders/{id}` | Single order detail |
| GET | `/api/orders/{id}/invoice?key=...` | Download invoice as PDF |

### Listings

| Method | Route | Description |
|---|---|---|
| GET | `/api/listings?page=1&limit=25&status=ACTIVE&search=...` | Paginated listing list |
| GET | `/api/listings/{id}` | Single listing detail |
| POST | `/api/listings` | Save a new draft listing |
| PUT | `/api/listings/{id}` | Update a draft listing |
| DELETE | `/api/listings/{id}` | Delete a draft listing |
| POST | `/api/listings/{id}/publish` | Publish draft to eBay |
| POST | `/api/listings/{id}/revise` | Revise a live eBay listing |
| GET | `/api/listings/{id}/health` | Listing quality score (6 dimensions, grade A–F) |
| GET | `/api/listings/category-suggest?q=...` | eBay category suggestions |
| POST | `/api/listings/{id}/check-competitors` | Live competitor price check for one listing |
| POST | `/api/listings/check-all-competitors` | Batch competitor check for all active listings |

### Dashboard

| Method | Route | Description |
|---|---|---|
| GET | `/api/dashboard` | Analytics (revenue_30d, orders_30d, avg_order_value, top_listings, revenue_by_day) |

### Reports

| Method | Route | Description |
|---|---|---|
| GET | `/api/reports/sales?from=2026-01-01&to=2026-04-15` | Sales report data (JSON) |
| GET | `/api/reports/sales/pdf?from=...&to=...&key=...` | Download sales report as PDF |

### Profile

| Method | Route | Description |
|---|---|---|
| GET | `/api/profile` | Get user profile |
| PUT | `/api/profile` | Update user profile |

### Orders (extended)

| Method | Route | Description |
|---|---|---|
| POST | `/api/orders/{id}/fulfill` | Mark order fulfilled with AliExpress order ID |
| POST | `/api/orders/{id}/track` | Push tracking number + carrier to eBay buyer |

### Price Monitor

| Method | Route | Description |
|---|---|---|
| GET | `/api/monitor` | Monitor status for all tracked listings |
| POST | `/api/monitor/check-all` | Re-check all source prices |
| POST | `/api/monitor/{id}/check` | Re-check source price for one listing |
| POST | `/api/monitor/{id}/toggle` | Enable / disable monitoring for a listing |
| POST | `/api/monitor/{id}/apply` | Apply a pending suggested price update |

### AI Features

| Method | Route | Description |
|---|---|---|
| POST | `/api/ai/analyze` | Scrape product URL + generate full eBay listing with AI |
| POST | `/api/ai/translate` | Translate listing title/description (German → English preview) |
| POST | `/api/ai/suggest-specifics` | Fill missing eBay item specifics with AI |
| POST | `/api/ai/improve-listing` | Improve listing title or description with AI (`aspect: title\|description`) |
| POST | `/api/ai/suggest-price` | AI-powered price recommendation based on live competitor data |
| POST | `/api/ai/feedback-response` | Generate professional German buyer reply (4 message types, 5 tones) |

---

## 9. Features & Functionality

### Order Management

- Browse all synced eBay orders in a paginated, searchable data table
- Filter orders by status: `PAID`, `SHIPPED`, `DELIVERED`, `CANCELLED`
- Search orders by buyer name or order ID
- View full order detail: buyer address, line items, payment info, shipping tracking
- Download a professionally formatted invoice PDF for any order
- **Order Fulfilment** — record AliExpress order ID + push tracking number to eBay buyer

### Listing Management

- Browse all synced eBay listings in a card grid with auto-sync on page load
- Filter by status: `ACTIVE`, `ENDED`, `OUT_OF_STOCK`, `DRAFT`
- Search by title or SKU
- View listing detail: price, quantity, condition, category, images, listing URL
- Manually trigger an eBay sync to pull the latest data
- Save draft listings locally before publishing
- Edit draft listings (title, price, description, images, shipping, item specifics)
- Publish drafts directly to eBay; revise live listings in-place

### AI-Assisted Listing Creation

The flagship feature for dropshipping workflows:

1. Seller pastes a product URL (AliExpress, DHgate, Banggood, or any product page)
2. Backend scrapes the URL (JSON-LD, Open Graph, image/price extraction)
3. Groq AI (LLaMA 3.1) generates a fully formatted German eBay listing:
   - **Title** — max 80 chars, SEO-optimized in German
   - **Condition** — "Neu" for dropshipping products
   - **HTML Description** — structured bullet points in German (150–250 words)
   - **Suggested Price** — 2.8× (China) or 1.6× (Germany) cost markup in EUR
   - **Shipping Preset** — auto-filled based on origin country
   - **eBay Category Suggestion** — with live category ID lookup
   - **Keywords** — 6–10 German search terms
   - **Item Specifics** — brand, product type, color, material, dimensions
4. Seller reviews and edits before saving as a draft or publishing directly

### Listing Health Score

Rule-based quality scoring on the edit page:

- **6 dimensions:** Title (20 pts), Images (20 pts), Item Specifics (20 pts), Description (15 pts), Category (15 pts), Source URL (10 pts) — total 100 pts
- **Grades:** A (≥80), B (≥60), C (≥40), D (≥20), F (<20)
- **Issue list** with priority levels (high/medium/low) and contextual action buttons:
  - **Fix with AI** button — for title, description, and item specifics issues; AI fills them automatically, saves draft, and revises on eBay if active
  - **Go to ↓** button — for images, category, and source URL issues; smooth-scrolls to the relevant form section

### Smart Repricing (AI)

Competitor-aware pricing on the `/repricing` page:

- Lists all active eBay listings with current price
- **Check Competitors** — pulls live eBay search results for each listing (lowest total, result count, individual item breakdown)
- **AI Suggest Price** — Groq AI analyzes the competitive landscape and recommends an optimal price with a strategy badge (Undercut / Match / Premium / No change) and English reasoning
- **Apply Price** — one-click updates the draft and revises the live eBay listing

### AI Feedback Replies

German buyer communication assistant at `/feedback`:

- **4 message types:** Negative Feedback, Buyer Message, Return Request, Item Not Received
- **5 reply tones:** Apologetic, Offer Refund, Offer Replacement, Explanation (not our fault), Firm but Polite
- Paste the buyer's message → AI drafts a professional German reply
- One-click copy to clipboard
- Local history of the last 20 replies (localStorage)

### Source Price Monitor

Supplier price tracking at `/monitor`:

- Monitors listed source URLs (AliExpress, DHgate, etc.) for price changes
- When a supplier price changes, calculates a new suggested eBay price preserving the original markup ratio
- One-click **Apply Update** to update the eBay price
- Per-listing enable/disable toggle

### Sales Analytics Dashboard

- **KPI Cards:** 30-day revenue, number of orders, average order value
- **Revenue Chart:** Recharts line chart of daily revenue over 30 days
- **Top Listings:** Ranked by revenue contribution

### Sales Reports

- Custom date range picker
- View order line items, totals, item counts
- Download as a formatted PDF

### User Management

- Self-registration with email and password
- Secure login with 30-day session persistence
- Profile page: update name, email, phone, address
- Store settings: store name, business name, tax number, VAT number, store address

### eBay Integration

- One-click OAuth 2.0 connect from the Settings page
- Incremental sync — only fetches orders/listings modified since the last sync
- Automatic access token refresh; refresh token TTL ~180 days
- Supports both eBay Sandbox and Production environments

---

## 10. AI Integration

### Groq API

| Setting | Value |
|---|---|
| Provider | Groq (free tier) |
| Endpoint | `https://api.groq.com/openai/v1/chat/completions` |
| Default model | `llama-3.1-8b-instant` |
| Alternative model | `llama-3.3-70b-versatile` |
| Temperature | 0.3 (consistent structured output) |
| Max tokens | 256–2048 (varies by endpoint) |
| Rate limits (free) | 30 req/min, 14,400 req/day |

### ProductScraperService

Extracts product data from any URL using:
- **JSON-LD** structured data (`@type: Product`)
- **Open Graph** meta tags (`og:title`, `og:description`, `og:image`, `og:price:amount`)
- **HTML fallback** — price patterns, largest images, body text snippet

### AiListingService — Methods

| Method | What it does |
|---|---|
| `analyze(array $scrapedData)` | Full listing generation from scraped product data — German title, description, price, shipping, category, item specifics |
| `suggestSpecifics(...)` | Fills missing eBay item specifics (brand, color, material, etc.) from title + description |
| `improveTitle(...)` | Rewrites an existing title to be SEO-optimized and ≤80 chars |
| `improveDescription(...)` | Generates a professional German HTML description from existing content |
| `suggestPrice(...)` | Recommends an optimal price given current price, shipping, and live competitor data |
| `feedbackResponse(...)` | Drafts a professional German buyer reply for 4 message types and 5 tone options |
| `translate(...)` | Translates German title + description to English (preview only; eBay listing stays German) |

### Error Handling

All uncaught PHP exceptions are caught by a global `set_exception_handler` in `index.php` and returned as JSON `{"data": null, "meta": [], "error": "..."}` instead of raw HTML 500 pages.

### Google Translate Protection

`<html translate="no">` is set in the Next.js root layout to prevent the Google Translate browser extension from mutating text nodes that React manages, which would cause `insertBefore` DOM crashes.

---

## 11. Testing

### Frontend — Vitest

| Setting | Value |
|---|---|
| Test runner | Vitest 2 |
| Environment | jsdom |
| Setup file | `test/setup.tsx` |
| Coverage provider | v8 |
| Coverage reporters | text, lcov, html |
| Test scope | `lib/**`, `components/**` |

**Scripts:**
```bash
npm run test          # Watch mode
npm run test:run      # Single run
npm run test:ui       # Browser UI
npm run coverage      # Coverage report
```

### Backend — PHPUnit

| Setting | Value |
|---|---|
| Test runner | PHPUnit 11 |
| Test suites | Unit, Integration |
| Bootstrap | `tests/bootstrap.php` |
| Fixtures | `tests/fixtures/` |
| Test env | `APP_ENV=test`, `STORAGE_DRIVER=json`, `EBAY_SANDBOX=true` |

**Scripts:**
```bash
composer test                              # All tests
vendor/bin/phpunit --testsuite=Unit        # Unit only
vendor/bin/phpunit --testsuite=Integration # Integration only
vendor/bin/phpunit --coverage-text         # With coverage
```

---

## 12. Deployment & DevOps

### Docker

**Backend Dockerfile (multi-stage):**
- `base` — PHP 8.2 CLI + system dependencies + Composer
- `production` — `composer install --no-dev`, runs `php -S 0.0.0.0:8080`
- `test` — Full composer install, runs PHPUnit

**Frontend Dockerfile (multi-stage):**
- `deps` — `npm ci` (production deps)
- `builder` — Full install + `next build` (standalone output)
- `runner` — Node 20 Alpine, non-root user, runs `node server.js`

### Docker Compose

```yaml
services:
  backend:   port 8080, volume seller_data, healthcheck
  frontend:  port 3000, depends on backend (healthy)
```

**Start everything:**
```bash
docker compose up -d
```

### GitLab CI/CD

**Pipeline stages: `test` → `build`**

| Job | Trigger | Action |
|---|---|---|
| backend:unit | every push | PHP 8.2, PHPUnit --testsuite=Unit |
| backend:integration | every push | PHPUnit --testsuite=Integration |
| frontend:unit | every push | Node 20, Vitest --reporter=junit |
| backend:build | main/develop only | Docker build --target production, push to GitLab registry |
| frontend:build | main/develop only | Docker build --target runner, push to GitLab registry |

**Caching:** Composer vendor cache and npm node_modules cache across pipeline runs.

### Nginx (Production Reverse Proxy)

```
/        → Next.js (port 3000)
/api/    → PHP backend (port 8080)
```

Supports HTTPS with Let's Encrypt. Sync routes have a 120-second proxy timeout.

---

## 13. Environment Variables

### Backend (`backend/.env`)

```env
# Application
APP_ENV=development
APP_URL=http://localhost:8080
FRONTEND_URL=http://localhost:3000
STORAGE_DRIVER=json

# API Security
API_KEY=<32-byte hex string>
ENCRYPTION_KEY=<exactly 32 characters>

# eBay Developer Account
EBAY_CLIENT_ID=...
EBAY_CLIENT_SECRET=...
EBAY_DEV_ID=...
EBAY_SITE_ID=77
EBAY_REDIRECT_URI=<RuName string from eBay developer console>
EBAY_SCOPES=sell.fulfillment.readonly sell.inventory commerce.identity.readonly
EBAY_SANDBOX=true

# eBay Endpoints (Sandbox)
EBAY_SANDBOX_AUTH_URL=https://auth.sandbox.ebay.com/oauth2/authorize
EBAY_SANDBOX_TOKEN_URL=https://api.sandbox.ebay.com/identity/v1/oauth2/token
EBAY_SANDBOX_API_URL=https://api.sandbox.ebay.com

# eBay Endpoints (Production)
EBAY_PROD_AUTH_URL=https://auth.ebay.com/oauth2/authorize
EBAY_PROD_TOKEN_URL=https://api.ebay.com/identity/v1/oauth2/token
EBAY_PROD_API_URL=https://api.ebay.com

# Groq AI (free tier)
GROQ_API_KEY=<your free Groq API key>
GROQ_MODEL=llama-3.1-8b-instant
```

### Frontend (`frontend/.env.local`)

```env
NEXT_PUBLIC_API_BASE_URL=http://localhost:8080
NEXT_PUBLIC_API_KEY=<must match backend API_KEY>
```

---

## 14. Data Models

### Order

```
id                  string
ebay_order_id       string
status              PAID | SHIPPED | DELIVERED | CANCELLED
buyer
  username          string
  email             string
  shipping_address
    name            string
    line1           string
    line2           string
    city            string
    state           string
    postal_code     string
    country_code    string (e.g. GB)
line_items[]
  ebay_item_id      string
  title             string
  sku               string
  quantity          int
  unit_price        float
  total_price       float
payment
  method            string
  status            string
  amount            float
  paid_at           ISO 8601 datetime
shipping
  service           string
  cost              float
  tracking_number   string
  shipped_at        ISO 8601 datetime | null
  delivered_at      ISO 8601 datetime | null
totals
  subtotal          float
  shipping          float
  grand_total       float
notes               string
created_at          ISO 8601 datetime
updated_at          ISO 8601 datetime
synced_at           ISO 8601 datetime
```

### Listing

```
id                  string
ebay_item_id        string
title               string
sku                 string
status              ACTIVE | ENDED | OUT_OF_STOCK | DRAFT
category
  ebay_category_id  string
  name              string
price
  value             float
  currency          string (e.g. GBP)
quantity
  available         int
  sold              int
images[]            string (URLs)
condition           string (e.g. New, Used)
description_snippet string
listing_url         string
listed_at           ISO 8601 datetime
ends_at             ISO 8601 datetime | null
synced_at           ISO 8601 datetime
```

### User

```
id                  string
email               string
full_name           string
password_hash       string (bcrypt)
role                string
status              string
created_at          ISO 8601 datetime
updated_at          ISO 8601 datetime
last_login_at       ISO 8601 datetime | null
```

### Profile

```
user_id             string
full_name           string
email               string
phone               string
address
  line1             string
  line2             string
  city              string
  state             string
  postal_code       string
  country           string
avatar_url          string | null
store
  name              string
  phone             string
  email             string
  address           string
  description       string
  business_name     string
  tax_number        string
  vat_number        string
created_at          ISO 8601 datetime
updated_at          ISO 8601 datetime
```

---

## 15. Roadmap

### AI Features (in progress)

| # | Feature | Status |
|---|---|---|
| 1 | Listing Health Score | ✅ Done |
| 2 | AI Feedback Replies | ✅ Done |
| 3 | Smart Repricing | ✅ Done |
| 4 | Product Sourcing Scout | Planned next |
| 5 | Bulk Listing (multi-URL import) | Planned |
| 6 | Demand Forecasting | Planned |
| 7 | CJ API Integration | Planned (last) |

### Platform (V2)

| Feature | Status |
|---|---|
| MySQL / PostgreSQL storage (swap `STORAGE_DRIVER=database`) | Planned |
| eBay Webhooks for real-time order/listing notifications | Planned |
| Automatic background sync (cron job) | Planned |
| Bulk shipping label creation | Planned |
| Inventory low-stock alerts | Planned |
| Multi-currency support | Planned |
| REST API versioning (`/api/v1/`, `/api/v2/`) | Planned |
