<?php

declare(strict_types=1);

namespace VeciAhorra\Tests\Manual\Support;

use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Orders\Contracts\OrderAdminReadRepositoryInterface;
use VeciAhorra\Modules\Orders\DTO\Admin\OrderAdminListQuery;

final class InstrumentedOrderAdminReadRepository implements OrderAdminReadRepositoryInterface
{
    public int $queryCount = 0;
    public ?string $failure = null;

    /** @param list<array<string,mixed>> $rows
     * @param array<int,array<string,list<array<string,mixed>>>> $bundles
     */
    public function __construct(public array $rows, public array $bundles)
    {
    }

    public function count(OrderAdminListQuery $query): int
    {
        ++$this->queryCount;
        $this->fail('count');
        return count($this->filtered($query));
    }

    public function paginate(OrderAdminListQuery $query): array
    {
        ++$this->queryCount;
        $this->fail('page');
        $rows = $this->filtered($query);
        usort($rows, match ($query->order) {
            'oldest' => static fn (array $a, array $b): int => [$a['order_created_at'], $a['order_id']] <=> [$b['order_created_at'], $b['order_id']],
            'updated' => static fn (array $a, array $b): int => [$b['order_updated_at'], $b['order_id']] <=> [$a['order_updated_at'], $a['order_id']],
            'total_asc' => static fn (array $a, array $b): int => [(string) $a['total'], $a['order_id']] <=> [(string) $b['total'], $b['order_id']],
            'total_desc' => static fn (array $a, array $b): int => [(string) $b['total'], $b['order_id']] <=> [(string) $a['total'], $a['order_id']],
            default => static fn (array $a, array $b): int => [$b['order_created_at'], $b['order_id']] <=> [$a['order_created_at'], $a['order_id']],
        });
        return array_slice($rows, $query->offset(), $query->perPage);
    }

    public function loadFacts(array $orderIds): array
    {
        $this->queryCount += 2;
        $this->fail('facts');
        return array_intersect_key($this->bundles, array_flip($orderIds));
    }

    public function findBase(int $orderId): ?array
    {
        ++$this->queryCount;
        $this->fail('detail');
        foreach ($this->rows as $row) {
            if ((int) $row['order_id'] === $orderId) {
                return $row;
            }
        }
        return null;
    }

    private function filtered(OrderAdminListQuery $query): array
    {
        return array_values(array_filter($this->rows, static function (array $row) use ($query): bool {
            if ($query->storeId !== null && (int) $row['minimarket_id'] !== $query->storeId) {
                return false;
            }
            if ($query->orderStatus !== null && $row['order_status'] !== $query->orderStatus) {
                return false;
            }
            if ($query->fulfillmentMode !== null && $row['fulfillment_method'] !== $query->fulfillmentMode) {
                return false;
            }
            if ($query->createdFrom !== null && $row['order_created_at'] < $query->createdFrom) {
                return false;
            }
            if ($query->createdTo !== null && $row['order_created_at'] > $query->createdTo) {
                return false;
            }
            if ($query->search !== null) {
                $search = trim($query->search);
                $checkoutSearch = preg_match('/^checkout:([1-9][0-9]*)$/Di', $search, $match) === 1
                    && (int) $match[1] === (int) $row['checkout_id'];
                $matches = $checkoutSearch
                    || (ctype_digit($search) && in_array((int) $search, [(int) $row['order_id'], (int) $row['checkout_id']], true))
                    || strcasecmp($search, (string) $row['checkout_public_id']) === 0
                    || str_contains(strtolower((string) $row['store_name']), strtolower($search));
                if (! $matches) {
                    return false;
                }
            }
            return true;
        }));
    }

    private function fail(string $operation): void
    {
        if ($this->failure === $operation) {
            throw new PersistenceException('SQL SELECT /private/path InternalClass token=secret');
        }
    }
}

final class OrderAdminReadFixture
{
    public static function base(int $id = 10, string $mode = 'pickup'): array
    {
        return [
            'order_id' => $id,
            'customer_id' => 7,
            'minimarket_id' => 3,
            'total' => '1000.00',
            'order_status' => $mode === 'delivery' ? 'delivered' : 'paid',
            'reservation_expires_at' => '2026-07-26 10:30:00',
            'order_created_at' => '2026-07-26 10:01:00',
            'order_updated_at' => '2026-07-26 10:09:00',
            'checkout_order_link_id' => 200 + $id,
            'checkout_id' => 40 + $id,
            'checkout_public_id' => 'checkout-' . $id,
            'checkout_status' => 'payment_started',
            'fulfillment_method' => $mode,
            'currency' => 'CLP',
            'total_amount' => '1000.00',
            'owner_type' => 'user',
            'user_id' => 7,
            'checkout_created_at' => '2026-07-26 10:00:00',
            'checkout_updated_at' => '2026-07-26 10:09:00',
            'checkout_expires_at' => '2026-07-26 10:30:00',
            'resolved_store_id' => 3,
            'store_name' => 'Almacen Seguro',
            'store_status' => 'active',
            'line_count' => 1,
            'unit_count' => 2,
        ];
    }

