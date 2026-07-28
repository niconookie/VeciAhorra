<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\Policies;

use DateTimeImmutable;
use InvalidArgumentException;
use VeciAhorra\Modules\Orders\Domain\Operational\OrderOperationalFacts;
use VeciAhorra\Modules\Orders\Domain\Operational\OrderOperationalResolution;

/**
 * Pure availability policy. A future authority adapter must reload durable
 * state and revalidate this decision before scheduling any work.
 */
final class OrderAdminActionPolicy
{
    public const RETRY_DURABLE_PROCESSING = 'retry_durable_processing';
    public const RISKS = ['low', 'medium', 'high'];
    public const STAGES = [
        'reconciliation',
        'business_completion',
        'delivery_completion',
        'fulfillment_completion',
    ];
    public const REASON_CODES = [
        'available',
        'no_retryable_work',
        'active_lease',
        'backoff_active',
        'attempt_limit',
        'blocking_inconsistency',
        'terminal_stage',
        'insufficient_facts',
        'incompatible_state',
        'uncertain_operation',
        'historical_remediation_required',
    ];

    private const ATTEMPT_LIMIT = 5;
    private const RETRYABLE = ['pending', 'retryable', 'processing'];
    private const TERMINAL = ['completed', 'not_required', 'permanent_failure', 'manual_review'];
    private const HISTORICAL_FINDINGS = [
        'reservations_active_after_payment',
        'fulfillment_completed_without_branch',
        'active_reservation_after_terminal_release',
        'stock_double_terminal_evidence',
    ];
    private const HARD_BLOCKING_FINDINGS = [
        'checkout_order_relation_missing',
        'checkout_order_owner_mismatch',
        'operational_order_set_mismatch',
        'payment_flow_mismatch',
        'order_item_subtotal_mismatch',
        'order_total_mismatch',
        'checkout_total_mismatch',
        'order_store_mismatch',
        'reservation_items_mismatch',
        'delivery_integrity_mismatch',
        'paid_without_financial_evidence',
    ];
    private const RECOVERABLE_FINDINGS = [
        'approved_without_business_processing',
        'processing_lease_expired',
    ];

    public function evaluate(
        string $actionCode,
        OrderOperationalFacts $facts,
        OrderOperationalResolution $resolution,
        DateTimeImmutable $now
    ): OrderAdminActionDecision {
        if ($actionCode !== self::RETRY_DURABLE_PROCESSING) {
            throw new InvalidArgumentException('Unsupported administrative action.');
        }

        $snapshot = $facts->all();
        if ($resolution->observedAt !== $facts->observedAt || $this->timestamp($facts->observedAt) === null) {
            return $this->blocked('insufficient_facts');
        }

        $findingCodes = [];
        foreach ($resolution->findings as $finding) {
            if (! is_object($finding)
                || ! property_exists($finding, 'code')
                || ! property_exists($finding, 'blocker')
            ) {
                return $this->blocked('insufficient_facts');
            }
            $findingCodes[] = $finding->code;
        }
        if (array_intersect(self::HISTORICAL_FINDINGS, $findingCodes) !== []) {
            return $this->blocked('historical_remediation_required');
        }
        if (array_intersect(self::HARD_BLOCKING_FINDINGS, $findingCodes) !== []
            || $this->hasBlockingFinding($resolution)
        ) {
            return $this->blocked('blocking_inconsistency');
        }
        foreach (self::STAGES as $candidate) {
            if (($snapshot[$candidate]['status'] ?? null) === 'manual_review') {
                return $this->blocked('uncertain_operation', $candidate);
            }
        }

        $stage = $this->nextStage($snapshot);
        if ($stage === null) {
            return $this->blocked($this->allTerminal($snapshot) ? 'terminal_stage' : 'no_retryable_work');
        }

        $row = $snapshot[$stage] ?? null;
        if (! is_array($row)) {
            return $this->blocked('insufficient_facts', $stage);
        }
        $status = $row['status'] ?? null;
        if (! is_string($status) || ! in_array($status, [...self::RETRYABLE, ...self::TERMINAL], true)) {
            return $this->blocked('incompatible_state', $stage);
        }
        if (in_array($status, self::TERMINAL, true)) {
            return $this->blocked(
                in_array($status, ['manual_review'], true) ? 'uncertain_operation' : 'terminal_stage',
                $stage
            );
        }
        if (! array_key_exists('attempt_count', $row) || ! is_numeric($row['attempt_count'])) {
            return $this->blocked('insufficient_facts', $stage);
        }
        $attempts = (int) $row['attempt_count'];
        if ($attempts < 0) {
            return $this->blocked('incompatible_state', $stage);
        }
        if ($attempts >= self::ATTEMPT_LIMIT) {
            return $this->blocked('attempt_limit', $stage);
        }

        $lease = $this->optionalTimestamp($row, 'lease_expires_at');
        if ($lease === false) {
            return $this->blocked('insufficient_facts', $stage);
        }
        if ($status === 'processing' && $lease === null) {
            return $this->blocked('active_lease', $stage);
        }
        if ($lease instanceof DateTimeImmutable && $lease > $now) {
            return $this->blocked('active_lease', $stage);
        }

        if (! array_key_exists('next_retry_at', $row)) {
            return $this->blocked('insufficient_facts', $stage);
        }
        $nextRetry = $this->optionalTimestamp($row, 'next_retry_at');
        if ($nextRetry === false) {
            return $this->blocked('insufficient_facts', $stage);
        }
        if ($nextRetry instanceof DateTimeImmutable && $nextRetry > $now) {
            return $this->blocked('backoff_active', $stage);
        }
        if (! $this->prerequisitesSatisfied($stage, $snapshot)) {
            return $this->blocked('incompatible_state', $stage);
        }

        return new OrderAdminActionDecision(
            self::RETRY_DURABLE_PROCESSING,
            true,
            'available',
            true,
            'medium',
            $stage
        );
    }

