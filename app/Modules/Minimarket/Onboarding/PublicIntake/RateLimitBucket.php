<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake;

final readonly class RateLimitBucket
{
    public function __construct(
        public string $name,
        public int $limit,
        public int $windowSeconds,
        public bool $consumeOnRetry = true,
        public bool $keyMarker = false
    ) {
        if (preg_match('/\Ava_r1c_rl_[a-f0-9]{48}\z/', $name) !== 1 || $limit <= 0 || $windowSeconds <= 0) {
            throw new PublicIntakeException('rate_limit_unavailable');
        }
    }
}
