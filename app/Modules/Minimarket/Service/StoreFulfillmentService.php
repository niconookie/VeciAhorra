<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Minimarket\Service;

use VeciAhorra\Exceptions\ConflictException;
use VeciAhorra\Modules\Minimarket\Repository\MinimarketRepository;

final class StoreFulfillmentService
{
    private const TRANSITIONS = [
        'confirmed' => ['from'=>'awaiting_confirmation','timestamp'=>'store_confirmed_at'],
        'preparing' => ['from'=>'confirmed','timestamp'=>'store_preparation_started_at'],
        'ready_for_pickup' => ['from'=>'preparing','timestamp'=>'store_ready_for_pickup_at'],
    ];
    public function __construct(private MinimarketRepository $repository = new MinimarketRepository()) {}
    public function transition(int $orderId, int $storeId, string $target): array
    {
        $rule = self::TRANSITIONS[$target] ?? throw new \InvalidArgumentException('Transición de preparación inválida.');
        $order = $this->repository->order($orderId, $storeId);
        if (($order['status'] ?? null) !== 'paid') throw new ConflictException('Solo se pueden preparar pedidos pagados.', 'order_not_paid');
        if (($order['store_fulfillment_status'] ?? null) === $target) return $order;
        if (($order['store_fulfillment_status'] ?? null) !== $rule['from']) throw new ConflictException('La transición de preparación no está permitida.', 'invalid_store_fulfillment_transition');
        if ($this->repository->transitionPreparation($orderId, $storeId, $rule['from'], $target, $rule['timestamp'], current_time('mysql', true)) !== 1) {
            $current = $this->repository->order($orderId, $storeId);
            if (($current['store_fulfillment_status'] ?? null) === $target) return $current;
            throw new ConflictException('La preparación fue modificada por otra solicitud.', 'store_fulfillment_conflict');
        }
        return $this->repository->order($orderId, $storeId);
    }
}
