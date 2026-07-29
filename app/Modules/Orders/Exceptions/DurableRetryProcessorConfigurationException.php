<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Exceptions;

use InvalidArgumentException;
use RuntimeException;

final class DurableRetryProcessorConfigurationException extends RuntimeException
{
    public const UNKNOWN_STAGE = 'unknown_stage';
    public const MISSING_PROCESSOR = 'missing_processor';
    public const DUPLICATE_PROCESSOR = 'duplicate_processor';
    public const PROCESSOR_STAGE_MISMATCH = 'processor_stage_mismatch';
    public const INVALID_PROCESSOR = 'invalid_processor';
    public const INCOMPLETE_REGISTRY = 'incomplete_registry';
    public const INVALID_REGISTRY_CONFIGURATION =
        'invalid_registry_configuration';

    private const MESSAGES = [
        self::UNKNOWN_STAGE => 'Unknown durable retry stage.',
        self::MISSING_PROCESSOR => 'A durable retry processor is missing.',
        self::DUPLICATE_PROCESSOR => 'A durable retry processor is duplicated.',
        self::PROCESSOR_STAGE_MISMATCH =>
            'A durable retry processor declares a different stage.',
        self::INVALID_PROCESSOR => 'Invalid durable retry processor.',
        self::INCOMPLETE_REGISTRY =>
            'The durable retry processor registry is incomplete.',
        self::INVALID_REGISTRY_CONFIGURATION =>
            'Invalid durable retry processor registry configuration.',
    ];

    public function __construct(private readonly string $reason)
    {
        if (! isset(self::MESSAGES[$reason])) {
            throw new InvalidArgumentException(
                'Invalid durable retry processor configuration reason.'
            );
        }

        parent::__construct(self::MESSAGES[$reason]);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
