<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Repositories;

use VeciAhorra\Database\Repository;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Orders\Contracts\OrderAdminReadRepositoryInterface;
use VeciAhorra\Modules\Orders\DTO\Admin\OrderAdminListQuery;

final class OrderAdminReadRepository extends Repository implements OrderAdminReadRepositoryInterface
{
    private const ORDER_BY = [
        'newest' => 'o.created_at DESC, o.id DESC',
        'oldest' => 'o.created_at ASC, o.id ASC',
        'updated' => 'o.updated_at DESC, o.id DESC',
        'total_desc' => 'o.total DESC, o.id DESC',
        'total_asc' => 'o.total ASC, o.id ASC',
    ];

    public function count(OrderAdminListQuery $query): int
    {
        [$joins, $where, $params] = $this->filterSql($query);
        $sql = sprintf(
            'SELECT COUNT(DISTINCT o.id) FROM %s o %s %s',
            $this->table('orders'),
            $joins,
            $where
        );
        $database = $this->db();
        $value = $database->get_var($params === [] ? $sql : $database->prepare($sql, ...$params));
        if ($database->last_error !== '' || $value === null) {
            throw new PersistenceException('No fue posible contar las Orders.');
        }

        return max(0, (int) $value);
    }

    public function paginate(OrderAdminListQuery $query): array
    {
        [$joins, $where, $params] = $this->filterSql($query);
        $sql = sprintf(
            'SELECT %s FROM %s o %s %s
             ORDER BY %s LIMIT %%d OFFSET %%d',
            $this->baseColumns(),
            $this->table('orders'),
            $joins,
            $where,
            self::ORDER_BY[$query->order]
        );
        array_push($params, $query->perPage, $query->offset());
        $database = $this->db();
        $rows = $database->get_results($database->prepare($sql, ...$params), ARRAY_A);
        if ($database->last_error !== '' || ! is_array($rows)) {
            throw new PersistenceException('No fue posible consultar las Orders.');
        }

        return $rows;
    }

    public function findBase(int $orderId): ?array
    {
        $sql = sprintf(
            'SELECT %s FROM %s o
             LEFT JOIN %s co ON co.order_id = o.id
             LEFT JOIN %s c ON c.id = co.checkout_id
             LEFT JOIN %s s ON s.id = o.minimarket_id
             WHERE o.id = %%d LIMIT 1',
            $this->baseColumns(),
            $this->table('orders'),
            $this->table('checkout_orders'),
            $this->table('checkouts'),
            $this->table('stores')
        );
        $database = $this->db();
        $row = $database->get_row($database->prepare($sql, $orderId), ARRAY_A);
        if ($database->last_error !== '') {
            throw new PersistenceException('No fue posible consultar la Order.');
        }

        return $row === null ? null : $row;
    }

    public function loadFacts(array $orderIds): array
    {
        $ids = array_values(array_unique(array_filter($orderIds, static fn (int $id): bool => $id > 0)));
        sort($ids, SORT_NUMERIC);
        if ($ids === []) {
            return [];
        }

        $bundles = array_fill_keys($ids, []);
        [$commerceSql, $commerceParams] = $this->commerceSql($ids);
        $commerceRows = $this->readRows($commerceSql, $commerceParams);
        if ($commerceRows === null) {
            throw new PersistenceException('No fue posible cargar hechos centrales de Orders.');
        }
        $this->mergeRows($bundles, $commerceRows);

        [$operationsSql, $operationsParams] = $this->operationsSql($ids);
        $operationsRows = $this->readRows($operationsSql, $operationsParams);
        if ($operationsRows === null) {
            throw new PersistenceException('No fue posible cargar autoridades operacionales.');
        }
        $this->mergeRows($bundles, $operationsRows);

        return $bundles;
    }

