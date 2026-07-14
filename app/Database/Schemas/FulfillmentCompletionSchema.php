<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Schemas;

use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Contracts\TableInterface;

final class FulfillmentCompletionSchema implements TableInterface
{
    public function name(): string { return 'fulfillment_completions'; }

    public function define(TableBuilder $table): void
    {
        $table->id()
            ->bigIntegerUnsigned('business_completion_id')
            ->string('idempotency_key', 64)
            ->string('completion_status', 30)->default('pending')
            ->integerUnsigned('attempt_count')->default('0')
            ->string('lease_owner', 64)->nullable()
            ->datetime('lease_acquired_at')->nullable()
            ->datetime('lease_expires_at')->nullable()
            ->integerUnsigned('lease_version')->default('0')
            ->string('last_result_code', 50)->nullable()
            ->datetime('last_error_at')->nullable()
            ->datetime('completed_at')->nullable()
            ->datetime('created_at')
            ->datetime('updated_at')
            ->unique('business_completion_id', 'fulfillment_completions_business_unique')
            ->unique('idempotency_key', 'fulfillment_completions_key_unique')
            ->index(['completion_status', 'lease_expires_at'], 'fulfillment_completions_claim_index');
    }
}
