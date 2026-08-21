<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake;

final class TransientPublicOnboardingRateLimiter implements PublicOnboardingRateLimiter
{
    public function __construct(private RateLimitKeyDeriver $keys, private RateLimitBucketStore $store) {}

    public function consume(PublicClientAddress $client, ?RateLimitIdentity $identity, string $idempotencyKey, ?callable $classifyIntent = null, ?callable $onAllowed = null): RateLimitDecision
    {
        $network = $client->networkBytes . pack('n', $client->prefixLength);
        $buckets = [
            $this->bucket('ip-short', $network, 5, 600),
            $this->bucket('ip-day', $network, 20, 86400),
        ];
        if ($identity !== null) {
            if (preg_match('/\A[a-f0-9]{64}\z/', $idempotencyKey) !== 1) throw new PublicIntakeException('invalid_idempotency_key');
            $buckets[] = $this->bucket('identity-day', $identity->emailHmac . $identity->rutHmac, 3, 86400, false);
            $buckets[] = $this->bucket('key-short', $this->keys->derive('idempotency', $idempotencyKey), 10, 600, true, true);
        }
        return $this->store->consumeAtomically($buckets, $classifyIntent, $onAllowed);
    }

    private function bucket(string $domain, string $value, int $limit, int $window, bool $onRetry = true, bool $key = false): RateLimitBucket
    {
        return new RateLimitBucket('va_r1c_rl_' . substr($this->keys->derive('bucket-' . $domain, $value), 0, 48), $limit, $window, $onRetry, $key);
    }
}