    /** @param list<int> $params
     * @return list<array<string,mixed>>|null
     */
    private function readRows(string $sql, array $params): ?array
    {
        $database = $this->db();
        $rows = $database->get_results($database->prepare($sql, ...$params), ARRAY_A);
        return $database->last_error === '' && is_array($rows) ? $rows : null;
    }

    /** @param array<int,array<string,list<array<string,mixed>>>> $bundles
     * @param list<array<string,mixed>> $rows
     */
    private function mergeRows(array &$bundles, array $rows): void
    {
        foreach ($rows as $row) {
            $orderId = (int) ($row['order_id'] ?? 0);
            $entity = (string) ($row['entity'] ?? '');
            $payload = json_decode((string) ($row['payload'] ?? ''), true);
            if (isset($bundles[$orderId]) && $entity !== '' && is_array($payload)) {
                $bundles[$orderId][$entity][] = $payload;
            }
        }
    }

    private function baseColumns(): string
    {
        return sprintf(
            'o.id AS order_id, o.customer_id, o.minimarket_id, o.total,
             o.status AS order_status, o.reservation_expires_at,
             o.created_at AS order_created_at, o.updated_at AS order_updated_at,
             co.id AS checkout_order_link_id, co.checkout_id,
             c.public_id AS checkout_public_id, c.status AS checkout_status,
             c.fulfillment_method, c.currency, c.total_amount,
             c.owner_type, c.user_id, c.created_at AS checkout_created_at,
             c.updated_at AS checkout_updated_at, c.expires_at AS checkout_expires_at,
             s.id AS resolved_store_id, s.business_name AS store_name,
             s.status AS store_status,
             (SELECT COUNT(*) FROM %s oi WHERE oi.order_id = o.id) AS line_count,
             (SELECT COALESCE(SUM(oi.quantity),0) FROM %s oi WHERE oi.order_id = o.id) AS unit_count',
            $this->table('order_items'),
            $this->table('order_items')
        );
    }

