<?php

declare(strict_types=1);

namespace App\Helpers;

class Response
{
    private static bool $testMode       = false;
    private static ?array $captured     = null;
    private static int $capturedStatus  = 200;

    // ── Test mode controls ────────────────────────────────────────────────────

    public static function enableTestMode(): void
    {
        self::$testMode = true;
    }

    public static function reset(): void
    {
        self::$captured       = null;
        self::$capturedStatus = 200;
    }

    public static function getCaptured(): ?array
    {
        return self::$captured;
    }

    public static function getCapturedStatus(): int
    {
        return self::$capturedStatus;
    }

    // ── Response methods ──────────────────────────────────────────────────────

    public static function json(mixed $data, array $meta = [], int $status = 200): void
    {
        $body = [
            'data'  => $data,
            'meta'  => $meta,
            'error' => null,
        ];

        if (self::$testMode) {
            self::$captured       = $body;
            self::$capturedStatus = $status;
            throw new ResponseSentException('Response sent (test mode)');
        }

        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function error(string $message, int $status = 400): void
    {
        $body = [
            'data'  => null,
            'meta'  => [],
            'error' => $message,
        ];

        if (self::$testMode) {
            self::$captured       = $body;
            self::$capturedStatus = $status;
            throw new ResponseSentException('Response sent (test mode)');
        }

        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($body, JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function stream(string $content, string $filename, string $mimeType = 'application/pdf'): void
    {
        if (self::$testMode) {
            self::$captured       = ['stream' => true, 'filename' => $filename, 'length' => strlen($content)];
            self::$capturedStatus = 200;
            throw new ResponseSentException('Response sent (test mode)');
        }

        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo $content;
        exit;
    }

    public static function redirect(string $url): void
    {
        if (self::$testMode) {
            self::$captured       = ['redirect' => $url];
            self::$capturedStatus = 302;
            throw new ResponseSentException('Response sent (test mode)');
        }

        header('Location: ' . $url, true, 302);
        exit;
    }
}
