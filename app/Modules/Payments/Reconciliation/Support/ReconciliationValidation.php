<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\Support;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class ReconciliationValidation
{
    public static function hash(string $value, string $field): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new InvalidArgumentException("{$field} no es un SHA-256 valido.");
        }

        return $value;
    }

    public static function identifier(
        string $value,
        string $field,
        int $maximum = 64
    ): string {
        if (
            $value === ''
            || strlen($value) > $maximum
            || preg_match('/^[A-Za-z0-9._:-]+$/D', $value) !== 1
        ) {
            throw new InvalidArgumentException("{$field} no es valido.");
        }

        return $value;
    }

    public static function clp(mixed $value): int
    {
        if (! is_int($value) || $value <= 0) {
            throw new InvalidArgumentException(
                'amount_clp debe ser un entero positivo en pesos.'
            );
        }

        return $value;
    }

    public static function mysqlDate(string $value, string $field): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);

        if ($date === false || $date->format('Y-m-d H:i:s') !== $value) {
            throw new InvalidArgumentException("{$field} no es valido.");
        }

        return $value;
    }

    public static function nullableMysqlDate(
        ?string $value,
        string $field
    ): ?string {
        return $value === null ? null : self::mysqlDate($value, $field);
    }

    public static function utcDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (
            preg_match(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}'
                . '(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D',
                $value
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'transaction_date no es ISO-8601 UTC valido.'
            );
        }

        try {
            $date = new DateTimeImmutable($value);
        } catch (\Exception) {
            throw new InvalidArgumentException(
                'transaction_date no es ISO-8601 UTC valido.'
            );
        }

        $errors = DateTimeImmutable::getLastErrors();

        if (
            $errors !== false
            && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)
        ) {
            throw new InvalidArgumentException(
                'transaction_date no es ISO-8601 UTC valido.'
            );
        }

        return $date->setTimezone(new DateTimeZone('UTC'))->format(
            str_contains($value, '.')
            ? 'Y-m-d\TH:i:s.u\Z'
            : 'Y-m-d\TH:i:s\Z'
        );
    }

    public static function nullableCode(
        ?string $value,
        string $field,
        int $maximum
    ): ?string {
        if ($value === null) {
            return null;
        }

        return self::identifier($value, $field, $maximum);
    }

    private function __construct()
    {
    }
}
