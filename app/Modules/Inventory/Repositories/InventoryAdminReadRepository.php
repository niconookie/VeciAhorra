<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Inventory\Repositories;

use VeciAhorra\Database\Repository;
use VeciAhorra\Exceptions\PersistenceException;

/**
 * Consultas acotadas para los read models operacionales de Inventory Admin.
 */
final class InventoryAdminReadRepository extends Repository
{
    private const ORDER_COLUMNS = [
        'updated_at' => 'i.updated_at',
        'id' => 'i.id',
        'product_name' => 'p.name',
        'store_name' => 's.business_name',
        'price' => 'i.price',
        'stock' => 'i.stock',
        'status' => 'i.status',
    ];

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function paginate(array $filters): array
    {
        $page = (int) $filters['page'];
        $perPage = (int) $filters['per_page'];
        [$where, $params] = $this->where($filters);
        $orderBy = self::ORDER_COLUMNS[$filters['order_by']]
            ?? self::ORDER_COLUMNS['updated_at'];
        $direction = $filters['direction'] === 'ASC' ? 'ASC' : 'DESC';
        $sql = sprintf(
            'SELECT %s
             FROM %s i
             LEFT JOIN %s p ON p.id = i.product_id
             LEFT JOIN %s s ON s.id = i.minimarket_id
             %s
             ORDER BY %s %s, i.id %s
             LIMIT %%d OFFSET %%d',
            $this->columns(),
            $this->table('inventory'),
            $this->table('products'),
            $this->table('stores'),
            $where,
            $orderBy,
            $direction,
            $direction
        );
        $params[] = $perPage;
        $params[] = ($page - 1) * $perPage;
        $database = $this->db();
        $rows = $database->get_results(
            $database->prepare($sql, ...$params),
            ARRAY_A
        );

        if ($database->last_error !== '' || ! is_array($rows)) {
            throw new PersistenceException(
                'No fue posible consultar Inventory Admin.'
            );
        }

        return $rows;
    }

    /** @param array<string, mixed> $filters */
    public function count(array $filters): int
    {
        [$where, $params] = $this->where($filters);
        $sql = sprintf(
            'SELECT COUNT(*)
             FROM %s i
             LEFT JOIN %s p ON p.id = i.product_id
             LEFT JOIN %s s ON s.id = i.minimarket_id
             %s',
            $this->table('inventory'),
            $this->table('products'),
            $this->table('stores'),
            $where
        );
        $database = $this->db();
        $prepared = $params === []
            ? $sql
            : $database->prepare($sql, ...$params);
        $value = $database->get_var($prepared);

        if ($database->last_error !== '' || $value === null) {
            throw new PersistenceException(
                'No fue posible contar Inventory Admin.'
            );
        }

        return max(0, (int) $value);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $database = $this->db();
        $row = $database->get_row(
            $database->prepare(
                sprintf(
                    'SELECT %s
                     FROM %s i
                     LEFT JOIN %s p ON p.id = i.product_id
                     LEFT JOIN %s s ON s.id = i.minimarket_id
                     WHERE i.id = %%d
                     LIMIT 1',
                    $this->columns(),
                    $this->table('inventory'),
                    $this->table('products'),
                    $this->table('stores')
                ),
                $id
            ),
            ARRAY_A
        );

        if ($database->last_error !== '') {
            throw new PersistenceException(
                'No fue posible consultar el detalle de Inventory Admin.'
            );
        }

        return $row === null ? null : $row;
    }

