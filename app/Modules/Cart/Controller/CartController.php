<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Cart\Controller;

use InvalidArgumentException;
use Throwable;
use VeciAhorra\Exceptions\PersistenceException;
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
                'data' => $this->items($owner),
            ];
        } catch (Throwable $exception) {
            return $this->error($exception);
        }
    }

    public function store(array $payload): array
    {
        try {
            $this->assertOwner($payload);
            $now = current_time('mysql');
            $id = $this->service->create([
                ...$payload,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return ['success' => true, 'data' => ['id' => $id]];
        } catch (Throwable $exception) {
            return $this->error($exception);
        }
    }

    public function updateQuantity(int $id, int $quantity): array
    {
        return $this->execute(
            fn (): bool => $this->service->updateQuantity($id, $quantity)
        );
    }

    public function delete(int $id): array
    {
        return $this->execute(fn (): bool => $this->service->delete($id));
    }

    public function clear(array $owner): array
    {
        try {
            $this->assertOwner($owner);

            return [
                'success' => true,
                'data' => [
                    'deleted' => $this->service->clear(
                        $owner['session_id'] ?? null,
                        $owner['user_id'] ?? null
                    ),
                ],
            ];
        } catch (Throwable $exception) {
            return $this->error($exception);
        }
    }

    private function items(array $owner): array
    {
        $this->assertOwner($owner);

        if (isset($owner['user_id'])) {
            return $this->service->findByUser((int) $owner['user_id']);
        }

        return $this->service->findBySession(
            (string) $owner['session_id']
        );
    }

    private function assertOwner(array $data): void
    {
        $sessionId = $data['session_id'] ?? null;
        $userId = $data['user_id'] ?? null;

        if (
            (! is_string($sessionId) || trim($sessionId) === '')
            && (! is_int($userId) || $userId <= 0)
        ) {
            throw new InvalidArgumentException(
                'El carrito requiere session_id o user_id.'
            );
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
