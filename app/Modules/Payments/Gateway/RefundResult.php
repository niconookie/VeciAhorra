<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Payments\Gateway;
final class RefundResult
{
    public function __construct(public readonly string $status, public readonly bool $reversed = false)
    {
        if (!in_array($status, ['refunded', 'refund_failed', 'refund_uncertain'], true)) {
            throw new \InvalidArgumentException('invalid_refund_result');
        }
    }
}
