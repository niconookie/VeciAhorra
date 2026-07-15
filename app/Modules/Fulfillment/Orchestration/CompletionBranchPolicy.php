<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Fulfillment\Orchestration;

use VeciAhorra\Modules\Payments\Reconciliation\DTO\DurablePaymentOrigin;
use VeciAhorra\Modules\Payments\Reconciliation\Model\PaymentReconciliation;

final class CompletionBranchPolicy
{
    public const BUSINESS_COMPLETION = 'business_completion';
    public const BRANCH_COMPLETED = 'branch_completed';
    public const UNSUPPORTED = 'unsupported';

    public static function businessOrigin(): string
    {
        return DurablePaymentOrigin::ORIGIN_VECIAHORRA;
    }

    public function nextAfterReconciliation(PaymentReconciliation $reconciliation): string
    {
        return $this->nextForOrigin($reconciliation->origin()->origin());
    }

    public function nextForOrigin(string $origin): string
    {
        return match ($origin) {
            DurablePaymentOrigin::ORIGIN_VECIAHORRA => self::BUSINESS_COMPLETION,
            DurablePaymentOrigin::ORIGIN_WOOCOMMERCE => self::BRANCH_COMPLETED,
            default => self::UNSUPPORTED,
        };
    }
}
