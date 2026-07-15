<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Service;

use InvalidArgumentException;

final class IdempotencyService
{
    public function key(string $value): string
    {
        $value = trim($value, " \t");

        if (
            strlen($value) < 16
            || strlen($value) > 128
            || preg_match('/^[A-Za-z0-9._:-]+$/D', $value) !== 1
        ) {
            throw new InvalidArgumentException(
                'Idempotency-Key debe contener entre 16 y 128 caracteres validos.'
            );
        }

        return $value;
    }

    public function owner(array $owner): array
    {
        $userId = $owner['user_id'] ?? null;

        if (is_int($userId) && $userId > 0) {
            return [
                'owner_type' => 'user',
                'user_id' => $userId,
                'session_id' => null,
            ];
        }

        $sessionId = $owner['session_id'] ?? null;

        if (! is_string($sessionId) || trim($sessionId) === '') {
            throw new InvalidArgumentException('El checkout requiere un owner valido.');
        }

        return [
            'owner_type' => 'session',
            'user_id' => null,
            'session_id' => hash_hmac(
                'sha256',
                trim($sessionId),
                wp_salt('auth')
            ),
        ];
    }

    public function fingerprint(
        string $checkoutPublicId,
        array $owner,
        string $currency,
        string $amount,
        array $orderIds,
        ?string $fulfillmentMethod = null
    ): string {
        sort($orderIds, SORT_NUMERIC);
        $stableOwner = $owner['owner_type'] === 'user'
            ? (string) $owner['user_id']
            : (string) $owner['session_id'];
        $canonical = [
            'operation' => 'payment_session.start.v1',
            'checkout_public_id' => $checkoutPublicId,
            'owner' => [
                'type' => $owner['owner_type'],
                'stable_id' => $stableOwner,
            ],
            'currency' => $currency,
            'total_amount' => $amount,
            'orders' => array_values($orderIds),
            'fulfillment_method' => $fulfillmentMethod,
            'gateway' => 'webpay_plus',
        ];

        return hash('sha256', (string) wp_json_encode(
            $canonical,
            JSON_UNESCAPED_SLASHES
        ));
    }
}
