<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Repository;

use VeciAhorra\Database\Repository;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Payments\Models\PaymentSession;
use VeciAhorra\Modules\Payments\Support\PaymentConfirmationFingerprint;

class PaymentSessionRepository extends Repository
{
    private const TABLE = 'payment_sessions';

    public function create(array $data): int
    {
        $result = $this->db()->insert($this->table(self::TABLE), $data);

        if ($result === false || (int) $this->db()->insert_id <= 0) {
            throw new PersistenceException('No fue posible crear la sesion de pago.');
        }

        return (int) $this->db()->insert_id;
    }

    public function find(int $id): ?array
    {
        $database = $this->db();
        $row = $database->get_row($database->prepare(
            sprintf('SELECT * FROM %s WHERE id = %%d LIMIT 1', $this->table(self::TABLE)),
            $id
        ), ARRAY_A);

        return $row === null ? null : $row;
    }

    public function findForUpdate(int $id): ?array
    {
        $this->assertPositive($id, 'payment_session_id');
        $this->assertActiveTransaction();
        $database = $this->db();
        $row = $database->get_row($database->prepare(
            sprintf(
                'SELECT * FROM %s WHERE id = %%d LIMIT 1 FOR UPDATE',
                $this->table(self::TABLE)
            ),
            $id
        ), ARRAY_A);

        if ($database->last_error !== '') {
            throw new PersistenceException(
                'No fue posible bloquear PaymentSession.'
            );
        }

        return $row === null ? null : $row;
    }

    public function findByPublicIdForUpdate(string $publicId): ?array
    {
        $this->assertActiveTransaction();
        $row = $this->db()->get_row($this->db()->prepare(
            sprintf('SELECT * FROM %s WHERE public_id = %%s LIMIT 1 FOR UPDATE', $this->table(self::TABLE)),
            $publicId
        ), ARRAY_A);
        return $row === null ? null : $row;
    }

    public function findByCheckoutId(int $checkoutId): array
    {
        $this->assertPositive($checkoutId, 'checkout_id');

        return $this->db()->get_results($this->db()->prepare(
            sprintf(
                'SELECT * FROM %s WHERE checkout_id = %%d ORDER BY id ASC',
                $this->table(self::TABLE)
            ),
            $checkoutId
        ), ARRAY_A);
    }

    public function findByPaymentId(int $paymentId): ?array
    {
        $this->assertPositive($paymentId, 'payment_id');

        $row = $this->db()->get_row($this->db()->prepare(
            sprintf(
                'SELECT * FROM %s WHERE payment_id = %%d LIMIT 1',
                $this->table(self::TABLE)
            ),
            $paymentId
        ), ARRAY_A);

        return $row === null ? null : $row;
    }

    public function linkPayment(int $sessionId, int $paymentId): void
    {
        if ($sessionId <= 0 || $paymentId <= 0) {
            throw new \InvalidArgumentException(
                'Los IDs de la relacion de pago no son validos.'
            );
        }

        $this->assertActiveTransaction();
        $result = $this->db()->query($this->db()->prepare(
            sprintf(
                'UPDATE %s SET payment_id = %%d, updated_at = %%s'
                . ' WHERE id = %%d AND payment_id IS NULL',
                $this->table(self::TABLE)
            ),
            $paymentId,
            current_time('mysql'),
            $sessionId
        ));

        if ($result === false) {
            throw new PersistenceException(
                'No fue posible vincular PaymentSession y Payment.'
            );
        }

        if ($result !== 1) {
            $stored = $this->find($sessionId);

            if ((int) ($stored['payment_id'] ?? 0) !== $paymentId) {
                throw new PersistenceException(
                    'PaymentSession ya posee otra relacion de pago.'
                );
            }
        }
    }

    public function storeConfirmationEvidence(
        int $sessionId,
        int $paymentId,
        string $fingerprint,
        int $fingerprintVersion,
        string $safeFinancialReference,
        string $confirmedAt
    ): void {
        PaymentConfirmationFingerprint::assertHash($fingerprint);

        if (
            $sessionId <= 0
            || $paymentId <= 0
            || $fingerprintVersion <= 0
            || preg_match(
                '/^sha256:[a-f0-9]{12,56}$/D',
                $safeFinancialReference
            ) !== 1
            || preg_match(
                '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',
                $confirmedAt
            ) !== 1
        ) {
            throw new \InvalidArgumentException(
                'La evidencia de confirmacion no es valida.'
            );
        }

        $this->assertActiveTransaction();
        $result = $this->db()->query($this->db()->prepare(
            sprintf(
                'UPDATE %s SET status = %%s, confirmed_at = %%s,'
                . ' confirmation_fingerprint = %%s,'
                . ' confirmation_fingerprint_version = %%d,'
                . ' safe_financial_reference = %%s, updated_at = %%s'
                . ' WHERE id = %%d AND payment_id = %%d AND status = %%s'
                . ' AND confirmed_at IS NULL',
                $this->table(self::TABLE)
            ),
            PaymentSession::STATUS_CONFIRMED,
            $confirmedAt,
            $fingerprint,
            $fingerprintVersion,
            $safeFinancialReference,
            $confirmedAt,
            $sessionId,
            $paymentId,
            PaymentSession::STATUS_READY
        ));

        if ($result !== 1) {
            throw new PersistenceException(
                'No fue posible guardar la evidencia de confirmacion.'
            );
        }
    }

