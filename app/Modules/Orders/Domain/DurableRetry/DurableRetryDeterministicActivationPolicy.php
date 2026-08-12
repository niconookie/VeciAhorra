<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

use VeciAhorra\Modules\Orders\Contracts\DurableRetryActivationConfigurationSourceInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryActivationPolicyInterface;
use VeciAhorra\Modules\Orders\Exceptions\DurableRetryActivationPolicyException;

final class DurableRetryDeterministicActivationPolicy implements
    DurableRetryActivationPolicyInterface
{
    public function __construct(
        private readonly DurableRetryActivationConfigurationSourceInterface $source
    ) {
    }

    public function allowsInitialTransfer(
        DurableRetryAuthorityIdentity $identity
    ): bool {
        $snapshot = $this->source->snapshot();

        if ($identity->stage() !== DurableRetryStage::RECONCILIATION) {
            throw DurableRetryActivationPolicyException::forCode(
                DurableRetryActivationPolicyException::UNSUPPORTED_STAGE
            );
        }
        if ($snapshot->stage() !== $identity->stage()) {
            throw DurableRetryActivationPolicyException::forCode(
                DurableRetryActivationPolicyException::
                    INVALID_CONFIGURATION_SNAPSHOT
            );
        }
        if ($snapshot->algorithmVersion()
            !== DurableRetryActivationCohort::ALGORITHM_VERSION
        ) {
            throw DurableRetryActivationPolicyException::forCode(
                DurableRetryActivationPolicyException::
                    UNSUPPORTED_ALGORITHM_VERSION
            );
        }
        if ($snapshot->isDisabled()) {
            return false;
        }
        if ($snapshot->isFullyEnabled()) {
            return true;
        }

        return DurableRetryActivationCohort::bucket($identity)
            < $snapshot->percentage();
    }
}
