<?php

declare(strict_types=1);

namespace App\Storage\Json;

/**
 * Remembers the Promoted Listings campaign this app created, per marketplace, so a
 * duplicate campaign is not created on every toggle. eBay stays the source of truth:
 * a cached id that no longer resolves is discarded and looked up again.
 */
class CampaignRepository
{
    private string $file;

    public function __construct(string $dataDir)
    {
        $this->file = rtrim($dataDir, '/\\') . DIRECTORY_SEPARATOR . 'campaigns.json';

        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (!file_exists($this->file)) {
            file_put_contents($this->file, '{}');
        }
    }

    public function getCampaignId(string $marketplaceId): string
    {
        return (string)($this->read()[$marketplaceId]['campaign_id'] ?? '');
    }

    public function saveCampaignId(string $marketplaceId, string $campaignId): void
    {
        $data = $this->read();
        $data[$marketplaceId] = [
            'campaign_id' => $campaignId,
            'saved_at'    => date('c'),
        ];
        $this->write($data);
    }

    public function forget(string $marketplaceId): void
    {
        $data = $this->read();
        unset($data[$marketplaceId]);
        $this->write($data);
    }

    private function read(): array
    {
        $content = @file_get_contents($this->file);
        if ($content === false || trim($content) === '') {
            return [];
        }
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    private function write(array $data): void
    {
        $fp = fopen($this->file, 'c');
        if ($fp === false) {
            throw new \RuntimeException('Cannot open campaigns file for writing.');
        }

        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
