<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Contracts;

use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryPersistenceResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryScheduleSnapshot;

interface DurableRetryScheduleRepositoryInterface
{
    public function create(array $initialFields): DurableRetryPersistenceResult;

    public function findById(int $id): DurableRetryPersistenceResult;

    public function findByIdentity(
        string $stage,
        int $subjectId,
        int $generation
    ): DurableRetryPersistenceResult;

    public function associateScheduledAction(
        int $id,
        int $expectedVersion,
        int $scheduledActionId,
        string $dispatchedAt,
        string $updatedAt
    ): DurableRetryPersistenceResult;

    public function transition(
        DurableRetryScheduleSnapshot $expected,
        DurableRetryScheduleSnapshot $target
    ): DurableRetryPersistenceResult;
}
