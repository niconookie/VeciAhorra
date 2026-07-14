<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\BusinessCompletion\DTO;

final class BusinessCompletionResult
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
        public readonly int $reconciliationId,
        public readonly ?int $completionId = null,
        public readonly ?int $paymentId = null
    ) {
    }
}
