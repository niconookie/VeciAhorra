<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\Verification;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

final readonly class StoreOnboardingEmailVerification
{
    public const PURPOSE = 'minimarket_account_activation';
    public const PENDING = 'pending';
    public const SENT = 'sent';
    public const FAILED = 'failed';
    public const UNCERTAIN = 'uncertain';
    public const DELIVERY_FAILED = 'delivery_failed';
    public const DELIVERY_UNCERTAIN = 'delivery_uncertain';

    private const KEYS = ['id', 'application_id', 'purpose', 'generation', 'candidate_user_id', 'attached_user_id', 'email_binding_hash', 'token_hash', 'expires_at', 'consumed_at', 'failed_attempts', 'resend_count', 'last_sent_at', 'delivery_state', 'delivery_attempt_count', 'last_error_code', 'created_at', 'updated_at'];
    private const STATES = [self::PENDING, self::SENT, self::FAILED, self::UNCERTAIN];
    private const ERRORS = [null, self::DELIVERY_FAILED, self::DELIVERY_UNCERTAIN];

    public function __construct(
        public int $id, public int $applicationId, public string $purpose, public int $generation,
        public ?int $candidateUserId, public ?int $attachedUserId, public string $emailBindingHash,
        public string $tokenHash, public string $expiresAt, public ?string $consumedAt,
        public int $failedAttempts, public int $resendCount, public ?string $lastSentAt,
        public string $deliveryState, public int $deliveryAttemptCount, public ?string $lastErrorCode,
        public string $createdAt, public string $updatedAt
    ) {
        if ($id <= 0 || $applicationId <= 0 || $generation < 1 || $candidateUserId !== null && $candidateUserId <= 0 || $attachedUserId !== null && $attachedUserId <= 0) throw new InvalidArgumentException('verification_invalid_id');
        if ($purpose !== self::PURPOSE) throw new InvalidArgumentException('verification_invalid_purpose');
        if (strlen($emailBindingHash) !== 32 || strlen($tokenHash) !== 32) throw new InvalidArgumentException('verification_invalid_hash');
        foreach ([$failedAttempts, $resendCount, $deliveryAttemptCount] as $counter) if ($counter < 0 || $counter > 65535) throw new InvalidArgumentException('verification_invalid_counter');
        foreach ([$expiresAt, $createdAt, $updatedAt] as $value) self::timestamp($value);
        foreach ([$consumedAt, $lastSentAt] as $value) if ($value !== null) self::timestamp($value);
        if ($expiresAt <= $createdAt || $updatedAt < $createdAt || $consumedAt !== null && ($consumedAt < $createdAt || $consumedAt > $updatedAt)) throw new InvalidArgumentException('verification_invalid_timestamp_order');
        if (!in_array($deliveryState, self::STATES, true) || !in_array($lastErrorCode, self::ERRORS, true)) throw new InvalidArgumentException('verification_invalid_state');
        if ($deliveryState === self::PENDING && ($lastSentAt !== null || $lastErrorCode !== null)
            || $deliveryState === self::SENT && ($lastSentAt === null || $deliveryAttemptCount < 1 || $lastErrorCode !== null)
            || $deliveryState === self::FAILED && ($deliveryAttemptCount < 1 || $lastErrorCode !== self::DELIVERY_FAILED || $lastSentAt !== null)
            || $deliveryState === self::UNCERTAIN && ($deliveryAttemptCount < 1 || $lastErrorCode !== self::DELIVERY_UNCERTAIN || $lastSentAt !== null)) throw new InvalidArgumentException('verification_incoherent_delivery');
        if (($consumedAt === null) !== ($attachedUserId === null)) throw new InvalidArgumentException('verification_incoherent_consumption');
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $keys = array_keys($row);
        sort($keys);
        $expectedKeys = self::KEYS;
        sort($expectedKeys);
        if ($keys !== $expectedKeys) throw new RuntimeException('verification_invalid_shape');
        return new self(
            self::positive($row['id'], 'id'), self::positive($row['application_id'], 'application_id'),
            self::oneOf($row['purpose'], [self::PURPOSE], 'purpose'), self::positive($row['generation'], 'generation'),
            self::nullablePositive($row['candidate_user_id'], 'candidate_user_id'), self::nullablePositive($row['attached_user_id'], 'attached_user_id'),
            self::binary($row['email_binding_hash'], 'email_binding_hash'), self::binary($row['token_hash'], 'token_hash'),
            self::string($row['expires_at'], 'expires_at'), self::nullableString($row['consumed_at'], 'consumed_at'),
            self::counter($row['failed_attempts'], 'failed_attempts'), self::counter($row['resend_count'], 'resend_count'),
            self::nullableString($row['last_sent_at'], 'last_sent_at'), self::oneOf($row['delivery_state'], self::STATES, 'delivery_state'),
            self::counter($row['delivery_attempt_count'], 'delivery_attempt_count'), self::nullableOneOf($row['last_error_code'], self::ERRORS, 'last_error_code'),
            self::string($row['created_at'], 'created_at'), self::string($row['updated_at'], 'updated_at')
        );
    }

    public static function assertOrdinaryDeliveryTransition(string $current, string $target): void
    {
        if ($current !== self::PENDING || !in_array($target, [self::SENT, self::FAILED, self::UNCERTAIN], true)) throw new RuntimeException('verification_conflict');
    }

    public static function assertUncertainResolution(string $current, string $target): void
    {
        if ($current !== self::UNCERTAIN || !in_array($target, [self::SENT, self::FAILED], true)) throw new RuntimeException('verification_conflict');
    }

    public static function timestamp(string $value): void
    {
        if (strlen($value) !== 19 || trim($value) !== $value) throw new InvalidArgumentException('verification_invalid_timestamp');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$parsed || $errors !== false && ($errors['warning_count'] || $errors['error_count']) || $parsed->format('Y-m-d H:i:s') !== $value) throw new InvalidArgumentException('verification_invalid_timestamp');
    }

    private static function parseUnsigned(mixed $value, string $field): int
    {
        if (is_int($value)) { if ($value < 0) throw new RuntimeException('verification_invalid_row_' . $field); return $value; }
        if (!is_string($value) || !preg_match('/^(?:0|[1-9][0-9]*)$/D', $value)) throw new RuntimeException('verification_invalid_row_' . $field);
        $max = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($max) || strlen($value) === strlen($max) && strcmp($value, $max) > 0) throw new RuntimeException('verification_invalid_row_' . $field);
        return (int) $value;
    }
    private static function positive(mixed $value, string $field): int { $value = self::parseUnsigned($value, $field); if ($value < 1) throw new RuntimeException('verification_invalid_row_' . $field); return $value; }
    private static function nullablePositive(mixed $value, string $field): ?int { return $value === null ? null : self::positive($value, $field); }
    private static function counter(mixed $value, string $field): int { $value = self::parseUnsigned($value, $field); if ($value > 65535) throw new RuntimeException('verification_invalid_row_' . $field); return $value; }
    private static function string(mixed $value, string $field): string { if (!is_string($value) || $value === '') throw new RuntimeException('verification_invalid_row_' . $field); return $value; }
    private static function nullableString(mixed $value, string $field): ?string { if ($value !== null && !is_string($value)) throw new RuntimeException('verification_invalid_row_' . $field); return $value; }
    /** @param list<string> $allowed */
    private static function oneOf(mixed $value, array $allowed, string $field): string { $value = self::string($value, $field); if (!in_array($value, $allowed, true)) throw new RuntimeException('verification_invalid_row_' . $field); return $value; }
    /** @param list<?string> $allowed */
    private static function nullableOneOf(mixed $value, array $allowed, string $field): ?string { if ($value !== null && !is_string($value) || !in_array($value, $allowed, true)) throw new RuntimeException('verification_invalid_row_' . $field); return $value; }
    private static function binary(mixed $value, string $field): string { if (!is_string($value) || strlen($value) !== 32) throw new RuntimeException('verification_invalid_row_' . $field); return $value; }
}
