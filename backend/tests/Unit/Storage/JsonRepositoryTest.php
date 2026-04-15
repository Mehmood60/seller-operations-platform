<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\Storage\Json\JsonRepository;
use Tests\TestCase;

/**
 * Concrete implementation of the abstract JsonRepository for unit testing.
 */
class ConcreteTestRepository extends JsonRepository
{
    protected function buildIndexEntry(array $entity): array
    {
        return [
            'id'         => $entity['id'],
            'name'       => $entity['name'] ?? '',
            'status'     => $entity['status'] ?? '',
            'created_at' => $entity['created_at'] ?? date('c'),
        ];
    }
}

class JsonRepositoryTest extends TestCase
{
    private string $tempDir;
    private ConcreteTestRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = $this->createTempDir();
        $this->repo    = new ConcreteTestRepository($this->tempDir, 'items');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDir($this->tempDir);
    }

    // ── find() ────────────────────────────────────────────────────────────────

    public function testFindReturnsNullForNonExistentId(): void
    {
        $result = $this->repo->find('does-not-exist');
        $this->assertNull($result);
    }

    public function testFindReturnsSavedEntity(): void
    {
        $this->repo->save(['id' => 'item-001', 'name' => 'Alpha']);
        $found = $this->repo->find('item-001');

        $this->assertNotNull($found);
        $this->assertEquals('item-001', $found['id']);
        $this->assertEquals('Alpha', $found['name']);
    }

    // ── save() ────────────────────────────────────────────────────────────────

    public function testSaveCreatesEntityFileOnDisk(): void
    {
        $this->repo->save(['id' => 'item-001', 'name' => 'Test']);

        $this->assertFileExists($this->tempDir . '/items/item-001.json');
    }

    public function testSaveAddsCreatedAtAndUpdatedAt(): void
    {
        $saved = $this->repo->save(['id' => 'item-001', 'name' => 'Test']);

        $this->assertArrayHasKey('created_at', $saved);
        $this->assertArrayHasKey('updated_at', $saved);
    }

    public function testSaveUpdatesIndexFile(): void
    {
        $this->repo->save(['id' => 'item-001', 'name' => 'Test']);

        $index = json_decode(file_get_contents($this->tempDir . '/items/index.json'), true);
        $this->assertCount(1, $index);
        $this->assertEquals('item-001', $index[0]['id']);
    }

    public function testSaveUpsertsByIdNotDuplicate(): void
    {
        $this->repo->save(['id' => 'item-001', 'name' => 'Original']);
        $this->repo->save(['id' => 'item-001', 'name' => 'Updated']);

        $index = json_decode(file_get_contents($this->tempDir . '/items/index.json'), true);
        $this->assertCount(1, $index);
        $this->assertEquals('Updated', $index[0]['name']);
    }

    public function testSaveThrowsWithoutIdField(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repo->save(['name' => 'No ID here']);
    }

    public function testSavePreservesExistingCreatedAt(): void
    {
        $originalDate = '2026-01-01T00:00:00+00:00';
        $this->repo->save(['id' => 'item-001', 'name' => 'A', 'created_at' => $originalDate]);
        $saved = $this->repo->save(['id' => 'item-001', 'name' => 'B', 'created_at' => $originalDate]);

        $this->assertEquals($originalDate, $saved['created_at']);
    }

    // ── delete() ──────────────────────────────────────────────────────────────

    public function testDeleteRemovesEntityFile(): void
    {
        $this->repo->save(['id' => 'item-001', 'name' => 'Test']);
        $this->repo->delete('item-001');

        $this->assertFileDoesNotExist($this->tempDir . '/items/item-001.json');
    }

    public function testDeleteRemovesEntryFromIndex(): void
    {
        $this->repo->save(['id' => 'item-001', 'name' => 'Test']);
        $this->repo->delete('item-001');

        $index = json_decode(file_get_contents($this->tempDir . '/items/index.json'), true);
        $this->assertCount(0, $index);
    }

    public function testDeleteReturnsTrueOnSuccess(): void
    {
        $this->repo->save(['id' => 'item-001', 'name' => 'Test']);
        $this->assertTrue($this->repo->delete('item-001'));
    }

    public function testDeleteReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->repo->delete('ghost'));
    }

    // ── count() ───────────────────────────────────────────────────────────────

    public function testCountReturnsZeroForEmptyRepo(): void
    {
        $this->assertEquals(0, $this->repo->count());
    }

    public function testCountReturnsCorrectEntityCount(): void
    {
        $this->repo->save(['id' => 'item-001', 'name' => 'A']);
        $this->repo->save(['id' => 'item-002', 'name' => 'B']);
        $this->repo->save(['id' => 'item-003', 'name' => 'C']);

        $this->assertEquals(3, $this->repo->count());
    }

    public function testCountWithFilterReturnsMatchingCount(): void
    {
        $this->repo->save(['id' => 'item-001', 'name' => 'A', 'status' => 'ACTIVE']);
        $this->repo->save(['id' => 'item-002', 'name' => 'B', 'status' => 'INACTIVE']);
        $this->repo->save(['id' => 'item-003', 'name' => 'C', 'status' => 'ACTIVE']);

        $this->assertEquals(2, $this->repo->count(['status' => 'ACTIVE']));
    }

    // ── findAll() ─────────────────────────────────────────────────────────────

    public function testFindAllReturnsEmptyItemsForEmptyRepo(): void
    {
        $result = $this->repo->findAll();
        $this->assertIsArray($result['items']);
        $this->assertEmpty($result['items']);
        $this->assertEquals(0, $result['total']);
    }

    public function testFindAllPaginatesCorrectly(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->repo->save(['id' => "item-00{$i}", 'name' => "Item {$i}"]);
        }

        $page1 = $this->repo->findAll([], 1, 2);
        $this->assertCount(2, $page1['items']);
        $this->assertEquals(5, $page1['total']);

        $page2 = $this->repo->findAll([], 2, 2);
        $this->assertCount(2, $page2['items']);

        $page3 = $this->repo->findAll([], 3, 2);
        $this->assertCount(1, $page3['items']);
    }

    public function testFindAllFiltersApplied(): void
    {
        $this->repo->save(['id' => 'item-001', 'name' => 'A', 'status' => 'ACTIVE']);
        $this->repo->save(['id' => 'item-002', 'name' => 'B', 'status' => 'INACTIVE']);

        $result = $this->repo->findAll(['status' => 'ACTIVE'], 1, 25);
        $this->assertCount(1, $result['items']);
        $this->assertEquals('item-001', $result['items'][0]['id']);
    }

    // ── findBy() ─────────────────────────────────────────────────────────────

    public function testFindByReturnsMatchingEntities(): void
    {
        $this->repo->save(['id' => 'item-001', 'name' => 'A', 'status' => 'ACTIVE']);
        $this->repo->save(['id' => 'item-002', 'name' => 'B', 'status' => 'INACTIVE']);
        $this->repo->save(['id' => 'item-003', 'name' => 'C', 'status' => 'ACTIVE']);

        $active = $this->repo->findBy('status', 'ACTIVE');
        $this->assertCount(2, $active);
    }

    public function testFindByReturnsEmptyArrayWhenNoMatch(): void
    {
        $this->repo->save(['id' => 'item-001', 'name' => 'A', 'status' => 'ACTIVE']);

        $result = $this->repo->findBy('status', 'NONEXISTENT');
        $this->assertEmpty($result);
    }

    // ── Resilience ────────────────────────────────────────────────────────────

    public function testFindHandlesMalformedJsonGracefully(): void
    {
        $dir = $this->tempDir . '/items';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($dir . '/corrupt.json', '{not valid json}');

        // Should return null, not throw
        $result = $this->repo->find('corrupt');
        $this->assertNull($result);
    }

    public function testAutoCreatesDirectoryAndIndexOnConstruction(): void
    {
        $newDir = $this->createTempDir();
        new ConcreteTestRepository($newDir, 'brand-new-entity');

        $this->assertDirectoryExists($newDir . '/brand-new-entity');
        $this->assertFileExists($newDir . '/brand-new-entity/index.json');

        $this->removeDir($newDir);
    }
}
