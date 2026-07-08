<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Service;

use Throwable;
use VeciAhorra\Modules\Payments\Repository\PaymentRepository;

final class PaymentService
{
    public const STATUS_PENDING = 'pending';

    public function __construct(private PaymentRepository $repository)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return $this->repository->list();
    }

    public function find(int $id): ?array
    {
        return $this->repository->find($id);
    }

    public function create(array $data): array
    {
        $now = current_time('mysql');
        $paymentId = null;

        try {
            $paymentId = $this->repository->create([
                'payment_reference' => $this->reference(),
                'customer_id' => $data['customer_id'],
                'amount' => $data['amount'],
                'currency' => $data['currency'],
                'status' => self::STATUS_PENDING,
                'provider' => $data['provider'],
                'provider_reference' => null,
                'expires_at' => null,
                'paid_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->repository->attachOrders(
                $paymentId,
                $data['order_ids'],
                $now
            );
        } catch (Throwable $exception) {
            if ($paymentId !== null) {
                $this->repository->delete($paymentId);
            }

            throw $exception;
        }

        return $this->repository->find($paymentId) ?? throw new \RuntimeException(
            'No fue posible recuperar el pago creado.'
        );
    }

    private function reference(): string
    {
        return 'PAY-' . strtoupper(str_replace('-', '', wp_generate_uuid4()));
    }
}
