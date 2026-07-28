<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class DurableRetryExternalScheduleCatalog
{
    public const GROUP = 'veciahorra-durable-retry';
    public const RECONCILIATION = 'veciahorra_durable_retry_reconciliation';
    public const BUSINESS_COMPLETION = 'veciahorra_durable_retry_business_completion';
    public const DELIVERY_COMPLETION = 'veciahorra_durable_retry_delivery_completion';
    public const FULFILLMENT_COMPLETION = 'veciahorra_durable_retry_fulfillment_completion';

    private const HOOKS = [
        self::RECONCILIATION,
        self::BUSINESS_COMPLETION,
        self::DELIVERY_COMPLETION,
        self::FULFILLMENT_COMPLETION,
    ];

    public static function hooks(): array
    {
        return self::HOOKS;
    }

    public static function normalizeIdentity(
        string $hook,
        array $arguments,
        string $group
    ): array {
        if (! in_array($hook, self::HOOKS, true) || $group !== self::GROUP) {
            throw new InvalidArgumentException('Invalid external retry identity.');
        }
        if (count($arguments) !== 2
            || ! array_key_exists('schedule_id', $arguments)
            || ! array_key_exists('generation', $arguments)
        ) {
            throw new InvalidArgumentException('Invalid external retry arguments.');
        }
        foreach (['schedule_id', 'generation'] as $key) {
            if (! is_int($arguments[$key]) || $arguments[$key] < 1) {
                throw new InvalidArgumentException('Invalid external retry argument.');
            }
        }

        return [
            'schedule_id' => $arguments['schedule_id'],
            'generation' => $arguments['generation'],
        ];
    }

    public static function timestamp(string $scheduledFor): int
    {
        $utc = new DateTimeZone('UTC');
        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $scheduledFor,
            $utc
        );
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false
            || ($errors !== false
                && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $parsed->format('Y-m-d H:i:s') !== $scheduledFor
        ) {
            throw new InvalidArgumentException('Invalid external retry timestamp.');
        }

        $timestamp = $parsed->getTimestamp();
        if ($timestamp < 1) {
            throw new InvalidArgumentException('Invalid external retry timestamp.');
        }

        return $timestamp;
    }

    private function __construct()
    {
    }
}
