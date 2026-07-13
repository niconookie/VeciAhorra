<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\Repository;

use VeciAhorra\Database\Repository;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\FinancialFingerprintComponents;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\ValidatedFinancialResult;
use VeciAhorra\Modules\Payments\Reconciliation\Exception\DuplicateValidatedFinancialResult;
use VeciAhorra\Modules\Payments\Reconciliation\Support\DatabaseErrorClassifier;
use VeciAhorra\Modules\Payments\Reconciliation\Support\FinancialFingerprint;

final class ValidatedFinancialResultRepository extends Repository
{
    private const TABLE = 'webpay_returns';

    public function create(ValidatedFinancialResult $result): int
    {
        $components = $result->components();
        $inserted = $this->db()->insert($this->table(self::TABLE), [
            'token_hash' => $result->tokenHash(),
            'payment_session_id' => null,
            'flow' => 'reconciliation',
            'processing_status' => 'completed',
            'result_status' => $result->financialStatus(),
            'result_json' => null,
            'public_result_id' => $result->publicResultId(),
            'provider' => FinancialFingerprintComponents::PROVIDER,
            'environment' => $components->environment(),
            'merchant_identity_hash' => $components->merchantIdentityHash(),
            'financial_status' => $result->financialStatus(),
            'financial_operation' => $result->operation(),
            'financial_fingerprint' => $result->fingerprint(),
            'fingerprint_version' => FinancialFingerprint::VERSION,
            'provider_status' => $components->providerStatus(),
            'response_code' => $components->responseCode(),
            'amount_clp' => $components->amountClp(),
            'currency' => 'CLP',
            'buy_order' => $components->buyOrder(),
            'financial_session_id' => $components->financialSessionId(),
            'authorization_code_hash' => $components->authorizationHashValue(),
            'payment_type_code' => $components->paymentTypeCode(),
            'installments_number' => $components->installmentsNumber(),
            'accounting_date' => $components->accountingDate(),
            'transaction_date' => $components->transactionDate(),
            'safe_financial_reference' => $result->safeFinancialReference(),
            'payload_version' => FinancialFingerprint::VERSION,
            'normalized_payload_json' => FinancialFingerprint::canonicalJson($components),
            'financial_obtained_at' => $result->obtainedAt(),
            'financial_validated_at' => $result->validatedAt(),
            'created_at' => $result->obtainedAt(),
            'updated_at' => $result->validatedAt(),
        ]);

        if ($inserted === false) {
            if (DatabaseErrorClassifier::isDuplicateKey($this->db())) {
                throw new DuplicateValidatedFinancialResult(
                    'El resultado financiero ya fue registrado.'
                );
            }

            throw new PersistenceException(
                'No fue posible persistir el resultado financiero.'
            );
        }

        return (int) $this->db()->insert_id;
    }

    public function find(int $id): ?ValidatedFinancialResult
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('webpay_return_id no es valido.');
        }

        $row = $this->db()->get_row($this->db()->prepare(
            sprintf('SELECT * FROM %s WHERE id = %%d LIMIT 1', $this->table(self::TABLE)),
            $id
        ), ARRAY_A);

        return $row === null ? null : $this->hydrate($row);
    }

    public function findByFingerprint(string $fingerprint): ?ValidatedFinancialResult
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

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ValidatedFinancialResult
    {
        if (
            ! isset($row['public_result_id'], $row['financial_status'])
            || ! isset($row['financial_operation'], $row['financial_fingerprint'])
        ) {
            throw new PersistenceException(
                'El retorno no contiene un resultado financiero validado.'
            );
        }

        $components = new FinancialFingerprintComponents(
            (string) $row['environment'],
            (string) $row['merchant_identity_hash'],
            (string) $row['provider_status'],
            (int) $row['response_code'],
            (int) $row['amount_clp'],
            (string) $row['buy_order'],
            (string) $row['financial_session_id'],
            isset($row['transaction_date']) ? (string) $row['transaction_date'] : null,
            isset($row['authorization_code_hash'])
                ? (string) $row['authorization_code_hash']
                : null,
            isset($row['payment_type_code']) ? (string) $row['payment_type_code'] : null,
            isset($row['installments_number']) ? (int) $row['installments_number'] : null,
            isset($row['accounting_date']) ? (string) $row['accounting_date'] : null
        );
        $result = new ValidatedFinancialResult(
            (string) $row['public_result_id'],
            (string) $row['financial_status'],
            (string) $row['financial_operation'],
            (string) $row['token_hash'],
            (string) $row['safe_financial_reference'],
            $components,
            (string) $row['financial_obtained_at'],
            (string) $row['financial_validated_at']
        );

        if (! hash_equals((string) $row['financial_fingerprint'], $result->fingerprint())) {
            throw new PersistenceException(
                'La evidencia financiera persistida no es coherente.'
            );
        }

        return $result;
    }
}
