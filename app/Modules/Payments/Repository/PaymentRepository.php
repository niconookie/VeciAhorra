<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Repository;

use Throwable;
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

    public function findByProviderReference(string $reference): ?array
    {
        return $this->findByProviderReferenceInternal($reference, false);
    }

    public function findByPaymentSessionId(int $paymentSessionId): ?array
    {
        return $this->findByPaymentSessionIdInternal(
            $paymentSessionId,
            false
        );
    }

    public function findByPaymentSessionIdForUpdate(
        int $paymentSessionId
    ): ?array {
        if ((int) $this->db()->get_var('SELECT @@in_transaction') !== 1) {
            throw new PersistenceException(
                'El lock de Payment requiere una transaccion activa.'
            );
        }

        return $this->findByPaymentSessionIdInternal(
            $paymentSessionId,
            true
        );
    }

    public function orderIdsMatchCheckout(
        int $paymentId,
        int $checkoutId
    ): bool {
        if ($paymentId <= 0 || $checkoutId <= 0) {
            throw new \InvalidArgumentException(
                'Los IDs del agregado de pago no son validos.'
            );
        }

        $paymentOrderIds = array_map('intval', $this->db()->get_col(
            $this->db()->prepare(
                sprintf(
                    'SELECT order_id FROM %s WHERE payment_id = %%d'
                    . ' ORDER BY order_id ASC',
                    $this->table(self::ORDERS_TABLE)
                ),
                $paymentId
            )
        ));
        $checkoutOrderIds = array_map('intval', $this->db()->get_col(
            $this->db()->prepare(
                sprintf(
                    'SELECT order_id FROM %s WHERE checkout_id = %%d'
                    . ' ORDER BY order_id ASC',
                    $this->table('checkout_orders')
                ),
                $checkoutId
            )
        ));

        return $paymentOrderIds !== []
            && $paymentOrderIds === $checkoutOrderIds;
    }

    public function findOrderIdsForUpdate(int $paymentId): array
    {
        if ($paymentId <= 0) {
            throw new \InvalidArgumentException('payment_id no es valido.');
        }

        if ((int) $this->db()->get_var('SELECT @@in_transaction') !== 1) {
            throw new PersistenceException(
                'El lock de PaymentOrders requiere una transaccion activa.'
            );
        }

        $database = $this->db();
        $ids = $database->get_col($database->prepare(
            sprintf(
                'SELECT order_id FROM %s WHERE payment_id = %%d'
                . ' ORDER BY order_id ASC FOR UPDATE',
                $this->table(self::ORDERS_TABLE)
            ),
            $paymentId
        ));

        if ($database->last_error !== '') {
            throw new PersistenceException(
                'No fue posible bloquear PaymentOrders.'
            );
        }

        return array_map('intval', $ids);
    }

    public function findByProviderReferenceForUpdate(
        string $reference
    ): ?array {
        return $this->findByProviderReferenceInternal($reference, true);
    }

    /** @param list<int> $orderIds */
    public function findByOrderIds(array $orderIds): ?array
    {
        if ($orderIds === []) {
            return null;
        }

        $placeholders = implode(', ', array_fill(0, count($orderIds), '%d'));
        $paymentIds = array_map(
            'intval',
            $this->db()->get_col($this->db()->prepare(
                sprintf(
                    'SELECT DISTINCT payment_id
                     FROM %s
                     WHERE order_id IN (%s)',
                    $this->table(self::ORDERS_TABLE),
                    $placeholders
                ),
                ...$orderIds
            ))
        );

        if ($paymentIds === []) {
            return null;
        }

        if (count($paymentIds) !== 1) {
            throw new PersistenceException(
                'Los pedidos pertenecen a pagos diferentes.'
            );
        }

        return $this->find($paymentIds[0]);
    }

    public function findByOrderId(int $orderId): ?array
    {
        $paymentId = $this->db()->get_var($this->db()->prepare(
            sprintf(
                'SELECT payment_id
                 FROM %s
                 WHERE order_id = %%d
                 LIMIT 1',
                $this->table(self::ORDERS_TABLE)
            ),
            $orderId
        ));

        return $paymentId === null ? null : $this->find((int) $paymentId);
    }

    public function updateStatus(
        int $id,
        string $expectedStatus,
        string $status,
        ?string $paidAt,
        string $updatedAt
    ): void {
        $paidAtAssignment = $paidAt === null ? 'NULL' : '%s';
        $result = $this->db()->query($this->db()->prepare(
            sprintf(
                'UPDATE %s
                 SET status = %%s,
                     paid_at = %s,
                     updated_at = %%s
                 WHERE id = %%d
                   AND status = %%s',
                $this->table(self::PAYMENTS_TABLE),
                $paidAtAssignment
            ),
            ...array_values(array_filter(
                [$status, $paidAt, $updatedAt, $id, $expectedStatus],
                static fn (mixed $value): bool => $value !== null
            ))
        ));

        if ($result !== 1) {
            throw new PersistenceException(
                'No fue posible actualizar el estado del pago.'
            );
        }
    }

    public function transaction(callable $callback): mixed
    {
        if ($this->db()->query('START TRANSACTION') === false) {
            throw new PersistenceException(
                'No fue posible iniciar la transaccion del pago.'
            );
        }

        try {
            $result = $callback();

            if ($this->db()->query('COMMIT') === false) {
                throw new PersistenceException(
                    'No fue posible confirmar la transaccion del pago.'
                );
            }

            return $result;
        } catch (Throwable $exception) {
            $this->db()->query('ROLLBACK');

            throw $exception;
        }
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

        if ($result === false) {
            throw new PersistenceException(
                'No fue posible guardar la sesion de pago.'
            );
        }

        if ($result === 0) {
            $stored = $this->find($id);

            if (
                $stored === null
                || ($stored['status'] ?? null) !== 'pending'
                || ($stored['provider'] ?? null) !== $provider
                || ($stored['provider_reference'] ?? null)
                    !== $providerReference
                || ($stored['expires_at'] ?? null) !== $expiresAt
            ) {
                throw new PersistenceException(
                    'No fue posible guardar la sesion de pago.'
                );
            }
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

    private function findByProviderReferenceInternal(
        string $reference,
        bool $forUpdate
    ): ?array {
        $payment = $this->db()->get_row(
            $this->db()->prepare(
                sprintf(
                    'SELECT *
                     FROM %s
                     WHERE provider_reference = %%s
                     LIMIT 1%s',
                    $this->table(self::PAYMENTS_TABLE),
                    $forUpdate ? ' FOR UPDATE' : ''
                ),
                $reference
            ),
            ARRAY_A
        );

        return $payment === null ? null : $this->withOrders($payment);
    }

    private function findByPaymentSessionIdInternal(
        int $paymentSessionId,
        bool $forUpdate
    ): ?array {
        if ($paymentSessionId <= 0) {
            throw new \InvalidArgumentException(
                'payment_session_id no es valido.'
            );
        }

        $database = $this->db();
        $payment = $database->get_row($database->prepare(
            sprintf(
                'SELECT p.* FROM %s p INNER JOIN %s ps ON ps.payment_id = p.id'
                . ' WHERE ps.id = %%d LIMIT 1%s',
                $this->table(self::PAYMENTS_TABLE),
                $this->table('payment_sessions'),
                $forUpdate ? ' FOR UPDATE' : ''
            ),
            $paymentSessionId
        ), ARRAY_A);

        if ($forUpdate && $database->last_error !== '') {
            throw new PersistenceException('No fue posible bloquear Payment.');
        }

        return $payment === null ? null : $this->withOrders($payment);
    }
}
