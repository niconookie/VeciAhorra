<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Repository;

use VeciAhorra\Database\Repository;
use VeciAhorra\Exceptions\PersistenceException;

class WebpayReturnRepository extends Repository
{
    private const TABLE = 'webpay_returns';

    public function claim(
        string $tokenHash,
        ?int $paymentSessionId,
        string $flow,
        string $now
    ): array {
        $result = $this->db()->query($this->db()->prepare(
            sprintf(
                'INSERT IGNORE INTO %s'
                . ' (token_hash, payment_session_id, flow, processing_status,'
                . ' created_at, updated_at) VALUES (%%s, %%d, %%s, %%s, %%s, %%s)',
                $this->table(self::TABLE)
            ),
            $tokenHash,
            $paymentSessionId ?? 0,
            $flow,
            'processing',
            $now,
            $now
        ));

        if ($result === false) {
            throw new PersistenceException(
                'No fue posible registrar el retorno Webpay.'
            );
        }

        if ($result === 1) {
            return ['claimed' => true, 'row' => null];
        }

        return ['claimed' => false, 'row' => $this->find($tokenHash)];
    }

    public function find(string $tokenHash): ?array
    {
        $row = $this->db()->get_row($this->db()->prepare(
            sprintf(
                'SELECT * FROM %s WHERE token_hash = %%s LIMIT 1',
                $this->table(self::TABLE)
            ),
            $tokenHash
        ), ARRAY_A);

        return $row === null ? null : $row;
    }

    public function complete(
        string $tokenHash,
        string $resultStatus,
        array $result,
        string $now
    ): void {
        $encoded = wp_json_encode($result, JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded)) {
            throw new PersistenceException(
                'No fue posible normalizar el retorno Webpay.'
            );
        }

        $updated = $this->db()->query($this->db()->prepare(
            sprintf(
                'UPDATE %s SET processing_status = %%s, result_status = %%s,'
                . ' result_json = %%s, updated_at = %%s'
                . ' WHERE token_hash = %%s AND processing_status = %%s',
                $this->table(self::TABLE)
            ),
            'completed',
            $resultStatus,
            $encoded,
            $now,
            $tokenHash,
            'processing'
        ));

        if ($updated !== 1) {
            throw new PersistenceException(
                'El retorno Webpay cambio durante su procesamiento.'
            );
        }
    }

    public function fail(string $tokenHash, string $now): void
    {
        $updated = $this->db()->query($this->db()->prepare(
            sprintf(
                'UPDATE %s SET processing_status = %%s, updated_at = %%s'
                . ' WHERE token_hash = %%s AND processing_status = %%s',
                $this->table(self::TABLE)
            ),
            'retryable',
            $now,
            $tokenHash,
            'processing'
        ));

        if ($updated === false) {
            throw new PersistenceException(
                'No fue posible liberar el retorno Webpay.'
            );
        }
    }

    public function retry(string $tokenHash, string $now): bool
    {
        return $this->db()->query($this->db()->prepare(
            sprintf(
                'UPDATE %s SET processing_status = %%s, updated_at = %%s'
                . ' WHERE token_hash = %%s AND processing_status = %%s',
                $this->table(self::TABLE)
            ),
            'processing',
            $now,
            $tokenHash,
            'retryable'
        )) === 1;
    }
}
