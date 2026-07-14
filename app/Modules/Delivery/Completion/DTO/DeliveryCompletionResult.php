<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Delivery\Completion\DTO;

final class DeliveryCompletionResult
{
    public const COMPLETED = 'completed';
    public const ALREADY_COMPLETED = 'already_completed';
    public const NOT_REQUIRED = 'not_required';
    public const RETRYABLE_FAILURE = 'retryable_failure';
    public const PERMANENT_FAILURE = 'permanent_failure';
    public const LEASE_LOST = 'lease_lost';
    public const MANUAL_REVIEW = 'manual_review';

    public function __construct(
        public readonly string $status,
        public readonly string $reason,
        public readonly int $businessCompletionId,
        public readonly ?int $deliveryCompletionId = null,
        public readonly array $deliveryIds = []
    ) {
    }
}
