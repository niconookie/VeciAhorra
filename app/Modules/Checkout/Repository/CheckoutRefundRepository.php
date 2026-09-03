<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Checkout\Repository;

use VeciAhorra\Database\Repository;
use VeciAhorra\Exceptions\PersistenceException;

final class CheckoutRefundRepository extends Repository
{
    private const TABLE = 'checkout_refunds';

    public function findByKey(int $checkoutId, string $key): ?array
    {
        $row = $this->db()->get_row($this->db()->prepare(
            sprintf('SELECT * FROM %s WHERE checkout_id = %%d AND idempotency_key = %%s LIMIT 1', $this->table(self::TABLE)),
            $checkoutId,
            $key
        ), ARRAY_A);
        return $row === null ? null : $row;
    }

    public function hasPaidPayment(int $checkoutId): bool
    {
        $value = $this->db()->get_var($this->db()->prepare(
            sprintf("SELECT 1 FROM %s WHERE checkout_id = %%d AND status = 'paid' LIMIT 1", $this->table('payments')),
            $checkoutId
        ));
        if ($this->db()->last_error !== '') {
            throw new PersistenceException('No fue posible verificar el pago del Checkout.');
        }
        return (string) $value === '1';
    }

    /** @return array{product_refund:string,platform_fee_refund:string,delivery_fee_refund:string} */
    public function totals(int $checkoutId): array
    {
        $row = $this->db()->get_row($this->db()->prepare(
            sprintf('SELECT COALESCE(SUM(product_refund),0) product_refund, COALESCE(SUM(platform_fee_refund),0) platform_fee_refund, COALESCE(SUM(delivery_fee_refund),0) delivery_fee_refund FROM %s WHERE checkout_id = %%d', $this->table(self::TABLE)),
            $checkoutId
        ), ARRAY_A);
        return [
            'product_refund' => (string) ($row['product_refund'] ?? '0.00'),
            'platform_fee_refund' => (string) ($row['platform_fee_refund'] ?? '0.00'),
            'delivery_fee_refund' => (string) ($row['delivery_fee_refund'] ?? '0.00'),
        ];
    }

    public function create(array $data): int
    {
        $result = $this->db()->insert($this->table(self::TABLE), $data);
        if ($result !== 1 || (int) $this->db()->insert_id <= 0) {
            throw new PersistenceException('No fue posible registrar la devolucion.');
        }
        return (int) $this->db()->insert_id;
    }
}