    public function findByKey(int $checkoutId, string $key): ?array
    {
        $row = $this->db()->get_row($this->db()->prepare(
            sprintf(
                'SELECT * FROM %s WHERE checkout_id = %%d'
                . ' AND idempotency_key = %%s LIMIT 1',
                $this->table(self::TABLE)
            ),
            $checkoutId,
            $key
        ), ARRAY_A);

        return $row === null ? null : $row;
    }

    public function findByProviderSessionId(string $providerSessionId): ?array
    {
        $row = $this->db()->get_row($this->db()->prepare(
            sprintf(
                'SELECT ps.*, c.public_id AS checkout_public_id'
                . ' FROM %s ps INNER JOIN %s c ON c.id = ps.checkout_id'
                . ' WHERE ps.provider_session_id = %%s AND ps.provider = %%s'
                . ' LIMIT 1',
                $this->table(self::TABLE),
                $this->table('checkouts')
            ),
            $providerSessionId,
            'webpay_plus'
        ), ARRAY_A);

        return $row === null ? null : $row;
    }

    public function findActive(int $checkoutId, string $now): ?array
    {
        $row = $this->db()->get_row($this->db()->prepare(
            sprintf(
                'SELECT * FROM %s WHERE checkout_id = %%d'
                . ' AND status IN (%%s, %%s) AND expires_at > %%s'
                . ' ORDER BY id DESC LIMIT 1',
                $this->table(self::TABLE)
            ),
            $checkoutId,
            'pending',
            'ready',
            $now
        ), ARRAY_A);

        return $row === null ? null : $row;
    }

    public function findOwnedByPublicId(string $publicId, array $owner): ?array
    {
        $ownerField = $owner['owner_type'] === 'user' ? 'user_id' : 'session_id';
        $placeholder = $owner['owner_type'] === 'user' ? '%d' : '%s';
        $value = $owner[$ownerField];
        $row = $this->db()->get_row($this->db()->prepare(
            sprintf(
                'SELECT ps.*, c.public_id AS checkout_public_id'
                . ' FROM %s ps INNER JOIN %s c ON c.id = ps.checkout_id'
                . ' WHERE ps.public_id = %%s AND c.owner_type = %%s'
                . ' AND c.%s = %s LIMIT 1',
                $this->table(self::TABLE),
                $this->table('checkouts'),
                $ownerField,
                $placeholder
            ),
            $publicId,
            $owner['owner_type'],
            $value
        ), ARRAY_A);

        return $row === null ? null : $row;
    }

    public function updateGatewayResult(
        int $id,
        string $expectedStatus,
        string $status,
        string $provider,
        string $providerSessionId,
        ?string $redirectUrl,
        string $expiresAt,
        string $updatedAt
    ): void {
        if (
            ! PaymentSession::validStatus($expectedStatus)
            || ! PaymentSession::validStatus($status)
            || $expectedStatus === PaymentSession::STATUS_CONFIRMED
            || $status === PaymentSession::STATUS_CONFIRMED
        ) {
            throw new \InvalidArgumentException(
                'La transicion de PaymentSession no es valida.'
            );
        }

        $redirectAssignment = $redirectUrl === null ? 'NULL' : '%s';
        $parameters = [
            $status,
            $provider,
            $providerSessionId,
        ];

        if ($redirectUrl !== null) {
            $parameters[] = $redirectUrl;
        }

        array_push(
            $parameters,
            $expiresAt,
            $updatedAt,
            $id,
            $expectedStatus
        );
        $result = $this->db()->query($this->db()->prepare(
            sprintf(
                'UPDATE %s SET status = %%s, provider = %%s,'
                . ' provider_session_id = %%s, redirect_url = %s,'
                . ' expires_at = %%s, updated_at = %%s'
                . ' WHERE id = %%d AND status = %%s',
                $this->table(self::TABLE),
                $redirectAssignment
            ),
            ...$parameters
        ));

        if ($result === false) {
            throw new PersistenceException(
                'No fue posible guardar el resultado del gateway.'
            );
        }

        if ($result === 0) {
            $stored = $this->find($id);

            if (
                $stored === null
                || ($stored['status'] ?? null) !== $status
                || ($stored['provider_session_id'] ?? null)
                    !== $providerSessionId
            ) {
                throw new PersistenceException(
                    'La sesion cambio durante la respuesta del gateway.'
                );
            }
        }
    }

    private function assertActiveTransaction(): void
    {
        if ((int) $this->db()->get_var('SELECT @@in_transaction') !== 1) {
            throw new PersistenceException(
                'La operacion de lock requiere una transaccion activa.'
            );
        }
    }

    private function assertPositive(int $value, string $field): void
    {
        if ($value <= 0) {
            throw new \InvalidArgumentException("{$field} no es valido.");
        }
    }
}
