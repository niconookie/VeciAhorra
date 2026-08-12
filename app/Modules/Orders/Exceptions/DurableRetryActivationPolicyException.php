<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Exceptions;

use InvalidArgumentException;

final class DurableRetryActivationPolicyException extends InvalidArgumentException
{
    public const INVALID_PERCENTAGE = 'invalid_percentage';
    public const UNSUPPORTED_STAGE = 'unsupported_stage';
    public const UNSUPPORTED_ALGORITHM_VERSION =
        'unsupported_algorithm_version';
    public const INVALID_CONFIGURATION_SNAPSHOT =
        'invalid_configuration_snapshot';

    private const MESSAGES = [
        self::INVALID_PERCENTAGE =>
            'Invalid durable retry activation percentage.',
        self::UNSUPPORTED_STAGE =>
            'Unsupported durable retry activation stage.',
        self::UNSUPPORTED_ALGORITHM_VERSION =>
            'Unsupported durable retry activation algorithm version.',
        self::INVALID_CONFIGURATION_SNAPSHOT =>
            'Invalid durable retry activation configuration snapshot.',
    ];

    private function __construct(private readonly string $reasonCode)
    {
        parent::__construct(self::MESSAGES[$reasonCode]);
    }

    public static function forCode(string $code): self
    {
        if (! isset(self::MESSAGES[$code])) {
            throw new InvalidArgumentException(
                'Invalid durable retry activation policy exception code.'
            );
        }

        return new self($code);
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }
}
