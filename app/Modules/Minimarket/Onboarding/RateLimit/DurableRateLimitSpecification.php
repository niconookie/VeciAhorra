<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Minimarket\Onboarding\RateLimit;
final readonly class DurableRateLimitSpecification
{
    public const POLICIES=['get_network'=>[30,600],'get_token'=>[10,600],'session_create_token'=>[5,900],'post_network'=>[20,900],'post_session'=>[5,900],'resend_network'=>[10,86400],'resend_application'=>[3,86400],'resend_cooldown_application'=>[1,600],'password_invalid_session'=>[5,900],'replay_session'=>[10,900],'replay_application'=>[10,900]];
    public function __construct(public string $bucketHash,public string $domain,public int $limit,public int $windowSeconds)
    {if(strlen($bucketHash)!==32||!isset(self::POLICIES[$domain])||self::POLICIES[$domain]!==[$limit,$windowSeconds])throw new DurableRateLimitException('rate_limit_specification_invalid');}
}
