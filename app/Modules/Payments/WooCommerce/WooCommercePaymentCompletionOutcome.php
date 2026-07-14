<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\WooCommerce;

use InvalidArgumentException;
use VeciAhorra\Modules\Payments\Reconciliation\Contracts\PaymentCompletionOutcomeInterface;
use VeciAhorra\Modules\Payments\Reconciliation\Model\PaymentReconciliation;

final class WooCommercePaymentCompletionOutcome implements
    PaymentCompletionOutcomeInterface
{
    public function __construct(
        private readonly string $result,
        private readonly int $orderId,
        private readonly ?string $durableReference,
        private readonly string $diagnosticCode
    ) {
        WooCommercePaymentCompletionResult::assert($result);

        if (
            $orderId <= 0
            || ($durableReference !== null
                && preg_match('/^va-wp-v1-[a-f0-9]{64}$/D', $durableReference) !== 1)
            || preg_match('/^[a-z][a-z0-9_]{2,49}$/D', $diagnosticCode) !== 1
        ) {
            throw new InvalidArgumentException(
                'El outcome de completitud WooCommerce no es valido.'
            );
        }

        if ($this->successful() !== ($durableReference !== null)) {
            throw new InvalidArgumentException(
                'La referencia durable no coincide con el resultado.'
            );
        }
    }

    public function successful(): bool
    {
        return in_array($this->result, [
            WooCommercePaymentCompletionResult::APPLIED_NOW,
            WooCommercePaymentCompletionResult::ALREADY_APPLIED_SAME_PAYMENT,
        ], true);
    }

    public function targetReconciliationStatus(): string
    {
        if ($this->successful()) {
            return PaymentReconciliation::STATUS_COMPLETED;
        }

        if (in_array($this->diagnosticCode, [
            'completion_marker_failed',
            'completion_marker_unverified',
            'verified_marker_failed',
        ], true)) {
            return PaymentReconciliation::STATUS_RETRYABLE;
        }

        if (in_array($this->result, [
            WooCommercePaymentCompletionResult::PAYMENT_ALREADY_DIFFERENT,
            WooCommercePaymentCompletionResult::PAYMENT_COMPLETION_FAILED,
            WooCommercePaymentCompletionResult::PAYMENT_RESULT_UNVERIFIED,
        ], true)) {
            return PaymentReconciliation::STATUS_MANUAL_REVIEW;
        }

        return PaymentReconciliation::STATUS_PERMANENT_FAILURE;
    }

    public function lastErrorCode(): ?string
    {
        return $this->successful() ? null : $this->diagnosticCode;
    }

    public function resultCode(): string { return $this->result; }
    public function orderId(): int { return $this->orderId; }
    public function durableReference(): ?string { return $this->durableReference; }
    public function diagnosticCode(): string { return $this->diagnosticCode; }
}
