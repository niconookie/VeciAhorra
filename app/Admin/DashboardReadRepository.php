<?php

declare(strict_types=1);

namespace VeciAhorra\Admin;

use DateTimeImmutable;
use DateTimeZone;
use VeciAhorra\Database\Repository;
use VeciAhorra\Exceptions\PersistenceException;

final class DashboardReadRepository extends Repository
{
    private int $queryCount = 0;

    /** @return array<string, mixed> */
    public function snapshot(int $recentLimit = 10): array
    {
        $timezone = wp_timezone();
        $todayStart = new DateTimeImmutable('today', $timezone);
        $todayEnd = $todayStart->modify('+1 day');
        $utc = new DateTimeZone('UTC');

        $metrics = $this->metrics(
            $todayStart->format('Y-m-d H:i:s'),
            $todayEnd->format('Y-m-d H:i:s'),
            $todayStart->setTimezone($utc)->format('Y-m-d H:i:s'),
            $todayEnd->setTimezone($utc)->format('Y-m-d H:i:s')
        );

        return [
            'metrics' => $metrics,
            'recent_orders' => $this->recentOrders(max(1, min(10, $recentLimit))),
            'timezone' => wp_timezone_string(),
            'today_start' => $todayStart->format(DATE_ATOM),
            'today_end' => $todayEnd->format(DATE_ATOM),
            'query_count' => $this->queryCount,
        ];
    }

    /** @return array<string, int|string> */
    private function metrics(string $localStart, string $localEnd, string $utcStart, string $utcEnd): array
    {
        $sql = sprintf(
            "SELECT
                (SELECT COALESCE(SUM(p.amount),0) FROM %s p
                    WHERE p.status='paid' AND p.currency='CLP'
                    AND p.paid_at >= %%s AND p.paid_at < %%s) sales_today,
                (SELECT COUNT(*) FROM %s o WHERE o.created_at >= %%s AND o.created_at < %%s) orders_today,
                (SELECT COUNT(DISTINCT po.order_id) FROM %s po
                    JOIN %s p ON p.id=po.payment_id WHERE p.status='paid') paid_orders,
                (SELECT COUNT(*) FROM %s d WHERE d.status='pending' AND d.courier_id IS NULL) deliveries_pending,
                (SELECT COUNT(*) FROM %s d WHERE d.status='assigned') deliveries_assigned,
                (SELECT COUNT(*) FROM %s d WHERE d.status='picked_up') deliveries_picked_up,
                (SELECT COUNT(*) FROM %s d WHERE d.status='delivered') deliveries_delivered,
                (SELECT COUNT(*) FROM %s s WHERE s.status='active') active_stores,
                (SELECT COUNT(*) FROM %s pr WHERE pr.status='active') active_products,
                (SELECT COUNT(*) FROM %s i
                    JOIN %s pr ON pr.id=i.product_id
                    JOIN %s s ON s.id=i.minimarket_id
                    WHERE i.status='active' AND i.stock > 0 AND i.price > 0
                    AND pr.status='active' AND s.status='active') public_offers,
                (SELECT COUNT(*) FROM %s c WHERE c.status='approved') approved_couriers,
                (SELECT COUNT(*) FROM %s sp WHERE sp.status='published') published_service_providers",
            $this->table('payments'),
            $this->table('orders'),
            $this->table('payment_orders'),
            $this->table('payments'),
            $this->table('deliveries'),
            $this->table('deliveries'),
            $this->table('deliveries'),
            $this->table('deliveries'),
            $this->table('stores'),
            $this->table('products'),
            $this->table('inventory'),
            $this->table('products'),
            $this->table('stores'),
            $this->table('couriers'),
            $this->table('service_providers')
        );
        $database = $this->db();
        $row = $database->get_row($database->prepare(
            $sql,
            $utcStart,
            $utcEnd,
            $localStart,
            $localEnd
        ), ARRAY_A);
        $this->queryCount++;
        if (! is_array($row) || $database->last_error !== '') {
            throw new PersistenceException('No fue posible calcular las métricas del dashboard.');
        }

        $result = [];
        foreach ($row as $key => $value) {
            $result[$key] = $key === 'sales_today'
                ? number_format((float) $value, 2, '.', '')
                : max(0, (int) $value);
        }
        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function recentOrders(int $limit): array
    {
        global $wpdb;
        $sql = sprintf(
            "SELECT o.id order_id,o.created_at,o.total,o.status order_status,
                o.customer_id,u.display_name customer_name,s.business_name store_name,
                c.fulfillment_method
             FROM %s o
             LEFT JOIN %s u ON u.ID=o.customer_id
             LEFT JOIN %s s ON s.id=o.minimarket_id
             LEFT JOIN %s co ON co.order_id=o.id
             LEFT JOIN %s c ON c.id=co.checkout_id
             ORDER BY o.created_at DESC,o.id DESC LIMIT %%d",
            $this->table('orders'),
            $wpdb->users,
            $this->table('stores'),
            $this->table('checkout_orders'),
            $this->table('checkouts')
        );
        $rows = $this->db()->get_results($this->db()->prepare($sql, $limit), ARRAY_A);
        $this->queryCount++;
        if (! is_array($rows) || $this->db()->last_error !== '') {
            throw new PersistenceException('No fue posible consultar los pedidos recientes.');
        }
        return $rows;
    }
}
