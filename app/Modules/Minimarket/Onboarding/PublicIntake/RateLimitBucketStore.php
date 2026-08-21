<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake;

interface RateLimitBucketStore
{
    /** @param list<RateLimitBucket> $buckets */
    public function consumeAtomically(array $buckets): RateLimitDecision;
}
