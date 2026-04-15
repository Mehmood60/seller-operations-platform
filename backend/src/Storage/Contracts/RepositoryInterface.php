<?php

declare(strict_types=1);

namespace App\Storage\Contracts;

interface RepositoryInterface
{
    /**
     * Find a single entity by its ID.
     */
    public function find(string $id): ?array;

    /**
     * Return a paginated list with optional filters applied.
     */
    public function findAll(array $filters = [], int $page = 1, int $limit = 25): array;

    /**
     * Find entities where a given field equals a value.
     */
    public function findBy(string $field, mixed $value): array;

    /**
     * Insert or update an entity (upsert by 'id').
     * Returns the saved entity.
     */
    public function save(array $entity): array;

    /**
     * Delete an entity by ID. Returns true on success.
     */
    public function delete(string $id): bool;

    /**
     * Count total entities matching the given filters.
     */
    public function count(array $filters = []): int;
}
