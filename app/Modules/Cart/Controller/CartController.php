<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Cart\Controller;

use InvalidArgumentException;
use Throwable;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Exceptions\RecordNotFoundException;
use VeciAhorra\Modules\Cart\Service\CartService;

final class CartController
{
    public function __construct(private CartService $service)
    {
    }

    public function index(array $owner): array
    {
        try {
            return [
                'success' => true,
                'data' => $this->service->getCart($owner),
            ];
        } catch (Throwable $exception) {
            return $this->error($exception);
        }
    }

    public function store(array $payload): array
    {
        try {
            $result = $this->service->addItem(
                $payload,
                (int) ($payload['inventory_id'] ?? 0),
                (int) ($payload['quantity'] ?? 0)
            );

            return ['success' => true, 'data' => $result];
        } catch (Throwable $exception) {
            return $this->error($exception);
        }
    }

    public function updateQuantity(
        array $owner,
        int $id,
        int $quantity
    ): array {
        return $this->execute(
            fn (): bool => $this->service->updateQuantity(
                $owner,
                $id,
                $quantity
            )
        );
    }

    public function delete(array $owner, int $id): array
    {
        return $this->execute(
            fn (): bool => $this->service->removeItem($owner, $id)
        );
    }

    public function clear(array $owner): array
    {
        try {
            return [
                'success' => true,
                'data' => [
                    'deleted' => $this->service->clearCart($owner),
                ],
            ];
        } catch (Throwable $exception) {
            return $this->error($exception);
        }
    }

    private function execute(callable $callback): array
    {
        try {
            return ['success' => true, 'data' => $callback()];
        } catch (Throwable $exception) {
            return $this->error($exception);
        }
    }

    private function error(Throwable $exception): array
    {
        if ($exception instanceof RecordNotFoundException) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'cart_item_not_found',
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
