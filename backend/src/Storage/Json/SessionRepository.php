<?php

declare(strict_types=1);

namespace App\Storage\Json;

/**
 * Manages user session tokens.
 *
 * Each session is stored as data/sessions/{token}.json
 * No index needed — O(1) lookup by token (filename).
 */
class SessionRepository
{
    private string $sessionDir;

    public function __construct(string $dataDir)
    {
        $this->sessionDir = rtrim($dataDir, '/\\') . DIRECTORY_SEPARATOR . 'sessions';

        if (!is_dir($this->sessionDir)) {
            mkdir($this->sessionDir, 0755, true);
        }
    }

    public function save(array $session): void
    {
        $file = $this->sessionFile($session['token']);
        $this->writeJson($file, $session);
    }

    public function findByToken(string $token): ?array
    {
        $file = $this->sessionFile($token);

        if (!file_exists($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    public function deleteByToken(string $token): void
    {
        $file = $this->sessionFile($token);

        if (file_exists($file)) {
            unlink($file);
        }
    }

    /** Remove sessions that have passed their expires_at timestamp. */
    public function cleanExpired(): int
    {
        $removed = 0;
        $files   = glob($this->sessionDir . DIRECTORY_SEPARATOR . '*.json') ?: [];

        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true);

            if (!is_array($data)) {
                unlink($file);
                $removed++;
                continue;
            }

            if (isset($data['expires_at']) && strtotime($data['expires_at']) < time()) {
                unlink($file);
                $removed++;
            }
        }

        return $removed;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function sessionFile(string $token): string
    {
        // Sanitise: only allow hex characters (our tokens are bin2hex output)
        $safe = preg_replace('/[^a-f0-9]/i', '', $token);
        return $this->sessionDir . DIRECTORY_SEPARATOR . $safe . '.json';
    }

    private function writeJson(string $file, array $data): void
    {
        $fp = fopen($file, 'c');
        if ($fp === false) {
            throw new \RuntimeException('Cannot open session file for writing: ' . $file);
        }

        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
