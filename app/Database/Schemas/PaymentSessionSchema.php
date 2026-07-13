<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Schemas;

use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Contracts\TableInterface;

final class PaymentSessionSchema implements TableInterface
{
    public function name(): string
    {
        return 'payment_sessions';
    }

    public function define(TableBuilder $table): void
    {
        $table
            ->id()
            ->string('public_id', 64)
            ->bigIntegerUnsigned('checkout_id')
            ->bigIntegerUnsigned('payment_id')->nullable()
            ->string('idempotency_key', 128)
            ->string('request_fingerprint', 64)
            ->string('status', 30)->default('pending')
            ->string('provider', 50)->nullable()
            ->string('provider_session_id', 191)->nullable()
            ->text('redirect_url')->nullable()
            ->string('currency', 3)
            ->decimal('amount', 10, 2)
            ->text('metadata')->nullable()
            ->string('confirmation_fingerprint', 64)->nullable()
            ->integerUnsigned('confirmation_fingerprint_version')->nullable()
            ->string('safe_financial_reference', 64)->nullable()
            ->datetime('confirmed_at')->nullable()
            ->datetime('created_at')
            ->datetime('updated_at')
            ->datetime('expires_at')
            ->unique('public_id', 'payment_sessions_public_id_unique')
            ->unique('payment_id', 'payment_sessions_payment_id_unique')
            ->unique(
                ['checkout_id', 'idempotency_key'],
                'payment_sessions_checkout_key_unique'
            )
            ->index(
                ['checkout_id', 'status', 'expires_at'],
                'payment_sessions_checkout_active_index'
            )
            ->index(
                ['status', 'expires_at'],
                'payment_sessions_expiration_index'
            )
            ->index(
                'confirmation_fingerprint',
                'payment_sessions_confirmation_fingerprint_index'
            );
    }
}
