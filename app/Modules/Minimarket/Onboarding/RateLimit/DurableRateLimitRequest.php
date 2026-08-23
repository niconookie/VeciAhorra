<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Minimarket\Onboarding\RateLimit;
final readonly class DurableRateLimitRequest
{
    /** @param list<DurableRateLimitSpecification> $specifications */
    public function __construct(public array $specifications,public string $now)
    {if($specifications===[])throw new DurableRateLimitException('rate_limit_request_invalid');DurableRateLimitBucket::timestamp($now);$domains=[];$hashes=[];foreach($specifications as$s){if(!$s instanceof DurableRateLimitSpecification)throw new DurableRateLimitException('rate_limit_request_invalid');$hex=bin2hex($s->bucketHash);if(isset($domains[$s->domain])||isset($hashes[$hex]))throw new DurableRateLimitException('rate_limit_request_duplicate');$domains[$s->domain]=true;$hashes[$hex]=true;}}
}
