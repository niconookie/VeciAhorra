<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Schemas;

use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Contracts\TableInterface;

final class PaymentReconciliationSchema implements TableInterface
{
    public function name(): string
    {
        return 'payment_reconciliations';
    }

    public function define(TableBuilder $table): void
    {
        $table
            ->id()
            ->string('public_id', 64)
            ->bigIntegerUnsigned('webpay_return_id')
            ->bigIntegerUnsigned('origin_context_id')
            ->string('provider', 30)
            ->integerUnsigned('fingerprint_version')
            ->string('financial_fingerprint', 64)
            ->string('site_scope', 64)
            ->string('origin', 30)
            ->string('origin_resource_id', 64)
            ->string('gateway_id', 64)
            ->string('payment_attempt_id', 64)
            ->string('origin_key', 64)
            ->string('reconciliation_status', 30)
            ->string('business_result_code', 50)->nullable()
            ->integerUnsigned('attempt_count')->default('0')
            ->string('lease_owner', 64)->nullable()
            ->datetime('lease_acquired_at')->nullable()
            ->datetime('lease_expires_at')->nullable()
            ->integerUnsigned('lease_version')->default('0')
            ->string('last_error_code', 50)->nullable()
            ->datetime('last_error_at')->nullable()
            ->datetime('created_at')
            ->datetime('last_attempt_at')->nullable()
            ->datetime('reconciled_at')->nullable()
            ->datetime('updated_at')
            ->unique('public_id', 'payment_reconciliations_public_unique')
            ->unique('webpay_return_id', 'payment_reconciliations_return_unique')
            ->unique('origin_key', 'payment_reconciliations_origin_key_unique')
            ->unique(
                ['provider', 'fingerprint_version', 'financial_fingerprint'],
                'payment_reconciliations_fingerprint_unique'
            )
            ->index(
                ['site_scope', 'origin', 'origin_resource_id'],
                'payment_reconciliations_origin_index'
            )
            ->index('reconciliation_status', 'payment_reconciliations_status_index');
    }
}
