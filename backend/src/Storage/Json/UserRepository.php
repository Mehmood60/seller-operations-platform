<?php

declare(strict_types=1);

namespace App\Storage\Json;

class UserRepository extends JsonRepository
{
    public function __construct(string $dataDir)
    {
        parent::__construct($dataDir, 'users');
    }

    protected function buildIndexEntry(array $entity): array
    {
        return [
            'id'         => $entity['id'],
            'email'      => $entity['email'],
            'status'     => $entity['status'],
            'created_at' => $entity['created_at'],
        ];
    }

    public function findByEmail(string $email): ?array
    {
        $email = strtolower(trim($email));
        $index = $this->readIndex();

        foreach ($index as $entry) {
            if (strtolower($entry['email']) === $email) {
                return $this->find((string) $entry['id']);
            }
        }

        return null;
    }
}
