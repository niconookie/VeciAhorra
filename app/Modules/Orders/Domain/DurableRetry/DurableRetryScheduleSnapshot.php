<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class DurableRetryScheduleSnapshot
{
    private const COLUMNS = [
        'id',
        'public_id',
        'stage',
        'subject_id',
        'completion_id',
        'generation',
        'attempt_number',
        'scheduled_for',
        'scheduled_action_id',
        'dispatch_token_hash',
        'status',
        'active_slot',
        'version',
        'reason_code',
        'dispatched_at',
        'claimed_at',
        'consumed_at',
        'terminal_at',
        'created_at',
        'updated_at',
    ];

    private const TIMESTAMPS = [
        'scheduled_for',
        'dispatched_at',
        'claimed_at',
        'consumed_at',
        'terminal_at',
        'created_at',
        'updated_at',
    ];

    private function __construct(private readonly array $fields)
    {
    }

    public static function fromArray(array $fields): self
    {
        if (array_keys($fields) !== self::COLUMNS) {
            throw new InvalidArgumentException('Invalid durable retry snapshot shape.');
        }
        self::assertPositiveInteger($fields['id'], 'id');
        self::validate($fields);

        return new self($fields);
    }

    public function toArray(): array
    {
        return $this->fields;
    }

    public function id(): int
    {
        return $this->fields['id'];
    }

    public function publicId(): string
    {
        return $this->fields['public_id'];
    }

    public function stage(): string
    {
        return $this->fields['stage'];
    }

    public function subjectId(): int
    {
        return $this->fields['subject_id'];
    }

    public function generation(): int
    {
        return $this->fields['generation'];
    }

    public function status(): string
    {
        return $this->fields['status'];
    }

    public function version(): int
    {
        return $this->fields['version'];
    }

    public static function validate(array $fields): void
    {
        $stage = self::requiredString($fields, 'stage');
        $status = self::requiredString($fields, 'status');
        $reason = self::requiredString($fields, 'reason_code');

        DurableRetryStage::assert($stage);
        DurableRetryStatus::assert($status);
        DurableRetryReason::assertForStatus($reason, $status);
        DurableRetryStatus::assertActiveSlot($status, $fields['active_slot'] ?? null);

        self::assertHex(self::requiredString($fields, 'public_id'), 'public_id');
        self::assertHex(
            self::requiredString($fields, 'dispatch_token_hash'),
            'dispatch_token_hash'
        );
        self::assertPositiveInteger($fields['subject_id'] ?? null, 'subject_id');
        self::assertNullablePositiveInteger(
            $fields['completion_id'] ?? null,
            'completion_id'
        );
        self::assertMinimumInteger($fields['generation'] ?? null, 1, 'generation');
        self::assertMinimumInteger(
            $fields['attempt_number'] ?? null,
            0,
            'attempt_number'
        );
        self::assertMinimumInteger($fields['version'] ?? null, 1, 'version');
        self::assertNullablePositiveInteger(
            $fields['scheduled_action_id'] ?? null,
            'scheduled_action_id'
        );

        if ($stage === DurableRetryStage::RECONCILIATION
            && ($fields['completion_id'] ?? null) !== ($fields['subject_id'] ?? null)
        ) {
            throw new InvalidArgumentException('Reconciliation completion identity mismatch.');
        }

        $times = [];
        foreach (self::TIMESTAMPS as $name) {
            $value = $fields[$name] ?? null;
            $times[$name] = $value === null ? null : self::timestamp($value, $name);
        }
        foreach (['scheduled_for', 'created_at', 'updated_at'] as $required) {
            if ($times[$required] === null) {
                throw new InvalidArgumentException("Missing {$required}.");
            }
        }

        self::assertStateMatrix($status, $fields, $times);
        self::assertTimeOrder($times);
    }

    public static function validateInitial(array $fields): void
    {
        self::validate($fields);

        if (($fields['status'] ?? null) !== DurableRetryStatus::DISPATCHING
            || ($fields['generation'] ?? null) < 1
            || ($fields['attempt_number'] ?? null) < 0
            || ($fields['version'] ?? null) < 1
            || ($fields['active_slot'] ?? null) !== 1
        ) {
            throw new InvalidArgumentException('Invalid initial durable retry snapshot.');
        }
    }

    private static function assertStateMatrix(
        string $status,
        array $fields,
        array $times
    ): void {
        $action = $fields['scheduled_action_id'] ?? null;
        $inactive = ! DurableRetryStatus::isActive($status);

        if ($action === null && $times['dispatched_at'] !== null) {
            throw new InvalidArgumentException('Dispatch timestamp requires action identity.');
        }
        if ($inactive && $action !== null && $times['dispatched_at'] === null) {
            throw new InvalidArgumentException('Inactive correlated state requires dispatch timestamp.');
        }
        if ($times['claimed_at'] !== null && $times['dispatched_at'] === null) {
            throw new InvalidArgumentException('Claim requires dispatch timestamp.');
        }
        if (($status === DurableRetryStatus::CONSUMED) !== ($times['consumed_at'] !== null)) {
            throw new InvalidArgumentException('Invalid consumed timestamp matrix.');
        }
        if ($inactive !== ($times['terminal_at'] !== null)) {
            throw new InvalidArgumentException('Invalid terminal timestamp matrix.');
        }

        $requirements = [
            DurableRetryStatus::DISPATCHING => [null, null, null, null],
            DurableRetryStatus::SCHEDULED => ['required', 'required', null, null],
            DurableRetryStatus::CLAIMED => ['required', 'required', 'required', null],
            DurableRetryStatus::CONSUMED => [
                'required',
                'required',
                'required',
                'required',
            ],
        ];
        if (isset($requirements[$status])) {
            [
                $requiredAction,
                $requiredDispatch,
                $requiredClaim,
                $requiredConsumed,
            ] = $requirements[$status];
            if (($requiredAction === 'required' && $action === null)
                || ($status === DurableRetryStatus::DISPATCHING && $action !== null)
                || ($requiredDispatch === 'required' && $times['dispatched_at'] === null)
                || ($requiredClaim === 'required' && $times['claimed_at'] === null)
                || ($requiredClaim === null && $times['claimed_at'] !== null)
                || ($requiredConsumed === 'required' && $times['consumed_at'] === null)
            ) {
                throw new InvalidArgumentException('Invalid fields for durable retry status.');
            }
            if ($status === DurableRetryStatus::DISPATCHING
                && $times['dispatched_at'] !== null
            ) {
                throw new InvalidArgumentException('Dispatching cannot be externally correlated.');
            }
        } elseif (in_array(
            $status,
            [DurableRetryStatus::SUPERSEDED, DurableRetryStatus::CANCELLED],
            true
        ) && $times['claimed_at'] !== null) {
            throw new InvalidArgumentException('Claim timestamp prohibited for status.');
        }
    }

    private static function assertTimeOrder(array $times): void
    {
        self::notAfter($times['created_at'], $times['updated_at']);
        foreach ($times as $name => $time) {
            if ($time !== null && $name !== 'created_at') {
                self::notAfter($times['created_at'], $time);
            }
        }
        if ($times['dispatched_at'] !== null && $times['claimed_at'] !== null) {
            self::notAfter($times['dispatched_at'], $times['claimed_at']);
        }
        if ($times['claimed_at'] !== null && $times['consumed_at'] !== null) {
            self::notAfter($times['claimed_at'], $times['consumed_at']);
        }
        if ($times['terminal_at'] !== null) {
            self::notAfter($times['updated_at'], $times['terminal_at']);
        }
    }

    private static function timestamp(mixed $value, string $name): DateTimeImmutable
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException("Invalid {$name}.");
        }
        $utc = new DateTimeZone('UTC');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $utc);
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false
            || ($errors !== false
                && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $parsed->format('Y-m-d H:i:s') !== $value
        ) {
            throw new InvalidArgumentException("Invalid {$name}.");
        }

        return $parsed;
    }

    private static function notAfter(DateTimeImmutable $left, DateTimeImmutable $right): void
    {
        if ($left > $right) {
            throw new InvalidArgumentException('Invalid durable retry timestamp order.');
        }
    }

    private static function assertHex(string $value, string $name): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new InvalidArgumentException("Invalid {$name}.");
        }
    }

    private static function assertPositiveInteger(mixed $value, string $name): void
    {
        self::assertMinimumInteger($value, 1, $name);
    }

    private static function assertNullablePositiveInteger(
        mixed $value,
        string $name
    ): void {
        if ($value !== null) {
            self::assertPositiveInteger($value, $name);
        }
    }

    private static function assertMinimumInteger(
        mixed $value,
        int $minimum,
        string $name
    ): void {
        if (! is_int($value) || $value < $minimum) {
            throw new InvalidArgumentException("Invalid {$name}.");
        }
    }

    private static function requiredString(array $fields, string $name): string
    {
        $value = $fields[$name] ?? null;
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("Invalid {$name}.");
        }

        return $value;
    }
}
