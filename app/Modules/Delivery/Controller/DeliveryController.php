<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Delivery\Controller;

use DomainException;
use InvalidArgumentException;
use Throwable;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Exceptions\RecordNotFoundException;
use VeciAhorra\Modules\Delivery\Service\DeliveryService;

/**
 * Adaptador de aplicacion del modulo Delivery.
 */
final class DeliveryController
{
    public function __construct(
        private DeliveryService $service
    ) {
    }

    public function index(array $filters = []): array
    {
        try {
            return [
                'success' => true,
                ...$this->service->listDeliveries($filters),
            ];
        } catch (Throwable $exception) {
            return $this->translateException($exception);
        }
    }

    public function show(int $id): array
    {
        try {
            $delivery = $this->service->getDelivery($id);

            if ($delivery === null) {
                throw new RecordNotFoundException(
                    'La entrega solicitada no existe.'
                );
            }

            return [
                'success' => true,
                'data' => $delivery,
            ];
        } catch (Throwable $exception) {
            return $this->translateException($exception);
        }
    }

    public function store(array $payload): array
    {
        try {
            return [
                'success' => true,
                'data' => $this->service->createDelivery($payload),
            ];
        } catch (Throwable $exception) {
            return $this->translateException($exception);
        }
    }

    public function updateStatus(int $id, array $payload): array
    {
        try {
            return [
                'success' => true,
                'data' => $this->service->updateStatus(
                    $id,
                    (string) ($payload['status'] ?? '')
                ),
            ];
        } catch (Throwable $exception) {
            return $this->translateException($exception);
        }
    }

    public function assignCourier(int $id, array $payload): array
    {
        try {
            return [
                'success' => true,
                'data' => $this->service->assignCourier(
                    $id,
                    (int) ($payload['courier_id'] ?? 0)
                ),
            ];
        } catch (Throwable $exception) {
            return $this->translateException($exception);
        }
    }

    private function translateException(Throwable $exception): array
    {
        if ($exception instanceof RecordNotFoundException) {
            return [
                'success' => false,
                'error' => [
                    'code' => $this->notFoundCode($exception),
                    'message' => $exception->getMessage(),
                ],
            ];
        }

        if ($exception instanceof DomainException) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'invalid_delivery_state_transition',
                    'message' => $exception->getMessage(),
                ],
            ];
        }

        if ($exception instanceof InvalidArgumentException) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'validation_error',
                    'message' => $exception->getMessage(),
                ],
            ];
        }

        if (
            $exception instanceof PersistenceException
            || $exception->getPrevious() instanceof PersistenceException
        ) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'persistence_error',
                    'message' => 'No fue posible completar la operacion.',
                ],
            ];
        }

        return [
            'success' => false,
            'error' => [
                'code' => 'internal_error',
                'message' => 'Ocurrio un error interno.',
            ],
        ];
    }

    private function notFoundCode(RecordNotFoundException $exception): string
    {
        return match ($exception->getMessage()) {
            'Courier not found.' => 'courier_not_found',
            'Delivery not found.' => 'delivery_not_found',
            default => str_contains($exception->getMessage(), 'pedido')
                ? 'order_not_found'
                : 'delivery_not_found',
        };
    }
}
