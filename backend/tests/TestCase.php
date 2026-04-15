<?php

declare(strict_types=1);

namespace Tests;

use App\Helpers\Response;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase
{
    protected string $fixturesDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset response capture between tests
        Response::reset();

        // Reset superglobals
        $_GET    = [];
        $_POST   = [];
        $_SERVER = array_merge($_SERVER, [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI'    => '/',
            'HTTP_X_API_KEY' => 'test-api-key',
        ]);

        $this->fixturesDir = __DIR__ . '/fixtures';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Response::reset();
    }

    /**
     * Create a temporary directory for isolated storage tests.
     * Must be cleaned up with removeDir() in tearDown.
     */
    protected function createTempDir(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sellerapp-test-' . uniqid('', true);
        mkdir($dir, 0755, true);
        return $dir;
    }

    /**
     * Recursively remove a directory and all its contents.
     */
    protected function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($dir);
    }

    /**
     * Seed a JSON entity file into a temp directory.
     * Creates the subdirectory automatically.
     */
    protected function seedJson(string $tempDir, string $entityDir, string $filename, array $data): void
    {
        $dir = $tempDir . DIRECTORY_SEPARATOR . $entityDir;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dir . DIRECTORY_SEPARATOR . $filename, json_encode($data, JSON_PRETTY_PRINT));
    }
}
