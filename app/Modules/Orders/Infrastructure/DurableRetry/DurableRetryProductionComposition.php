<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Infrastructure\DurableRetry;

use Closure;
use LogicException;
use Throwable;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryActivationConfigurationValueReaderInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryExternalSchedulerInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryLegacySchedulerInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryDeterministicActivationPolicy;
use VeciAhorra\Modules\Orders\Repositories\DurableRetryInitialTransferRepository;
use VeciAhorra\Modules\Orders\Repositories\DurableRetryLegacyAuthorityRepository;
use VeciAhorra\Modules\Orders\Repositories\DurableRetryScheduleRepository;
use VeciAhorra\Modules\Orders\Services\DurableRetryExternalScheduleCoordinator;
use VeciAhorra\Modules\Orders\Services\DurableRetryInitialAuthorityProducer;
use VeciAhorra\Modules\Orders\Services\DurableRetryInitialProductionRouter;
use VeciAhorra\Modules\Orders\Services\DurableRetryInitialScheduleCoordinator;
use VeciAhorra\Modules\Orders\Services\DurableRetryInitialScheduleResolver;
use VeciAhorra\Modules\Orders\Services\DurableRetryInitialTransferAuthority;
use wpdb;

final class DurableRetryProductionComposition
{
    private const UNINITIALIZED = 0;
    private const BUILDING = 1;
    private const COMPLETE = 2;

    private int $state = self::UNINITIALIZED;
    private ?DurableRetryInitialProductionRouter $composedRouter = null;

    public function __construct(
        private readonly wpdb $database,
        private readonly DurableRetryActivationConfigurationValueReaderInterface $configurationValueReader,
        private readonly DurableRetryExternalSchedulerInterface $externalScheduler,
        private readonly DurableRetryLegacySchedulerInterface $legacyScheduler,
        private readonly Closure $utcNow
    ) {
    }

    public function router(): DurableRetryInitialProductionRouter
    {
        if ($this->state === self::COMPLETE) {
            return $this->composedRouter;
        }
        if ($this->state === self::BUILDING) {
            throw new LogicException(
                'Durable Retry production composition re-entry is not allowed.'
            );
        }

        $this->state = self::BUILDING;
        try {
            $configurationSource =
                new DurableRetryProductionActivationConfigurationSource(
                    $this->configurationValueReader
                );
            $activationPolicy = new DurableRetryDeterministicActivationPolicy(
                $configurationSource
            );
            $legacyAuthorityRepository =
                new DurableRetryLegacyAuthorityRepository($this->database);
            $initialTransferRepository =
                new DurableRetryInitialTransferRepository($this->database);
            $initialTransferAuthority = new DurableRetryInitialTransferAuthority(
                $initialTransferRepository
            );
            $initialAuthorityProducer = new DurableRetryInitialAuthorityProducer(
                $legacyAuthorityRepository,
                $activationPolicy,
                $initialTransferAuthority
            );
            $scheduleRepository = new DurableRetryScheduleRepository(
                $this->database
            );
            $initialScheduleResolver = new DurableRetryInitialScheduleResolver(
                $scheduleRepository
            );
            $externalScheduleCoordinator =
                new DurableRetryExternalScheduleCoordinator(
                    $scheduleRepository,
                    $this->externalScheduler,
                    $this->utcNow
                );
            $initialScheduleCoordinator =
                new DurableRetryInitialScheduleCoordinator(
                    $externalScheduleCoordinator
                );
            $router = new DurableRetryInitialProductionRouter(
                $initialAuthorityProducer,
                $initialScheduleResolver,
                $initialScheduleCoordinator,
                $this->legacyScheduler
            );

            $this->composedRouter = $router;
            $this->state = self::COMPLETE;

            return $router;
        } catch (Throwable $error) {
            $this->composedRouter = null;
            $this->state = self::UNINITIALIZED;

            throw $error;
        }
    }
}
