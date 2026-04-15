<?php

declare(strict_types=1);

namespace App\Storage\Json;

class TokenRepository
{
    private string $tokenFile;
    private string $encryptionKey;

    public function __construct(string $dataDir)
    {
        $dir                 = rtrim($dataDir, '/\\');
        $this->tokenFile     = $dir . DIRECTORY_SEPARATOR . 'tokens.json';
        $this->encryptionKey = $_ENV['ENCRYPTION_KEY'] ?? str_repeat('0', 32);
        $this->ensureFile();
    }

    public function getTokens(): ?array
    {
        if (!file_exists($this->tokenFile)) {
            return null;
        }

        $content = file_get_contents($this->tokenFile);
        if ($content === false || trim($content) === '' || trim($content) === '{}') {
            return null;
        }

        $data = json_decode($content, true);
        if (!is_array($data) || empty($data['access_token'])) {
            return null;
        }

        // Decrypt tokens
        $data['access_token']  = $this->decrypt($data['access_token']);
        $data['refresh_token'] = $this->decrypt($data['refresh_token'] ?? '');

        return $data;
    }

    public function saveTokens(array $tokens): void
    {
        $toStore                  = $tokens;
        $toStore['access_token']  = $this->encrypt($tokens['access_token']);
        $toStore['refresh_token'] = $this->encrypt($tokens['refresh_token'] ?? '');
        $toStore['saved_at']      = date('c');

        $fp = fopen($this->tokenFile, 'c');
        if ($fp === false) {
            throw new \RuntimeException('Cannot open tokens file for writing.');
        }

        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($toStore, JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    public function clearTokens(): void
    {
        file_put_contents($this->tokenFile, '{}');
    }

    public function hasValidTokens(): bool
    {
        $tokens = $this->getTokens();
        if ($tokens === null) {
            return false;
        }
        $expiresAt = strtotime($tokens['expires_at'] ?? '');
        return $expiresAt !== false && $expiresAt > time() + 60;
    }

    public function isConnected(): bool
    {
        $tokens = $this->getTokens();
        return $tokens !== null && !empty($tokens['access_token']);
    }

    private function encrypt(string $value): string
    {
        if (empty($value)) {
            return '';
        }
        $iv        = openssl_random_pseudo_bytes(16);
        $key       = substr(hash('sha256', $this->encryptionKey, true), 0, 32);
        $encrypted = openssl_encrypt($value, 'AES-256-CBC', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    private function decrypt(string $value): string
    {
        if (empty($value)) {
            return '';
        }
        try {
            $data = base64_decode($value);
            if ($data === false || strlen($data) < 17) {
                return '';
            }
            $iv        = substr($data, 0, 16);
            $encrypted = substr($data, 16);
            $key       = substr(hash('sha256', $this->encryptionKey, true), 0, 32);
            $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
            return $decrypted !== false ? $decrypted : '';
        } catch (\Throwable) {
            return '';
        }
    }

    private function ensureFile(): void
    {
        $dir = dirname($this->tokenFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (!file_exists($this->tokenFile)) {
            file_put_contents($this->tokenFile, '{}');
        }
    }
}
