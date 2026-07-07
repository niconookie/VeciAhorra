<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Controllers;

use InvalidArgumentException;
use Throwable;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Exceptions\RecordNotFoundException;
use VeciAhorra\Modules\Orders\Services\OrderService;

/**
 * Adaptador de aplicacion del modulo Orders.
 */
final class OrderController
{
    public function __construct(
        private OrderService $service
    ) {
    }

    public function index(array $filters = []): array
    {
        try {
            return [
                'success' => true,
                'data' => $this->service->list($filters),
            ];
        } catch (Throwable $exception) {
            return $this->translateException($exception);
        }
    }

    public function show(int $id): array
    {
        try {
            $order = $this->service->find($id);

            if ($order === null) {
                throw new RecordNotFoundException(
                    'El pedido solicitado no existe.'
                );
            }

            return [
                'success' => true,
                'data' => $order,
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
                'data' => $this->service->create($payload),
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
                    'code' => 'order_not_found',
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
}
