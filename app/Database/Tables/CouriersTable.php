<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Tables;

use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Contracts\TableInterface;

final class CouriersTable implements TableInterface
{
    public function name(): string { return 'couriers'; }

    public function define(TableBuilder $table): void
    {
        $table->id()
            ->string('display_name', 150)
            ->string('phone', 30)
            ->string('email', 150)->nullable()
            ->string('status', 20)->default('pending')
            ->datetime('approved_at')->nullable()
            ->datetime('created_at')
            ->datetime('updated_at')
            ->index('status', 'couriers_status_index')
            ->index('email', 'couriers_email_index');
    }
}
