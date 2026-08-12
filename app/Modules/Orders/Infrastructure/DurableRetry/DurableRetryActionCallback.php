<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Infrastructure\DurableRetry;

use InvalidArgumentException;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryExecutorInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExecutionResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExternalScheduleCatalog;

final class DurableRetryActionCallback
{
    public function __construct(
        private readonly DurableRetryExecutorInterface $executor
    ) {
    }

    public function execute(
        mixed $hook,
        mixed $scheduleId,
        mixed $generation
    ): DurableRetryExecutionResult {
        if (! is_string($hook)) {
            throw new InvalidArgumentException(
                'Invalid durable retry callback invocation.'
            );
        }

        try {
            $identity = DurableRetryExternalScheduleCatalog::normalizeIdentity(
                $hook,
                [
                    'schedule_id' => $scheduleId,
                    'generation' => $generation,
                ],
                DurableRetryExternalScheduleCatalog::GROUP
            );
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException(
                'Invalid durable retry callback invocation.'
            );
        }

        return $this->executor->execute(
            $hook,
            $identity['schedule_id'],
            $identity['generation']
        );
    }
}
