<?php

declare(strict_types=1);

namespace App\Storage\Json;

/**
 * eBay charges that belong to no order — insertion fees, store subscription,
 * account-level adjustments — as imported from the Finances API. Keyed by
 * transaction, so re-importing an overlapping date window never double counts.
 */
class EbayChargeRepository
{
    private string $file;

    public function __construct(string $dataDir)
    {
        $this->file = rtrim($dataDir, '/\\') . DIRECTORY_SEPARATOR . 'ebay_charges.json';

        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (!file_exists($this->file)) {
            file_put_contents($this->file, '{}');
        }
    }

    /** @param array<int, array> $charges */
    public function upsertMany(array $charges): void
    {
        if ($charges === []) {
            return;
        }
        $data = $this->read();
        foreach ($charges as $charge) {
            $data[$charge['id']] = $charge;
        }
        $this->write($data);
    }

    /** Charges dated within [from, to], both YYYY-MM-DD, newest first. */
    public function findBetween(string $from, string $to): array
    {
        $items = array_values(array_filter(
            $this->read(),
            static fn (array $c): bool => ($c['date'] ?? '') >= $from && ($c['date'] ?? '') <= $to
        ));
        usort($items, static fn (array $a, array $b): int => strcmp($b['date'] ?? '', $a['date'] ?? ''));
        return $items;
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
            throw new \RuntimeException('Cannot open eBay charges file for writing.');
        }

        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
