<?php

declare(strict_types=1);

namespace App\Storage\Json;

/**
 * Stores one profile per user.
 *
 * File layout: data/profiles/{userId}.json
 * Keyed by user_id — no separate index needed.
 */
class ProfileRepository
{
    private string $profileDir;

    public function __construct(string $dataDir)
    {
        $this->profileDir = rtrim($dataDir, '/\\') . DIRECTORY_SEPARATOR . 'profiles';

        if (!is_dir($this->profileDir)) {
            mkdir($this->profileDir, 0755, true);
        }
    }

    /**
     * Return the first profile found on disk.
     * Used by invoice generation, which runs in a browser context (no Bearer token)
     * and therefore cannot resolve the current user — safe for single-seller setups.
     */
    public function findFirst(): ?array
    {
        $files = glob($this->profileDir . DIRECTORY_SEPARATOR . '*.json');
        if (empty($files)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($files[0]), true);
        return is_array($data) ? $data : null;
    }

    public function findByUserId(string $userId): ?array
    {
        $file = $this->profileFile($userId);

        if (!file_exists($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    public function save(array $profile): array
    {
        $profile['updated_at'] = date('c');

        if (!isset($profile['created_at'])) {
            $profile['created_at'] = $profile['updated_at'];
        }

        $this->writeJson($this->profileFile($profile['user_id']), $profile);

        return $profile;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function profileFile(string $userId): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $userId);
        return $this->profileDir . DIRECTORY_SEPARATOR . $safe . '.json';
    }

    private function writeJson(string $file, array $data): void
    {
        $fp = fopen($file, 'c');
        if ($fp === false) {
            throw new \RuntimeException('Cannot open profile file for writing: ' . $file);
        }

        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
