<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\Repository;

use VeciAhorra\Database\Repository;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\DurablePaymentOrigin;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\PaymentOriginTokenBindResult;
use VeciAhorra\Modules\Payments\Reconciliation\Exception\DuplicatePaymentOriginContext;
use VeciAhorra\Modules\Payments\Reconciliation\Support\DatabaseErrorClassifier;
use VeciAhorra\Modules\Payments\Reconciliation\Support\ReconciliationValidation;

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

    public function findByPaymentAttemptId(
        string $paymentAttemptId
    ): ?DurablePaymentOrigin {
        $row = $this->db()->get_row($this->db()->prepare(
            sprintf(
                'SELECT * FROM %s WHERE payment_attempt_id = %%s LIMIT 1',
                $this->table(self::TABLE)
            ),
            $paymentAttemptId
        ), ARRAY_A);

        return $row === null ? null : $this->hydrate($row);
    }

    public function findByTokenHash(string $tokenHash): ?DurablePaymentOrigin
    {
        ReconciliationValidation::hash($tokenHash, 'token_hash');
        $row = $this->db()->get_row($this->db()->prepare(
            sprintf(
                'SELECT * FROM %s WHERE token_hash = %%s LIMIT 1',
                $this->table(self::TABLE)
            ),
            $tokenHash
        ), ARRAY_A);

        return $row === null ? null : $this->hydrate($row);
    }

    public function bindTokenHash(
        int $originContextId,
        string $paymentAttemptId,
        string $tokenHash,
        string $updatedAt
    ): PaymentOriginTokenBindResult {
        if ($originContextId <= 0) {
            throw new \InvalidArgumentException('origin_context_id no es valido.');
        }

        ReconciliationValidation::identifier(
            $paymentAttemptId,
            'payment_attempt_id'
        );
        ReconciliationValidation::hash($tokenHash, 'token_hash');
        ReconciliationValidation::mysqlDate($updatedAt, 'updated_at');
        $database = $this->db();
        $previousSuppression = $database->suppress_errors(true);

        try {
            $updated = $database->query($database->prepare(
                sprintf(
                    'UPDATE %s SET token_hash = %%s, updated_at = %%s'
                    . ' WHERE id = %%d AND payment_attempt_id = %%s'
                    . ' AND token_hash IS NULL AND expires_at > UTC_TIMESTAMP()',
                    $this->table(self::TABLE)
                ),
                $tokenHash,
                $updatedAt,
                $originContextId,
                $paymentAttemptId
            ));
        } finally {
            $database->suppress_errors($previousSuppression);
        }

        if ($updated === false) {
            if (DatabaseErrorClassifier::isDuplicateKey($database)) {
                return new PaymentOriginTokenBindResult(
                    PaymentOriginTokenBindResult::TOKEN_CONFLICT
                );
            }

            throw new PersistenceException('No fue posible vincular el token seguro.');
        }

        if ($updated === 1) {
            return new PaymentOriginTokenBindResult(
                PaymentOriginTokenBindResult::BOUND
            );
        }

        $origin = $this->find($originContextId);

        if ($origin === null) {
            return new PaymentOriginTokenBindResult(
                PaymentOriginTokenBindResult::NOT_FOUND
            );
        }

        if (! hash_equals($origin->paymentAttemptId(), $paymentAttemptId)) {
            return new PaymentOriginTokenBindResult(
                PaymentOriginTokenBindResult::ATTEMPT_MISMATCH
            );
        }

        if ($origin->tokenHash() !== null) {
            return new PaymentOriginTokenBindResult(
                hash_equals($origin->tokenHash(), $tokenHash)
                    ? PaymentOriginTokenBindResult::ALREADY_BOUND
                    : PaymentOriginTokenBindResult::TOKEN_CONFLICT
            );
        }

        return new PaymentOriginTokenBindResult(
            PaymentOriginTokenBindResult::EXPIRED
        );
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
