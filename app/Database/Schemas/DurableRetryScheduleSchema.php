<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Schemas;

use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Contracts\TableInterface;

final class DurableRetryScheduleSchema implements TableInterface
{
    public function name(): string
    {
        return 'durable_retry_schedules';
    }

    public function define(TableBuilder $table): void
    {
        $table->id()
            ->char('public_id', 64)
            ->string('stage', 40)
            ->bigIntegerUnsigned('subject_id')
            ->bigIntegerUnsigned('completion_id')->nullable()->defaultNull()
            ->integerUnsigned('generation')
            ->integerUnsigned('attempt_number')
            ->datetime('scheduled_for')
            ->bigIntegerUnsigned('scheduled_action_id')->nullable()->defaultNull()
            ->char('dispatch_token_hash', 64)
            ->string('status', 24)
            ->tinyIntegerUnsigned('active_slot')->nullable()->defaultNull()
            ->integerUnsigned('version')
            ->string('reason_code', 50)
            ->datetime('dispatched_at')->nullable()->defaultNull()
            ->datetime('claimed_at')->nullable()->defaultNull()
            ->datetime('consumed_at')->nullable()->defaultNull()
            ->datetime('terminal_at')->nullable()->defaultNull()
            ->datetime('created_at')
            ->datetime('updated_at')
            ->unique('public_id', 'durable_retry_public_unique')
            ->unique(
                ['stage', 'subject_id', 'generation'],
                'durable_retry_identity_unique'
            )
            ->unique(
                ['stage', 'subject_id', 'active_slot'],
                'durable_retry_active_unique'
            )
            ->unique('scheduled_action_id', 'durable_retry_action_unique')
            ->index(
                ['status', 'updated_at'],
                'durable_retry_recovery_index'
            )
            ->index(
                ['status', 'terminal_at'],
                'durable_retry_retention_index'
            )
            ->index(
                ['stage', 'completion_id', 'status'],
                'durable_retry_completion_read_index'
            );
    }
}
