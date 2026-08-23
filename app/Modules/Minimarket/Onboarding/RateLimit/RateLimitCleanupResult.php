<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Minimarket\Onboarding\RateLimit;
final readonly class RateLimitCleanupResult
{
    private const ALLOWED=['rate_limit_cleanup_applied','rate_limit_cleanup_not_applied','rate_limit_cleanup_outcome_uncertain'];
    public function __construct(public string $reason){if(!in_array($reason,self::ALLOWED,true))throw new DurableRateLimitException('rate_limit_cleanup_result_invalid');}
    public function applied():bool{return $this->reason==='rate_limit_cleanup_applied';}
}
