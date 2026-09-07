<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Delivery\Controller;

use DomainException;
use InvalidArgumentException;
use Throwable;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Exceptions\RecordNotFoundException;
use VeciAhorra\Modules\Delivery\Service\DeliveryService;
use VeciAhorra\Modules\Delivery\Service\DeliveryTrackingService;

/**
 * Adaptador de aplicacion del modulo Delivery.
 */
final class DeliveryController
{
    public function __construct(
        private DeliveryService $service,
        private DeliveryTrackingService $trackingService
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
                    (string) ($payload['status'] ?? ''),
                    $this->version($payload)
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
                    (int) ($payload['courier_id'] ?? 0),
                    $this->version($payload),
                    array_key_exists('reason',$payload) ? (string)$payload['reason'] : null
                ),
            ];
        } catch (Throwable $exception) {
            return $this->translateException($exception);
        }
    }

    public function getTracking(int $id): array
    {
        try {
            return [
                'success' => true,
                'data' => $this->trackingService->getTracking($id),
            ];
        } catch (Throwable $exception) {
            return $this->translateException($exception);
        }
    }

    public function recordTracking(int $id, array $payload): array
    {
        try {
            return [
                'success' => true,
                'data' => $this->trackingService->recordTracking(
                    $id,
                    isset($payload['latitude'])
                        ? (float) $payload['latitude']
                        : null,
                    isset($payload['longitude'])
                        ? (float) $payload['longitude']
                        : null,
                    (string) ($payload['event'] ?? '')
                ),
            ];
        } catch (Throwable $exception) {
            return $this->translateException($exception);
        }
    }

    private function translateException(Throwable $exception): array
    {
        if ($exception instanceof RecordNotFoundException || $exception instanceof \OutOfBoundsException) {
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
                    'code' => $this->domainCode($exception),
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

    private function version(array $payload): int
    {
        $value = $payload['expected_version'] ?? null;
        if ($value === null || filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]) === false) throw new InvalidArgumentException('expected_version_required');
        return (int)$value;
    }
    private function notFoundCode(\Throwable $exception): string
    {
        return match ($exception->getMessage()) {
            'Courier not found.' => 'courier_not_found',
            'Delivery not found.' => 'delivery_not_found',
            default => str_contains($exception->getMessage(), 'pedido')
                ? 'order_not_found'
                : 'delivery_not_found',
        };
    }

    private function domainCode(DomainException $exception): string
    {
        return match ($exception->getMessage()) {
            'Cannot track cancelled delivery.' =>
                'cannot_track_cancelled_delivery',
            'Delivery already exists for order.' =>
                'delivery_already_exists',
            default => 'invalid_delivery_state_transition',
        };
    }
}
