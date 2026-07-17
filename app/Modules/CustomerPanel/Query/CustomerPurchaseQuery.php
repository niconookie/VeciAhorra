<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\CustomerPanel\Query;

use VeciAhorra\Database\Repository;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\DurablePaymentOrigin;

/** Read-only projection queries. Every statement in this class is SELECT-only. */
final class CustomerPurchaseQuery extends Repository
{
    /** @return list<array<string, mixed>> */
    public function listOwnedCheckouts(int $userId, int $limit = 20): array
    {
        return $this->db()->get_results($this->db()->prepare(sprintf(
            'SELECT c.* FROM %s c WHERE c.owner_type = %%s AND c.user_id = %%d'
            . ' AND NOT EXISTS (SELECT 1 FROM %s ps INNER JOIN %s poc'
            . ' ON poc.payment_attempt_id = ps.public_id'
            . ' WHERE ps.checkout_id = c.id AND poc.origin <> %%s)'
            . ' ORDER BY c.created_at DESC, c.id DESC LIMIT %%d',
            $this->table('checkouts'),
            $this->table('payment_sessions'),
            $this->table('payment_origin_contexts')
        ), 'user', $userId, DurablePaymentOrigin::ORIGIN_VECIAHORRA, $limit), ARRAY_A);
    }

    public function findOwnedCheckout(string $publicId, int $userId): ?array
    {
        $row = $this->db()->get_row($this->db()->prepare(sprintf(
            'SELECT c.* FROM %s c WHERE c.public_id = %%s AND c.owner_type = %%s'
            . ' AND c.user_id = %%d'
            . ' AND NOT EXISTS (SELECT 1 FROM %s ps INNER JOIN %s poc'
            . ' ON poc.payment_attempt_id = ps.public_id'
            . ' WHERE ps.checkout_id = c.id AND poc.origin <> %%s) LIMIT 1',
            $this->table('checkouts'),
            $this->table('payment_sessions'),
            $this->table('payment_origin_contexts')
        ), $publicId, 'user', $userId, DurablePaymentOrigin::ORIGIN_VECIAHORRA), ARRAY_A);

        return $row === null ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function orders(int $checkoutId): array
    {
        return $this->ordersForCheckouts([$checkoutId]);
    }

    /** @param list<int> $checkoutIds @return list<array<string, mixed>> */
    public function ordersForCheckouts(array $checkoutIds): array
    {
        if ($checkoutIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($checkoutIds), '%d'));

        return $this->db()->get_results($this->db()->prepare(sprintf(
            'SELECT co.checkout_id, o.*, s.business_name AS minimarket_name,'
            . ' COALESCE(ia.item_quantity, 0) AS item_quantity,'
            . ' COALESCE(ia.item_lines, 0) AS item_lines,'
            . ' COALESCE(ia.item_total, 0) AS item_total'
            . ' FROM %s co INNER JOIN %s o ON o.id = co.order_id'
            . ' LEFT JOIN %s s ON s.id = o.minimarket_id'
            . ' LEFT JOIN (SELECT order_id, SUM(quantity) AS item_quantity,'
            . ' COUNT(*) AS item_lines, SUM(subtotal) AS item_total'
            . ' FROM %s GROUP BY order_id) ia ON ia.order_id = o.id'
            . ' WHERE co.checkout_id IN (%s) ORDER BY co.checkout_id ASC, o.id ASC',
            $this->table('checkout_orders'),
            $this->table('orders'),
            $this->table('stores'),
            $this->table('order_items'),
            $placeholders
        ), ...$checkoutIds), ARRAY_A);
    }

