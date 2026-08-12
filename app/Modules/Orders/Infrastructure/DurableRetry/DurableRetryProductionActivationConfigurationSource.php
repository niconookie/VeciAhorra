<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Infrastructure\DurableRetry;

use Throwable;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryActivationConfigurationSourceInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryActivationConfigurationValueReaderInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryActivationCohort;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryActivationConfiguration;
use VeciAhorra\Modules\Orders\Exceptions\DurableRetryActivationConfigurationSourceException;

final class DurableRetryProductionActivationConfigurationSource implements DurableRetryActivationConfigurationSourceInterface
{
    public function __construct(
        private readonly DurableRetryActivationConfigurationValueReaderInterface $reader
    ) {
    }

    public function snapshot(): DurableRetryActivationConfiguration
    {
        try {
            $configurationValue = $this->reader->read();
        } catch (Throwable $exception) {
            throw DurableRetryActivationConfigurationSourceException::forCode(
                DurableRetryActivationConfigurationSourceException::SOURCE_UNAVAILABLE,
                $exception
            );
        }

        if (! $configurationValue->isPresent()) {
            return DurableRetryActivationConfiguration::disabled();
        }

        $raw = $configurationValue->value();
        if (is_int($raw)) {
            $percentage = $raw;
        } elseif (is_string($raw)
            && preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/D', $raw) === 1
        ) {
            $percentage = (int) $raw;
        } else {
            throw DurableRetryActivationConfigurationSourceException::forCode(
                DurableRetryActivationConfigurationSourceException::INVALID_VALUE
            );
        }

        if ($percentage < DurableRetryActivationConfiguration::MIN_PERCENTAGE
            || $percentage > DurableRetryActivationConfiguration::MAX_PERCENTAGE
        ) {
            throw DurableRetryActivationConfigurationSourceException::forCode(
                DurableRetryActivationConfigurationSourceException::INVALID_VALUE
            );
        }

        return DurableRetryActivationConfiguration::reconciliation(
            $percentage,
            DurableRetryActivationCohort::ALGORITHM_VERSION
        );
    }
}
