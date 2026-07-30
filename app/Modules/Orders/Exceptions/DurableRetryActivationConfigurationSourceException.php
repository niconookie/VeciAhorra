<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Exceptions;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class DurableRetryActivationConfigurationSourceException extends RuntimeException
{
    public const INVALID_VALUE =
        'invalid_activation_configuration_value';
    public const SOURCE_UNAVAILABLE =
        'activation_configuration_source_unavailable';

    private const MESSAGES = [
        self::INVALID_VALUE =>
            'Invalid durable retry activation configuration value.',
        self::SOURCE_UNAVAILABLE =>
            'Durable retry activation configuration source is unavailable.',
    ];

    private function __construct(
        private readonly string $reasonCode,
        ?Throwable $previous
    ) {
        parent::__construct(self::MESSAGES[$reasonCode], 0, $previous);
    }

    public static function forCode(
        string $code,
        ?Throwable $previous = null
    ): self {
        if (! isset(self::MESSAGES[$code])) {
            throw new InvalidArgumentException(
                'Invalid durable retry activation configuration source exception code.'
            );
        }

        return new self($code, $previous);
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }
}
