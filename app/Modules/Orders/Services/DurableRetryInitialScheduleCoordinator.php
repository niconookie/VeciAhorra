<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Services;

use DateTimeZone;
use InvalidArgumentException;
use Throwable;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryExternalScheduleCoordinatorInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryInitialScheduleCoordinatorInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryCoordinationResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialScheduleResolutionResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialSchedulingResult;

final class DurableRetryInitialScheduleCoordinator
    implements DurableRetryInitialScheduleCoordinatorInterface
{
    public function __construct(
        private readonly DurableRetryExternalScheduleCoordinatorInterface $coordinator
    ) {
    }

    public function coordinate(
        DurableRetryInitialScheduleResolutionResult $resolution
    ): DurableRetryInitialSchedulingResult {
        $scheduleId = $resolution->scheduleId();
        $generation = $resolution->generation();
        $scheduledFor = $resolution->scheduledForUtc();
        if (! $resolution->mayContinueToA7()
            || ! in_array($resolution->state(), [
                DurableRetryInitialScheduleResolutionResult::RESOLVED_DISPATCHING,
                DurableRetryInitialScheduleResolutionResult::RESOLVED_SCHEDULED,
            ], true)
            || $scheduleId === null
            || $scheduleId < 1
            || $generation !== 1
            || $scheduledFor === null
            || $scheduledFor->getTimezone()->getName() !== (new DateTimeZone('UTC'))->getName()
        ) {
            throw new InvalidArgumentException('Initial schedule resolution cannot continue.');
        }

        try {
            $result = $this->coordinator->coordinate($scheduleId, $generation);
        } catch (Throwable) {
            $result = new DurableRetryCoordinationResult(
                DurableRetryCoordinationResult::EXTERNAL_ERROR,
                $scheduleId,
                $generation,
                null,
                false,
                true
            );
        }

        if ($result->scheduleId() !== $scheduleId
            || $result->generation() !== $generation
        ) {
            $result = new DurableRetryCoordinationResult(
                DurableRetryCoordinationResult::EXTERNAL_INCONSISTENCY,
                $scheduleId,
                $generation,
                null,
                false,
                true
            );
        }

        return match ($result->code()) {
            DurableRetryCoordinationResult::SYNCHRONIZED_NEW,
            DurableRetryCoordinationResult::SYNCHRONIZED_EXISTING,
            DurableRetryCoordinationResult::CONCURRENT_CONVERGENCE =>
                DurableRetryInitialSchedulingResult::synchronized($result),
            DurableRetryCoordinationResult::ALREADY_SYNCHRONIZED =>
                DurableRetryInitialSchedulingResult::alreadySynchronized($result),
            DurableRetryCoordinationResult::EXTERNAL_UNAVAILABLE =>
                DurableRetryInitialSchedulingResult::externalUnavailable($result),
            default => $result->interventionRequired()
                ? DurableRetryInitialSchedulingResult::coordinationUncertain($result)
                : DurableRetryInitialSchedulingResult::coordinationFailed($result),
        };
    }
}
