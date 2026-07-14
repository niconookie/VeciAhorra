<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Schemas;

use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Contracts\TableInterface;

final class PaymentSchema implements TableInterface
{
    public function name(): string
    {
        return 'payments';
    }

    public function define(TableBuilder $table): void
    {
        $table
            ->id()
            ->string('payment_reference', 64)
            ->bigIntegerUnsigned('checkout_id')->nullable()
            ->bigIntegerUnsigned('payment_session_id')->nullable()
            ->bigIntegerUnsigned('reconciliation_id')->nullable()
            ->string('payment_attempt_id', 64)->nullable()
            ->string('financial_fingerprint', 64)->nullable()
            ->string('idempotency_key', 64)->nullable()
            ->bigIntegerUnsigned('customer_id')
            ->decimal('amount', 10, 2)
            ->string('currency', 3)->default('CLP')
            ->string('status', 30)->default('pending')
            ->string('provider', 50)->nullable()
            ->string('provider_reference', 191)->nullable()
            ->datetime('expires_at')->nullable()
            ->datetime('paid_at')->nullable()
            ->datetime('created_at')
            ->datetime('updated_at')
            ->unique(
                'payment_reference',
                'payments_reference_unique'
            )
            ->unique('payment_session_id', 'payments_session_unique')
            ->unique('reconciliation_id', 'payments_reconciliation_unique')
            ->unique('idempotency_key', 'payments_idempotency_unique')
            ->index('customer_id', 'payments_customer_id_index')
            ->index('status', 'payments_status_index')
            ->index('provider_reference', 'payments_provider_reference_index')
            ->index('expires_at', 'payments_expires_at_index');
    }
}
