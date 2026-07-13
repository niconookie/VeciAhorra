<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Support;

use InvalidArgumentException;

final class PaymentConfirmationFingerprint
{
    public const VERSION = 1;

    public static function make(array $data): string
    {
        $requiredIntegers = [
            'payment_session_id', 'payment_id', 'checkout_id', 'amount',
        ];

        foreach ($requiredIntegers as $field) {
            if (! is_int($data[$field] ?? null) || $data[$field] <= 0) {
                throw new InvalidArgumentException(
                    "El campo {$field} del fingerprint no es valido."
                );
            }
        }

        $orderIds = $data['order_ids'] ?? null;

        if (! is_array($orderIds) || $orderIds === []) {
            throw new InvalidArgumentException(
                'El fingerprint requiere Orders.'
            );
        }

        foreach ($orderIds as $orderId) {
            if (! is_int($orderId) || $orderId <= 0) {
                throw new InvalidArgumentException(
                    'Los IDs de Order del fingerprint no son validos.'
                );
            }
        }

        $orderIds = array_values(array_unique($orderIds));
        sort($orderIds, SORT_NUMERIC);
        $normalized = [
            'version' => self::VERSION,
            'provider' => self::string($data, 'provider', 2, 50),
            'payment_session_id' => $data['payment_session_id'],
            'payment_id' => $data['payment_id'],
            'checkout_id' => $data['checkout_id'],
            'order_ids' => $orderIds,
            'amount' => $data['amount'],
            'currency' => strtoupper(self::string($data, 'currency', 3, 3)),
            'buy_order' => self::string($data, 'buy_order', 1, 64),
            'financial_session_id' => self::string(
                $data,
                'financial_session_id',
                1,
                64
            ),
            'safe_financial_reference' => self::string(
                $data,
                'safe_financial_reference',
                8,
                64
            ),
            'transaction_date' => self::string(
                $data,
                'transaction_date',
                10,
                40
            ),
        ];
        if (
            preg_match(
                '/^sha256:[a-f0-9]{12,56}$/D',
                $normalized['safe_financial_reference']
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'La referencia financiera segura no es valida.'
            );
        }
        $json = json_encode(
            $normalized,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        return hash('sha256', $json);
    }

    public static function matches(string $expected, string $actual): bool
    {
        self::assertHash($expected);
        self::assertHash($actual);

        return hash_equals($expected, $actual);
    }

    public static function assertHash(string $fingerprint): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new InvalidArgumentException(
                'El fingerprint de confirmacion no es valido.'
            );
        }
    }

    private static function string(
        array $data,
        string $field,
        int $minimum,
        int $maximum
    ): string {
        $value = $data[$field] ?? null;

        if (
            ! is_string($value)
            || $value !== trim($value)
            || strlen($value) < $minimum
            || strlen($value) > $maximum
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            throw new InvalidArgumentException(
                "El campo {$field} del fingerprint no es valido."
            );
        }

        return $value;
    }
}