    /** @return array{0:string,1:string,2:list<int|string>} */
    private function filterSql(OrderAdminListQuery $query): array
    {
        $joins = sprintf(
            'LEFT JOIN %s co ON co.order_id = o.id
             LEFT JOIN %s c ON c.id = co.checkout_id
             LEFT JOIN %s s ON s.id = o.minimarket_id',
            $this->table('checkout_orders'),
            $this->table('checkouts'),
            $this->table('stores')
        );
        $conditions = [];
        $params = [];
        if ($query->storeId !== null) {
            $conditions[] = 'o.minimarket_id = %d';
            $params[] = $query->storeId;
        }
        if ($query->orderStatus !== null) {
            $conditions[] = 'o.status = %s';
            $params[] = $query->orderStatus;
        }
        if ($query->fulfillmentMode !== null) {
            $conditions[] = 'c.fulfillment_method = %s';
            $params[] = $query->fulfillmentMode;
        }
        if ($query->createdFrom !== null) {
            $conditions[] = 'o.created_at >= %s';
            $params[] = $query->createdFrom;
        }
        if ($query->createdTo !== null) {
            $conditions[] = 'o.created_at <= %s';
            $params[] = $query->createdTo;
        }
        if ($query->search !== null) {
            $search = trim($query->search);
            if (preg_match('/^[1-9][0-9]*$/D', $search) === 1) {
                $conditions[] = '(o.id = %d OR c.id = %d)';
                array_push($params, (int) $search, (int) $search);
            } elseif (preg_match('/^checkout:([1-9][0-9]*)$/Di', $search, $match) === 1) {
                $conditions[] = 'c.id = %d';
                $params[] = (int) $match[1];
            } else {
                $like = '%' . $this->db()->esc_like($search) . '%';
                $conditions[] = '(c.public_id = %s OR s.business_name LIKE %s)';
                array_push($params, $search, $like);
            }
        }

        return [$joins, $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions), $params];
    }

    /** @param list<int> $ids
     * @return array{0:string,1:list<int>}
     */
    private function commerceSql(array $ids): array
    {
        $in = implode(',', array_fill(0, count($ids), '%d'));
        $sql = sprintf(
            "SELECT 'order_items' entity, oi.order_id,
                JSON_OBJECT('id',oi.id,'order_id',oi.order_id,'product_id',oi.product_id,
                'inventory_id',oi.inventory_id,'quantity',oi.quantity,
                'unit_price',oi.unit_price,'subtotal',oi.subtotal,
                'created_at',oi.created_at,'updated_at',oi.updated_at) payload
             FROM %s oi WHERE oi.order_id IN (%s)
             UNION ALL
             SELECT 'reservations', r.order_id,
                JSON_OBJECT('id',r.id,'order_id',r.order_id,'product_id',r.product_id,
                'inventory_id',r.inventory_id,'minimarket_id',r.minimarket_id,
                'quantity',r.quantity,'status',r.status,'reserved_at',r.reserved_at,
                'expires_at',r.expires_at,'released_at',r.released_at,'updated_at',r.updated_at)
             FROM %s r WHERE r.order_id IN (%s)",
            $this->table('order_items'),
            $in,
            $this->table('reservations'),
            $in
        );

        return [$sql, [...$ids, ...$ids]];
    }

    /** @param list<int> $ids
     * @return array{0:string,1:list<int>}
     */
    private function operationsSql(array $ids): array
    {
        $in = implode(',', array_fill(0, count($ids), '%d'));
        $co = $this->table('checkout_orders');
        $ps = $this->table('payment_sessions');
        $wr = $this->table('webpay_returns');
        $pr = $this->table('payment_reconciliations');
        $payments = $this->table('payments');
        $po = $this->table('payment_orders');
        $bc = $this->table('business_completions');
        $bco = $this->table('business_completion_orders');
        $dc = $this->table('delivery_completions');
        $fc = $this->table('fulfillment_completions');
        $deliveries = $this->table('deliveries');
        $tracking = $this->table('delivery_tracking');
        $parts = [
            "SELECT 'payment_sessions' entity, co.order_id,
             JSON_OBJECT('id',ps.id,'public_id',ps.public_id,'checkout_id',ps.checkout_id,
             'payment_id',ps.payment_id,'status',ps.status,'create_version',ps.create_version,
             'create_lease_expires_at',ps.create_lease_expires_at,
             'create_started_at',ps.create_started_at,'amount',ps.amount,'currency',ps.currency,
             'confirmed_at',ps.confirmed_at,'created_at',ps.created_at,'updated_at',ps.updated_at) payload
             FROM {$co} co JOIN {$ps} ps ON ps.checkout_id=co.checkout_id WHERE co.order_id IN ({$in})",
            "SELECT 'financial_evidence', co.order_id,
             JSON_OBJECT('id',wr.id,'payment_session_id',wr.payment_session_id,
             'status',wr.financial_status,'validated',IF(wr.financial_validated_at IS NULL,FALSE,TRUE),
             'amount',wr.amount_clp,'currency',wr.currency,
             'obtained_at',wr.financial_obtained_at,'validated_at',wr.financial_validated_at,
             'updated_at',wr.updated_at) FROM {$co} co JOIN {$ps} ps ON ps.checkout_id=co.checkout_id
             JOIN {$wr} wr ON wr.payment_session_id=ps.id WHERE co.order_id IN ({$in})",
            "SELECT 'reconciliations', co.order_id,
             JSON_OBJECT('id',pr.id,'checkout_id',co.checkout_id,'status',pr.reconciliation_status,
             'attempt_count',pr.attempt_count,'lease_expires_at',pr.lease_expires_at,
             'lease_version',pr.lease_version,'last_attempt_at',pr.last_attempt_at,
             'completed_at',pr.reconciled_at,'updated_at',pr.updated_at)
             FROM {$co} co JOIN {$ps} ps ON ps.checkout_id=co.checkout_id
             JOIN {$wr} wr ON wr.payment_session_id=ps.id JOIN {$pr} pr ON pr.webpay_return_id=wr.id
             WHERE co.order_id IN ({$in})",
            "SELECT 'payments', po.order_id,
             JSON_OBJECT('id',p.id,'checkout_id',p.checkout_id,'payment_session_id',p.payment_session_id,
             'reconciliation_id',p.reconciliation_id,'amount',p.amount,'currency',p.currency,
             'status',p.status,'paid_at',p.paid_at,'created_at',p.created_at,'updated_at',p.updated_at)
             FROM {$po} po JOIN {$payments} p ON p.id=po.payment_id WHERE po.order_id IN ({$in})",
            "SELECT 'payment_order_links', po.order_id,
             JSON_OBJECT('id',po.id,'payment_id',po.payment_id,'order_id',po.order_id,'created_at',po.created_at)
             FROM {$po} po WHERE po.order_id IN ({$in})",
            "SELECT 'business_completions', bco.order_id,
             JSON_OBJECT('id',bc.id,'reconciliation_id',bc.reconciliation_id,'payment_id',bc.payment_id,
             'status',bc.status,'fulfillment_method',bc.fulfillment_method,
             'attempt_count',bc.attempt_count,'lease_expires_at',bc.lease_expires_at,
             'lease_version',bc.lease_version,'completed_at',bc.completed_at,
             'created_at',bc.created_at,'updated_at',bc.updated_at)
             FROM {$bco} bco JOIN {$bc} bc ON bc.id=bco.business_completion_id WHERE bco.order_id IN ({$in})",
            "SELECT 'business_order_links', bco.order_id,
             JSON_OBJECT('id',bco.id,'business_completion_id',bco.business_completion_id,
             'order_id',bco.order_id,'created_at',bco.created_at) FROM {$bco} bco WHERE bco.order_id IN ({$in})",
            "SELECT 'delivery_completions', bco.order_id,
             JSON_OBJECT('id',dc.id,'business_completion_id',dc.business_completion_id,
             'status',dc.completion_status,'attempt_count',dc.attempt_count,
             'lease_expires_at',dc.lease_expires_at,'lease_version',dc.lease_version,
             'completed_at',dc.completed_at,'created_at',dc.created_at,'updated_at',dc.updated_at)
             FROM {$bco} bco JOIN {$dc} dc ON dc.business_completion_id=bco.business_completion_id
             WHERE bco.order_id IN ({$in})",
            "SELECT 'fulfillment_completions', bco.order_id,
             JSON_OBJECT('id',fc.id,'business_completion_id',fc.business_completion_id,
             'status',fc.completion_status,'attempt_count',fc.attempt_count,
             'lease_expires_at',fc.lease_expires_at,'lease_version',fc.lease_version,
             'completed_at',fc.completed_at,'created_at',fc.created_at,'updated_at',fc.updated_at)
             FROM {$bco} bco JOIN {$fc} fc ON fc.business_completion_id=bco.business_completion_id
             WHERE bco.order_id IN ({$in})",
            "SELECT 'deliveries', d.order_id,
             JSON_OBJECT('id',d.id,'order_id',d.order_id,'customer_id',d.customer_id,
             'minimarket_id',d.minimarket_id,'courier_id',d.courier_id,'status',d.status,
             'created_at',d.created_at,'updated_at',d.updated_at)
             FROM {$deliveries} d WHERE d.order_id IN ({$in})",
            "SELECT 'delivery_tracking', d.order_id,
             JSON_OBJECT('id',dt.id,'delivery_id',dt.delivery_id,'event',dt.event,'created_at',dt.created_at)
             FROM {$deliveries} d JOIN {$tracking} dt ON dt.delivery_id=d.id
             WHERE d.order_id IN ({$in}) AND dt.event IN ('assigned','picked_up','delivered')",
        ];

        return [implode(' UNION ALL ', $parts), array_merge(...array_fill(0, count($parts), $ids))];
    }
}
