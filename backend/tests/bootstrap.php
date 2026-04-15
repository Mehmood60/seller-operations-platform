<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Helpers\Response;

// Set test environment variables
$_ENV['APP_ENV']        = 'test';
$_ENV['ENCRYPTION_KEY'] = 'test-32-char-key-change-me!!xx';
$_ENV['API_KEY']        = 'test-api-key';
$_ENV['EBAY_SANDBOX']   = 'true';
$_ENV['FRONTEND_URL']   = 'http://localhost:3000';

// Enable test mode — Response methods capture instead of exit()
Response::enableTestMode();

// Initialise a clean $_SERVER superglobal for tests
$_SERVER = array_merge($_SERVER, [
    'REQUEST_METHOD' => 'GET',
    'REQUEST_URI'    => '/',
    'HTTP_X_API_KEY' => 'test-api-key',
    'REMOTE_ADDR'    => '127.0.0.1',
]);
