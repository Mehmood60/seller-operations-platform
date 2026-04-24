# eBay Seller Operations Platform

A self-hosted seller dashboard for managing your eBay shop. Syncs orders and listings directly from the eBay API, generates professional invoices and sales reports as PDFs, displays analytics, and uses AI (Groq) to automate listing creation and operations — all from a single interface you control.

> **Language policy:** The app UI is in English. Listing content (titles, descriptions, item specifics) is generated and published in German for the eBay.de marketplace.

---

## Table of Contents

- [Project Overview](#project-overview)
- [Tech Stack](#tech-stack)
- [Project Structure](#project-structure)
- [Prerequisites](#prerequisites)
- [Backend Setup](#backend-setup)
- [Frontend Setup](#frontend-setup)
- [Nginx Setup](#nginx-setup)
- [Running the Project](#running-the-project)
- [Environment Variables](#environment-variables)
- [User Authentication](#user-authentication)
- [API Reference](#api-reference)
- [GitHub & Version Control](#github--version-control)
- [Common Issues & Troubleshooting](#common-issues--troubleshooting)
- [Migrating to MySQL / PostgreSQL (V2)](#migrating-to-mysql--postgresql-v2)

---

## Project Overview

This platform connects to your eBay seller account via OAuth 2.0 and pulls real-time data into a local dashboard. Key features:

- **Order management** — view, filter, and search all orders; download invoices as PDFs; record AliExpress fulfilment and push tracking to buyers
- **Listing management** — browse active/ended listings, create AI-assisted drafts, edit and publish to eBay
- **Listing Health Score** — rule-based quality scoring (100 pts, grades A–F) with AI fix buttons for title, description, and item specifics
- **Smart Repricing** — check live eBay competitor prices per listing and get AI-powered price recommendations
- **AI Feedback Replies** — paste a buyer message and get a professional German reply drafted by AI (4 message types, 5 tones)
- **Source Price Monitor** — track supplier prices and get suggested eBay price updates when costs change
- **Sales analytics** — 30-day dashboard with revenue, order volume, and top sellers
- **PDF reports** — downloadable sales reports with date range filtering
- **User accounts** — register/login with session-based authentication
- **Encrypted storage** — eBay OAuth tokens encrypted at rest (AES-256-CBC)
- **V2-ready storage** — swap JSON files for MySQL/PostgreSQL with one config change

---

## Tech Stack

| Layer | Technology |
|---|---|
| Frontend | Next.js 14 (App Router), React 18, TypeScript |
| Styling | Tailwind CSS 3.4 |
| Charts | Recharts |
| Icons | Lucide React |
| Backend | PHP 8.2+ (custom lightweight router, no framework) |
| HTTP Client | Guzzle 7.8 |
| PDF Generation | DomPDF 2.0 |
| AI | Groq API (LLaMA 3.1-8b-instant) |
| Storage (V1) | JSON flat files (`backend/data/`) |
| Storage (V2) | MySQL / PostgreSQL (migration path built in) |
| Auth | eBay OAuth 2.0 + session-based user auth |
| Package Managers | npm (frontend), Composer (backend) |
| Container | Docker + Docker Compose (optional) |

---

## Project Structure

```
sellerapp/
├── frontend/               Next.js app (port 3000)
│   ├── src/app/            App Router pages
│   ├── src/components/     UI components
│   ├── .env.example        Frontend env template
│   └── package.json
├── backend/                PHP REST API (port 8080)
│   ├── public/             Web root — index.php (entry point)
│   ├── src/
│   │   ├── Controllers/    Route handlers
│   │   ├── Services/       Business logic
│   │   ├── Storage/
│   │   │   ├── Contracts/  RepositoryInterface (migration seam)
│   │   │   ├── Json/       V1 JSON implementation
│   │   │   └── Database/   V2 stub
│   │   ├── eBay/           eBay API client + mappers
│   │   ├── PDF/            Invoice + report generators
│   │   └── Helpers/
│   ├── data/               JSON storage (git-ignored, auto-created)
│   ├── templates/          HTML templates for PDFs
│   ├── .env.example        Backend env template
│   └── composer.json
├── docker-compose.yml      Docker setup (optional)
├── .gitignore
└── README.md
```

---

## Prerequisites

Before starting, ensure the following are installed:

| Requirement | Version | Check |
|---|---|---|
| PHP | 8.2+ | `php --version` |
| Composer | Latest | `composer --version` |
| Node.js | 18+ | `node --version` |
| npm | 9+ | `npm --version` |
| OpenSSL | Any | `openssl version` |

**PHP extensions required:** `openssl`, `fileinfo`, `json`, `mbstring`

**eBay Developer account:** Free registration at [developer.ebay.com](https://developer.ebay.com)

---

## Backend Setup

### Step 1 — Install PHP dependencies

```bash
cd backend
composer install
```

### Step 2 — Create the environment file

```bash
cp .env.example .env
```

### Step 3 — Generate security keys

Run these commands and copy the outputs into your `.env` files:

```bash
# Shared API key — paste into BOTH backend/.env (API_KEY) and frontend/.env.local (NEXT_PUBLIC_API_KEY)
openssl rand -hex 32

# Token encryption key — paste into backend/.env (ENCRYPTION_KEY), must be exactly 32 chars
openssl rand -hex 16
```

### Step 4 — Configure backend/.env

Open `backend/.env` and fill in:

| Variable | Where to get it |
|---|---|
| `API_KEY` | Generated above (openssl rand -hex 32) |
| `ENCRYPTION_KEY` | Generated above (openssl rand -hex 16) |
| `EBAY_CLIENT_ID` | developer.ebay.com → App Keys → your keyset |
| `EBAY_CLIENT_SECRET` | Same location as Client ID |
| `EBAY_REDIRECT_URI` | The **RuName** string from eBay portal (see Step 5) |
| `EBAY_SANDBOX` | `true` for development, `false` for production |

### Step 5 — Register your OAuth callback in eBay Developer Portal

1. Log in to [developer.ebay.com](https://developer.ebay.com)
2. Navigate to **My Account** → **Application Keys** → select your app → **OAuth**
3. Under **User Tokens**, click **Add eBay Redirect URL**
4. Set the redirect URL to: `http://localhost:8080/api/auth/ebay/callback`
5. eBay generates a **RuName** string (e.g., `YourName-AppName-SBX-a1b2c3`)
6. Paste the RuName (not the URL) into `EBAY_REDIRECT_URI` in your `.env`

> **Production OAuth:** eBay requires HTTPS for production. Use ngrok during development:
> ```bash
> ngrok http 8080
> # Then set EBAY_REDIRECT_URI to: https://your-ngrok-url.ngrok.io/api/auth/ebay/callback
> ```

### Step 6 — Start the PHP development server

```bash
cd backend
php -S localhost:8080 -t public
```

The backend API is now available at `http://localhost:8080`

---

## Frontend Setup

### Step 1 — Install dependencies

```bash
cd frontend
npm install
```

### Step 2 — Create the environment file

```bash
cp .env.example .env.local
```

### Step 3 — Configure frontend/.env.local

```env
NEXT_PUBLIC_API_BASE_URL=http://localhost:8080
NEXT_PUBLIC_API_KEY=<same value as API_KEY in backend/.env>
```

### Step 4 — Start the development server

```bash
npm run dev
```

Open [http://localhost:3000](http://localhost:3000)

### Step 5 — Build for production

```bash
npm run build
npm run start
```

---

## Nginx Setup

Nginx is the recommended way to serve this project in production. It acts as a reverse proxy, routing frontend and API traffic to the correct server.

### Install Nginx

**Ubuntu / Debian:**
```bash
sudo apt update
sudo apt install nginx -y
```

**CentOS / RHEL:**
```bash
sudo yum install nginx -y
sudo systemctl enable nginx
```

### Option A — Reverse Proxy (recommended for production)

This setup puts Nginx in front of both the Next.js app and the PHP backend on a single domain.

Create a new config file:

```bash
sudo nano /etc/nginx/sites-available/sellerapp
```

Paste the following (replace `your-domain.com` with your actual domain or server IP):

```nginx
server {
    listen 80;
    server_name your-domain.com;

    # ── Frontend (Next.js) ────────────────────────────────────────────
    location / {
        proxy_pass         http://localhost:3000;
        proxy_http_version 1.1;
        proxy_set_header   Upgrade $http_upgrade;
        proxy_set_header   Connection 'upgrade';
        proxy_set_header   Host $host;
        proxy_set_header   X-Real-IP $remote_addr;
        proxy_set_header   X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
        proxy_cache_bypass $http_upgrade;
    }

    # ── Backend PHP API ───────────────────────────────────────────────
    location /api {
        proxy_pass         http://localhost:8080;
        proxy_http_version 1.1;
        proxy_set_header   Host $host;
        proxy_set_header   X-Real-IP $remote_addr;
        proxy_set_header   X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;

        # Allow larger uploads / long sync requests
        proxy_read_timeout 120s;
        client_max_body_size 10M;
    }
}
```

Enable the site and reload:

```bash
sudo ln -s /etc/nginx/sites-available/sellerapp /etc/nginx/sites-enabled/
sudo nginx -t          # test config — must say "syntax is ok"
sudo systemctl reload nginx
```

### Option B — PHP-FPM Direct Serve (advanced)

If you want Nginx to serve PHP directly (no `php -S`), install PHP-FPM:

```bash
sudo apt install php8.2-fpm -y
```

Create a site config:

```nginx
server {
    listen 8080;
    root /var/www/sellerapp/backend/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass   unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index  index.php;
        fastcgi_param  SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include        fastcgi_params;
    }

    location ~ /\.(env|git) {
        deny all;
    }
}
```

### Nginx commands reference

```bash
sudo nginx -t                    # Test configuration syntax
sudo systemctl reload nginx      # Apply config changes (no downtime)
sudo systemctl restart nginx     # Full restart
sudo systemctl status nginx      # Check if running
sudo tail -f /var/log/nginx/error.log   # View errors
```

### HTTPS with Let's Encrypt (optional but recommended)

```bash
sudo apt install certbot python3-certbot-nginx -y
sudo certbot --nginx -d your-domain.com
sudo systemctl reload nginx
```

---

## Running the Project

### Local development (without Docker)

Open two terminals:

**Terminal 1 — Backend:**
```bash
cd backend
php -S localhost:8080 -t public
```

**Terminal 2 — Frontend:**
```bash
cd frontend
npm run dev
```

| Service | URL |
|---|---|
| Frontend (dashboard) | http://localhost:3000 |
| Backend API | http://localhost:8080 |
| API health check | http://localhost:8080/api/sync/status |

### With Docker Compose

```bash
# Start all services
docker compose up -d

# View logs
docker compose logs -f

# Stop all services
docker compose down
```

### First-run checklist

After both servers are running:

- [ ] `composer install` done in `backend/`
- [ ] `backend/.env` filled with eBay credentials and generated keys
- [ ] `ENCRYPTION_KEY` is exactly 32 characters and changed from the default
- [ ] `API_KEY` matches in both `backend/.env` and `frontend/.env.local`
- [ ] eBay RuName registered in Developer Portal
- [ ] `npm install` done in `frontend/`
- [ ] Visit `http://localhost:3000/settings` → **Connect eBay Account**
- [ ] Trigger a sync from Orders or Listings pages

---

## Environment Variables

### backend/.env

| Variable | Required | Example | Description |
|---|---|---|---|
| `APP_ENV` | Yes | `development` | Environment mode |
| `APP_URL` | Yes | `http://localhost:8080` | Backend base URL |
| `FRONTEND_URL` | Yes | `http://localhost:3000` | Frontend URL for CORS |
| `STORAGE_DRIVER` | Yes | `json` | Storage driver: `json` or `database` |
| `API_KEY` | **Yes** | *(32-byte hex)* | Shared secret with frontend — generate: `openssl rand -hex 32` |
| `ENCRYPTION_KEY` | **Yes** | *(32 chars)* | AES-256-CBC key for eBay token encryption — generate: `openssl rand -hex 16` |
| `EBAY_CLIENT_ID` | **Yes** | `YourApp-SBX-...` | From eBay Developer Portal keyset |
| `EBAY_CLIENT_SECRET` | **Yes** | `SBX-...` | From eBay Developer Portal keyset |
| `EBAY_REDIRECT_URI` | **Yes** | `YourName-App-SBX-a1b2c` | **RuName** from eBay OAuth settings (not the raw URL) |
| `EBAY_SCOPES` | Yes | *(see .env.example)* | Space-separated OAuth scopes |
| `EBAY_SANDBOX` | Yes | `true` | `true` = sandbox, `false` = production |
| `GROQ_API_KEY` | **Yes** | `gsk_...` | Free key from [console.groq.com](https://console.groq.com) — required for all AI features |
| `GROQ_MODEL` | No | `llama-3.1-8b-instant` | Groq model ID (default is free-tier fast model) |
| `EBAY_SANDBOX_AUTH_URL` | Yes | *(see .env.example)* | eBay sandbox auth endpoint |
| `EBAY_SANDBOX_TOKEN_URL` | Yes | *(see .env.example)* | eBay sandbox token endpoint |
| `EBAY_SANDBOX_API_URL` | Yes | *(see .env.example)* | eBay sandbox API base URL |
| `EBAY_PROD_AUTH_URL` | Yes | *(see .env.example)* | eBay production auth endpoint |
| `EBAY_PROD_TOKEN_URL` | Yes | *(see .env.example)* | eBay production token endpoint |
| `EBAY_PROD_API_URL` | Yes | *(see .env.example)* | eBay production API base URL |

### frontend/.env.local

| Variable | Required | Description |
|---|---|---|
| `NEXT_PUBLIC_API_BASE_URL` | Yes | Backend URL — must match `APP_URL` in backend |
| `NEXT_PUBLIC_API_KEY` | Yes | Must exactly match `API_KEY` in `backend/.env` |

> **Security:** Never commit `.env` or `.env.local`. The `.gitignore` already excludes them.  
> **Always regenerate** `API_KEY` and `ENCRYPTION_KEY` before exposing the app to any network.

---

## User Authentication

The platform includes session-based user authentication:

- **Register:** `POST /api/users/register` with `{ email, password, name }`
- **Login:** `POST /api/users/login` with `{ email, password }`
- **Logout:** `POST /api/users/logout`
- **Sessions:** Bearer token in `Authorization` header, 30-day TTL
- **Passwords:** bcrypt with cost 12

Frontend routes: `/login`, `/register`, `/profile`

---

## API Reference

All requests require the `X-API-Key` header (except public OAuth routes).  
All responses use the envelope format:

```json
{ "data": {}, "meta": { "page": 1, "total": 100 }, "error": null }
```

### Auth (eBay OAuth)
| Method | Path | Auth Required | Description |
|---|---|---|---|
| `GET` | `/api/auth/ebay` | Yes | Connection status |
| `GET` | `/api/auth/ebay/connect` | No | Redirect to eBay OAuth |
| `GET` | `/api/auth/ebay/callback` | No | OAuth callback handler |
| `DELETE` | `/api/auth/ebay` | Yes | Disconnect account |

### User Auth
| Method | Path | Description |
|---|---|---|
| `POST` | `/api/users/register` | Create account |
| `POST` | `/api/users/login` | Get session token |
| `POST` | `/api/users/logout` | Invalidate session |

### Sync
| Method | Path | Description |
|---|---|---|
| `POST` | `/api/sync/orders` | Pull new/updated orders from eBay |
| `POST` | `/api/sync/listings` | Pull listings from eBay |
| `GET` | `/api/sync/status` | Last sync timestamps |

### Orders
| Method | Path | Description |
|---|---|---|
| `GET` | `/api/orders` | List (`?page=1&limit=25&status=PAID&search=`) |
| `GET` | `/api/orders/{id}` | Order detail |
| `GET` | `/api/orders/{id}/invoice` | Download invoice PDF |

### Listings
| Method | Path | Description |
|---|---|---|
| `GET` | `/api/listings` | List (`?status=ACTIVE&search=&page=1&limit=50`) |
| `GET` | `/api/listings/{id}` | Listing detail |
| `POST` | `/api/listings` | Create new draft |
| `PUT` | `/api/listings/{id}` | Update draft |
| `DELETE` | `/api/listings/{id}` | Delete draft |
| `POST` | `/api/listings/{id}/publish` | Publish draft to eBay |
| `POST` | `/api/listings/{id}/revise` | Revise live eBay listing |
| `GET` | `/api/listings/{id}/health` | Quality score (grade A–F, 6 dimensions) |
| `GET` | `/api/listings/category-suggest?q=` | eBay category suggestions |
| `POST` | `/api/listings/{id}/check-competitors` | Live competitor price check |
| `POST` | `/api/listings/check-all-competitors` | Batch competitor check |

### Price Monitor
| Method | Path | Description |
|---|---|---|
| `GET` | `/api/monitor` | Monitor status for all listings |
| `POST` | `/api/monitor/check-all` | Re-check all source prices |
| `POST` | `/api/monitor/{id}/check` | Re-check one source price |
| `POST` | `/api/monitor/{id}/toggle` | Enable / disable monitoring |
| `POST` | `/api/monitor/{id}/apply` | Apply pending price update |

### AI
| Method | Path | Description |
|---|---|---|
| `POST` | `/api/ai/analyze` | Scrape URL + generate full listing |
| `POST` | `/api/ai/translate` | German → English preview translation |
| `POST` | `/api/ai/suggest-specifics` | Fill missing item specifics |
| `POST` | `/api/ai/improve-listing` | Improve title or description (`aspect: title\|description`) |
| `POST` | `/api/ai/suggest-price` | AI price recommendation from competitor data |
| `POST` | `/api/ai/feedback-response` | Generate German buyer reply |

### Dashboard & Reports
| Method | Path | Description |
|---|---|---|
| `GET` | `/api/dashboard` | Analytics summary (30-day) |
| `GET` | `/api/reports/sales` | Sales data (`?from=2026-01-01&to=2026-04-15`) |
| `GET` | `/api/reports/sales/pdf` | Download sales report PDF |

---

## GitHub & Version Control

### If the repository does NOT exist yet

1. Create a new repository on [github.com/new](https://github.com/new)  
   - Name: `sellerapp` (or your preferred name)
   - Visibility: Private (recommended — contains OAuth credentials)
   - Do **not** initialize with README or .gitignore (we already have them)

2. Initialize and push:

```bash
# Navigate to project root
cd "d:/personal_mehmood/Onlinestore app/sellerapp"

# Initialize git
git init

# Stage all files (respects .gitignore)
git add .

# First commit
git commit -m "Initial commit — eBay Seller Operations Platform V1"

# Set main branch
git branch -M main

# Connect to GitHub (replace with your actual repo URL)
git remote add origin https://github.com/YOUR_USERNAME/sellerapp.git

# Push
git push -u origin main
```

### If the repository already exists

```bash
cd "d:/personal_mehmood/Onlinestore app/sellerapp"

# Connect remote (if not already connected)
git remote add origin https://github.com/YOUR_USERNAME/sellerapp.git

# If you get "remote already exists":
git remote set-url origin https://github.com/YOUR_USERNAME/sellerapp.git

# Push
git branch -M main
git push -u origin main
```

### Authentication

GitHub no longer accepts passwords. Use one of:

- **Personal Access Token (PAT):** GitHub → Settings → Developer settings → Personal access tokens → Generate new token  
  Use the token as your password when prompted.
- **SSH key:**
  ```bash
  ssh-keygen -t ed25519 -C "your-email@example.com"
  # Add ~/.ssh/id_ed25519.pub to GitHub → Settings → SSH keys
  # Then use: git remote add origin git@github.com:YOUR_USERNAME/sellerapp.git
  ```

### Handling large files

If push fails due to large files:

```bash
# Find files over 50MB
find . -size +50M -not -path './.git/*'

# Add to .gitignore if needed, then:
git rm --cached path/to/large-file
git commit -m "Remove large file from tracking"
```

---

## Common Issues & Troubleshooting

### Port 3000 or 8080 already in use

```bash
# Find what's using the port (Linux/Mac)
lsof -i :3000
lsof -i :8080

# Kill the process
kill -9 <PID>

# Windows
netstat -ano | findstr :3000
taskkill /PID <PID> /F
```

### composer install fails

```bash
# Check PHP version (must be 8.2+)
php --version

# Check required extensions
php -m | grep -E "openssl|fileinfo|json|mbstring"

# Install missing extensions (Ubuntu)
sudo apt install php8.2-openssl php8.2-fileinfo php8.2-mbstring
```

### npm install fails

```bash
# Check Node version (must be 18+)
node --version

# Clear cache and retry
npm cache clean --force
npm install
```

### API returns 401 Unauthorized

- Verify `API_KEY` in `backend/.env` matches `NEXT_PUBLIC_API_KEY` in `frontend/.env.local`
- Ensure the header `X-API-Key` is being sent (check browser DevTools → Network tab)

### eBay OAuth callback fails

Common causes:

1. **RuName mismatch:** `EBAY_REDIRECT_URI` must be the RuName string, not the raw URL
2. **Sandbox vs production:** Ensure `EBAY_SANDBOX=true` if testing in sandbox
3. **HTTPS required for production:** eBay production OAuth requires HTTPS
4. **ngrok URL changed:** Each `ngrok http 8080` session generates a new URL — update your eBay portal and `.env` each time
5. **Callback URL not registered:** The actual URL `http://localhost:8080/api/auth/ebay/callback` must be registered in eBay portal

### eBay OAuth with ngrok step-by-step

```bash
# 1. Install ngrok: https://ngrok.com/download
# 2. Start tunnel
ngrok http 8080

# 3. Copy the https URL (e.g., https://abc123.ngrok.io)
# 4. In eBay Developer Portal, update redirect URL to:
#    https://abc123.ngrok.io/api/auth/ebay/callback
# 5. Copy the new RuName into backend/.env EBAY_REDIRECT_URI
# 6. Restart the PHP server
```

### backend/data/ directory missing or permission denied

The `data/` directory is created automatically on first run. If you see permission errors:

```bash
mkdir -p backend/data
chmod 755 backend/data   # Linux/Mac
```

### PHP server shows 500 errors

```bash
# Check PHP error log
tail -f /var/log/php_errors.log

# Or run with errors visible
php -S localhost:8080 -t public 2>&1
```

### Docker Compose issues

```bash
# Rebuild containers after code changes
docker compose up --build -d

# View container logs
docker compose logs backend
docker compose logs frontend

# Reset everything (WARNING: deletes data volume)
docker compose down -v
```

---

## Migrating to MySQL / PostgreSQL (V2)

The storage layer is fully abstracted. Migration requires zero changes to Controllers, Services, or the frontend.

1. Add database credentials to `backend/.env`
2. Set `STORAGE_DRIVER=database`
3. Implement `DatabaseOrderRepository` and `DatabaseListingRepository` extending `DatabaseRepository` base class — same `RepositoryInterface` contract as the JSON implementations
4. Run a one-time migration script: read all `backend/data/**/*.json` → insert via the new repositories
5. Swap the concrete classes in `backend/public/index.php`

---

## Security Notes

- All API routes require `X-API-Key` header (shared secret)
- Two routes are intentionally public (no key):
  - `GET /api/auth/ebay/connect` — browser navigation to start OAuth
  - `GET /api/auth/ebay/callback` — eBay redirects here; headers cannot be set by browser
- Invoice and report PDF download links pass the key as `?key=` query parameter (browser navigation)
- eBay tokens are encrypted at rest using AES-256-CBC with your `ENCRYPTION_KEY`
- User passwords are hashed with bcrypt (cost 12)
- Session tokens are 64-char random hex with 30-day TTL
- `backend/data/`, `backend/.env`, and `frontend/.env.local` are never committed

---

## AI Features Roadmap

| # | Feature | Status |
|---|---|---|
| 1 | Listing Health Score | ✅ Done |
| 2 | AI Feedback Replies | ✅ Done |
| 3 | Smart Repricing | ✅ Done |
| 4 | Product Sourcing Scout | Planned next |
| 5 | Bulk Listing (multi-URL import) | Planned |
| 6 | Demand Forecasting | Planned |
| 7 | CJ API Integration | Planned (last) |

## Platform V2 Roadmap

- MySQL / PostgreSQL storage driver
- eBay Webhooks for real-time order notifications
- Automatic background sync (cron)
- Bulk shipping label creation
- Inventory low-stock alerts
- Multi-currency support
- REST API versioning (`/api/v1/`)
