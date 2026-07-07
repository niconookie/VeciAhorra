<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Cart\Service;

use VeciAhorra\Modules\Cart\Repository\CartRepository;

final class CartService
{
    public function __construct(private CartRepository $repository)
    {
    }

    public function create(array $data): int
    {
        return $this->repository->create($data);
    }

    public function findBySession(string $sessionId): array
    {
        return $this->repository->findBySession($sessionId);
    }

    public function findByUser(int $userId): array
    {
        return $this->repository->findByUser($userId);
    }

    public function updateQuantity(int $id, int $quantity): bool
    {
        return $this->repository->updateQuantity($id, $quantity);
    }

    public function delete(int $id): bool
    {
        return $this->repository->delete($id);
    }

    public function clear(?string $sessionId, ?int $userId): int
    {
        return $this->repository->clear($sessionId, $userId);
    }
}
