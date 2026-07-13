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
            ->datetime('created_at')
            ->datetime('updated_at')
            ->unique('token_hash', 'webpay_returns_token_hash_unique')
            ->index('payment_session_id', 'webpay_returns_session_index');
    }
}
