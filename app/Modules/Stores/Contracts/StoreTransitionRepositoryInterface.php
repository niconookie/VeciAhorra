<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Stores\Contracts;

use VeciAhorra\Database\Model;

interface StoreTransitionRepositoryInterface
{
    public function find(int $id): ?Model;

    public function compareAndSetLifecycle(
        int $id,
        array $expected,
        array $target,
        string $updatedAt
    ): int;
}
