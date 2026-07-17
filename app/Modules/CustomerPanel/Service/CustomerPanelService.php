<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\CustomerPanel\Service;

use InvalidArgumentException;
use VeciAhorra\Exceptions\RecordNotFoundException;
use VeciAhorra\Modules\Checkout\Models\Checkout;
use VeciAhorra\Modules\CustomerPanel\DTO\CustomerPurchaseAmountSummary;
use VeciAhorra\Modules\CustomerPanel\DTO\CustomerPurchaseDetail;
use VeciAhorra\Modules\CustomerPanel\DTO\CustomerPurchaseItem;
use VeciAhorra\Modules\CustomerPanel\DTO\CustomerPurchaseListItem;
use VeciAhorra\Modules\CustomerPanel\DTO\CustomerPurchaseOrder;
use VeciAhorra\Modules\CustomerPanel\DTO\CustomerPurchaseTimelineEvent;
use VeciAhorra\Modules\CustomerPanel\Query\CustomerPurchaseQuery;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\DurablePaymentOrigin;

final class CustomerPanelService
{
    public function __construct(
        private CustomerPurchaseQuery $query,
        private CustomerPurchaseStatusResolver $statusResolver
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function listPurchases(int $userId): array
    {
        $this->assertUser($userId);
        $result = [];
        $checkouts = $this->query->listOwnedCheckouts($userId);
        $checkoutIds = array_map('intval', array_column($checkouts, 'id'));
        $ordersByCheckout = $this->groupByInt($this->query->ordersForCheckouts($checkoutIds), 'checkout_id');
        $attemptsByCheckout = $this->groupByInt($this->query->attemptsForCheckouts($checkoutIds), 'checkout_id');
        $paymentsByCheckout = $this->groupByInt($this->query->paymentsForCheckouts($checkoutIds), 'checkout_id');
        $allOrders = array_merge(...array_values($ordersByCheckout ?: [[]]));
        $orderIds = array_map('intval', array_column($allOrders, 'id'));
        $deliveriesByOrder = $this->groupByInt($this->query->deliveries($orderIds), 'order_id');
        $paymentIds = array_map('intval', array_column(array_merge(...array_values($paymentsByCheckout ?: [[]])), 'id'));
        $paymentOrderIds = $this->query->paymentOrderIdsForPayments($paymentIds);
        $businessIds = array_values(array_filter(array_map(
            'intval',
            array_column(array_merge(...array_values($attemptsByCheckout ?: [[]])), 'business_id')
        )));
        $businessOrderIds = $this->query->businessOrderIdsForBusinesses($businessIds);

        foreach ($checkouts as $checkout) {
            $checkoutId = (int) $checkout['id'];
            $orders = $ordersByCheckout[$checkoutId] ?? [];
            $deliveries = [];
            foreach ($orders as $order) {
                array_push($deliveries, ...($deliveriesByOrder[(int) $order['id']] ?? []));
            }
            $projection = $this->project($checkout, $userId, false, [
                'orders' => $orders,
                'attempts' => $attemptsByCheckout[$checkoutId] ?? [],
                'payments' => $paymentsByCheckout[$checkoutId] ?? [],
                'deliveries' => $deliveries,
                'payment_order_ids' => $paymentOrderIds,
                'business_order_ids' => $businessOrderIds,
            ]);

            if ($projection === null) {
                continue;
            }

            $result[] = $this->listDto($projection)->toArray();
        }

        return $result;
    }

    public function getPurchase(int $userId, string $publicId): array
    {
        $this->assertUser($userId);

        if (! Checkout::validPublicId($publicId)) {
            throw new RecordNotFoundException('La compra no está disponible.');
        }

        $checkout = $this->query->findOwnedCheckout($publicId, $userId);

        if ($checkout === null) {
            throw new RecordNotFoundException('La compra no está disponible.');
        }

        $projection = $this->project($checkout, $userId, true);

        if ($projection === null) {
            throw new RecordNotFoundException('La compra no está disponible.');
        }

        return $this->detailDto($projection)->toArray();
    }

    /** @return array<string, mixed>|null */
    private function project(array $checkout, int $userId, bool $withItems, ?array $loaded = null): ?array
    {
        $orders = $loaded['orders'] ?? $this->query->orders((int) $checkout['id']);
        foreach ($orders as $order) {
            if ((int) ($order['customer_id'] ?? 0) !== $userId) {
                return null;
            }
        }
        $orderIds = array_map('intval', array_column($orders, 'id'));
        $minimarketIds = array_map('intval', array_column($orders, 'minimarket_id'));
        if (count($minimarketIds) !== count(array_unique($minimarketIds))) {
            return true;
        }
        $attempts = $loaded['attempts'] ?? $this->query->attempts((int) $checkout['id']);
        $origins = array_values(array_unique(array_filter(array_column($attempts, 'origin'))));

        if ($origins !== [] && ($origins !== [DurablePaymentOrigin::ORIGIN_VECIAHORRA])) {
            return null;
        }

        $attempt = $this->authoritativeAttempt($attempts);
        $paymentRows = $loaded['payments'] ?? null;
        $payment = $paymentRows === null
            ? $this->query->paymentForCheckout((int) $checkout['id'])
            : (count($paymentRows) === 1
                ? $paymentRows[0]
                : ($paymentRows === [] ? null : ['_cardinality_invalid' => true]));
        $deliveries = $loaded['deliveries'] ?? $this->query->deliveries($orderIds);
        $items = $withItems ? $this->query->items($orderIds) : [];
        $inconsistent = $this->inconsistent(
            $checkout,
            $orders,
            $items,
            $payment,
            $deliveries,
            $attempt,
            $userId,
            $loaded['payment_order_ids'] ?? null,
            $loaded['business_order_ids'] ?? null
        );
        $context = [
            'inconsistent' => $inconsistent,
            'checkout_status' => (string) $checkout['status'],
            'fulfillment_method' => $checkout['fulfillment_method'],
            'attempt' => $attempt,
            'payment' => $payment,
            'deliveries' => $deliveries,
        ];

        if ($payment !== null && (int) ($payment['customer_id'] ?? 0) !== $userId) {
            $payment = null;
        }
        if (array_filter($deliveries, static fn (array $delivery): bool => (int) ($delivery['customer_id'] ?? 0) !== $userId) !== []) {
            $deliveries = [];
        }

        return compact('checkout', 'orders', 'items', 'attempt', 'payment', 'deliveries', 'context');
    }

    private function inconsistent(
        array $checkout,
        array $orders,
        array $items,
        ?array $payment,
        array $deliveries,
        array $attempt,
        int $userId,
        ?array $paymentOrderIdsByPayment = null,
        ?array $businessOrderIdsByBusiness = null
    ): bool {
        if ($orders === []
            || ($attempt['_multiple_completed_reconciliations'] ?? false) === true
            || ! in_array($checkout['fulfillment_method'] ?? null, ['pickup', 'delivery', null], true)
        ) {
            return true;
        }
        $orderIds = array_map('intval', array_column($orders, 'id'));
        $orderTotal = '0.00';
        foreach ($orders as $order) {
            if ((int) $order['customer_id'] !== $userId
                || ! in_array($order['status'], ['reserved', 'paid', 'delivered'], true)
            ) {
                return true;
            }
            $orderTotal = $this->add($orderTotal, (string) $order['total']);
            if ($items !== []) {
                $lineTotal = '0.00';
                foreach ($items as $item) {
                    if ((int) $item['order_id'] === (int) $order['id']) {
                        $lineTotal = $this->add($lineTotal, (string) $item['subtotal']);
                    }
                }
            } else {
                $lineTotal = $this->money((string) ($order['item_total'] ?? ''));
            }
            if ($lineTotal !== $this->money((string) $order['total'])) {
                return true;
            }
        }
        if ($orderTotal !== $this->money((string) $checkout['total_amount'])) {
            return true;
        }
        if ($payment !== null) {
            if (($payment['_cardinality_invalid'] ?? false) === true
                || ! in_array($payment['status'] ?? null, ['pending', 'paid'], true)
                || (int) ($payment['customer_id'] ?? 0) !== $userId
                || (int) ($payment['checkout_id'] ?? 0) !== (int) $checkout['id']
                || ($paymentOrderIdsByPayment === null
                    ? $this->query->paymentOrderIds((int) $payment['id'])
                    : ($paymentOrderIdsByPayment[(int) $payment['id']] ?? [])) !== $orderIds
                || (string) ($payment['currency'] ?? '') !== (string) $checkout['currency']
                || $this->money((string) ($payment['amount'] ?? '')) !== $this->money((string) $checkout['total_amount'])
                || (($attempt['reconciliation_id'] ?? null) !== null
                    && (int) ($payment['reconciliation_id'] ?? 0) !== (int) $attempt['reconciliation_id'])
            ) {
                return true;
            }
        }
        if ($payment !== null && ($attempt['reconciliation_status'] ?? null) === null) {
            return true;
        }
        if (($attempt['reconciliation_status'] ?? null) === 'completed' && $payment === null) {
            return true;
        }
        if (($attempt['reconciliation_status'] ?? null) === 'completed'
            && (($payment['status'] ?? null) !== 'paid' || ($attempt['financial_status'] ?? null) !== 'approved')
        ) {
            return true;
        }
        if (($checkout['status'] ?? null) === 'cancelled'
            && (($attempt['reconciliation_status'] ?? null) === 'completed'
                || ($payment['status'] ?? null) === 'paid'
                || in_array('delivered', array_column($deliveries, 'status'), true))
        ) {
            return true;
        }
        if (($attempt['origin'] ?? null) !== null
            && ((string) ($attempt['origin_resource_id'] ?? '') !== (string) $checkout['public_id']
                || (string) ($attempt['origin_attempt_id'] ?? '') !== (string) ($attempt['session_public_id'] ?? '')
                || (string) ($attempt['origin_currency'] ?? '') !== (string) $checkout['currency']
                || $this->moneyToMinor((string) $checkout['total_amount'])
                    !== ((int) ($attempt['origin_amount_clp'] ?? -1) * 100))
        ) {
            return true;
        }
        if (($attempt['reconciliation_status'] ?? null) !== null
            && ((string) ($attempt['reconciliation_origin'] ?? '') !== (string) ($attempt['origin'] ?? '')
                || (string) ($attempt['reconciliation_resource_id'] ?? '') !== (string) $checkout['public_id']
                || (string) ($attempt['reconciliation_attempt_id'] ?? '') !== (string) ($attempt['origin_attempt_id'] ?? ''))
        ) {
            return true;
        }
        if (($attempt['financial_status'] ?? null) !== null
            && ((int) ($attempt['return_amount_clp'] ?? -1) * 100) !== $this->moneyToMinor((string) $checkout['total_amount'])
        ) {
            return true;
        }
        if (($attempt['financial_status'] ?? null) !== null
            && (string) ($attempt['return_currency'] ?? '') !== (string) $checkout['currency']
        ) {
            return true;
        }
        if ($payment !== null && ($payment['status'] ?? null) === 'paid'
            && ($attempt['reconciliation_status'] ?? null) !== 'completed'
        ) {
            return true;
        }
        if (($attempt['business_status'] ?? null) === 'completed') {
            if (($attempt['reconciliation_status'] ?? null) !== 'completed'
                || ($payment['status'] ?? null) !== 'paid'
                || (int) ($attempt['business_payment_id'] ?? 0) !== (int) ($payment['id'] ?? 0)
                || ($attempt['business_fulfillment_method'] ?? null) !== ($checkout['fulfillment_method'] ?? null)
                || ($businessOrderIdsByBusiness === null
                    ? $this->query->businessOrderIds((int) $attempt['business_id'])
                    : ($businessOrderIdsByBusiness[(int) $attempt['business_id']] ?? [])) !== $orderIds
                || array_diff(array_column($orders, 'status'), ['paid', 'delivered']) !== []
            ) {
                return true;
            }
        }
        if (in_array($attempt['delivery_completion_status'] ?? null, ['completed', 'not_required'], true)
            && ($attempt['business_status'] ?? null) !== 'completed'
        ) {
            return true;
        }
        if (($attempt['fulfillment_completion_status'] ?? null) === 'completed'
            && ($attempt['business_status'] ?? null) !== 'completed'
        ) {
            return true;
        }
        $deliveryOrderIds = [];
        $ordersById = [];
        foreach ($orders as $order) {
            $ordersById[(int) $order['id']] = $order;
        }
        foreach ($deliveries as $delivery) {
            $deliveryOrderIds[] = (int) $delivery['order_id'];
            if (! in_array((int) $delivery['order_id'], $orderIds, true)
                || (int) $delivery['customer_id'] !== $userId
                || (int) ($delivery['minimarket_id'] ?? 0)
                    !== (int) ($ordersById[(int) $delivery['order_id']]['minimarket_id'] ?? 0)
                || ! in_array($delivery['status'], ['pending', 'assigned', 'picked_up', 'delivered', 'cancelled'], true)
            ) {
                return true;
            }
        }
        if (count($deliveryOrderIds) !== count(array_unique($deliveryOrderIds))) {
            return true;
        }
        if ($deliveries !== []
            && (($attempt['business_status'] ?? null) !== 'completed'
                || ($attempt['reconciliation_status'] ?? null) !== 'completed'
                || ($payment['status'] ?? null) !== 'paid')
        ) {
            return true;
        }
        $method = $checkout['fulfillment_method'] ?? null;
        if ($method !== 'delivery' && $deliveries !== []) {
            return true;
        }
        if (($attempt['delivery_completion_status'] ?? null) === 'not_required' && $method !== 'pickup') {
            return true;
        }
        if (($attempt['delivery_completion_status'] ?? null) === 'completed' && $method !== 'delivery') {
            return true;
        }
        sort($deliveryOrderIds);
        if ($method === 'delivery' && $deliveries !== [] && $deliveryOrderIds !== $orderIds) {
            return true;
        }
        if ($method === 'delivery'
            && ($attempt['delivery_completion_status'] ?? null) === 'completed'
            && $deliveryOrderIds !== $orderIds
        ) {
            return true;
        }

        return false;
    }

    private function listDto(array $projection): CustomerPurchaseListItem
    {
        $checkout = $projection['checkout'];
        $orders = $projection['orders'];
        $quantity = array_sum(array_map('intval', array_column($orders, 'item_quantity')));
        $minimarketsById = [];
        foreach ($orders as $order) {
            $minimarketsById[(int) $order['minimarket_id']] = (string) ($order['minimarket_name'] ?: 'Minimarket');
        }

        return new CustomerPurchaseListItem(
            (string) $checkout['public_id'],
            $this->date((string) $checkout['created_at']),
            new CustomerPurchaseAmountSummary($this->money((string) $checkout['total_amount']), (string) $checkout['currency']),
            $quantity,
            count($orders),
            count($minimarketsById),
            array_values($minimarketsById),
            $checkout['fulfillment_method'] === null ? null : (string) $checkout['fulfillment_method'],
            $this->statusResolver->resolve($projection['context'])
        );
    }

    private function detailDto(array $projection): CustomerPurchaseDetail
    {
        $checkout = $projection['checkout'];
        $itemsByOrder = [];
        $fallbackPosition = [];
        foreach ($projection['items'] as $item) {
            $orderId = (int) $item['order_id'];
            $fallbackPosition[$orderId] = ($fallbackPosition[$orderId] ?? 0) + 1;
            $imageId = (int) ($item['current_image_id'] ?? 0);
            $image = $imageId > 0 ? wp_get_attachment_image_url($imageId, 'medium') : null;
            $itemsByOrder[$orderId][] = new CustomerPurchaseItem(
                trim((string) ($item['current_product_name'] ?? '')) ?: 'Producto ' . $fallbackPosition[$orderId] . ' del pedido',
                is_string($image) ? $image : null,
                (int) $item['quantity'],
                $this->money((string) $item['unit_price']),
                $this->money((string) $item['subtotal'])
            );
        }
        $orderDtos = array_map(
            fn (array $order): CustomerPurchaseOrder => new CustomerPurchaseOrder(
                (string) ($order['minimarket_name'] ?: 'Minimarket'),
                $this->money((string) $order['total']),
                $itemsByOrder[(int) $order['id']] ?? []
            ),
            $projection['orders']
        );
        $payment = $this->publicPayment($projection['payment']);
        $timeline = $this->timeline($projection);
        $visibleStatus = $this->statusResolver->resolve($projection['context']);

        return new CustomerPurchaseDetail(
            (string) $checkout['public_id'],
            $this->date((string) $checkout['created_at']),
            $visibleStatus,
            $checkout['fulfillment_method'] === null ? null : (string) $checkout['fulfillment_method'],
            new CustomerPurchaseAmountSummary($this->money((string) $checkout['total_amount']), (string) $checkout['currency']),
            array_sum(array_map('intval', array_column($projection['items'], 'quantity'))),
            count(array_unique(array_map('intval', array_column($projection['orders'], 'minimarket_id')))),
            $orderDtos,
            $payment,
            $this->publicDelivery((string) ($checkout['fulfillment_method'] ?? ''), $visibleStatus->code),
            $timeline
        );
    }

    private function publicDelivery(string $method, string $visibleCode): array
    {
        $logistics = in_array($visibleCode, ['preparing_delivery', 'out_for_delivery', 'delivered', 'cancelled', 'under_review'], true)
            ? $visibleCode
            : ($method === 'pickup' ? 'not_applicable' : 'not_available');

        return [
            'method' => $method !== '' ? $method : null,
            'status' => $logistics,
            'label' => $method === 'pickup' ? 'Retiro' : ($method === 'delivery' ? 'Despacho' : 'Por confirmar'),
        ];
    }

    /** @param list<array<string, mixed>> $attempts @return array<string, mixed> */
    private function authoritativeAttempt(array $attempts): array
    {
        $completed = array_values(array_filter(
            $attempts,
            static fn (array $attempt): bool => ($attempt['reconciliation_status'] ?? null) === 'completed'
        ));

        if (count($completed) > 1) {
            $attempt = $completed[0];
            $attempt['_multiple_completed_reconciliations'] = true;
            return $attempt;
        }

        return $completed[0] ?? ($attempts[0] ?? []);
    }

    private function publicPayment(?array $payment): ?array
    {
        if ($payment === null || ($payment['_cardinality_invalid'] ?? false) === true) {
            return null;
        }
        return [
            'status' => $payment['status'] === 'paid' ? 'received' : 'pending',
            'label' => $payment['status'] === 'paid' ? 'Pago recibido' : 'Pago pendiente',
            'amount' => $this->money((string) $payment['amount']),
            'currency' => (string) $payment['currency'],
            'paid_at' => empty($payment['paid_at']) ? null : $this->date((string) $payment['paid_at']),
            'method' => $payment['provider'] === 'webpay_plus' ? 'Webpay Plus' : null,
        ];
    }

    /** @return list<CustomerPurchaseTimelineEvent> */
    private function timeline(array $projection): array
    {
        $events = [new CustomerPurchaseTimelineEvent('checkout_created', 'Compra creada', $this->date((string) $projection['checkout']['created_at']))];
        if (is_array($projection['payment']) && ! empty($projection['payment']['paid_at'])) {
            $events[] = new CustomerPurchaseTimelineEvent('payment_confirmed', 'Pago confirmado', $this->date((string) $projection['payment']['paid_at']));
        } elseif (! empty($projection['attempt']['reconciled_at'])) {
            $events[] = new CustomerPurchaseTimelineEvent('payment_reconciled', 'Pago conciliado', $this->date((string) $projection['attempt']['reconciled_at']));
        }
        if (! empty($projection['attempt']['business_completed_at'])) {
            $events[] = new CustomerPurchaseTimelineEvent('orders_materialized', 'Pedidos preparados en el sistema', $this->date((string) $projection['attempt']['business_completed_at']));
        }
        if (($projection['checkout']['fulfillment_method'] ?? null) === 'delivery') {
            foreach ($projection['deliveries'] as $delivery) {
                if (! empty($delivery['created_at'])) {
                    $events[] = new CustomerPurchaseTimelineEvent('delivery_created', 'Despacho creado', $this->date((string) $delivery['created_at']));
                }
            }
        }
        usort($events, static fn (CustomerPurchaseTimelineEvent $a, CustomerPurchaseTimelineEvent $b): int => [$a->occurredAt, $a->code] <=> [$b->occurredAt, $b->code]);

        return $events;
    }

    private function money(string $amount): string
    {
        $minor = $this->moneyToMinor($amount);

        return $minor === null
            ? ''
            : intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    private function add(string $left, string $right): string
    {
        $leftMinor = $this->moneyToMinor($left);
        $rightMinor = $this->moneyToMinor($right);

        return $leftMinor === null || $rightMinor === null
            ? ''
            : $this->money((string) intdiv($leftMinor + $rightMinor, 100)
                . '.' . str_pad((string) (($leftMinor + $rightMinor) % 100), 2, '0', STR_PAD_LEFT));
    }

    private function moneyToMinor(string $amount): ?int
    {
        if (preg_match('/^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/D', $amount, $matches) !== 1) {
            return null;
        }

        return ((int) $matches[1] * 100)
            + (int) str_pad($matches[2] ?? '', 2, '0', STR_PAD_RIGHT);
    }

    private function date(string $date): string
    {
        return get_gmt_from_date($date, 'Y-m-d\TH:i:s\Z');
    }

    private function assertUser(int $userId): void
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('El cliente autenticado no es válido.');
        }
    }

    /** @return array<int, list<array<string, mixed>>> */
    private function groupByInt(array $rows, string $field): array
    {
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row[$field]][] = $row;
        }

        return $result;
    }

}
