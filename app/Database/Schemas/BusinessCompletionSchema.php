<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Schemas;

use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Contracts\TableInterface;

final class BusinessCompletionSchema implements TableInterface
{
    public function name(): string { return 'business_completions'; }

    public function define(TableBuilder $table): void
    {
        $table
            ->id()
            ->bigIntegerUnsigned('reconciliation_id')
            ->string('idempotency_key', 64)
            ->string('status', 30)->default('pending')
            ->bigIntegerUnsigned('payment_id')->nullable()
            ->string('lease_owner', 64)->nullable()
            ->datetime('lease_acquired_at')->nullable()
            ->datetime('lease_expires_at')->nullable()
            ->integerUnsigned('lease_version')->default('0')
            ->integerUnsigned('attempt_count')->default('0')
            ->string('last_result_code', 50)->nullable()
            ->datetime('last_error_at')->nullable()
            ->datetime('completed_at')->nullable()
            ->datetime('created_at')
            ->datetime('updated_at')
            ->unique('reconciliation_id', 'business_completions_reconciliation_unique')
            ->unique('idempotency_key', 'business_completions_key_unique')
            ->unique('payment_id', 'business_completions_payment_unique')
            ->index(['status', 'lease_expires_at'], 'business_completions_claim_index');
    }
}
