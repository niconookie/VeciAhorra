<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryProcessingPolicyInterface;

final class DurableRetryProcessingPolicy implements DurableRetryProcessingPolicyInterface
{
    private const BACKOFF_BY_ATTEMPT = [
        1 => 60,
        2 => 120,
        3 => 240,
        4 => 480,
    ];

    public function decideNextAttempt(
        DurableRetryScheduleSnapshot $claimed,
        DurableRetryProcessingFailure $failure,
        string $decidedAtUtc
    ): DurableRetryNextAttemptDecision {
        if ($claimed->status() !== DurableRetryStatus::CLAIMED) {
            throw new InvalidArgumentException('Ineligible durable retry snapshot.');
        }
        $fields = $claimed->toArray();
        $persistedAttempt = $fields['attempt_number'];
        $confirmedAttempt = $failure->confirmedAttemptNumber();
        if ($persistedAttempt < 0
            || $persistedAttempt > 4
            || ($confirmedAttempt === null
                && $failure->classification()
                    !== DurableRetryProcessingFailure::OUTCOME_UNCERTAIN)
            || ($confirmedAttempt !== null
                && $confirmedAttempt !== $persistedAttempt + 1)
        ) {
            throw new InvalidArgumentException('Incompatible confirmed attempt.');
        }

        $decidedAt = self::utc($decidedAtUtc);

        return match ($failure->classification()) {
            DurableRetryProcessingFailure::TERMINAL_FAILURE =>
                DurableRetryNextAttemptDecision::terminal(),
            DurableRetryProcessingFailure::OUTCOME_UNCERTAIN =>
                DurableRetryNextAttemptDecision::uncertain(),
            DurableRetryProcessingFailure::RETRYABLE_FAILURE =>
                $this->retryable(
                    $claimed,
                    $confirmedAttempt
                        ?? throw new InvalidArgumentException(
                            'Missing confirmed retryable attempt.'
                        ),
                    $decidedAt
                ),
            default => throw new InvalidArgumentException(
                'Invalid durable retry processing classification.'
            ),
        };
    }

    private function retryable(
        DurableRetryScheduleSnapshot $claimed,
        int $confirmedAttempt,
        DateTimeImmutable $decidedAt
    ): DurableRetryNextAttemptDecision {
        if ($confirmedAttempt === 5) {
            return DurableRetryNextAttemptDecision::exhausted();
        }
        if (! isset(self::BACKOFF_BY_ATTEMPT[$confirmedAttempt])
            || $claimed->generation() === PHP_INT_MAX
        ) {
            throw new InvalidArgumentException('Invalid retry progression.');
        }

        $backoff = self::BACKOFF_BY_ATTEMPT[$confirmedAttempt];
        $scheduledFor = $decidedAt->add(new DateInterval("PT{$backoff}S"));
        $formatted = $scheduledFor->format('Y-m-d H:i:s');
        if ($scheduledFor->getTimestamp() < 1
            || strlen($formatted) !== 19
            || self::utc($formatted)->getTimestamp() !== $scheduledFor->getTimestamp()
        ) {
            throw new InvalidArgumentException('Durable retry timestamp overflow.');
        }

        return DurableRetryNextAttemptDecision::retry(
            $claimed->generation() + 1,
            $confirmedAttempt,
            $formatted,
            $backoff
        );
    }

    private static function utc(string $value): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $utc);
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false
            || ($errors !== false
                && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $parsed->format('Y-m-d H:i:s') !== $value
            || $parsed->getTimestamp() < 1
        ) {
            throw new InvalidArgumentException('Invalid UTC decision timestamp.');
        }

        return $parsed;
    }
}
