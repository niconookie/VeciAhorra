<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Schemas;

use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Contracts\TableInterface;

final class PaymentOriginContextSchema implements TableInterface
{
    public function name(): string
    {
        return 'payment_origin_contexts';
    }

    public function define(TableBuilder $table): void
    {
        $table
            ->id()
            ->string('public_id', 64)
            ->string('site_scope', 64)
            ->string('origin', 30)
            ->string('origin_resource_id', 64)
            ->string('gateway_id', 64)
            ->string('payment_attempt_id', 64)
            ->string('origin_key', 64)
            ->bigIntegerUnsigned('amount_clp')
            ->string('currency', 3)
            ->string('environment', 20)
            ->string('merchant_identity_hash', 64)
            ->string('buy_order', 26)
            ->string('financial_session_id', 64)
            ->string('token_hash', 64)->nullable()
            ->integerUnsigned('context_version')
            ->datetime('created_at')
            ->datetime('updated_at')
            ->datetime('expires_at')
            ->unique('public_id', 'payment_origin_contexts_public_unique')
            ->unique('payment_attempt_id', 'payment_origin_contexts_attempt_unique')
            ->unique('origin_key', 'payment_origin_contexts_origin_key_unique')
            ->unique('token_hash', 'payment_origin_contexts_token_unique')
            ->index(
                ['site_scope', 'origin', 'origin_resource_id'],
                'payment_origin_contexts_origin_index'
            )
            ->index('expires_at', 'payment_origin_contexts_expires_index');
    }
}
