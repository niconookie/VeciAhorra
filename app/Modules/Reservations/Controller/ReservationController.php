<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Reservations\Controller;

use InvalidArgumentException;
use Throwable;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Reservations\Requests\ReservationRequest;
use VeciAhorra\Modules\Reservations\Service\ReservationService;

final class ReservationController
{
    public function __construct(private ReservationService $service)
    {
    }

    public function index(array $filters): array
    {
        try {
            $orderId = filter_var(
                $filters['order_id'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            if ($orderId === false) {
                throw new InvalidArgumentException('order_id es obligatorio y debe ser positivo.');
            }

            return ['success' => true, 'data' => $this->service->findByOrderId($orderId)];
        } catch (Throwable $exception) {
            return $this->error($exception);
        }
    }

    public function store(array $payload): array
    {
        try {
            return [
                'success' => true,
                'data' => $this->service->create((new ReservationRequest($payload))->validated()),
            ];
        } catch (Throwable $exception) {
            return $this->error($exception);
        }
    }

    private function error(Throwable $exception): array
    {
        if ($exception instanceof InvalidArgumentException) {
            return ['success' => false, 'error' => ['code' => 'validation_error', 'message' => $exception->getMessage()]];
        }

        if ($exception instanceof PersistenceException || $exception->getPrevious() instanceof PersistenceException) {
            return ['success' => false, 'error' => ['code' => 'persistence_error', 'message' => 'No fue posible completar la operacion.']];
        }

        return ['success' => false, 'error' => ['code' => 'internal_error', 'message' => 'Ocurrio un error interno.']];
    }
}
