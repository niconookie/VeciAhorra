<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Exceptions;

use InvalidArgumentException;

final class DurableRetryActivationContractException extends InvalidArgumentException
{
    public const INVALID_AUTHORITY_IDENTITY = 'invalid_authority_identity';
    public const INVALID_GENERATION_IDENTITY = 'invalid_generation_identity';
    public const INVALID_IDENTITY_COLLECTION = 'invalid_identity_collection';
    public const INVALID_AUTHORITY_RESULT = 'invalid_authority_result';
    public const INVALID_AUTHORITY_BATCH = 'invalid_authority_batch';
    public const INVALID_INITIAL_TRANSFER_REQUEST = 'invalid_initial_transfer_request';
    public const INVALID_INITIAL_TRANSFER_RESULT = 'invalid_initial_transfer_result';
    public const CONTRACT_VIOLATION = 'contract_violation';

    private const MESSAGES = [
        self::INVALID_AUTHORITY_IDENTITY => 'Invalid durable retry authority identity.',
        self::INVALID_GENERATION_IDENTITY => 'Invalid durable retry generation identity.',
        self::INVALID_IDENTITY_COLLECTION => 'Invalid durable retry authority identity collection.',
        self::INVALID_AUTHORITY_RESULT => 'Invalid durable retry legacy authority result.',
        self::INVALID_AUTHORITY_BATCH => 'Invalid durable retry legacy authority batch result.',
        self::INVALID_INITIAL_TRANSFER_REQUEST => 'Invalid durable retry initial transfer request.',
        self::INVALID_INITIAL_TRANSFER_RESULT => 'Invalid durable retry initial transfer result.',
        self::CONTRACT_VIOLATION => 'Durable retry activation contract violation.',
    ];

    private function __construct(private readonly string $reasonCode)
    {
        parent::__construct(self::MESSAGES[$reasonCode]);
    }

    public static function forCode(string $code): self
    {
        if (! isset(self::MESSAGES[$code])) {
            throw new InvalidArgumentException(
                'Invalid durable retry activation exception code.'
            );
        }

        return new self($code);
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }
}
