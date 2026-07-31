<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Services;

use DateTimeImmutable;
use Throwable;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryInitialAuthorityProducerInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryInitialScheduleCoordinatorInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryInitialScheduleResolverInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryLegacySchedulerInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryAuthorityIdentity;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialProductionRoutingResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialTransferRequest;

final class DurableRetryInitialProductionRouter
{
    public function __construct(
        private readonly DurableRetryInitialAuthorityProducerInterface $authorityProducer,
        private readonly DurableRetryInitialScheduleResolverInterface $scheduleResolver,
        private readonly DurableRetryInitialScheduleCoordinatorInterface $scheduleCoordinator,
        private readonly DurableRetryLegacySchedulerInterface $legacyScheduler
    ) {
    }

    public function routeReconciliation(
        int $reconciliationId,
        DateTimeImmutable $scheduledForUtc
    ): DurableRetryInitialProductionRoutingResult {
        if ($reconciliationId < 1
            || $scheduledForUtc->getOffset() !== 0
            || $scheduledForUtc->format('u') !== '000000'
        ) {
            return DurableRetryInitialProductionRoutingResult::invalidInput();
        }

        try {
            $request = DurableRetryInitialTransferRequest::reconciliation(
                DurableRetryAuthorityIdentity::reconciliation($reconciliationId),
                $reconciliationId,
                $scheduledForUtc
            );
            $authority = $this->authorityProducer->produceReconciliation($request);
        } catch (Throwable) {
            return DurableRetryInitialProductionRoutingResult::dependencyFailure(
                $reconciliationId
            );
        }

        if ($authority->permitsLegacyProduction()) {
            try {
                $scheduled = $this->legacyScheduler->scheduleReconciliation(
                    $reconciliationId
                );
            } catch (Throwable) {
                return DurableRetryInitialProductionRoutingResult::dependencyFailure(
                    $reconciliationId
                );
            }

            return $scheduled
                ? DurableRetryInitialProductionRoutingResult::legacyScheduled(
                    $reconciliationId,
                    $authority->reason()
                )
                : DurableRetryInitialProductionRoutingResult::legacyUnavailable(
                    $reconciliationId,
                    $authority->reason()
                );
        }

        if (! $authority->durableAuthorityConfirmed()) {
            return DurableRetryInitialProductionRoutingResult::authorityClosed(
                $reconciliationId,
                $authority
            );
        }

        try {
            $resolution = $this->scheduleResolver->resolve($request, $authority);
        } catch (Throwable) {
            return DurableRetryInitialProductionRoutingResult::dependencyFailure(
                $reconciliationId
            );
        }

        if (! $resolution->mayContinueToA7()) {
            return DurableRetryInitialProductionRoutingResult::resolutionFailed(
                $reconciliationId,
                $resolution
            );
        }

        try {
            return DurableRetryInitialProductionRoutingResult::fromScheduling(
                $reconciliationId,
                $this->scheduleCoordinator->coordinate($resolution)
            );
        } catch (Throwable) {
            return DurableRetryInitialProductionRoutingResult::dependencyFailure(
                $reconciliationId
            );
        }
    }
}
