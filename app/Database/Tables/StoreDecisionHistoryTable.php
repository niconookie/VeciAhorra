<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Tables;

use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Contracts\TableInterface;

final class StoreDecisionHistoryTable implements TableInterface
{
    public function name(): string { return 'store_decision_history'; }

    public function define(TableBuilder $table): void
    {
        $table->id()
            ->bigIntegerUnsigned('store_id')
            ->bigIntegerUnsigned('actor_user_id')
            ->string('actor_role', 64)
            ->string('action', 20)
            ->string('from_state', 30)
            ->string('to_state', 30)
            ->text('reason')->nullable()
            ->bigIntegerUnsigned('authority_service_zone_id')->nullable()
            ->datetime('created_at')
            ->index(['store_id', 'created_at', 'id'], 'store_decision_history_store_order')
            ->index('actor_user_id', 'store_decision_history_actor_index')
            ->index('authority_service_zone_id', 'store_decision_history_zone_index');
    }
}
