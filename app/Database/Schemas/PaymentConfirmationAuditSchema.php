<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Schemas;

use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Contracts\TableInterface;

final class PaymentConfirmationAuditSchema implements TableInterface
{
    public function name(): string
    {
        return 'payment_confirmation_audits';
    }

    public function define(TableBuilder $table): void
    {
        $table
            ->id()
            ->string('correlation_id', 64)
            ->string('event_type', 50)
            ->string('event_key', 64)->nullable()
            ->bigIntegerUnsigned('payment_session_id')
            ->bigIntegerUnsigned('payment_id')->nullable()
            ->bigIntegerUnsigned('checkout_id')
            ->string('confirmation_fingerprint', 64)->nullable()
            ->integerUnsigned('confirmation_fingerprint_version')->nullable()
            ->string('provider', 50)->nullable()
            ->decimal('amount', 10, 2)->nullable()
            ->string('currency', 3)->nullable()
            ->string('previous_state', 30)->nullable()
            ->string('resulting_state', 30)->nullable()
            ->string('result_code', 50)
            ->string('severity', 20)
            ->integerUnsigned('attempt_number')
            ->string('safe_financial_reference', 64)->nullable()
            ->text('order_ids_json')
            ->text('context_json')->nullable()
            ->datetime('created_at')
            ->index('correlation_id', 'payment_confirmation_audit_correlation_index')
            ->unique('event_key', 'payment_confirmation_audit_event_key_unique')
            ->index(
                ['payment_session_id', 'created_at'],
                'payment_confirmation_audit_session_date_index'
            )
            ->index(
                'confirmation_fingerprint',
                'payment_confirmation_audit_fingerprint_index'
            );
    }
}
