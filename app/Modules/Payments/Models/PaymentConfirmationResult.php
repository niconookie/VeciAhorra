<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Models;

final class PaymentConfirmationResult
{
    private function __construct(public readonly string $status)
    {
    }

    public static function paid(): self
    {
        return new self('paid');
    }

    public static function failed(): self
    {
        return new self('failed');
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'paid';
    }
}
