<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Services;

use Closure;
use Throwable;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryExternalScheduleCoordinatorInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryExternalSchedulerInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryScheduleRepositoryInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryCoordinationResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExternalScheduleCatalog;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExternalScheduleResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryPersistenceResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryScheduleSnapshot;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryStatus;

final class DurableRetryExternalScheduleCoordinator implements
    DurableRetryExternalScheduleCoordinatorInterface
{
    public function __construct(
        private readonly DurableRetryScheduleRepositoryInterface $repository,
        private readonly DurableRetryExternalSchedulerInterface $scheduler,
        private readonly Closure $utcNow
    ) {
    }

    public function coordinate(
        int $scheduleId,
        int $generation
    ): DurableRetryCoordinationResult {
        if ($scheduleId < 1 || $generation < 1) {
            return $this->result(
                DurableRetryCoordinationResult::DURABLE_INCONSISTENCY,
                max(1, $scheduleId),
                max(1, $generation),
                null,
                false,
                true
            );
        }

        $initial = $this->read($scheduleId);
        $closed = $this->closeRead($initial, $scheduleId, $generation);
        if ($closed !== null) {
            return $closed;
        }
        $snapshot = $initial->snapshot();
        if ($snapshot === null) {
            return $this->persistenceError($scheduleId, $generation);
        }
        $fields = $snapshot->toArray();
        $actionId = $fields['scheduled_action_id'];

        if ($actionId !== null) {
            if ($snapshot->status() !== DurableRetryStatus::SCHEDULED) {
                return $this->result(
                    DurableRetryCoordinationResult::INELIGIBLE_STATE,
                    $scheduleId,
                    $generation
                );
            }

            return $this->verifyAssociated($snapshot, $actionId);
        }
        if ($snapshot->status() !== DurableRetryStatus::DISPATCHING) {
            return $this->result(
                DurableRetryCoordinationResult::INELIGIBLE_STATE,
                $scheduleId,
                $generation
            );
        }

        $confirmed = $this->read($scheduleId);
        $closed = $this->closeRead($confirmed, $scheduleId, $generation);
        if ($closed !== null) {
            return $closed;
        }
        $snapshot = $confirmed->snapshot();
        if ($snapshot === null) {
            return $this->persistenceError($scheduleId, $generation);
        }
        $fields = $snapshot->toArray();
        if ($snapshot->status() !== DurableRetryStatus::DISPATCHING
            || $fields['scheduled_action_id'] !== null
        ) {
            return $fields['scheduled_action_id'] !== null
                ? $this->verifyAssociated($snapshot, $fields['scheduled_action_id'])
                : $this->result(
                    DurableRetryCoordinationResult::INELIGIBLE_STATE,
                    $scheduleId,
                    $generation
                );
        }

        $identity = $this->identity($snapshot);
        try {
            $external = $this->scheduler->schedule(
                $identity['hook'],
                $identity['arguments'],
                $identity['group'],
                $fields['scheduled_for']
            );
        } catch (Throwable) {
            return $this->result(
                DurableRetryCoordinationResult::EXTERNAL_ERROR,
                $scheduleId,
                $generation,
                null,
                false,
                true
            );
        }
        $externalFailure = $this->externalFailure(
            $external,
            $scheduleId,
            $generation
        );
        if ($externalFailure !== null) {
            return $externalFailure;
        }
        $actionId = $external->scheduledActionId();
        if ($actionId === null) {
            return $this->externalInconsistency($scheduleId, $generation);
        }

        $now = $this->utcNow();
        if ($now === null) {
            return $this->compensate(
                $snapshot,
                $actionId,
                $external->code() === DurableRetryExternalScheduleResult::SCHEDULED,
                DurableRetryCoordinationResult::PERSISTENCE_ERROR
            );
        }
        try {
            $associated = $this->repository->associateScheduledAction(
                $scheduleId,
                $snapshot->version(),
                $actionId,
                $now,
                $now
            );
        } catch (Throwable) {
            return $this->resolveCasFailure(
                $snapshot,
                $actionId,
                $external->code() === DurableRetryExternalScheduleResult::SCHEDULED,
                DurableRetryCoordinationResult::PERSISTENCE_ERROR
            );
        }

        if ($this->associationConfirmed($associated, $snapshot, $actionId)) {
            $code = $associated->code() === DurableRetryPersistenceResult::ALREADY_APPLIED
                ? DurableRetryCoordinationResult::CONCURRENT_CONVERGENCE
                : ($external->code() === DurableRetryExternalScheduleResult::SCHEDULED
                    ? DurableRetryCoordinationResult::SYNCHRONIZED_NEW
                    : DurableRetryCoordinationResult::SYNCHRONIZED_EXISTING);

            return $this->result($code, $scheduleId, $generation, $actionId);
        }

        return $this->resolveCasFailure(
            $snapshot,
            $actionId,
            $external->code() === DurableRetryExternalScheduleResult::SCHEDULED,
            $associated->code() === DurableRetryPersistenceResult::PERSISTENCE_ERROR
                ? DurableRetryCoordinationResult::PERSISTENCE_ERROR
                : DurableRetryCoordinationResult::DURABLE_INCONSISTENCY
        );
    }

    private function verifyAssociated(
        DurableRetryScheduleSnapshot $snapshot,
        int $actionId
    ): DurableRetryCoordinationResult {
        $identity = $this->identity($snapshot);
        try {
            $pending = $this->scheduler->findPending(
                $identity['hook'],
                $identity['arguments'],
                $identity['group']
            );
        } catch (Throwable) {
            return $this->result(
                DurableRetryCoordinationResult::EXTERNAL_ERROR,
                $snapshot->id(),
                $snapshot->generation(),
                $actionId,
                false,
                true
            );
        }
        if ($pending->code() === DurableRetryExternalScheduleResult::FOUND
            && $pending->scheduledActionId() === $actionId
        ) {
            return $this->result(
                DurableRetryCoordinationResult::ALREADY_SYNCHRONIZED,
                $snapshot->id(),
                $snapshot->generation(),
                $actionId
            );
        }
        if ($pending->code() === DurableRetryExternalScheduleResult::UNAVAILABLE) {
            return $this->result(
                DurableRetryCoordinationResult::EXTERNAL_UNAVAILABLE,
                $snapshot->id(),
                $snapshot->generation(),
                $actionId
            );
        }
        if ($pending->code() === DurableRetryExternalScheduleResult::EXTERNAL_ERROR) {
            return $this->result(
                DurableRetryCoordinationResult::EXTERNAL_ERROR,
                $snapshot->id(),
                $snapshot->generation(),
                $actionId,
                false,
                true
            );
        }

        return $this->result(
            DurableRetryCoordinationResult::EXTERNAL_INCONSISTENCY,
            $snapshot->id(),
            $snapshot->generation(),
            $actionId,
            false,
            true
        );
    }

    private function resolveCasFailure(
        DurableRetryScheduleSnapshot $attempted,
        int $actionId,
        bool $created,
        string $failureCode
    ): DurableRetryCoordinationResult {
        $read = $this->read($attempted->id());
        $current = $read->snapshot();
        if ($current !== null) {
            $fields = $current->toArray();
            if ($current->generation() === $attempted->generation()
                && $current->status() === DurableRetryStatus::SCHEDULED
                && $fields['scheduled_action_id'] === $actionId
            ) {
                return $this->result(
                    DurableRetryCoordinationResult::CONCURRENT_CONVERGENCE,
                    attempted: $attempted->id(),
                    generation: $attempted->generation(),
                    actionId: $actionId
                );
            }
        }

        return $this->compensate($attempted, $actionId, $created, $failureCode);
    }

    private function compensate(
        DurableRetryScheduleSnapshot $snapshot,
        int $actionId,
        bool $created,
        string $failureCode
    ): DurableRetryCoordinationResult {
        if (! $created) {
            return $this->result(
                $failureCode,
                $snapshot->id(),
                $snapshot->generation(),
                $actionId,
                false,
                true
            );
        }
        $identity = $this->identity($snapshot);
        try {
            $cancelled = $this->scheduler->cancel(
                $actionId,
                $identity['hook'],
                $identity['arguments'],
                $identity['group']
            );
        } catch (Throwable) {
            return $this->compensationUnconfirmed($snapshot, $actionId);
        }
        if (in_array($cancelled->code(), [
            DurableRetryExternalScheduleResult::CANCELLED,
            DurableRetryExternalScheduleResult::ALREADY_ABSENT,
        ], true)) {
            return $this->result(
                $failureCode === DurableRetryCoordinationResult::PERSISTENCE_ERROR
                    ? DurableRetryCoordinationResult::PERSISTENCE_ERROR
                    : DurableRetryCoordinationResult::CONFLICT_COMPENSATED,
                $snapshot->id(),
                $snapshot->generation(),
                $actionId,
                true
            );
        }

        return $this->compensationUnconfirmed($snapshot, $actionId);
    }

    private function closeRead(
        DurableRetryPersistenceResult $read,
        int $scheduleId,
        int $generation
    ): ?DurableRetryCoordinationResult {
        if ($read->code() === DurableRetryPersistenceResult::NOT_FOUND) {
            return $this->result(
                DurableRetryCoordinationResult::NOT_FOUND,
                $scheduleId,
                $generation
            );
        }
        if ($read->code() !== DurableRetryPersistenceResult::EXISTING_COMPATIBLE) {
            return $this->persistenceError($scheduleId, $generation);
        }
        $snapshot = $read->snapshot();
        if ($snapshot === null) {
            return $this->persistenceError($scheduleId, $generation);
        }
        if ($snapshot->id() !== $scheduleId) {
            return $this->durableInconsistency($scheduleId, $generation);
        }
        if ($snapshot->generation() !== $generation) {
            return $this->result(
                DurableRetryCoordinationResult::STALE_GENERATION,
                $scheduleId,
                $generation
            );
        }

        return null;
    }

    private function read(int $scheduleId): DurableRetryPersistenceResult
    {
        try {
            return $this->repository->findById($scheduleId);
        } catch (Throwable) {
            return new DurableRetryPersistenceResult(
                DurableRetryPersistenceResult::PERSISTENCE_ERROR
            );
        }
    }

    private function identity(DurableRetryScheduleSnapshot $snapshot): array
    {
        return [
            'hook' => DurableRetryExternalScheduleCatalog::hookForStage(
                $snapshot->stage()
            ),
            'arguments' => [
                'schedule_id' => $snapshot->id(),
                'generation' => $snapshot->generation(),
            ],
            'group' => DurableRetryExternalScheduleCatalog::GROUP,
        ];
    }

    private function utcNow(): ?string
    {
        try {
            $now = ($this->utcNow)();
            if (! is_string($now)) {
                return null;
            }
            DurableRetryExternalScheduleCatalog::timestamp($now);

            return $now;
        } catch (Throwable) {
            return null;
        }
    }

    private function associationConfirmed(
        DurableRetryPersistenceResult $result,
        DurableRetryScheduleSnapshot $before,
        int $actionId
    ): bool {
        $snapshot = $result->snapshot();
        if (! in_array($result->code(), [
            DurableRetryPersistenceResult::APPLIED,
            DurableRetryPersistenceResult::ALREADY_APPLIED,
        ], true) || $snapshot === null) {
            return false;
        }
        $fields = $snapshot->toArray();

        return $snapshot->id() === $before->id()
            && $snapshot->generation() === $before->generation()
            && $snapshot->status() === DurableRetryStatus::SCHEDULED
            && $fields['scheduled_action_id'] === $actionId
            && $snapshot->version() === $before->version() + 1;
    }

    private function externalFailure(
        DurableRetryExternalScheduleResult $external,
        int $scheduleId,
        int $generation
    ): ?DurableRetryCoordinationResult {
        if (in_array($external->code(), [
            DurableRetryExternalScheduleResult::SCHEDULED,
            DurableRetryExternalScheduleResult::ALREADY_SCHEDULED,
        ], true)) {
            return null;
        }
        $code = match ($external->code()) {
            DurableRetryExternalScheduleResult::UNAVAILABLE =>
                DurableRetryCoordinationResult::EXTERNAL_UNAVAILABLE,
            DurableRetryExternalScheduleResult::EXTERNAL_ERROR =>
                DurableRetryCoordinationResult::EXTERNAL_ERROR,
            default => DurableRetryCoordinationResult::EXTERNAL_INCONSISTENCY,
        };

        return $this->result(
            $code,
            $scheduleId,
            $generation,
            null,
            false,
            $code !== DurableRetryCoordinationResult::EXTERNAL_UNAVAILABLE
        );
    }

    private function result(
        string $code,
        int $attempted,
        int $generation,
        ?int $actionId = null,
        bool $compensated = false,
        bool $intervention = false
    ): DurableRetryCoordinationResult {
        return new DurableRetryCoordinationResult(
            $code,
            $attempted,
            $generation,
            $actionId,
            $compensated,
            $intervention
        );
    }

    private function persistenceError(
        int $scheduleId,
        int $generation
    ): DurableRetryCoordinationResult {
        return $this->result(
            DurableRetryCoordinationResult::PERSISTENCE_ERROR,
            $scheduleId,
            $generation,
            null,
            false,
            true
        );
    }

    private function durableInconsistency(
        int $scheduleId,
        int $generation
    ): DurableRetryCoordinationResult {
        return $this->result(
            DurableRetryCoordinationResult::DURABLE_INCONSISTENCY,
            $scheduleId,
            $generation,
            null,
            false,
            true
        );
    }

    private function externalInconsistency(
        int $scheduleId,
        int $generation
    ): DurableRetryCoordinationResult {
        return $this->result(
            DurableRetryCoordinationResult::EXTERNAL_INCONSISTENCY,
            $scheduleId,
            $generation,
            null,
            false,
            true
        );
    }

    private function compensationUnconfirmed(
        DurableRetryScheduleSnapshot $snapshot,
        int $actionId
    ): DurableRetryCoordinationResult {
        return $this->result(
            DurableRetryCoordinationResult::COMPENSATION_UNCONFIRMED,
            $snapshot->id(),
            $snapshot->generation(),
            $actionId,
            false,
            true
        );
    }
}
