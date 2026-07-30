<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Services;

use Throwable;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryActivationPolicyInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryInitialAuthorityProducerInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryInitialTransferAuthorityInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryLegacyExclusionInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialAuthorityProductionResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialTransferRequest;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryStage;
use VeciAhorra\Modules\Orders\Exceptions\DurableRetryActivationConfigurationSourceException;
use VeciAhorra\Modules\Orders\Exceptions\DurableRetryActivationPolicyException;

final class DurableRetryInitialAuthorityProducer implements
    DurableRetryInitialAuthorityProducerInterface
{
    public function __construct(
        private readonly DurableRetryLegacyExclusionInterface $authority,
        private readonly DurableRetryActivationPolicyInterface $activation,
        private readonly DurableRetryInitialTransferAuthorityInterface $transfer
    ) {
    }

    public function produceReconciliation(
        DurableRetryInitialTransferRequest $request
    ): DurableRetryInitialAuthorityProductionResult {
        if (! $this->validRequest($request)) {
            return DurableRetryInitialAuthorityProductionResult::
                operationalFailure(
                    null,
                    DurableRetryInitialAuthorityProductionResult::
                        DEPENDENCY_FAILURE
                );
        }

        try {
            $authority = $this->authority->classify($request->authority());
        } catch (Throwable) {
            return DurableRetryInitialAuthorityProductionResult::
                operationalFailure(
                    null,
                    DurableRetryInitialAuthorityProductionResult::
                        DEPENDENCY_FAILURE
                );
        }

        if ($authority->isDurable()) {
            return DurableRetryInitialAuthorityProductionResult::
                durableExisting($authority);
        }
        if ($authority->isIndeterminate()) {
            return DurableRetryInitialAuthorityProductionResult::
                authorityIndeterminate($authority);
        }

        try {
            $selected = $this->activation->allowsInitialTransfer(
                $request->authority()
            );
        } catch (DurableRetryActivationConfigurationSourceException $error) {
            if ($error->reasonCode()
                === DurableRetryActivationConfigurationSourceException::
                    INVALID_VALUE
            ) {
                return DurableRetryInitialAuthorityProductionResult::
                    configurationInvalid($authority, $error->reasonCode());
            }

            return DurableRetryInitialAuthorityProductionResult::
                operationalFailure($authority, $error->reasonCode());
        } catch (DurableRetryActivationPolicyException $error) {
            if ($error->reasonCode()
                === DurableRetryActivationPolicyException::UNSUPPORTED_STAGE
            ) {
                return DurableRetryInitialAuthorityProductionResult::
                    operationalFailure(
                        $authority,
                        DurableRetryInitialAuthorityProductionResult::
                            DEPENDENCY_FAILURE
                    );
            }

            return DurableRetryInitialAuthorityProductionResult::
                configurationInvalid($authority, $error->reasonCode());
        } catch (Throwable) {
            return DurableRetryInitialAuthorityProductionResult::
                operationalFailure(
                    $authority,
                    DurableRetryInitialAuthorityProductionResult::
                        DEPENDENCY_FAILURE
                );
        }

        if (! $selected) {
            return DurableRetryInitialAuthorityProductionResult::
                legacyAllowed($authority);
        }

        try {
            return DurableRetryInitialAuthorityProductionResult::fromTransfer(
                $authority,
                $this->transfer->transferReconciliation($request)
            );
        } catch (Throwable) {
            return DurableRetryInitialAuthorityProductionResult::
                operationalFailure(
                    $authority,
                    DurableRetryInitialAuthorityProductionResult::
                        DEPENDENCY_FAILURE
                );
        }
    }

    private function validRequest(
        DurableRetryInitialTransferRequest $request
    ): bool {
        return $request->authority()->stage()
                === DurableRetryStage::RECONCILIATION
            && $request->authority()->subjectId() > 0
            && $request->completionId() === $request->authority()->subjectId()
            && $request->generation()
                === DurableRetryInitialTransferRequest::INITIAL_GENERATION
            && $request->attemptNumber()
                === DurableRetryInitialTransferRequest::INITIAL_ATTEMPT
            && $request->scheduledForUtc()->getOffset() === 0
            && $request->scheduledForUtc()->format('u') === '000000';
    }
}
