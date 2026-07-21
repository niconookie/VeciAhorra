<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Stores\Contracts;

use VeciAhorra\Database\Model;

interface StoreDeletionRepositoryInterface
{
    public function beginSerializable(): bool;
    public function commit(): void;
    public function rollBack(): void;
    public function find(int $id): ?Model;
    public function findForUpdate(int $id): ?Model;
    public function referenceCounts(int $id, bool $lock = false): array;
    public function compareAndDeleteLifecycle(int $id, array $expected): int;
}