    /** @param list<int> $orderIds @return list<array<string, mixed>> */
    public function items(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($orderIds), '%d'));

        return $this->db()->get_results($this->db()->prepare(sprintf(
            'SELECT oi.*, p.name AS current_product_name, p.image_id AS current_image_id'
            . ' FROM %s oi LEFT JOIN %s p ON p.id = oi.product_id'
            . ' WHERE oi.order_id IN (%s) ORDER BY oi.order_id ASC, oi.id ASC',
            $this->table('order_items'),
            $this->table('products'),
            $placeholders
        ), ...$orderIds), ARRAY_A);
    }

    /** @return list<array<string, mixed>> */
    public function attempts(int $checkoutId): array
    {
        return $this->attemptsForCheckouts([$checkoutId]);
    }

    /** @param list<int> $checkoutIds @return list<array<string, mixed>> */
    public function attemptsForCheckouts(array $checkoutIds): array
    {
        if ($checkoutIds === []) {
            return [];
        }
        $p = fn (string $name): string => $this->table($name);
        $placeholders = implode(', ', array_fill(0, count($checkoutIds), '%d'));

        return $this->db()->get_results($this->db()->prepare(
            'SELECT ps.checkout_id, ps.public_id AS session_public_id,'
            . ' ps.status AS session_status, ps.created_at AS session_created_at,'
            . ' o.origin, o.payment_attempt_id AS origin_attempt_id,'
            . ' o.origin_resource_id, o.amount_clp AS origin_amount_clp,'
            . ' o.currency AS origin_currency, wr.financial_status, wr.financial_obtained_at,'
            . ' wr.amount_clp AS return_amount_clp, wr.currency AS return_currency,'
            . ' r.reconciliation_status, r.reconciled_at, r.id AS reconciliation_id,'
            . ' r.origin AS reconciliation_origin,'
            . ' r.origin_resource_id AS reconciliation_resource_id,'
            . ' r.payment_attempt_id AS reconciliation_attempt_id,'
            . ' b.id AS business_id, b.status AS business_status,'
            . ' b.payment_id AS business_payment_id,'
            . ' b.fulfillment_method AS business_fulfillment_method,'
            . ' b.completed_at AS business_completed_at,'
            . ' dc.completion_status AS delivery_completion_status,'
            . ' fc.completion_status AS fulfillment_completion_status'
            . " FROM {$p('payment_sessions')} ps"
            . " LEFT JOIN {$p('payment_origin_contexts')} o ON o.payment_attempt_id = ps.public_id"
            . " LEFT JOIN {$p('webpay_returns')} wr ON wr.token_hash = o.token_hash"
            . " LEFT JOIN {$p('payment_reconciliations')} r"
            . ' ON r.webpay_return_id = wr.id AND r.origin_context_id = o.id'
            . " LEFT JOIN {$p('business_completions')} b ON b.reconciliation_id = r.id"
            . " LEFT JOIN {$p('delivery_completions')} dc ON dc.business_completion_id = b.id"
            . " LEFT JOIN {$p('fulfillment_completions')} fc ON fc.business_completion_id = b.id"
            . " WHERE ps.checkout_id IN ({$placeholders})"
            . ' ORDER BY ps.checkout_id ASC, ps.id DESC',
            ...$checkoutIds
        ), ARRAY_A);
    }

    /** @param list<int> $checkoutIds @return list<array<string, mixed>> */
    public function paymentsForCheckouts(array $checkoutIds): array
    {
        if ($checkoutIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($checkoutIds), '%d'));

        return $this->db()->get_results($this->db()->prepare(sprintf(
            'SELECT * FROM %s WHERE checkout_id IN (%s) ORDER BY checkout_id ASC, id ASC',
            $this->table('payments'),
            $placeholders
        ), ...$checkoutIds), ARRAY_A);
    }

    public function paymentForCheckout(int $checkoutId): ?array
    {
        $rows = $this->paymentsForCheckouts([$checkoutId]);

        if (count($rows) !== 1) {
            return $rows === [] ? null : ['_cardinality_invalid' => true];
        }

        return $rows[0];
    }

    /** @return list<int> */
    public function paymentOrderIds(int $paymentId): array
    {
        return array_map('intval', $this->db()->get_col($this->db()->prepare(sprintf(
            'SELECT order_id FROM %s WHERE payment_id = %%d ORDER BY order_id ASC',
            $this->table('payment_orders')
        ), $paymentId)));
    }

    /** @param list<int> $paymentIds @return array<int, list<int>> */
    public function paymentOrderIdsForPayments(array $paymentIds): array
    {
        if ($paymentIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($paymentIds), '%d'));
        $rows = $this->db()->get_results($this->db()->prepare(sprintf(
            'SELECT payment_id, order_id FROM %s WHERE payment_id IN (%s)'
            . ' ORDER BY payment_id ASC, order_id ASC',
            $this->table('payment_orders'),
            $placeholders
        ), ...$paymentIds), ARRAY_A);
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['payment_id']][] = (int) $row['order_id'];
        }

        return $result;
    }

    /** @param list<int> $orderIds @return list<array<string, mixed>> */
    public function deliveries(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($orderIds), '%d'));

        return $this->db()->get_results($this->db()->prepare(sprintf(
            'SELECT order_id, customer_id, minimarket_id, status, created_at'
            . ' FROM %s WHERE order_id IN (%s) ORDER BY order_id ASC',
            $this->table('deliveries'),
            $placeholders
        ), ...$orderIds), ARRAY_A);
    }

    /** @return list<int> */
    public function businessOrderIds(int $businessId): array
    {
        return array_map('intval', $this->db()->get_col($this->db()->prepare(sprintf(
            'SELECT order_id FROM %s WHERE business_completion_id = %%d ORDER BY order_id ASC',
            $this->table('business_completion_orders')
        ), $businessId)));
    }

    /** @param list<int> $businessIds @return array<int, list<int>> */
    public function businessOrderIdsForBusinesses(array $businessIds): array
    {
        if ($businessIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($businessIds), '%d'));
        $rows = $this->db()->get_results($this->db()->prepare(sprintf(
            'SELECT business_completion_id, order_id FROM %s'
            . ' WHERE business_completion_id IN (%s)'
            . ' ORDER BY business_completion_id ASC, order_id ASC',
            $this->table('business_completion_orders'),
            $placeholders
        ), ...$businessIds), ARRAY_A);
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['business_completion_id']][] = (int) $row['order_id'];
        }

        return $result;
    }
}