    private function columns(): string
    {
        return 'i.id AS inventory_id,
            i.product_id,
            i.minimarket_id,
            i.price,
            i.stock,
            i.status AS inventory_status,
            i.created_at,
            i.updated_at,
            p.id AS resolved_product_id,
            p.name AS product_name,
            p.slug AS product_slug,
            p.sku AS product_sku,
            p.status AS product_status,
            p.image_id AS product_image_id,
            s.id AS resolved_store_id,
            s.business_name AS store_name,
            s.status AS store_status,
            s.onboarding_status AS store_onboarding_status,
            s.approved_at AS store_approved_at,
            s.commune AS store_commune,
            s.city AS store_city,
            s.region AS store_region';
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: list<int|string>}
     */
    private function where(array $filters): array
    {
        $conditions = [];
        $params = [];

        foreach (['product_id', 'minimarket_id'] as $field) {
            if ($filters[$field] !== null) {
                $conditions[] = "i.{$field} = %d";
                $params[] = $filters[$field];
            }
        }

        if ($filters['status'] !== null) {
            if ($filters['status'] === 'unknown') {
                $conditions[] =
                    "(i.status IS NULL OR i.status NOT IN ('active','inactive'))";
            } else {
                $conditions[] = 'i.status = %s';
                $params[] = $filters['status'];
            }
        }

        if ($filters['search'] !== null) {
            $this->search(
                (string) $filters['search'],
                $conditions,
                $params
            );
        }

        if ($filters['availability'] !== null) {
            $case = $this->availabilityCase();

            if ($filters['availability'] === 'public') {
                $conditions[] = "({$case}) = 'publicly_available'";
            } elseif ($filters['availability'] === 'diagnostic_error') {
                $conditions[] = sprintf(
                    "(%s) IN ('product_reference_invalid',
                     'store_reference_invalid','product_missing','store_missing',
                     'reference_mismatch','inventory_status_unknown',
                     'product_status_unknown','store_status_unknown')",
                    $case
                );
            } else {
                $conditions[] = sprintf(
                    "(%s) IN ('inventory_inactive','product_not_public',
                     'store_not_active','invalid_public_price','out_of_stock')",
                    $case
                );
            }
        }

        if ($filters['cause'] !== null) {
            $conditions[] = '(' . $this->availabilityCase() . ') = %s';
            $params[] = $filters['cause'];
        }

        if ($filters['reference'] !== null) {
            $conditions[] = $this->referenceCondition(
                (string) $filters['reference']
            );
        }

        return [
            $conditions === []
                ? ''
                : 'WHERE ' . implode("\n AND ", $conditions),
            $params,
        ];
    }

    /**
     * @param list<string> $conditions
     * @param list<int|string> $params
     */
    private function search(
        string $search,
        array &$conditions,
        array &$params
    ): void {
        if (preg_match('/^[1-9][0-9]*$/D', $search) === 1) {
            $conditions[] = 'i.id = %d';
            $params[] = (int) $search;
            return;
        }

        if (
            preg_match(
                '/^(inventory|product|store):([1-9][0-9]*)$/D',
                strtolower($search),
                $match
            ) === 1
        ) {
            $column = match ($match[1]) {
                'inventory' => 'i.id',
                'product' => 'i.product_id',
                default => 'i.minimarket_id',
            };
            $conditions[] = "{$column} = %d";
            $params[] = (int) $match[2];
            return;
        }

        $like = '%' . $this->db()->esc_like($search) . '%';
        $conditions[] =
            '(p.name LIKE %s OR p.sku LIKE %s OR s.business_name LIKE %s)';
        array_push($params, $like, $like, $like);
    }

    private function availabilityCase(): string
    {
        return "CASE
            WHEN i.product_id IS NULL OR i.product_id <= 0
                THEN 'product_reference_invalid'
            WHEN i.minimarket_id IS NULL OR i.minimarket_id <= 0
                THEN 'store_reference_invalid'
            WHEN p.id IS NULL THEN 'product_missing'
            WHEN s.id IS NULL THEN 'store_missing'
            WHEN p.id <> i.product_id OR s.id <> i.minimarket_id
                THEN 'reference_mismatch'
            WHEN i.status IS NULL OR i.status NOT IN ('active','inactive')
                THEN 'inventory_status_unknown'
            WHEN i.status = 'inactive' THEN 'inventory_inactive'
            WHEN p.status IS NULL OR p.status NOT IN ('draft','active','inactive')
                THEN 'product_status_unknown'
            WHEN p.status <> 'active' THEN 'product_not_public'
            WHEN s.status IS NULL
                OR s.status NOT IN ('pending','active','inactive','rejected')
                THEN 'store_status_unknown'
            WHEN s.status <> 'active' THEN 'store_not_active'
            WHEN i.price IS NULL OR i.price <= 0
                THEN 'invalid_public_price'
            WHEN i.stock IS NULL OR i.stock <= 0 THEN 'out_of_stock'
            ELSE 'publicly_available'
        END";
    }

    private function referenceCondition(string $reference): string
    {
        $cart = $this->table('cart_items');
        $reservations = $this->table('reservations');
        $orders = $this->table('order_items');

        return match ($reference) {
            'active_reservation' => "EXISTS (
                SELECT 1 FROM {$reservations} r
                WHERE r.inventory_id = i.id AND r.status = 'active'
            )",
            'cart' => "EXISTS (
                SELECT 1 FROM {$cart} c WHERE c.inventory_id = i.id
            )",
            'history' => "(EXISTS (
                SELECT 1 FROM {$reservations} r
                WHERE r.inventory_id = i.id
                    AND (r.status IS NULL OR r.status <> 'active')
            ) OR EXISTS (
                SELECT 1 FROM {$orders} oi WHERE oi.inventory_id = i.id
            ))",
            default => "(NOT EXISTS (
                SELECT 1 FROM {$cart} c WHERE c.inventory_id = i.id
            ) AND NOT EXISTS (
                SELECT 1 FROM {$reservations} r WHERE r.inventory_id = i.id
            ) AND NOT EXISTS (
                SELECT 1 FROM {$orders} oi WHERE oi.inventory_id = i.id
            ))",
        };
    }
}