    public static function bundle(int $id = 10, string $mode = 'pickup'): array
    {
        $checkoutId = 40 + $id;
        $bundle = [
            'order_items' => [[
                'id' => 100 + $id, 'order_id' => $id, 'product_id' => 20,
                'inventory_id' => 30, 'quantity' => 2, 'unit_price' => '500.0',
                'subtotal' => '1000.00', 'created_at' => '2026-07-26 10:01:00',
                'updated_at' => '2026-07-26 10:01:00',
            ]],
            'reservations' => [[
                'id' => 50 + $id, 'order_id' => $id, 'product_id' => 20,
                'inventory_id' => 30, 'minimarket_id' => 3, 'quantity' => 2,
                'status' => 'consumed', 'reserved_at' => '2026-07-26 10:02:00',
                'expires_at' => '2026-07-26 10:30:00', 'released_at' => null,
                'updated_at' => '2026-07-26 10:08:00',
            ]],
            'payment_sessions' => [[
                'id' => 60 + $id, 'public_id' => 'session-safe-' . $id,
                'checkout_id' => $checkoutId, 'status' => 'confirmed',
                'create_version' => 1, 'amount' => '1000.00', 'currency' => 'CLP',
                'confirmed_at' => '2026-07-26 10:06:00',
                'created_at' => '2026-07-26 10:03:00', 'updated_at' => '2026-07-26 10:06:00',
            ]],
            'financial_evidence' => [[
                'id' => 70 + $id, 'payment_session_id' => 60 + $id,
                'status' => 'approved', 'validated' => 1,
                'amount' => 1000, 'currency' => 'CLP',
                'obtained_at' => '2026-07-26 10:04:00',
                'validated_at' => '2026-07-26 10:05:00',
                'updated_at' => '2026-07-26 10:05:00',
            ]],
            'reconciliations' => [[
                'id' => 80 + $id, 'checkout_id' => $checkoutId, 'status' => 'completed',
                'attempt_count' => 1, 'lease_version' => 1,
                'completed_at' => '2026-07-26 10:07:00',
                'created_at' => '2026-07-26 10:04:00', 'updated_at' => '2026-07-26 10:07:00',
            ]],
            'payments' => [[
                'id' => 90 + $id, 'checkout_id' => $checkoutId, 'status' => 'paid',
                'payment_session_id' => 60 + $id, 'reconciliation_id' => 80 + $id,
                'amount' => '1000.00', 'currency' => 'CLP',
                'paid_at' => '2026-07-26 10:08:00',
                'created_at' => '2026-07-26 10:08:00', 'updated_at' => '2026-07-26 10:08:00',
            ]],
            'payment_order_links' => [['id' => 300 + $id, 'payment_id' => 90 + $id, 'order_id' => $id]],
            'business_completions' => [[
                'id' => 110 + $id, 'reconciliation_id' => 80 + $id,
                'payment_id' => 90 + $id, 'status' => 'completed', 'lease_version' => 1,
                'fulfillment_method' => $mode, 'completed_at' => '2026-07-26 10:09:00',
                'created_at' => '2026-07-26 10:08:00', 'updated_at' => '2026-07-26 10:09:00',
            ]],
            'business_order_links' => [['id' => 400 + $id, 'business_completion_id' => 110 + $id, 'order_id' => $id]],
            'delivery_completions' => [[
                'id' => 120 + $id, 'business_completion_id' => 110 + $id,
                'status' => $mode === 'pickup' ? 'not_required' : 'completed',
                'lease_version' => 1, 'completed_at' => '2026-07-26 10:10:00',
                'created_at' => '2026-07-26 10:09:00', 'updated_at' => '2026-07-26 10:10:00',
            ]],
            'fulfillment_completions' => [[
                'id' => 130 + $id, 'business_completion_id' => 110 + $id,
                'status' => 'completed', 'lease_version' => 1,
                'completed_at' => '2026-07-26 10:11:00',
                'created_at' => '2026-07-26 10:10:00', 'updated_at' => '2026-07-26 10:11:00',
            ]],
            'deliveries' => [],
            'delivery_tracking' => [],
        ];
        if ($mode === 'delivery') {
            $bundle['deliveries'][] = [
                'id' => 140 + $id, 'order_id' => $id, 'customer_id' => 7,
                'minimarket_id' => 3, 'courier_id' => 8, 'status' => 'delivered',
                'created_at' => '2026-07-26 10:10:00', 'updated_at' => '2026-07-26 10:12:00',
            ];
            $bundle['delivery_tracking'][] = [
                'id' => 150 + $id, 'delivery_id' => 140 + $id,
                'event' => 'delivered', 'created_at' => '2026-07-26 10:12:00',
            ];
        }
        return $bundle;
    }
}
