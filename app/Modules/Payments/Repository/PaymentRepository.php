<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Repository;

use VeciAhorra\Database\Repository;
use VeciAhorra\Exceptions\PersistenceException;

class PaymentRepository extends Repository
{
    private const PAYMENTS_TABLE = 'payments';
    private const ORDERS_TABLE = 'payment_orders';

    private const PAYMENT_FIELDS = [
        'payment_reference', 'customer_id', 'amount', 'currency', 'status',
        'provider', 'provider_reference', 'expires_at', 'paid_at',
        'created_at', 'updated_at',
    ];

    public function create(array $payment): int
    {
        $result = $this->db()->insert(
            $this->table(self::PAYMENTS_TABLE),
            array_intersect_key(
                $payment,
                array_flip(self::PAYMENT_FIELDS)
            )
        );

        if ($result === false || (int) $this->db()->insert_id <= 0) {
            throw new PersistenceException('No fue posible crear el pago.');
        }

        return (int) $this->db()->insert_id;
    }

    /** @param list<int> $orderIds */
    public function attachOrders(
        int $paymentId,
        array $orderIds,
        string $createdAt
    ): void {
        foreach ($orderIds as $orderId) {
            $result = $this->db()->insert(
                $this->table(self::ORDERS_TABLE),
                [
                    'payment_id' => $paymentId,
                    'order_id' => $orderId,
                    'created_at' => $createdAt,
                ]
            );

            if ($result === false) {
                throw new PersistenceException(
                    'No fue posible asociar los pedidos al pago.'
                );
            }
        }
    }

    public function find(int $id): ?array
    {
        $payment = $this->db()->get_row(
            $this->db()->prepare(
                sprintf(
                    'SELECT * FROM %s WHERE id = %%d LIMIT 1',
                    $this->table(self::PAYMENTS_TABLE)
                ),
                $id
            ),
            ARRAY_A
        );

        return $payment === null ? null : $this->withOrders($payment);
    }

    public function findByReference(string $reference): ?array
    {
        $payment = $this->db()->get_row(
            $this->db()->prepare(
                sprintf(
                    'SELECT *
                     FROM %s
                     WHERE payment_reference = %%s
                     LIMIT 1',
                    $this->table(self::PAYMENTS_TABLE)
                ),
                $reference
            ),
            ARRAY_A
        );

        return $payment === null ? null : $this->withOrders($payment);
    }

    public function updateSessionData(
        int $id,
        string $provider,
        string $providerReference,
        string $expiresAt,
        string $updatedAt
    ): void {
        $result = $this->db()->query($this->db()->prepare(
            sprintf(
                'UPDATE %s
                 SET provider = %%s,
                     provider_reference = %%s,
                     expires_at = %%s,
                     updated_at = %%s
                 WHERE id = %%d
                   AND status = %%s',
                $this->table(self::PAYMENTS_TABLE)
            ),
            $provider,
            $providerReference,
            $expiresAt,
            $updatedAt,
            $id,
            'pending'
        ));

        if ($result !== 1) {
            throw new PersistenceException(
                'No fue posible guardar la sesion de pago.'
            );
        }
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $payments = $this->db()->get_results(
            sprintf(
                'SELECT * FROM %s ORDER BY id DESC',
                $this->table(self::PAYMENTS_TABLE)
            ),
            ARRAY_A
        );

        return array_map(
            fn (array $payment): array => $this->withOrders($payment),
            $payments
        );
    }

    public function delete(int $id): void
    {
        $ordersResult = $this->db()->delete(
            $this->table(self::ORDERS_TABLE),
            ['payment_id' => $id]
        );
        $paymentResult = $this->db()->delete(
            $this->table(self::PAYMENTS_TABLE),
            ['id' => $id]
        );

        if ($ordersResult === false || $paymentResult === false) {
            throw new PersistenceException(
                'No fue posible limpiar el pago incompleto.'
            );
        }
    }

    private function withOrders(array $payment): array
    {
        $payment['order_ids'] = array_map(
            'intval',
            $this->db()->get_col($this->db()->prepare(
                sprintf(
                    'SELECT order_id
                     FROM %s
                     WHERE payment_id = %%d
                     ORDER BY id ASC',
                    $this->table(self::ORDERS_TABLE)
                ),
                (int) $payment['id']
            ))
        );

        return $payment;
    }
}
