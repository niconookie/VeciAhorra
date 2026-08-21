<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake;

use VeciAhorra\Modules\Minimarket\Onboarding\Contracts\OnboardingIntentClassifier;
use VeciAhorra\Modules\Minimarket\Onboarding\Exceptions\OnboardingConflictException;

final class WordPressTransientRateLimitBucketStore implements RateLimitBucketStore
{
    public function __construct(private \wpdb $database, private RateLimitLockManager $locks) {}

    public function consumeAtomically(array $buckets, ?callable $classifyIntent = null, ?callable $onAllowed = null): RateLimitDecision
    {
        if (wp_using_ext_object_cache() || $buckets === []) throw new PublicIntakeException('rate_limit_unavailable');
        $names = array_map(static fn (RateLimitBucket $bucket): string => 'va-r1c-' . substr(hash('sha256', $bucket->name), 0, 48), $buckets);
        return $this->locks->synchronized($names, fn (): RateLimitDecision => $this->consumeTransaction($buckets, $classifyIntent, $onAllowed));
    }

    /** @param list<RateLimitBucket> $buckets */
    private function consumeTransaction(array $buckets, ?callable $classifyIntent, ?callable $onAllowed): RateLimitDecision
    {
        if ($this->database->query('START TRANSACTION') === false) throw new PublicIntakeException('rate_limit_unavailable');
        try {
            $now = time();
            $states = [];
            $retry = false;
            foreach ($buckets as $bucket) {
                if (! $bucket instanceof RateLimitBucket) throw new PublicIntakeException('rate_limit_unavailable');
                $state = $this->read($bucket, $now);
                $states[$bucket->name] = $state;
                if ($bucket->keyMarker && $state['existing']) $retry = true;
            }
            if ($classifyIntent !== null) {
                $classification = $classifyIntent();
                if ($classification === OnboardingIntentClassifier::COMPATIBLE_REPLAY) $retry = true;
                elseif ($classification === OnboardingIntentClassifier::CONFLICT) throw new OnboardingConflictException('idempotency_conflict');
                elseif ($classification !== OnboardingIntentClassifier::NEW) throw new PublicIntakeException('rate_limit_unavailable');
            }

            $retryAfter = 0;
            foreach ($buckets as $bucket) {
                if ($retry && ! $bucket->consumeOnRetry) continue;
                $state = $states[$bucket->name];
                if ($state['count'] >= $bucket->limit) $retryAfter = max($retryAfter, $state['expires_at'] - $now);
            }
            if ($retryAfter > 0) {
                $this->rollback();
                return new RateLimitDecision(false, $retry, max(1, $retryAfter), 'rate_limited');
            }

            foreach ($buckets as $bucket) {
                if ($retry && ! $bucket->consumeOnRetry) continue;
                $state = $states[$bucket->name];
                $next = ['count' => $state['count'] + 1, 'window_started_at' => $state['window_started_at'], 'expires_at' => $state['expires_at']];
                $this->write($bucket->name, $next);
            }
            if ($this->database->query('COMMIT') === false) throw new PublicIntakeException('rate_limit_unavailable');
            foreach ($buckets as $bucket) {
                wp_cache_delete('_transient_' . $bucket->name, 'options');
                wp_cache_delete('_transient_timeout_' . $bucket->name, 'options');
            }
            if ($onAllowed !== null) $onAllowed();
            return new RateLimitDecision(true, $retry, null, 'allowed');
        } catch (\Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    /** @return array{count:int,window_started_at:int,expires_at:int,existing:bool} */
    private function read(RateLimitBucket $bucket, int $now): array
    {
        $valueName = '_transient_' . $bucket->name;
        $timeoutName = '_transient_timeout_' . $bucket->name;
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT option_name,option_value FROM {$this->database->options} WHERE option_name IN (%s,%s) FOR UPDATE",
            $valueName, $timeoutName
        ), OBJECT_K);
        if (! is_array($rows)) throw new PublicIntakeException('rate_limit_unavailable');
        $hasValue = isset($rows[$valueName]);
        $hasTimeout = isset($rows[$timeoutName]);
        if (! $hasValue && ! $hasTimeout) return $this->fresh($bucket, $now);
        if (! $hasValue || ! $hasTimeout || ! ctype_digit((string) $rows[$timeoutName]->option_value)) {
            throw new PublicIntakeException('rate_limit_unavailable');
        }
        $timeout = (int) $rows[$timeoutName]->option_value;
        if ($timeout <= $now) return $this->fresh($bucket, $now);
        $value = maybe_unserialize($rows[$valueName]->option_value);
        if (! is_array($value) || array_keys($value) !== ['count','window_started_at','expires_at']
            || ! is_int($value['count']) || ! is_int($value['window_started_at']) || ! is_int($value['expires_at'])
            || $value['count'] < 0 || $value['window_started_at'] <= 0 || $value['expires_at'] <= $value['window_started_at']
            || $value['expires_at'] - $value['window_started_at'] !== $bucket->windowSeconds
            || $value['expires_at'] !== $timeout || $value['expires_at'] <= $now) {
            throw new PublicIntakeException('rate_limit_unavailable');
        }
        return $value + ['existing' => true];
    }

    /** @return array{count:int,window_started_at:int,expires_at:int,existing:bool} */
    private function fresh(RateLimitBucket $bucket, int $now): array
    {
        return ['count' => 0, 'window_started_at' => $now, 'expires_at' => $now + $bucket->windowSeconds, 'existing' => false];
    }

    /** @param array{count:int,window_started_at:int,expires_at:int} $state */
    private function write(string $name, array $state): void
    {
        $queries = [
            $this->database->prepare(
                "INSERT INTO {$this->database->options} (option_name,option_value,autoload) VALUES (%s,%s,'off') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value),autoload='off'",
                '_transient_timeout_' . $name, (string) $state['expires_at']
            ),
            $this->database->prepare(
                "INSERT INTO {$this->database->options} (option_name,option_value,autoload) VALUES (%s,%s,'off') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value),autoload='off'",
                '_transient_' . $name, maybe_serialize($state)
            ),
        ];
        foreach ($queries as $query) if ($this->database->query($query) === false) throw new PublicIntakeException('rate_limit_unavailable');
        $check = $this->database->get_var($this->database->prepare("SELECT option_value FROM {$this->database->options} WHERE option_name=%s", '_transient_' . $name));
        if (maybe_unserialize($check) !== $state) throw new PublicIntakeException('rate_limit_unavailable');
    }

    private function rollback(): void
    {
        $this->database->query('ROLLBACK');
    }
}