    private function nextStage(array $facts): ?string
    {
        foreach (self::STAGES as $stage) {
            $row = $facts[$stage] ?? null;
            if (is_array($row) && in_array($row['status'] ?? null, self::RETRYABLE, true)) {
                return $stage;
            }
            if ($row === null && $stage !== 'reconciliation' && $this->prerequisitesSatisfied($stage, $facts)) {
                return $stage;
            }
        }
        return null;
    }

    private function prerequisitesSatisfied(string $stage, array $facts): bool
    {
        $method = $facts['checkout']['fulfillment_method'] ?? null;
        if (! in_array($method, ['pickup', 'delivery'], true)) {
            return false;
        }
        if ($stage === 'reconciliation') {
            return ($facts['financial_evidence']['validated'] ?? false) === true
                && ($facts['financial_evidence']['status'] ?? null) === 'approved';
        }
        if (($facts['reconciliation']['status'] ?? null) !== 'completed') {
            return false;
        }
        if ($stage === 'business_completion') {
            return true;
        }
        if (($facts['business_completion']['status'] ?? null) !== 'completed') {
            return false;
        }
        if ($stage === 'delivery_completion') {
            return $method === 'delivery' || $method === 'pickup';
        }

        return in_array($facts['delivery_completion']['status'] ?? null, ['completed', 'not_required'], true)
            && (($method === 'pickup' && ($facts['deliveries'] ?? []) === [])
                || ($method === 'delivery' && count($facts['deliveries'] ?? []) === 1));
    }

    private function allTerminal(array $facts): bool
    {
        $seen = false;
        foreach (self::STAGES as $stage) {
            if (! is_array($facts[$stage] ?? null)) {
                continue;
            }
            $seen = true;
            if (! in_array($facts[$stage]['status'] ?? null, self::TERMINAL, true)) {
                return false;
            }
        }
        return $seen;
    }

    private function hasBlockingFinding(OrderOperationalResolution $resolution): bool
    {
        foreach ($resolution->findings as $finding) {
            if ($finding->blocker === true && ! in_array($finding->code, self::RECOVERABLE_FINDINGS, true)) {
                return true;
            }
        }
        return false;
    }

    private function blocked(string $reason, ?string $stage = null): OrderAdminActionDecision
    {
        return new OrderAdminActionDecision(
            self::RETRY_DURABLE_PROCESSING,
            false,
            $reason,
            true,
            'medium',
            $stage
        );
    }

    /** @return DateTimeImmutable|false|null */
    private function optionalTimestamp(array $row, string $key): DateTimeImmutable|false|null
    {
        if (! array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') {
            return null;
        }
        return $this->timestamp($row[$key]) ?? false;
    }

    private function timestamp(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
