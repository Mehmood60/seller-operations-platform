<?php

declare(strict_types=1);

namespace App\Storage\Json;

use App\Storage\Contracts\RepositoryInterface;

abstract class JsonRepository implements RepositoryInterface
{
    protected string $dataDir;
    protected string $entityName;
    protected string $entityDir;
    protected string $indexFile;

    public function __construct(string $dataDir, string $entityName)
    {
        $this->dataDir    = rtrim($dataDir, '/\\');
        $this->entityName = $entityName;
        $this->entityDir  = $this->dataDir . DIRECTORY_SEPARATOR . $entityName;
        $this->indexFile  = $this->entityDir . DIRECTORY_SEPARATOR . 'index.json';
        $this->ensureDirectoryExists($this->entityDir);
        $this->ensureFileExists($this->indexFile, '[]');
    }

    // --- RepositoryInterface ---

    public function find(string $id): ?array
    {
        $file = $this->entityFile($id);
        if (!file_exists($file)) {
            return null;
        }
        return $this->readJson($file);
    }

    public function findAll(array $filters = [], int $page = 1, int $limit = 25): array
    {
        $index = $this->readIndex();

        // Apply filters against index entries
        if (!empty($filters)) {
            $index = array_values(array_filter($index, function (array $entry) use ($filters): bool {
                return $this->matchesFilters($entry, $filters);
            }));
        }

        $total = count($index);

        // Sort by created_at descending if available
        usort($index, function (array $a, array $b): int {
            $dateA = $a['created_at'] ?? $a['listed_at'] ?? '';
            $dateB = $b['created_at'] ?? $b['listed_at'] ?? '';
            return strcmp($dateB, $dateA);
        });

        // Paginate
        $offset   = ($page - 1) * $limit;
        $page_ids = array_column(array_slice($index, $offset, $limit), 'id');

        // Load full entities
        $entities = [];
        foreach ($page_ids as $id) {
            $entity = $this->find((string)$id);
            if ($entity !== null) {
                $entities[] = $entity;
            }
        }

        return [
            'items' => $entities,
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
        ];
    }

    public function findBy(string $field, mixed $value): array
    {
        $index  = $this->readIndex();
        $result = [];

        foreach ($index as $entry) {
            if (isset($entry[$field]) && $entry[$field] === $value) {
                $entity = $this->find((string)$entry['id']);
                if ($entity !== null) {
                    $result[] = $entity;
                }
            }
        }

        return $result;
    }

    public function save(array $entity): array
    {
        if (empty($entity['id'])) {
            throw new \InvalidArgumentException('Entity must have an id field.');
        }

        $entity['updated_at'] = date('c');
        if (!isset($entity['created_at'])) {
            $entity['created_at'] = $entity['updated_at'];
        }

        // Write entity file
        $this->writeJson($this->entityFile($entity['id']), $entity);

        // Update index
        $this->updateIndex($entity);

        return $entity;
    }

    public function delete(string $id): bool
    {
        $file = $this->entityFile($id);
        if (!file_exists($file)) {
            return false;
        }

        unlink($file);
        $this->removeFromIndex($id);
        return true;
    }

    public function count(array $filters = []): int
    {
        $index = $this->readIndex();
        if (empty($filters)) {
            return count($index);
        }
        return count(array_filter($index, fn($entry) => $this->matchesFilters($entry, $filters)));
    }

    // --- Abstract ---

    /**
     * Subclasses define which fields go into the index entry (lightweight summary).
     */
    abstract protected function buildIndexEntry(array $entity): array;

    // --- Protected helpers ---

    protected function entityFile(string $id): string
    {
        return $this->entityDir . DIRECTORY_SEPARATOR . $id . '.json';
    }

    protected function readIndex(): array
    {
        $data = $this->readJson($this->indexFile);
        return is_array($data) ? $data : [];
    }

    protected function updateIndex(array $entity): void
    {
        $indexEntry = $this->buildIndexEntry($entity);

        $fp = fopen($this->indexFile, 'c+');
        if ($fp === false) {
            throw new \RuntimeException('Cannot open index file: ' . $this->indexFile);
        }

        flock($fp, LOCK_EX);

        $content = '';
        rewind($fp);
        while (!feof($fp)) {
            $content .= fread($fp, 8192);
        }

        $index = json_decode($content, true);
        if (!is_array($index)) {
            $index = [];
        }

        // Upsert entry
        $found = false;
        foreach ($index as &$entry) {
            if ((string)$entry['id'] === (string)$entity['id']) {
                $entry = $indexEntry;
                $found = true;
                break;
            }
        }
        unset($entry);

        if (!$found) {
            $index[] = $indexEntry;
        }

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    protected function removeFromIndex(string $id): void
    {
        $fp = fopen($this->indexFile, 'c+');
        if ($fp === false) {
            return;
        }

        flock($fp, LOCK_EX);

        $content = '';
        rewind($fp);
        while (!feof($fp)) {
            $content .= fread($fp, 8192);
        }

        $index = json_decode($content, true);
        if (!is_array($index)) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return;
        }

        $index = array_values(array_filter($index, fn($e) => (string)$e['id'] !== $id));

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    protected function readJson(string $file): ?array
    {
        if (!file_exists($file)) {
            return null;
        }

        $content = file_get_contents($file);
        if ($content === false || trim($content) === '') {
            return null;
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    protected function writeJson(string $file, array $data): void
    {
        $fp = fopen($file, 'c');
        if ($fp === false) {
            throw new \RuntimeException('Cannot open file for writing: ' . $file);
        }

        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    protected function ensureDirectoryExists(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    protected function ensureFileExists(string $file, string $default = '{}'): void
    {
        if (!file_exists($file)) {
            file_put_contents($file, $default);
        }
    }

    protected function matchesFilters(array $entry, array $filters): bool
    {
        foreach ($filters as $key => $value) {
            if ($key === 'search') {
                // Search by title or id
                $haystack = strtolower(
                    ($entry['title'] ?? '') . ' ' .
                    ($entry['id'] ?? '') . ' ' .
                    ($entry['buyer_username'] ?? '')
                );
                if (strpos($haystack, strtolower((string)$value)) === false) {
                    return false;
                }
                continue;
            }
            if ($key === 'date_from' && isset($entry['created_at'])) {
                if ($entry['created_at'] < $value) {
                    return false;
                }
                continue;
            }
            if ($key === 'date_to' && isset($entry['created_at'])) {
                if ($entry['created_at'] > $value . 'T23:59:59Z') {
                    return false;
                }
                continue;
            }
            if (isset($entry[$key]) && (string)$entry[$key] !== (string)$value) {
                return false;
            }
        }
        return true;
    }
}
