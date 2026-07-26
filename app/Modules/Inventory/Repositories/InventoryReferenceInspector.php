<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Inventory\Repositories;

use VeciAhorra\Database\Repository;
use VeciAhorra\Exceptions\PersistenceException;

/**
 * Proyecta agregados referenciales sin exponer registros ni datos personales.
 */
final class InventoryReferenceInspector extends Repository
{
    /**
     * @return array<string, mixed>
     */
    public function inspect(int $inventoryId): array
    {
        $all = $this->inspectMany([$inventoryId]);

        return $all[$inventoryId] ?? $this->emptyInspection();
    }

    /**
     * @param list<int> $inventoryIds
     * @return array<int, array<string, mixed>>
     */
    public function inspectMany(array $inventoryIds): array
    {
        $inventoryIds = array_values(array_unique(array_filter(
            array_map('intval', $inventoryIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($inventoryIds === []) {
            return [];
        }

        $placeholders = implode(
            ', ',
            array_fill(0, count($inventoryIds), '%d')
        );
        $sql = sprintf(
            "SELECT source, inventory_id, status_key, total, quantity,
                integrity_mismatches
             FROM (
                SELECT 'cart' AS source, c.inventory_id,
                    'total' AS status_key, COUNT(*) AS total,
                    NULL AS quantity,
                    SUM(CASE
                        WHEN i.id IS NULL
                            OR c.product_id <> i.product_id
                            OR c.minimarket_id <> i.minimarket_id
                        THEN 1 ELSE 0
                    END) AS integrity_mismatches
                FROM %s c
                LEFT JOIN %s i ON i.id = c.inventory_id
                WHERE c.inventory_id IN (%s)
                GROUP BY c.inventory_id
                UNION ALL
                SELECT 'reservation', r.inventory_id, r.status,
                    COUNT(*) AS total,
                    CASE
                        WHEN r.status = 'active' THEN SUM(r.quantity)
                        ELSE NULL
                    END,
                    SUM(CASE
                        WHEN i.id IS NULL
                            OR r.product_id <> i.product_id
                            OR r.minimarket_id <> i.minimarket_id
                        THEN 1 ELSE 0
                    END)
                FROM %s r
                LEFT JOIN %s i ON i.id = r.inventory_id
                WHERE r.inventory_id IN (%s)
                GROUP BY r.inventory_id, r.status
                UNION ALL
                SELECT 'order_item', oi.inventory_id, 'total',
                    COUNT(*) AS total, NULL AS quantity,
                    SUM(CASE
                        WHEN i.id IS NULL OR oi.product_id <> i.product_id
                        THEN 1 ELSE 0
                    END)
                FROM %s oi
                LEFT JOIN %s i ON i.id = oi.inventory_id
                WHERE oi.inventory_id IN (%s)
                GROUP BY oi.inventory_id
             ) references_by_inventory
             ORDER BY inventory_id, source, status_key",
            $this->table('cart_items'),
            $this->table('inventory'),
            $placeholders,
            $this->table('reservations'),
            $this->table('inventory'),
            $placeholders,
            $this->table('order_items'),
            $this->table('inventory'),
            $placeholders
        );
        $params = [
            ...$inventoryIds,
            ...$inventoryIds,
            ...$inventoryIds,
        ];
        $database = $this->db();
        $rows = $database->get_results(
            $database->prepare($sql, ...$params),
            ARRAY_A
        );

        if ($database->last_error !== '' || ! is_array($rows)) {
            throw new PersistenceException(
                'No fue posible inspeccionar las referencias de Inventory.'
            );
        }

        $result = [];
        $mismatches = [];

        foreach ($inventoryIds as $inventoryId) {
            $result[$inventoryId] = $this->emptyInspection();
            $mismatches[$inventoryId] = 0;
        }

        foreach ($rows as $row) {
            $inventoryId = (int) ($row['inventory_id'] ?? 0);

            if (! isset($result[$inventoryId])) {
                continue;
            }

            $source = (string) ($row['source'] ?? '');
            $status = (string) ($row['status_key'] ?? '');
            $total = max(0, (int) ($row['total'] ?? 0));
            $mismatches[$inventoryId] += max(
                0,
                (int) ($row['integrity_mismatches'] ?? 0)
            );

            if ($source === 'cart') {
                $result[$inventoryId]['cart']['total'] += $total;
                continue;
            }

            if ($source === 'order_item') {
                $result[$inventoryId]['order_items']['total'] += $total;
                continue;
            }

            if ($source !== 'reservation') {
                continue;
            }

            if (in_array(
                $status,
                ['active', 'released', 'expired', 'consumed'],
                true
            )) {
                $result[$inventoryId]['reservations'][$status] += $total;

                if ($status === 'active') {
                    $result[$inventoryId]['reservations']['active_quantity'] =
                        $this->nonNegativeInteger(
                            $row['quantity'] ?? null
                        );
                }
            } else {
                $result[$inventoryId]['reservations']['unknown'] += $total;
            }

            $result[$inventoryId]['reservations']['total'] += $total;
        }

        foreach ($result as $inventoryId => &$inspection) {
            $this->classify(
                $inspection,
                ($mismatches[$inventoryId] ?? 0) > 0
            );
        }
        unset($inspection);

        return $result;
    }

    /** @return array<string, mixed> */
    private function emptyInspection(): array
    {
        return [
            'inspection_status' => 'complete',
            'classification' => 'unreferenced',
            'cart' => ['total' => 0],
            'reservations' => [
                'total' => 0,
                'active' => 0,
                'active_quantity' => null,
                'released' => 0,
                'expired' => 0,
                'consumed' => 0,
                'unknown' => 0,
            ],
            'order_items' => ['total' => 0],
            'warning_codes' => [],
        ];
    }

    /** @param array<string, mixed> $inspection */
    private function classify(
        array &$inspection,
        bool $hasReferenceMismatch
    ): void
    {
        $cart = (int) $inspection['cart']['total'];
        $reservations = $inspection['reservations'];
        $orders = (int) $inspection['order_items']['total'];
        $active = (int) $reservations['active'];
        $unknown = (int) $reservations['unknown'];
        $historicalReservations = (int) $reservations['released']
            + (int) $reservations['expired']
            + (int) $reservations['consumed'];
        $operational = $cart > 0 || $active > 0;
        $historical = $historicalReservations > 0 || $orders > 0;

        $inspection['classification'] = $unknown > 0 || $hasReferenceMismatch
            ? 'unknown'
            : ($operational && $historical
                ? 'mixed'
                : ($operational
                    ? 'operationally_referenced'
                    : ($historical
                        ? 'historically_referenced'
                        : 'unreferenced')));

        $warnings = [];

        if ($cart > 0 || (int) $reservations['total'] > 0 || $orders > 0) {
            $warnings[] = 'references_present';
        }

        if ($active > 0) {
            $warnings[] = 'active_reservation_present';
        }

        if ($unknown > 0 || $hasReferenceMismatch) {
            $warnings[] = 'reference_mismatch';
        }

        $inspection['warning_codes'] = $warnings;
    }

    private function nonNegativeInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (
            ! is_string($value)
            || preg_match('/^[0-9]+$/D', $value) !== 1
        ) {
            return null;
        }

        $number = filter_var($value, FILTER_VALIDATE_INT);

        return $number === false || $number < 0 ? null : $number;
    }
}
