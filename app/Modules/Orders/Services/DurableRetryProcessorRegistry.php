<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Services;

use VeciAhorra\Modules\Orders\Contracts\DurableRetryStageProcessorInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryStageProcessorResolverInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryStage;
use VeciAhorra\Modules\Orders\Exceptions\DurableRetryProcessorConfigurationException;

final class DurableRetryProcessorRegistry implements
    DurableRetryStageProcessorResolverInterface
{
    /** @var array<string, DurableRetryStageProcessorInterface> */
    private readonly array $processors;

    /**
     * Numeric keys form a processor list. String keys form an explicit stage
     * map and must match the stage declared by their processor.
     *
     * PHP overwrites duplicate array keys before this constructor receives
     * them. Equivalent ambiguity is rejected through processor identity,
     * declared-stage uniqueness, cardinality and complete catalog coverage.
     *
     * @param array<array-key, mixed> $processors
     */
    public function __construct(array $processors)
    {
        if ($processors === []) {
            throw new DurableRetryProcessorConfigurationException(
                DurableRetryProcessorConfigurationException::
                    INVALID_REGISTRY_CONFIGURATION
            );
        }

        $catalog = DurableRetryStage::all();
        if ($catalog === []
            || count($catalog) !== count(array_unique($catalog, SORT_STRING))
        ) {
            throw new DurableRetryProcessorConfigurationException(
                DurableRetryProcessorConfigurationException::
                    INVALID_REGISTRY_CONFIGURATION
            );
        }

        $validated = [];
        $identities = [];
        foreach ($processors as $key => $processor) {
            if (! $processor instanceof DurableRetryStageProcessorInterface) {
                throw new DurableRetryProcessorConfigurationException(
                    DurableRetryProcessorConfigurationException::
                        INVALID_PROCESSOR
                );
            }

            $identity = spl_object_id($processor);
            if (isset($identities[$identity])) {
                throw new DurableRetryProcessorConfigurationException(
                    DurableRetryProcessorConfigurationException::
                        DUPLICATE_PROCESSOR
                );
            }
            $identities[$identity] = true;

            $declaredStage = $processor->stage();
            if (is_string($key) && ! in_array($key, $catalog, true)) {
                throw new DurableRetryProcessorConfigurationException(
                    DurableRetryProcessorConfigurationException::UNKNOWN_STAGE
                );
            }
            if (! in_array($declaredStage, $catalog, true)) {
                throw new DurableRetryProcessorConfigurationException(
                    DurableRetryProcessorConfigurationException::UNKNOWN_STAGE
                );
            }
            if (is_string($key) && $key !== $declaredStage) {
                throw new DurableRetryProcessorConfigurationException(
                    DurableRetryProcessorConfigurationException::
                        PROCESSOR_STAGE_MISMATCH
                );
            }
            if (isset($validated[$declaredStage])) {
                throw new DurableRetryProcessorConfigurationException(
                    DurableRetryProcessorConfigurationException::
                        DUPLICATE_PROCESSOR
                );
            }

            $validated[$declaredStage] = $processor;
        }

        $missing = array_values(array_diff($catalog, array_keys($validated)));
        if (count($missing) === 1) {
            throw new DurableRetryProcessorConfigurationException(
                DurableRetryProcessorConfigurationException::MISSING_PROCESSOR
            );
        }
        if ($missing !== [] || count($validated) !== count($catalog)) {
            throw new DurableRetryProcessorConfigurationException(
                DurableRetryProcessorConfigurationException::
                    INCOMPLETE_REGISTRY
            );
        }

        $this->processors = $validated;
    }

    public function resolve(
        string $stage
    ): DurableRetryStageProcessorInterface {
        if (! isset($this->processors[$stage])) {
            throw new DurableRetryProcessorConfigurationException(
                DurableRetryProcessorConfigurationException::UNKNOWN_STAGE
            );
        }

        return $this->processors[$stage];
    }
}
