<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\Repository;

use VeciAhorra\Database\Repository;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\DurablePaymentOrigin;
use VeciAhorra\Modules\Payments\Reconciliation\Exception\DuplicatePaymentOriginContext;
use VeciAhorra\Modules\Payments\Reconciliation\Support\DatabaseErrorClassifier;

final class PaymentOriginContextRepository extends Repository
{
    private const TABLE = 'payment_origin_contexts';

    public function create(DurablePaymentOrigin $origin): int
    {
        $result = $this->db()->insert($this->table(self::TABLE), [
            'public_id' => $origin->publicId(),
            'site_scope' => $origin->siteScope(),
            'origin' => $origin->origin(),
            'origin_resource_id' => $origin->originResourceId(),
            'gateway_id' => $origin->gatewayId(),
            'payment_attempt_id' => $origin->paymentAttemptId(),
            'origin_key' => $origin->originKey(),
            'amount_clp' => $origin->amountClp(),
            'currency' => $origin->currency(),
            'environment' => $origin->environment(),
            'merchant_identity_hash' => $origin->merchantIdentityHash(),
            'buy_order' => $origin->buyOrder(),
            'financial_session_id' => $origin->financialSessionId(),
            'token_hash' => $origin->tokenHash(),
            'context_version' => $origin->contextVersion(),
            'created_at' => $origin->createdAt(),
            'updated_at' => $origin->updatedAt(),
            'expires_at' => $origin->expiresAt(),
        ]);

        if ($result === false) {
            if (DatabaseErrorClassifier::isDuplicateKey($this->db())) {
                throw new DuplicatePaymentOriginContext(
                    'El contexto durable ya fue registrado.'
                );
            }

            throw new PersistenceException(
                'No fue posible persistir el contexto durable.'
            );
        }

        return (int) $this->db()->insert_id;
    }

    public function find(int $id): ?DurablePaymentOrigin
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('origin_context_id no es valido.');
        }

        $row = $this->db()->get_row($this->db()->prepare(
            sprintf('SELECT * FROM %s WHERE id = %%d LIMIT 1', $this->table(self::TABLE)),
            $id
        ), ARRAY_A);

        return $row === null ? null : $this->hydrate($row);
    }

    /** @return list<DurablePaymentOrigin> */
    public function findByOrigin(
        string $siteScope,
        string $origin,
        string $resourceId
    ): array {
        $rows = $this->db()->get_results($this->db()->prepare(
            sprintf(
                'SELECT * FROM %s WHERE site_scope = %%s AND origin = %%s'
                . ' AND origin_resource_id = %%s ORDER BY id ASC',
                $this->table(self::TABLE)
            ),
            $siteScope,
            $origin,
            $resourceId
        ), ARRAY_A);

        return array_map(fn (array $row): DurablePaymentOrigin => $this->hydrate($row), $rows);
    }

    public function findByOriginKey(string $originKey): ?DurablePaymentOrigin
    {
        $row = $this->db()->get_row($this->db()->prepare(
            sprintf('SELECT * FROM %s WHERE origin_key = %%s LIMIT 1', $this->table(self::TABLE)),
            $originKey
        ), ARRAY_A);

        return $row === null ? null : $this->hydrate($row);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): DurablePaymentOrigin
    {
        return new DurablePaymentOrigin(
            (string) $row['public_id'],
            (string) $row['site_scope'],
            (string) $row['origin'],
            (string) $row['origin_resource_id'],
            (string) $row['gateway_id'],
            (string) $row['payment_attempt_id'],
            (int) $row['amount_clp'],
            (string) $row['environment'],
            (string) $row['merchant_identity_hash'],
            (string) $row['buy_order'],
            (string) $row['financial_session_id'],
            isset($row['token_hash']) ? (string) $row['token_hash'] : null,
            (int) $row['context_version'],
            (string) $row['created_at'],
            (string) $row['updated_at'],
            (string) $row['expires_at']
        );
    }
}
