<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\ZonalAdmin\Repositories;

use VeciAhorra\Core\Config;
use VeciAhorra\Exceptions\PersistenceException;

final class ZonalSalesRepository
{
    public function report(int $userId, bool $global, string $from, string $to, string $order, string $direction, int $page, int $perPage): array
    {
        global $wpdb;
        [$territory, $territoryParams] = $this->territory($userId, $global);
        $sales = $this->salesSubquery();
        $orderSql = match ($order) {
            'name' => 's.business_name',
            'orders' => 'paid_orders',
            'last_sale' => 'last_paid_at',
            default => 'product_sales',
        };
        $directionSql = $direction === 'asc' ? 'ASC' : 'DESC';
        $params = [$from, $to, ...$territoryParams, $perPage, ($page - 1) * $perPage];
        $sql = "SELECT s.id,s.business_name,s.status,s.onboarding_status,s.approved_at,"
            . "COALESCE(a.paid_orders,0) paid_orders,COALESCE(a.product_sales,0) product_sales,a.last_paid_at "
            . "FROM {$this->table('stores')} s LEFT JOIN ({$sales}) a ON a.store_id=s.id "
            . "WHERE {$territory} AND {$this->activeStoreLifecycle()} "
            . "ORDER BY {$orderSql} {$directionSql},s.business_name ASC,s.id ASC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        if (! is_array($rows) || $wpdb->last_error !== '') {
            throw new PersistenceException('No fue posible consultar las ventas zonales.');
        }

        $summaryParams = [$from, $to, ...$territoryParams];
        $summarySql = "SELECT COUNT(*) stores_total,COUNT(*) active_stores,"
            . "COALESCE(SUM(a.paid_orders),0) paid_orders,COALESCE(SUM(a.product_sales),0) product_sales "
            . "FROM {$this->table('stores')} s LEFT JOIN ({$sales}) a ON a.store_id=s.id "
            . "WHERE {$territory} AND {$this->activeStoreLifecycle()}";
        $summary = $wpdb->get_row($wpdb->prepare($summarySql, ...$summaryParams), ARRAY_A);
        if (! is_array($summary) || $wpdb->last_error !== '') {
            throw new PersistenceException('No fue posible resumir las ventas zonales.');
        }

        $storeIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $zones = $this->zones($storeIds, $userId, $global);
        $items = array_map(function (array $row) use ($zones): array {
            $orders = (int) $row['paid_orders'];
            $sales = $this->money((string) $row['product_sales']);
            return [
                'minimarket' => (string) $row['business_name'],
                'sectors' => $zones[(int) $row['id']] ?? [],
                'status' => (string) $row['status'],
                'paid_orders' => $orders,
                'product_sales' => $sales,
                'average_ticket' => $orders === 0 ? '0.00' : $this->divide($sales, $orders),
                'last_paid_at' => $row['last_paid_at'] === null ? null : (string) $row['last_paid_at'],
            ];
        }, $rows);
        $paidOrders = (int) $summary['paid_orders'];
        $productSales = $this->money((string) $summary['product_sales']);
        $total = (int) $summary['stores_total'];
        return [
            'summary' => [
                'active_stores' => (int) $summary['active_stores'],
                'paid_orders' => $paidOrders,
                'product_sales' => $productSales,
                'average_ticket' => $paidOrders === 0 ? '0.00' : $this->divide($productSales, $paidOrders),
            ],
            'items' => $items,
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => $total === 0 ? 0 : (int) ceil($total / $perPage)],
        ];
    }

    private function salesSubquery(): string
    {
        return "SELECT o.minimarket_id store_id,COUNT(DISTINCT o.id) paid_orders,"
            . "COALESCE(SUM(oi.subtotal),0) product_sales,MAX(paid.paid_at) last_paid_at "
            . "FROM {$this->table('orders')} o "
            . "INNER JOIN {$this->table('order_items')} oi ON oi.order_id=o.id "
            . "INNER JOIN (SELECT po.order_id,MAX(p.paid_at) paid_at FROM {$this->table('payment_orders')} po"
            . " INNER JOIN {$this->table('payments')} p ON p.id=po.payment_id"
            . " WHERE p.status='paid' AND p.paid_at >= %s AND p.paid_at < %s GROUP BY po.order_id) paid ON paid.order_id=o.id"
            . " WHERE o.status IN ('paid','delivered') GROUP BY o.minimarket_id";
    }

    private function territory(int $userId, bool $global): array
    {
        if ($global) return ['1=1', []];
        return [
            "EXISTS (SELECT 1 FROM {$this->table('store_service_zones')} sz"
            . " INNER JOIN {$this->table('zonal_admin_service_zones')} ua ON ua.service_zone_id=sz.zone_id AND ua.user_id=%d"
            . " INNER JOIN {$this->table('service_zones')} z ON z.id=sz.zone_id AND z.status='active' WHERE sz.store_id=s.id)",
            [$userId],
        ];
    }

    private function activeStoreLifecycle(): string
    {
        return "s.status='active' AND s.onboarding_status='complete' AND s.approved_at IS NOT NULL";
    }

    private function zones(array $storeIds, int $userId, bool $global): array
    {
        $result = array_fill_keys($storeIds, []);
        if ($storeIds === []) return $result;
        global $wpdb;
        $in = implode(',', array_fill(0, count($storeIds), '%d'));
        $join = $global ? '' : " INNER JOIN {$this->table('zonal_admin_service_zones')} ua ON ua.service_zone_id=z.id AND ua.user_id=%d";
        $params = $global ? [] : [$userId];
        array_push($params, ...$storeIds);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT sz.store_id,z.name FROM {$this->table('service_zones')} z INNER JOIN {$this->table('store_service_zones')} sz ON sz.zone_id=z.id{$join} WHERE z.status='active' AND sz.store_id IN ({$in}) ORDER BY z.name ASC",
            ...$params
        ), ARRAY_A);
        if (! is_array($rows) || $wpdb->last_error !== '') throw new PersistenceException('No fue posible consultar los sectores zonales.');
        foreach ($rows as $row) $result[(int) $row['store_id']][] = (string) $row['name'];
        return $result;
    }

    private function money(string $value): string { return number_format((float) $value, 2, '.', ''); }
    private function divide(string $value, int $count): string { return number_format(((float) $value) / $count, 2, '.', ''); }
    private function table(string $name): string { global $wpdb; return $wpdb->prefix . Config::TABLE_PREFIX . $name; }
}
