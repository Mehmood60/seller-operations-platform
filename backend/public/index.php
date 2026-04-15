<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Helpers\Response;
use App\Kernel;

// ── Load .env ────────────────────────────────────────────────────────────────
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $vars = parse_ini_file($envFile, false, INI_SCANNER_RAW);
    if (is_array($vars)) {
        foreach ($vars as $key => $value) {
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

// ── CORS ─────────────────────────────────────────────────────────────────────
$allowedOrigin = $_ENV['FRONTEND_URL'] ?? 'http://localhost:3000';
header('Access-Control-Allow-Origin: ' . $allowedOrigin);
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── API Key middleware ────────────────────────────────────────────────────────
// Public routes that bypass key validation:
//   /api/auth/ebay/callback — eBay redirects here; we have no control over headers
//   /api/auth/ebay/connect  — browser navigation (window.location); headers not sent
$requestUri   = $_SERVER['REQUEST_URI'] ?? '/';
$publicRoutes = [
    '/api/auth/ebay/callback',
    '/api/auth/ebay/connect',
];
$isPublic = false;
foreach ($publicRoutes as $route) {
    if (str_contains($requestUri, $route)) {
        $isPublic = true;
        break;
    }
}

if (!$isPublic) {
    $apiKey = $_ENV['API_KEY'] ?? '';

    // Accept key from header (all fetch requests) or ?key= query param
    // (used for direct browser links: invoice PDFs, sales report PDFs).
    $sentKey = $_SERVER['HTTP_X_API_KEY']
        ?? $_GET['key']
        ?? '';

    if (!empty($apiKey) && $sentKey !== $apiKey) {
        Response::error('Unauthorized', 401);
    }
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────
// DATA_DIR can be overridden via env for tests or custom setups
$dataDir     = $_ENV['DATA_DIR'] ?? __DIR__ . '/../data';
$templateDir = __DIR__ . '/../templates';

$kernel = new Kernel($dataDir, $templateDir);
$kernel->handle($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
