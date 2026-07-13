<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Schemas;

use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Contracts\TableInterface;

final class WebpayReturnSchema implements TableInterface
{
    public function name(): string
    {
        return 'webpay_returns';
    }

    public function define(TableBuilder $table): void
    {
        $table
            ->id()
            ->string('token_hash', 64)
            ->bigIntegerUnsigned('payment_session_id')->nullable()
            ->string('flow', 20)
            ->string('processing_status', 20)
            ->string('result_status', 30)->nullable()
            ->text('result_json')->nullable()
            ->string('public_result_id', 64)->nullable()
            ->string('provider', 30)->nullable()
            ->string('environment', 20)->nullable()
            ->string('merchant_identity_hash', 64)->nullable()
            ->string('financial_status', 30)->nullable()
            ->string('financial_operation', 20)->nullable()
            ->string('financial_fingerprint', 64)->nullable()
            ->integerUnsigned('fingerprint_version')->nullable()
            ->string('provider_status', 30)->nullable()
            ->integer('response_code')->nullable()
            ->bigIntegerUnsigned('amount_clp')->nullable()
            ->string('currency', 3)->nullable()
            ->string('buy_order', 26)->nullable()
            ->string('financial_session_id', 64)->nullable()
            ->string('authorization_code_hash', 64)->nullable()
            ->string('payment_type_code', 4)->nullable()
            ->integerUnsigned('installments_number')->nullable()
            ->string('accounting_date', 10)->nullable()
            ->string('transaction_date', 35)->nullable()
            ->string('safe_financial_reference', 64)->nullable()
            ->integerUnsigned('payload_version')->nullable()
            ->text('normalized_payload_json')->nullable()
            ->datetime('financial_obtained_at')->nullable()
            ->datetime('financial_validated_at')->nullable()
            ->datetime('created_at')
            ->datetime('updated_at')
            ->unique('token_hash', 'webpay_returns_token_hash_unique')
            ->unique('public_result_id', 'webpay_returns_public_result_unique')
            ->unique(
                ['provider', 'fingerprint_version', 'financial_fingerprint'],
                'webpay_returns_fingerprint_unique'
            )
            ->index('payment_session_id', 'webpay_returns_session_index');
    }
}
