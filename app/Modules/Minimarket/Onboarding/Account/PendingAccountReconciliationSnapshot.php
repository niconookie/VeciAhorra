<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Minimarket\Onboarding\Account;
final class PendingAccountReconciliationSnapshot
{
    /** @param array<string,mixed> $application @param array<string,mixed> $verification */
    public function __construct(public readonly array $application,public readonly array $verification,public readonly PendingUser $user,public readonly bool $hasStore,public readonly bool $hasStoreMeta,public readonly bool $hasOtherApplication){}
}
