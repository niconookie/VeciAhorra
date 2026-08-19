<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Tables;

use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Contracts\TableInterface;

final class StoreOnboardingApplicationsTable implements TableInterface
{
    public function name(): string
    {
        return 'store_onboarding_applications';
    }

    public function define(TableBuilder $table): void
    {
        $table
            ->id()
            ->string('public_id', 64)
            ->bigIntegerUnsigned('user_id')->nullable()
            ->string('account_email', 190)
            ->string('owner_rut_normalized', 12)
            ->string('status', 32)
            ->char('idempotency_key_hash', 64)
            ->string('terms_version', 32)
            ->datetime('terms_accepted_at')
            ->bigIntegerUnsigned('store_id')->nullable()
            ->string('failure_code', 64)->nullable()
            ->integerUnsigned('attempt_count')->default('0')
            ->datetime('last_attempt_at')->nullable()
            ->datetime('created_at')
            ->datetime('updated_at')
            ->datetime('abandoned_at')->nullable()
            ->unique('public_id', 'onboarding_public_id_unique')
            ->unique('user_id', 'onboarding_user_unique')
            ->unique('store_id', 'onboarding_store_unique')
            ->unique('idempotency_key_hash', 'onboarding_idempotency_unique')
            ->index(['status', 'updated_at'], 'onboarding_status_updated')
            ->index('account_email', 'onboarding_account_email')
            ->index('owner_rut_normalized', 'onboarding_owner_rut');
    }
}
