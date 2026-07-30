<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Services;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryInitialScheduleResolverInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryScheduleRepositoryInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialAuthorityProductionResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialScheduleResolutionResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialTransferRequest;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryPersistenceResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryStage;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryStatus;

final class DurableRetryInitialScheduleResolver
    implements DurableRetryInitialScheduleResolverInterface
{
    public function __construct(
        private readonly DurableRetryScheduleRepositoryInterface $repository
    ) {
    }

    public function resolve(
        DurableRetryInitialTransferRequest $request,
        DurableRetryInitialAuthorityProductionResult $authority
    ): DurableRetryInitialScheduleResolutionResult {
        if (! $authority->durableAuthorityConfirmed()
            || $request->authority()->stage() !== DurableRetryStage::RECONCILIATION
            || $request->completionId() !== $request->authority()->subjectId()
            || $request->generation()
                !== DurableRetryInitialTransferRequest::INITIAL_GENERATION
        ) {
            return DurableRetryInitialScheduleResolutionResult::incompatible();
        }

        try {
            $read = $this->repository->findByIdentity(
                DurableRetryStage::RECONCILIATION,
                $request->authority()->subjectId(),
                DurableRetryInitialTransferRequest::INITIAL_GENERATION
            );
        } catch (Throwable) {
            return DurableRetryInitialScheduleResolutionResult::readError();
        }

        if ($read->code() === DurableRetryPersistenceResult::NOT_FOUND) {
            return DurableRetryInitialScheduleResolutionResult::notFound();
        }
        if ($read->code() === DurableRetryPersistenceResult::PERSISTENCE_ERROR) {
            return DurableRetryInitialScheduleResolutionResult::readError();
        }
        if ($read->code() !== DurableRetryPersistenceResult::EXISTING_COMPATIBLE
            || $read->snapshot() === null
        ) {
            return DurableRetryInitialScheduleResolutionResult::incompatible();
        }

        $snapshot = $read->snapshot();
        $fields = $snapshot->toArray();
        if ($snapshot->id() < 1
            || $snapshot->stage() !== DurableRetryStage::RECONCILIATION
            || $snapshot->subjectId() !== $request->authority()->subjectId()
            || ($fields['completion_id'] ?? null) !== $snapshot->subjectId()
            || $snapshot->generation() !== 1
            || ($fields['attempt_number'] ?? null) !== 0
            || $snapshot->version() < 1
            || ($fields['active_slot'] ?? null) !== 1
            || ! in_array($snapshot->status(), [
                DurableRetryStatus::DISPATCHING,
                DurableRetryStatus::SCHEDULED,
            ], true)
        ) {
            return DurableRetryInitialScheduleResolutionResult::incompatible();
        }

        $scheduledFor = $this->utc($fields['scheduled_for'] ?? null);
        if ($scheduledFor === null
            || ($authority->state()
                    !== DurableRetryInitialAuthorityProductionResult::DURABLE_EXISTING
                && $scheduledFor != $request->scheduledForUtc())
        ) {
            return DurableRetryInitialScheduleResolutionResult::incompatible();
        }

        return $snapshot->status() === DurableRetryStatus::DISPATCHING
            ? DurableRetryInitialScheduleResolutionResult::resolvedDispatching(
                $snapshot->id(),
                $snapshot->generation(),
                $scheduledFor
            )
            : DurableRetryInitialScheduleResolutionResult::resolvedScheduled(
                $snapshot->id(),
                $snapshot->generation(),
                $scheduledFor
            );
    }

    private function utc(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value)) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $value,
            new DateTimeZone('UTC')
        );
        $errors = DateTimeImmutable::getLastErrors();

        return $parsed !== false
            && ($errors === false
                || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $parsed->format('Y-m-d H:i:s') === $value
            ? $parsed
            : null;
    }
}
