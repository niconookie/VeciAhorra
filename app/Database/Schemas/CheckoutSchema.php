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
            ->string('fulfillment_method', 20)->nullable()
            ->string('delivery_recipient_name', 200)->nullable()
            ->string('delivery_contact_phone', 30)->nullable()
            ->string('delivery_address_line1', 255)->nullable()
            ->string('delivery_commune', 120)->nullable()
            ->string('delivery_reference', 255)->nullable()
            ->text('delivery_notes')->nullable()
            ->string('idempotency_owner_key', 64)->nullable()
            ->string('idempotency_key', 128)->nullable()
            ->string('request_fingerprint', 64)->nullable()
            ->string('currency', 3)->default('CLP')
            ->decimal('product_subtotal', 10, 2)->nullable()->defaultNull()
            ->decimal('platform_fee', 10, 2)->default('0.00')
            ->decimal('delivery_fee', 10, 2)->default('0.00')
            ->string('fee_policy_version', 40)->nullable()->defaultNull()
            ->decimal('total_amount', 10, 2)
            ->datetime('created_at')
            ->datetime('updated_at')
            ->datetime('expires_at')
            ->unique('public_id', 'checkouts_public_id_unique')
            ->unique(
                ['idempotency_owner_key', 'idempotency_key'],
                'checkouts_owner_idempotency_unique'
            )
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
