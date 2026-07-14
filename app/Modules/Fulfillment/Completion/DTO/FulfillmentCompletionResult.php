<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Fulfillment\Completion\DTO;

final class FulfillmentCompletionResult
{
    public const COMPLETED = 'completed';
    public const ALREADY_COMPLETED = 'already_completed';
    public const RETRYABLE = 'retryable';
    public const PERMANENT_FAILURE = 'permanent_failure';
    public const MANUAL_REVIEW = 'manual_review';
    public const LEASE_LOST = 'lease_lost';

    public function __construct(
        public readonly string $status,
        public readonly string $reason,
        public readonly int $businessCompletionId,
        public readonly ?int $fulfillmentCompletionId = null
    ) {
    }
}
