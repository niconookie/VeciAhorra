<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Minimarket\Onboarding\RateLimit;
final readonly class DurableRateLimitDecision
{
    private function __construct(public bool $allowed,public ?int $retryAfter,public string $outcome){}
    public static function allowed():self{return new self(true,null,'allowed');}
    public static function blocked(int $retry):self{if($retry<1||$retry>86400)throw new DurableRateLimitException('rate_limit_decision_invalid');return new self(false,$retry,'blocked');}
    public static function uncertain():self{return new self(false,null,'uncertain');}
}
