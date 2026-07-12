<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Schemas;

use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Contracts\TableInterface;

final class CheckoutSchema implements TableInterface
{
    public function name(): string
    {
        return 'checkouts';
    }

    public function define(TableBuilder $table): void
    {
        $table
            ->id()
            ->string('public_id', 64)
            ->string('owner_type', 16)
            ->bigIntegerUnsigned('user_id')->nullable()
            ->string('session_id', 64)->nullable()
            ->string('status', 30)->default('pending')
            ->string('currency', 3)->default('CLP')
            ->decimal('total_amount', 10, 2)
            ->datetime('created_at')
            ->datetime('updated_at')
            ->datetime('expires_at')
            ->unique('public_id', 'checkouts_public_id_unique')
            ->index(
                ['owner_type', 'user_id', 'status'],
                'checkouts_user_owner_index'
            )
            ->index(
                ['owner_type', 'session_id', 'status'],
                'checkouts_session_owner_index'
            )
            ->index(['status', 'expires_at'], 'checkouts_expiration_index');
    }
}
