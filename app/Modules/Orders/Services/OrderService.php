<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Services;

use InvalidArgumentException;
use RuntimeException;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Orders\Repositories\OrderRepository;

/**
 * Casos de uso iniciales para crear pedidos reservados.
 */
final class OrderService
{
    private const RESERVATION_MINUTES = 15;

    private OrderRepository $repository;

    public function __construct(
        ?OrderRepository $repository = null
    ) {
        $this->repository = $repository
            ?? new OrderRepository();
    }

    /**
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        $items = $payload['items'] ?? null;

        if (! is_array($items) || $items === []) {
            throw new InvalidArgumentException(
                'El pedido debe contener al menos un item.'
            );
        }

        $createdAt = current_datetime();
        $createdAtSql = $createdAt->format('Y-m-d H:i:s');
        $reservationExpiresAt = $createdAt
            ->modify(sprintf('+%d minutes', self::RESERVATION_MINUTES))
            ->format('Y-m-d H:i:s');
        $preparedItems = [];
        $total = 0.0;

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException(sprintf(
                    'El item %d del pedido no es valido.',
                    $index
                ));
            }

            $quantity = $item['quantity'] ?? null;
            $unitPrice = $item['unit_price'] ?? null;

            if (! is_int($quantity) || $quantity <= 0) {
                throw new InvalidArgumentException(sprintf(
                    'La cantidad del item %d debe ser un entero positivo.',
                    $index
                ));
            }

            if (
                (! is_int($unitPrice) && ! is_float($unitPrice))
                || ! is_finite((float) $unitPrice)
                || $unitPrice < 0
            ) {
                throw new InvalidArgumentException(sprintf(
                    'El precio unitario del item %d no es valido.',
                    $index
                ));
            }

            $subtotal = round($quantity * (float) $unitPrice, 2);
            $total = round($total + $subtotal, 2);
            $preparedItems[] = [
                'product_id' => (int) ($item['product_id'] ?? 0),
                'inventory_id' => (int) ($item['inventory_id'] ?? 0),
                'quantity' => $quantity,
                'unit_price' => round((float) $unitPrice, 2),
                'subtotal' => $subtotal,
                'created_at' => $createdAtSql,
                'updated_at' => $createdAtSql,
            ];
        }

        $order = [
            'customer_id' => (int) ($payload['customer_id'] ?? 0),
            'minimarket_id' => (int) ($payload['minimarket_id'] ?? 0),
            'total' => $total,
            'status' => 'reserved',
            'reservation_expires_at' => $reservationExpiresAt,
            'created_at' => $createdAtSql,
            'updated_at' => $createdAtSql,
        ];

        try {
            $orderId = $this->repository->create($order);
            $this->repository->createItems($orderId, $preparedItems);
        } catch (PersistenceException $exception) {
            throw new RuntimeException(
                'No fue posible crear el pedido.',
                0,
                $exception
            );
        }

        $created = $this->repository->find($orderId);

        if ($created === null) {
            throw new RuntimeException(
                'No fue posible recuperar el pedido creado.'
            );
        }

        return [
            ...$created,
            'items' => $preparedItems,
        ];
    }
}
