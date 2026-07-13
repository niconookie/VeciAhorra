<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Checkout\Repository;

use VeciAhorra\Database\Repository;
use VeciAhorra\Exceptions\PersistenceException;

final class CheckoutOrderRepository extends Repository
{
    private const TABLE = 'checkout_orders';

    public function attach(int $checkoutId, array $orderIds, string $createdAt): void
    {
        foreach ($orderIds as $orderId) {
            $result = $this->db()->insert($this->table(self::TABLE), [
                'checkout_id' => $checkoutId,
                'order_id' => $orderId,
                'created_at' => $createdAt,
            ]);

            if ($result === false) {
                throw new PersistenceException(
                    'No fue posible asociar los pedidos al checkout.'
                );
            }
        }
    }

    public function findOrderIds(int $checkoutId, bool $forUpdate = false): array
    {
        $database = $this->db();
        $ids = $database->get_col($database->prepare(
            sprintf(
                'SELECT order_id FROM %s WHERE checkout_id = %%d'
                . ' ORDER BY order_id ASC%s',
                $this->table(self::TABLE),
                $forUpdate ? ' FOR UPDATE' : ''
            ),
            $checkoutId
        ));

        if ($forUpdate && $database->last_error !== '') {
            throw new PersistenceException(
                'No fue posible bloquear CheckoutOrders.'
            );
        }

        return array_map('intval', $ids);
    }

    public function findAttachedOrderIds(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($orderIds), '%d'));

        return array_map('intval', $this->db()->get_col($this->db()->prepare(
            sprintf(
                'SELECT order_id FROM %s WHERE order_id IN (%s)',
                $this->table(self::TABLE),
                $placeholders
            ),
            ...$orderIds
        )));
    }
}
