<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\Repository;

use VeciAhorra\Database\Repository;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\CreatePaymentReconciliation;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\ReconciliationReferences;
use VeciAhorra\Modules\Payments\Reconciliation\Exception\DuplicateReconciliation;
use VeciAhorra\Modules\Payments\Reconciliation\Model\PaymentReconciliation;
use VeciAhorra\Modules\Payments\Reconciliation\Support\FinancialFingerprint;
use VeciAhorra\Modules\Payments\Reconciliation\Support\DatabaseErrorClassifier;

final class PaymentReconciliationRepository extends Repository
{
    private const TABLE = 'payment_reconciliations';

    public function __construct(
        private readonly ?PaymentOriginContextRepository $origins = null,
        private readonly ?ValidatedFinancialResultRepository $financialResults = null
    ) {
        parent::__construct();
    }

    public function create(CreatePaymentReconciliation $data): int
    {
        $origin = $data->origin();
        $financial = $data->financialResult();
        $inserted = $this->db()->insert($this->table(self::TABLE), [
            'public_id' => $data->publicId(),
            'webpay_return_id' => $data->webpayReturnId(),
            'origin_context_id' => $data->originContextId(),
            'provider' => 'webpay_plus',
            'fingerprint_version' => FinancialFingerprint::VERSION,
            'financial_fingerprint' => $financial->fingerprint(),
            'site_scope' => $origin->siteScope(),
            'origin' => $origin->origin(),
            'origin_resource_id' => $origin->originResourceId(),
            'gateway_id' => $origin->gatewayId(),
            'payment_attempt_id' => $origin->paymentAttemptId(),
            'origin_key' => $origin->originKey(),
            'reconciliation_status' => $data->status(),
            'business_result_code' => $data->businessResultCode(),
            'attempt_count' => $data->attemptCount(),
            'last_error_code' => $data->lastErrorCode(),
            'last_error_at' => $data->lastErrorAt(),
            'created_at' => $data->createdAt(),
            'last_attempt_at' => $data->lastAttemptAt(),
            'reconciled_at' => $data->reconciledAt(),
            'updated_at' => $data->updatedAt(),
        ]);

        if ($inserted === false) {
            if (DatabaseErrorClassifier::isDuplicateKey($this->db())) {
                throw new DuplicateReconciliation(
                    'La conciliacion ya fue registrada.'
                );
            }

            throw new PersistenceException(
                'No fue posible persistir la conciliacion.'
            );
        }

        return (int) $this->db()->insert_id;
    }

    public function find(int $id): ?PaymentReconciliation
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('reconciliation_id no es valido.');
        }

        $row = $this->db()->get_row($this->db()->prepare(
            sprintf('SELECT * FROM %s WHERE id = %%d LIMIT 1', $this->table(self::TABLE)),
            $id
        ), ARRAY_A);

        return $row === null ? null : $this->hydrate($row);
    }

    public function findByFingerprint(string $fingerprint): ?PaymentReconciliation
    {
        $row = $this->db()->get_row($this->db()->prepare(
            sprintf(
                'SELECT * FROM %s WHERE financial_fingerprint = %%s LIMIT 1',
                $this->table(self::TABLE)
            ),
            $fingerprint
        ), ARRAY_A);

        return $row === null ? null : $this->hydrate($row);
    }

    public function findReferences(int $id): ?ReconciliationReferences
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('reconciliation_id no es valido.');
        }

        $row = $this->db()->get_row($this->db()->prepare(
            sprintf(
                'SELECT id, webpay_return_id, origin_context_id, provider,'
                . ' fingerprint_version, financial_fingerprint, origin_key,'
                . ' reconciliation_status FROM %s WHERE id = %%d LIMIT 1',
                $this->table(self::TABLE)
            ),
            $id
        ), ARRAY_A);

        if ($this->db()->last_error !== '') {
            throw new PersistenceException(
                'No fue posible leer las referencias de conciliacion.'
            );
        }

        if ($row === null) {
            return null;
        }

        return new ReconciliationReferences(
            (int) $row['id'],
            (int) $row['webpay_return_id'],
            (int) $row['origin_context_id'],
            (string) $row['provider'],
            (int) $row['fingerprint_version'],
            (string) $row['financial_fingerprint'],
            (string) $row['origin_key'],
            (string) $row['reconciliation_status']
        );
    }

    /** @return list<PaymentReconciliation> */
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

        return array_map(fn (array $row): PaymentReconciliation => $this->hydrate($row), $rows);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): PaymentReconciliation
    {
        $origin = ($this->origins ?? new PaymentOriginContextRepository())->find(
            (int) $row['origin_context_id']
        );
        $financial = ($this->financialResults
            ?? new ValidatedFinancialResultRepository())->find(
                (int) $row['webpay_return_id']
            );

        if ($origin === null || $financial === null) {
            throw new PersistenceException(
                'La conciliacion no posee sus autoridades durables.'
            );
        }

        if (
            ! hash_equals((string) $row['origin_key'], $origin->originKey())
            || ! hash_equals(
                (string) $row['financial_fingerprint'],
                $financial->fingerprint()
            )
        ) {
            throw new PersistenceException(
                'La conciliacion persistida no es coherente.'
            );
        }

        return new PaymentReconciliation(
            (int) $row['id'],
            (string) $row['public_id'],
            (int) $row['webpay_return_id'],
            (int) $row['origin_context_id'],
            $financial,
            $origin,
            (string) $row['reconciliation_status'],
            isset($row['business_result_code']) ? (string) $row['business_result_code'] : null,
            (int) $row['attempt_count'],
            isset($row['last_error_code']) ? (string) $row['last_error_code'] : null,
            isset($row['last_error_at']) ? (string) $row['last_error_at'] : null,
            (string) $row['created_at'],
            isset($row['last_attempt_at']) ? (string) $row['last_attempt_at'] : null,
            isset($row['reconciled_at']) ? (string) $row['reconciled_at'] : null,
            (string) $row['updated_at'],
            isset($row['lease_owner']) ? (string) $row['lease_owner'] : null,
            isset($row['lease_acquired_at'])
                ? (string) $row['lease_acquired_at']
                : null,
            isset($row['lease_expires_at'])
                ? (string) $row['lease_expires_at']
                : null,
            (int) ($row['lease_version'] ?? 0)
        );
    }
}
