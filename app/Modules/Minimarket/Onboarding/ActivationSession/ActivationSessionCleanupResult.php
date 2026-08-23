<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Minimarket\Onboarding\ActivationSession;
final readonly class ActivationSessionCleanupResult
{
    private const ALLOWED=['activation_session_cleanup_applied','activation_session_cleanup_not_applied','activation_session_cleanup_outcome_uncertain'];
    public function __construct(public string $reason){if(!in_array($reason,self::ALLOWED,true))throw new ActivationSessionException('activation_session_cleanup_result_invalid');}
    public function applied():bool{return $this->reason==='activation_session_cleanup_applied';}
}
