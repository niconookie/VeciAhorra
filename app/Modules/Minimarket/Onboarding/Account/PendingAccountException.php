<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Minimarket\Onboarding\Account;
use RuntimeException;
final class PendingAccountException extends RuntimeException
{
    private const REASONS=['invalid_activation_command','invalid_password','verification_unavailable','verification_expired','verification_attempts_exhausted','pending_account_conflict','pending_account_incompatible','pending_account_creation_failed','pending_account_identity_collision','pending_account_compensation_failed','pending_account_outcome_uncertain','pending_account_lock_unavailable'];
    public readonly string $reason;
    public function __construct(string $reason){$this->reason=in_array($reason,self::REASONS,true)?$reason:'pending_account_outcome_uncertain';parent::__construct($this->reason);}
}
