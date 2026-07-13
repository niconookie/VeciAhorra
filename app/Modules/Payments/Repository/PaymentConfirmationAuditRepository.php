<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Repository;

use VeciAhorra\Database\Repository;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Payments\Models\PaymentConfirmationAudit;
use VeciAhorra\Modules\Payments\Support\PaymentConfirmationFingerprint;

class PaymentConfirmationAuditRepository extends Repository
{
    private const TABLE = 'payment_confirmation_audits';

    public function insert(PaymentConfirmationAudit $audit): int
    {
        $result = $this->db()->insert(
            $this->table(self::TABLE),
            $audit->toPersistence()
        );

        if ($result !== 1 || (int) $this->db()->insert_id <= 0) {
            throw new PersistenceException(
                'No fue posible registrar la auditoria de confirmacion.'
            );
        }

        return (int) $this->db()->insert_id;
    }

    public function findByCorrelationId(string $correlationId): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $correlationId) !== 1) {
            throw new \InvalidArgumentException(
                'correlation_id no es valido.'
            );
        }

        return $this->findBy('correlation_id', $correlationId);
    }

    public function findByPaymentSessionId(int $paymentSessionId): array
    {
        if ($paymentSessionId <= 0) {
            throw new \InvalidArgumentException(
                'payment_session_id no es valido.'
            );
        }

        return $this->db()->get_results($this->db()->prepare(
            sprintf(
                'SELECT * FROM %s WHERE payment_session_id = %%d'
                . ' ORDER BY id ASC',
                $this->table(self::TABLE)
            ),
            $paymentSessionId
        ), ARRAY_A);
    }

    public function findByFingerprint(string $fingerprint): array
    {
        PaymentConfirmationFingerprint::assertHash($fingerprint);

        return $this->findBy('confirmation_fingerprint', $fingerprint);
    }

    public function countEvent(int $paymentSessionId, string $eventType): int
    {
        if (
            $paymentSessionId <= 0
            || ! PaymentConfirmationAudit::validEvent($eventType)
        ) {
            throw new \InvalidArgumentException(
                'El evento de auditoria no es valido.'
            );
        }

        return (int) $this->db()->get_var($this->db()->prepare(
            sprintf(
                'SELECT COUNT(*) FROM %s'
                . ' WHERE payment_session_id = %%d AND event_type = %%s',
                $this->table(self::TABLE)
            ),
            $paymentSessionId,
            $eventType
        ));
    }

    public function hasEventKey(string $eventKey): bool
    {
        PaymentConfirmationFingerprint::assertHash($eventKey);

        return (int) $this->db()->get_var($this->db()->prepare(
            sprintf(
                'SELECT COUNT(*) FROM %s WHERE event_key = %%s',
                $this->table(self::TABLE)
            ),
            $eventKey
        )) === 1;
    }

    private function findBy(string $column, string $value): array
    {
        if (
            $value === ''
            || ! in_array($column, [
                'correlation_id',
                'confirmation_fingerprint',
            ], true)
        ) {
            throw new \InvalidArgumentException(
                'El criterio de auditoria no es valido.'
            );
        }

        return $this->db()->get_results($this->db()->prepare(
            sprintf(
                'SELECT * FROM %s WHERE %s = %%s ORDER BY id ASC',
                $this->table(self::TABLE),
                $column
            ),
            $value
        ), ARRAY_A);
    }
}
