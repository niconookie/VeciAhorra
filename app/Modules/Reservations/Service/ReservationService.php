<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Reservations\Service;

use InvalidArgumentException;
use RuntimeException;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Reservations\Repository\ReservationRepository;

final class ReservationService
{
    public const DURATION_MINUTES = 15;
    public const ALLOWED_STATUSES = ['active', 'released', 'expired', 'consumed'];

    public function __construct(private ?ReservationRepository $repository = null)
    {
        $this->repository ??= new ReservationRepository();
    }

    public function find(int $id): ?array
    {
        return $this->repository->find($id);
    }

    /** @return list<array<string, mixed>> */
    public function findByOrderId(int $orderId): array
    {
        $this->assertPositive($orderId, 'order_id');

        return $this->repository->findByOrderId($orderId);
    }

    /** @return array<string, mixed> */
    public function create(array $data): array
    {
        foreach (['order_id', 'inventory_id', 'product_id', 'minimarket_id', 'quantity'] as $field) {
            $this->assertPositive($data[$field] ?? null, $field);
        }

        $status = $data['status'] ?? 'active';
        $this->assertAllowedStatus($status);

        $reservedAt = current_datetime();
        $reservedAtSql = $reservedAt->format('Y-m-d H:i:s');
        $payload = [
            'order_id' => (int) $data['order_id'],
            'inventory_id' => (int) $data['inventory_id'],
            'product_id' => (int) $data['product_id'],
            'minimarket_id' => (int) $data['minimarket_id'],
            'quantity' => (int) $data['quantity'],
            'status' => $status,
            'reserved_at' => $reservedAtSql,
            'expires_at' => $reservedAt->modify('+' . self::DURATION_MINUTES . ' minutes')->format('Y-m-d H:i:s'),
            'released_at' => null,
            'created_at' => $reservedAtSql,
            'updated_at' => $reservedAtSql,
        ];

        try {
            $id = $this->repository->create($payload);
        } catch (PersistenceException $exception) {
            throw new RuntimeException('No fue posible crear la reserva.', 0, $exception);
        }

        $reservation = $this->repository->find($id);

        if ($reservation === null) {
            throw new RuntimeException('No fue posible recuperar la reserva creada.');
        }

        return $reservation;
    }

    public function assertAllowedStatus(mixed $status): void
    {
        if (! is_string($status) || ! in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException('El estado de la reserva no es valido.');
        }
    }

    private function assertPositive(mixed $value, string $field): void
    {
        if (! is_int($value) || $value <= 0) {
            throw new InvalidArgumentException("El campo {$field} debe ser un entero positivo.");
        }
    }
}
