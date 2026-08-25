<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Catalog\Security;

use InvalidArgumentException;
use VeciAhorra\Modules\Frontend\Support\CartSession;

final class PublicOfferToken
{
    private const VERSION = 1;
    private const PURPOSE = 'offer:add-to-cart';
    private const TTL = 900;
    private const AAD = 'veciahorra-public-offer|v1';

    public function issue(
        int $inventoryId,
        int $productId,
        int $sectorId,
        array $owner = []
    ): string
    {
        if ($inventoryId <= 0 || $productId <= 0 || $sectorId <= 0) {
            throw $this->invalid();
        }

        $issuedAt = time();
        $payload = wp_json_encode([
            'v' => self::VERSION,
            'u' => self::PURPOSE,
            'i' => $inventoryId,
            'p' => $productId,
            'z' => $sectorId,
            'o' => $this->ownerBinding($owner),
            'a' => $issuedAt,
            'e' => $issuedAt + self::TTL,
        ], JSON_UNESCAPED_SLASHES);
        if (! is_string($payload)) {
            throw $this->invalid();
        }

        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $payload,
            'aes-256-gcm',
            $this->key(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::AAD,
            16
        );
        if (! is_string($ciphertext) || strlen($tag) !== 16) {
            throw $this->invalid();
        }

        return $this->encode($nonce . $tag . $ciphertext);
    }

    /** @return array{inventory_id:int, product_id:int, sector_id:int} */
    public function resolve(string $token, array $owner = []): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{40,512}$/D', $token) !== 1) {
            throw $this->invalid();
        }

        $raw = $this->decode($token);
        if ($raw === null || strlen($raw) < 29) {
            throw $this->invalid();
        }

        $plain = openssl_decrypt(
            substr($raw, 28),
            'aes-256-gcm',
            $this->key(),
            OPENSSL_RAW_DATA,
            substr($raw, 0, 12),
            substr($raw, 12, 16),
            self::AAD
        );
        $data = is_string($plain) ? json_decode($plain, true) : null;
        $now = time();
        $expectedKeys = ['a', 'e', 'i', 'o', 'p', 'u', 'v', 'z'];
        $actualKeys = is_array($data) ? array_keys($data) : [];
        sort($actualKeys);

        if (
            ! is_array($data)
            || $actualKeys !== $expectedKeys
            || $data['v'] !== self::VERSION
            || $data['u'] !== self::PURPOSE
            || ! is_int($data['i']) || $data['i'] <= 0
            || ! is_int($data['p']) || $data['p'] <= 0
            || ! is_int($data['z']) || $data['z'] <= 0
            || ! is_string($data['o'])
            || ! is_int($data['a']) || ! is_int($data['e'])
            || $data['a'] > $now
            || $data['e'] <= $now
            || $data['e'] - $data['a'] !== self::TTL
            || ! hash_equals($data['o'], $this->ownerBinding($owner))
        ) {
            throw $this->invalid();
        }

        return [
            'inventory_id' => $data['i'],
            'product_id' => $data['p'],
            'sector_id' => $data['z'],
        ];
    }

    private function ownerBinding(array $owner = []): string
    {
        $userId = $owner['user_id'] ?? get_current_user_id();
        if (is_int($userId) && $userId > 0) {
            $identity = 'user|' . $userId;
        } else {
            $sessionId = $owner['session_id'] ?? (new CartSession())->identifier();
            if (! is_string($sessionId) || preg_match('/^[a-f0-9]{64}$/D', $sessionId) !== 1) {
                throw $this->invalid();
            }
            $identity = 'session|' . $sessionId;
        }

        return hash_hmac('sha256', $identity, $this->key());
    }

    private function key(): string
    {
        $secret = wp_salt('auth');
        if (! is_string($secret) || strlen($secret) < 32) {
            throw $this->invalid();
        }
        $installation = hash('sha256', ABSPATH . '|' . home_url('/'), true);

        return hash_hkdf(
            'sha256',
            $secret,
            32,
            'veciahorra|public-offer|aes-256-gcm|v1',
            $installation
        );
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): ?string
    {
        $remainder = strlen($value) % 4;
        if ($remainder === 1) {
            return null;
        }
        $padded = $value . str_repeat('=', (4 - $remainder) % 4);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);

        return is_string($decoded) ? $decoded : null;
    }

    private function invalid(): InvalidArgumentException
    {
        return new InvalidArgumentException(
            'La oferta seleccionada no es válida o expiró.'
        );
    }
}
