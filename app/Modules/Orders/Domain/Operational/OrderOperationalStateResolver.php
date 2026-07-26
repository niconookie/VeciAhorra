<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\Operational;

final class OrderOperationalStateResolver
{
    public function resolve(OrderOperationalFacts $input): OrderOperationalResolution
    {
        $facts = $this->normalize($input->all());
        $dimensions = [
            'payment_session' => $this->paymentSessionState($facts),
            'financial' => $this->financialState($facts),
            'reservations' => $this->reservationState($facts),
        ];
        $dimensions['processing'] = $this->processingState($facts, $dimensions['financial'], $input->observedAt);
        $dimensions['delivery'] = $this->deliveryState($facts);
        $dimensions['fulfillment'] = $this->fulfillmentState($facts, $dimensions['processing'], $dimensions['delivery']);

        $findings = $this->findings($facts, $dimensions, $input->observedAt);
        $consistency = $this->consistency($facts, $dimensions, $findings);
        $dimensions['commercial'] = $this->commercialState($facts, $dimensions, $findings);
        $primary = $this->primaryState($facts, $dimensions, $consistency, $findings);
        $timeline = $this->timeline($facts);
        $version = $this->operationalVersion($facts, $findings);

        foreach ($dimensions as $catalog => $state) {
            OperationalStateCatalog::assert($catalog === 'reservations' ? 'reservation' : $catalog, $state);
        }

        return new OrderOperationalResolution(
            $primary,
            $dimensions,
            $consistency,
            $findings,
            $timeline,
            $version,
            $input->observedAt,
            [
                'order_id' => (int) ($facts['order']['id'] ?? 0),
                'order_status' => (string) ($facts['order']['status'] ?? ''),
                'checkout_status' => $facts['checkout']['status'] ?? null,
                'fulfillment_method' => $facts['checkout']['fulfillment_method'] ?? null,
                'reservation_count' => count($facts['reservations']),
                'delivery_count' => count($facts['deliveries']),
            ]
        );
    }

    private function normalize(array $facts): array
    {
        foreach (['order_items', 'checkout_order_links', 'reservations', 'payment_attempts', 'payment_order_links', 'business_order_links', 'deliveries', 'delivery_tracking', 'read_failures'] as $key) {
            $facts[$key] = array_values(is_array($facts[$key] ?? null) ? $facts[$key] : []);
            usort($facts[$key], static function (array $left, array $right): int {
                return [(string) ($left['created_at'] ?? $left['updated_at'] ?? ''), (int) ($left['id'] ?? 0)]
                    <=> [(string) ($right['created_at'] ?? $right['updated_at'] ?? ''), (int) ($right['id'] ?? 0)];
            });
        }
        foreach (['checkout', 'payment_session', 'financial_evidence', 'reconciliation', 'payment', 'business_completion', 'delivery_completion', 'fulfillment_completion'] as $key) {
            $facts[$key] = is_array($facts[$key] ?? null) ? $facts[$key] : null;
        }
        $facts['historical_profile'] = (string) ($facts['historical_profile'] ?? 'none');

        return $facts;
    }

    private function paymentSessionState(array $facts): string
    {
        if ($facts['payment_session'] === null) {
            return 'absent';
        }

        $status = (string) ($facts['payment_session']['status'] ?? '');
        return in_array($status, OperationalStateCatalog::PAYMENT_SESSION, true) && $status !== 'absent'
            ? $status
            : 'unknown';
    }

    private function financialState(array $facts): string
    {
        $session = $this->paymentSessionState($facts);
        $evidence = $facts['financial_evidence'];
        $reconciliation = (string) ($facts['reconciliation']['status'] ?? '');
        $payment = (string) ($facts['payment']['status'] ?? '');

        if ($this->flag($facts, 'financial_inconsistent')) {
            return 'inconsistent';
        }
        if ($reconciliation === 'manual_review' || $session === 'create_ambiguous') {
            return 'manual_review';
        }
        if (($evidence['validated'] ?? false) === true && ($evidence['status'] ?? null) === 'approved' && in_array($reconciliation, ['completed', 'processing', 'pending', 'retryable'], true)) {
            return 'approved';
        }
        if (($evidence['validated'] ?? false) === true && ($evidence['status'] ?? null) === 'rejected') {
            return 'rejected';
        }
        if ($reconciliation === 'permanent_failure' || $session === 'create_failed') {
            return 'failed';
        }
        if ($session === 'unknown' || ($evidence !== null && ! in_array($evidence['status'] ?? null, ['approved', 'rejected'], true))) {
            return 'unknown';
        }
        if ($session !== 'absent' || $evidence !== null || $facts['reconciliation'] !== null || $payment !== '') {
            return 'pending';
        }

        return 'not_started';
    }

    private function reservationState(array $facts): string
    {
        if ($facts['reservations'] === []) {
            return 'missing';
        }
        if ($this->reservationItemsMismatch($facts)) {
            return 'inconsistent';
        }
        $states = array_values(array_unique(array_map(
            static fn (array $reservation): string => (string) ($reservation['status'] ?? ''),
            $facts['reservations']
        )));
        if (array_diff($states, ['active', 'consumed', 'expired', 'released']) !== []) {
            return 'unknown';
        }
        if (count($states) > 1) {
            return 'mixed';
        }

        return $states[0];
    }

    private function processingState(array $facts, string $financial, string $observedAt): string
    {
        $statuses = [
            (string) ($facts['reconciliation']['status'] ?? ''),
            (string) ($facts['business_completion']['status'] ?? ''),
        ];
        if ($financial !== 'approved' && array_filter($statuses) === []) {
            return 'not_required';
        }
        if (in_array('manual_review', $statuses, true)) {
            return 'manual_review';
        }
        if (in_array('permanent_failure', $statuses, true)) {
            return 'failed';
        }
        if (in_array('retryable', $statuses, true) || $this->leaseExpired($facts, $observedAt)) {
            return 'retry_wait';
        }
        if (in_array('processing', $statuses, true)) {
            return 'processing';
        }
        if (($facts['reconciliation']['status'] ?? null) === 'completed' && ($facts['business_completion']['status'] ?? null) === 'completed') {
            return 'completed';
        }
        if (array_diff(array_filter($statuses), ['pending', 'completed']) !== []) {
            return 'unknown';
        }

        return 'pending';
    }

    private function deliveryState(array $facts): string
    {
        $method = $facts['checkout']['fulfillment_method'] ?? null;
        if ($method === 'pickup') {
            return $facts['deliveries'] === [] ? 'not_applicable' : 'inconsistent';
        }
        if ($method !== 'delivery') {
            return 'unknown';
        }
        if (count($facts['deliveries']) > 1 || $this->deliveryIntegrityMismatch($facts)) {
            return 'inconsistent';
        }
        if ($facts['deliveries'] === []) {
            return 'not_started';
        }
        $status = (string) ($facts['deliveries'][0]['status'] ?? '');

        return in_array($status, ['pending', 'assigned', 'picked_up', 'delivered', 'cancelled'], true)
            ? $status
            : 'unknown';
    }

    private function fulfillmentState(array $facts, string $processing, string $delivery): string
    {
        $completion = (string) ($facts['fulfillment_completion']['status'] ?? '');
        if ($completion === 'manual_review') {
            return 'manual_review';
        }
        if ($completion === 'permanent_failure') {
            return 'failed';
        }
        if ($completion === 'completed') {
            $method = $facts['checkout']['fulfillment_method'] ?? null;
            $pickupValid = $method === 'pickup' && ($facts['delivery_completion']['status'] ?? null) === 'not_required' && $delivery === 'not_applicable';
            $deliveryValid = $method === 'delivery' && ($facts['delivery_completion']['status'] ?? null) === 'completed' && $delivery === 'delivered';
            return $pickupValid || $deliveryValid ? 'completed' : 'inconsistent';
        }
        if ($delivery === 'inconsistent' || $delivery === 'unknown') {
            return $delivery;
        }
        if (in_array($delivery, ['assigned', 'picked_up'], true) || ($facts['delivery_completion']['status'] ?? null) === 'processing') {
            return 'in_progress';
        }
        if ($processing === 'completed') {
            return 'pending';
        }
        if ($processing === 'not_required') {
            return 'not_started';
        }
        if (in_array($completion, ['pending', 'retryable'], true)) {
            return 'pending';
        }

        return $completion === '' ? 'not_started' : 'unknown';
    }

    private function commercialState(array $facts, array $dimensions, array $findings): string
    {
        if ($this->hasBlockingError($findings)) {
            return 'inconsistent';
        }
        if ($dimensions['fulfillment'] === 'completed') {
            return 'fulfilled';
        }
        if ($dimensions['processing'] === 'completed' && $dimensions['financial'] === 'approved') {
            return 'confirmed';
        }
        $checkout = $facts['checkout']['status'] ?? null;
        if ($checkout === 'cancelled' && $dimensions['financial'] !== 'approved') {
            return 'cancelled';
        }
        if (($checkout === 'expired' || $dimensions['reservations'] === 'expired') && $dimensions['financial'] !== 'approved') {
            return 'expired';
        }
        if (in_array($dimensions['financial'], ['pending', 'rejected'], true)) {
            return 'payment_pending';
        }
        if ($dimensions['reservations'] === 'active') {
            return 'reserved';
        }

        return 'unknown';
    }

    private function primaryState(array $facts, array $dimensions, string $consistency, array $findings): string
    {
        if ($consistency === 'unknown') {
            return 'unknown';
        }
        if ($this->hasBlockingError($findings)) {
            return 'inconsistent';
        }
        if (in_array('manual_review', [$dimensions['financial'], $dimensions['processing'], $dimensions['fulfillment']], true)) {
            return 'manual_review';
        }
        if (in_array('failed', [$dimensions['processing'], $dimensions['fulfillment']], true)) {
            return 'failed';
        }
        if ($dimensions['fulfillment'] === 'completed') {
            return 'completed';
        }
        if (in_array($dimensions['delivery'], ['assigned', 'picked_up'], true) || $dimensions['fulfillment'] === 'in_progress') {
            return 'in_fulfillment';
        }
        if ($dimensions['commercial'] === 'confirmed' && in_array($dimensions['fulfillment'], ['pending', 'not_started'], true)) {
            return 'fulfillment_pending';
        }
        if ($dimensions['financial'] === 'approved' && $dimensions['processing'] !== 'completed') {
            return 'post_payment_processing';
        }
        if ($dimensions['processing'] === 'completed' && $dimensions['fulfillment'] === 'unknown') {
            return 'confirmed';
        }
        if ($facts['historical_profile'] !== 'none'
            && in_array($facts['order']['status'] ?? null, ['paid', 'delivered'], true)
        ) {
            return 'confirmed';
        }
        if ($dimensions['financial'] === 'rejected') {
            return 'payment_rejected';
        }
        if ($dimensions['financial'] === 'pending') {
            return 'payment_in_progress';
        }
        if ($dimensions['commercial'] === 'cancelled') {
            return 'cancelled';
        }
        if ($dimensions['commercial'] === 'expired') {
            return 'expired';
        }
        if (in_array('unknown', [$dimensions['financial'], $dimensions['reservations'], $dimensions['processing'], $dimensions['fulfillment'], $dimensions['delivery'], $dimensions['payment_session']], true)) {
            return 'unknown';
        }
        if ($dimensions['reservations'] === 'active') {
            return 'reserved';
        }

        return 'unknown';
    }

    private function findings(array $facts, array $dimensions, string $observedAt): array
    {
        $order = $facts['order'];
        $status = $order['status'] ?? null;
        $historical = $facts['historical_profile'] !== 'none';
        $rules = [
            'order_status_unknown' => ! in_array($status, ['reserved', 'paid', 'delivered'], true),
            'paid_without_financial_evidence' => in_array($status, ['paid', 'delivered'], true) && $dimensions['financial'] !== 'approved',
            'approved_without_business_processing' => $dimensions['financial'] === 'approved' && ($facts['business_completion']['status'] ?? null) !== 'completed',
            'business_completed_without_paid_order' => ($facts['business_completion']['status'] ?? null) === 'completed' && ! in_array($status, ['paid', 'delivered'], true),
            'delivered_without_delivery_evidence' => $status === 'delivered' && ($facts['checkout']['fulfillment_method'] ?? null) === 'delivery' && $dimensions['delivery'] !== 'delivered',
            'delivery_completed_order_not_delivered' => $dimensions['delivery'] === 'delivered' && $status !== 'delivered',
            'pickup_has_delivery' => ($facts['checkout']['fulfillment_method'] ?? null) === 'pickup' && $facts['deliveries'] !== [],
            'pickup_completion_invalid' => ($facts['checkout']['fulfillment_method'] ?? null) === 'pickup'
                && ($facts['delivery_completion'] !== null || $facts['fulfillment_completion'] !== null || $dimensions['processing'] === 'completed')
                && (($facts['delivery_completion']['status'] ?? null) !== 'not_required'
                    || (($facts['fulfillment_completion']['status'] ?? null) === 'completed' && $dimensions['fulfillment'] !== 'completed')),
            'delivery_integrity_mismatch' => $this->deliveryIntegrityMismatch($facts) || count($facts['deliveries']) > 1,
            'fulfillment_completed_without_branch' => ($facts['fulfillment_completion']['status'] ?? null) === 'completed' && $dimensions['fulfillment'] !== 'completed',
            'reservation_items_mismatch' => $this->reservationItemsMismatch($facts),
            'active_reservation_after_terminal_release' => $this->any($facts['reservations'], static fn (array $r): bool => ($r['status'] ?? null) === 'active' && ($r['terminal_release_evidence'] ?? false) === true),
            'reservations_active_after_payment' => $dimensions['reservations'] === 'active' && (($facts['payment']['status'] ?? null) === 'paid' || ($facts['business_completion']['status'] ?? null) === 'completed'),
            'reservations_consumed_without_approval' => $dimensions['reservations'] === 'consumed'
                && $dimensions['financial'] !== 'approved'
                && ! ($historical && in_array($status, ['paid', 'delivered'], true)),
            'reservation_terminal_mixed' => $dimensions['reservations'] === 'mixed' && count(array_intersect($this->reservationStatuses($facts), ['released', 'expired', 'consumed'])) > 1,
            'stock_double_terminal_evidence' => $this->any($facts['reservations'], static fn (array $r): bool => ($r['consumed_evidence'] ?? false) && ($r['restored_evidence'] ?? false)),
            'order_item_subtotal_mismatch' => $this->itemSubtotalMismatch($facts),
            'order_total_mismatch' => $this->orderTotalMismatch($facts),
            'checkout_total_mismatch' => $this->checkoutTotalMismatch($facts),
            'order_store_mismatch' => $this->orderStoreMismatch($facts),
            'checkout_order_relation_missing' => $facts['checkout'] !== null && ! in_array((int) ($order['id'] ?? 0), $this->ids($facts['checkout_order_links'], 'order_id'), true),
            'checkout_order_owner_mismatch' => $facts['checkout'] !== null && isset($order['customer_id'], $facts['checkout']['customer_id']) && (int) $order['customer_id'] !== (int) $facts['checkout']['customer_id'],
            'operational_order_set_mismatch' => $this->orderSetMismatch($facts),
            'payment_flow_mismatch' => $this->paymentFlowMismatch($facts),
            'payment_amount_mismatch' => $this->paymentAmountMismatch($facts),
            'financial_terminal_regression' => $this->flag($facts, 'financial_terminal_regression'),
            'processing_lease_expired' => $this->leaseExpired($facts, $observedAt),
            'processing_retry_scheduled' => in_array('retryable', [(string) ($facts['reconciliation']['status'] ?? ''), (string) ($facts['business_completion']['status'] ?? ''), (string) ($facts['delivery_completion']['status'] ?? ''), (string) ($facts['fulfillment_completion']['status'] ?? '')], true),
            'current_catalog_reference_missing' => $this->any($facts['order_items'], static fn (array $i): bool => ($i['current_product_exists'] ?? true) === false || ($i['current_inventory_exists'] ?? true) === false),
            'current_store_missing' => ($facts['order']['current_store_exists'] ?? true) === false,
            'read_failure' => $facts['read_failures'] !== [],
        ];

        $result = [];
        foreach (InvariantCatalog::codes() as $code) {
            if (! ($rules[$code] ?? false)) {
                continue;
            }
            $severity = null;
            $tolerance = false;
            if ($code === 'paid_without_financial_evidence' && $historical) {
                $severity = 'warning';
                $tolerance = true;
            }
            if ($code === 'reservation_terminal_mixed' && $historical) {
                $severity = 'warning';
                $tolerance = true;
            }
            if ($code === 'checkout_order_relation_missing' && $historical) {
                $severity = 'warning';
                $tolerance = true;
            }
            if ($code === 'current_catalog_reference_missing') {
                $tolerance = true;
            }
            if ($code === 'read_failure' && $this->any($facts['read_failures'], static fn (array $f): bool => ($f['scope'] ?? '') === 'core')) {
                $severity = 'critical';
            }
            $result[] = InvariantCatalog::create($code, $this->safeEvidence($code, $facts), $severity, $tolerance);
        }

        return $result;
    }

    private function consistency(array $facts, array $dimensions, array $findings): string
    {
        if ($this->any($facts['read_failures'], static fn (array $failure): bool => ($failure['scope'] ?? '') === 'core')) {
            return 'unknown';
        }
        if ($this->hasBlockingError($findings)) {
            return 'inconsistent';
        }
        if ($facts['read_failures'] !== []) {
            return 'degraded';
        }
        if (in_array('unknown', $dimensions, true)) {
            return 'degraded';
        }
        if ($findings !== []) {
            return 'warning';
        }

        return 'consistent';
    }

    private function timeline(array $facts): array
    {
        $events = [];
        $this->event($events, 'checkout_created', $facts['checkout']['created_at'] ?? null, 10, 'checkout', $facts['checkout']['id'] ?? null, 'Checkout creado');
        $this->event($events, 'order_created', $facts['order']['created_at'] ?? null, 20, 'order', $facts['order']['id'] ?? null, 'Order creada para Store');
        foreach ($facts['reservations'] as $reservation) {
            $this->event($events, 'stock_reserved', $reservation['reserved_at'] ?? null, 30, 'reservation', $reservation['id'] ?? null, 'Stock reservado');
            if (in_array($reservation['status'] ?? null, ['expired', 'released'], true)) {
                $type = $reservation['status'] === 'expired' ? 'reservation_expired' : 'reservation_released';
                $this->event($events, $type, $reservation['released_at'] ?? null, 30, 'reservation', $reservation['id'] ?? null, $reservation['status'] === 'expired' ? 'Reserva expirada' : 'Reserva liberada');
            }
        }
        $session = $facts['payment_session'];
        $this->event($events, 'payment_started', $session['create_started_at'] ?? $session['created_at'] ?? null, 40, 'payment_session', $session['id'] ?? null, 'Inicio de pago');
        if (($session['status'] ?? null) === 'ready') {
            $this->event($events, 'payment_session_ready', $session['updated_at'] ?? null, 40, 'payment_session', $session['id'] ?? null, 'Sesion de pago preparada');
        }
        $financial = $facts['financial_evidence'];
        $this->event($events, 'financial_evidence_obtained', $financial['obtained_at'] ?? null, 50, 'financial', $financial['id'] ?? null, 'Evidencia financiera obtenida');
        if (($financial['validated'] ?? false) === true) {
            $this->event($events, 'financial_evidence_validated', $financial['validated_at'] ?? null, 50, 'financial', $financial['id'] ?? null, 'Evidencia financiera validada');
        }
        $reconciliation = $facts['reconciliation'];
        $this->event($events, 'reconciliation_attempted', $reconciliation['last_attempt_at'] ?? null, 60, 'reconciliation', $reconciliation['id'] ?? null, 'Reconciliacion intentada');
        if (($reconciliation['status'] ?? null) === 'completed') {
            $this->event($events, 'reconciliation_completed', $reconciliation['completed_at'] ?? $reconciliation['updated_at'] ?? null, 60, 'reconciliation', $reconciliation['id'] ?? null, 'Reconciliacion completada');
        }
        $payment = $facts['payment'];
        $this->event($events, 'payment_confirmed', $payment['paid_at'] ?? null, 70, 'payment', $payment['id'] ?? null, 'Pago confirmado');
        $business = $facts['business_completion'];
        if (($business['status'] ?? null) === 'completed') {
            $this->event($events, 'business_completed', $business['completed_at'] ?? null, 80, 'business', $business['id'] ?? null, 'Procesamiento de negocio completado');
        }
        $deliveryCompletion = $facts['delivery_completion'];
        if (in_array($deliveryCompletion['status'] ?? null, ['completed', 'not_required'], true)) {
            $this->event($events, 'delivery_processing_completed', $deliveryCompletion['completed_at'] ?? null, 90, 'delivery_completion', $deliveryCompletion['id'] ?? null, ($deliveryCompletion['status'] ?? null) === 'not_required' ? 'Pickup: entrega no requerida' : 'Delivery materializada');
        }
        foreach ($facts['deliveries'] as $delivery) {
            $this->event($events, 'delivery_created', $delivery['created_at'] ?? null, 100, 'delivery', $delivery['id'] ?? null, 'Delivery creada');
        }
        foreach ($facts['delivery_tracking'] as $tracking) {
            if (in_array($tracking['event'] ?? null, ['assigned', 'picked_up', 'delivered'], true)) {
                $this->event($events, 'delivery_event', $tracking['created_at'] ?? null, 110, 'tracking', $tracking['id'] ?? null, 'Hito de entrega: ' . $tracking['event']);
            }
        }
        $fulfillment = $facts['fulfillment_completion'];
        if (($fulfillment['status'] ?? null) === 'completed') {
            $this->event($events, 'fulfillment_completed', $fulfillment['completed_at'] ?? null, 120, 'fulfillment', $fulfillment['id'] ?? null, 'Fulfillment completado');
        }
        if (in_array($facts['checkout']['status'] ?? null, ['expired', 'cancelled'], true)) {
            $type = $facts['checkout']['status'] === 'expired' ? 'checkout_expired' : 'checkout_cancelled';
            $this->event($events, $type, $facts['checkout']['updated_at'] ?? null, 10, 'checkout', $facts['checkout']['id'] ?? null, $facts['checkout']['status'] === 'expired' ? 'Checkout expirado observado' : 'Checkout cancelado observado');
        }
        usort($events, static fn (array $a, array $b): int => [$a['occurred_at'], $a['source_rank'], (string) $a['source_id'], $a['type'], $a['sequence']] <=> [$b['occurred_at'], $b['source_rank'], (string) $b['source_id'], $b['type'], $b['sequence']]);
        foreach ($events as $index => &$event) {
            $event['sequence'] = $index;
            $event['key'] = hash('sha256', implode('|', [$event['type'], $event['occurred_at'], $event['source'], (string) $event['source_id'], (string) $index]));
        }

        return $events;
    }

    private function operationalVersion(array $facts, array $findings): string
    {
        $pick = fn (?array $row, array $keys): ?array => $row === null
            ? null
            : $this->normalizeVersionRow(array_intersect_key($row, array_flip($keys)));
        $versionFacts = [
            'policy' => 'orders-operational-v1',
            'order' => $pick($facts['order'], ['id', 'status', 'updated_at', 'total', 'minimarket_id']),
            'checkout' => $pick($facts['checkout'], ['id', 'status', 'fulfillment_method', 'updated_at', 'total_amount']),
            'order_items' => array_map(static fn (array $row): array => $pick($row, ['id', 'updated_at', 'quantity', 'unit_price', 'subtotal']), $facts['order_items']),
            'reservations' => array_map(static fn (array $row): array => $pick($row, ['id', 'status', 'updated_at', 'quantity']), $facts['reservations']),
            'payment_session' => $pick($facts['payment_session'], ['id', 'status', 'updated_at', 'create_version']),
            'financial_evidence' => $pick($facts['financial_evidence'], ['id', 'status', 'validated', 'updated_at']),
            'payment' => $pick($facts['payment'], ['id', 'status', 'updated_at', 'amount']),
            'reconciliation' => $pick($facts['reconciliation'], ['id', 'status', 'lease_version', 'updated_at']),
            'business_completion' => $pick($facts['business_completion'], ['id', 'status', 'lease_version', 'updated_at']),
            'delivery_completion' => $pick($facts['delivery_completion'], ['id', 'status', 'lease_version', 'updated_at']),
            'deliveries' => array_map(static fn (array $row): array => $pick($row, ['id', 'status', 'updated_at', 'courier_id']), $facts['deliveries']),
            'fulfillment_completion' => $pick($facts['fulfillment_completion'], ['id', 'status', 'lease_version', 'updated_at']),
            'relations' => [
                'checkout_orders' => $this->ids($facts['checkout_order_links'], 'order_id'),
                'payment_orders' => $this->ids($facts['payment_order_links'], 'order_id'),
                'business_orders' => $this->ids($facts['business_order_links'], 'order_id'),
            ],
            'blockers' => array_values(array_map(static fn (ConsistencyFinding $finding): string => $finding->code, array_filter($findings, static fn (ConsistencyFinding $finding): bool => $finding->blocker))),
        ];
        $canonical = json_encode($this->canonicalize($versionFacts), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $digest = rtrim(strtr(base64_encode(hash('sha256', $canonical, true)), '+/', '-_'), '=');

        return 'orders-operational-v1:' . $digest;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            $value = array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
            usort($value, static fn (mixed $a, mixed $b): int => json_encode($a) <=> json_encode($b));
            return $value;
        }
        ksort($value, SORT_STRING);
        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }

    private function normalizeVersionRow(array $row): array
    {
        $integerFields = ['id', 'minimarket_id', 'quantity', 'create_version', 'lease_version', 'courier_id'];
        $moneyFields = ['total', 'total_amount', 'unit_price', 'subtotal', 'amount'];
        foreach ($row as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (in_array($key, $integerFields, true)) {
                $row[$key] = (int) $value;
                continue;
            }
            if (in_array($key, $moneyFields, true)) {
                $row[$key] = $this->canonicalMoney($value);
                continue;
            }
            if (str_ends_with($key, '_at') && is_string($value)) {
                try {
                    $row[$key] = (new \DateTimeImmutable($value))
                        ->setTimezone(new \DateTimeZone('UTC'))
                        ->format('Y-m-d\TH:i:s\Z');
                } catch (\Exception) {
                    $row[$key] = $value;
                }
            }
        }

        return $row;
    }

    private function event(array &$events, string $type, mixed $timestamp, int $rank, string $source, mixed $id, string $label): void
    {
        if (! is_string($timestamp) || $timestamp === '' || $id === null) {
            return;
        }
        try {
            $occurredAt = (new \DateTimeImmutable($timestamp))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        } catch (\Exception) {
            return;
        }
        $events[] = ['key' => '', 'type' => $type, 'occurred_at' => $occurredAt, 'source' => $source, 'source_id' => is_int($id) ? $id : (string) $id, 'source_rank' => $rank, 'sequence' => count($events), 'label' => $label, 'tone' => 'info', 'metadata' => []];
    }

    private function reservationItemsMismatch(array $facts): bool
    {
        $items = [];
        foreach ($facts['order_items'] as $item) {
            $key = implode(':', [(int) ($item['product_id'] ?? 0), (int) ($item['inventory_id'] ?? 0)]);
            $items[$key] = ($items[$key] ?? 0) + (int) ($item['quantity'] ?? 0);
        }
        $reservations = [];
        foreach ($facts['reservations'] as $reservation) {
            $key = implode(':', [(int) ($reservation['product_id'] ?? 0), (int) ($reservation['inventory_id'] ?? 0)]);
            $reservations[$key] = ($reservations[$key] ?? 0) + (int) ($reservation['quantity'] ?? 0);
        }
        ksort($items);
        ksort($reservations);
        return $items !== $reservations;
    }

    private function deliveryIntegrityMismatch(array $facts): bool
    {
        $order = $facts['order'];
        return $this->any($facts['deliveries'], static fn (array $delivery): bool =>
            (int) ($delivery['order_id'] ?? 0) !== (int) ($order['id'] ?? 0)
            || (isset($delivery['minimarket_id'], $order['minimarket_id']) && (int) $delivery['minimarket_id'] !== (int) $order['minimarket_id'])
            || (isset($delivery['customer_id'], $order['customer_id']) && (int) $delivery['customer_id'] !== (int) $order['customer_id'])
        );
    }

    private function itemSubtotalMismatch(array $facts): bool
    {
        return $this->any($facts['order_items'], fn (array $item): bool => $this->moneyMultiply($item['unit_price'] ?? '0', (int) ($item['quantity'] ?? 0)) !== $this->money($item['subtotal'] ?? '0'));
    }

    private function orderTotalMismatch(array $facts): bool
    {
        $sum = array_sum(array_map(fn (array $item): int => $this->money($item['subtotal'] ?? '0'), $facts['order_items']));
        return $sum !== $this->money($facts['order']['total'] ?? '0');
    }

    private function checkoutTotalMismatch(array $facts): bool
    {
        if ($facts['checkout'] === null) {
            return false;
        }
        $orderTotal = $this->money($facts['order']['total'] ?? '0');
        $checkoutTotal = $this->money($facts['checkout']['total_amount'] ?? '0');
        return $orderTotal !== $checkoutTotal || (($facts['checkout']['currency'] ?? 'CLP') !== ($facts['order']['currency'] ?? 'CLP'));
    }

    private function orderStoreMismatch(array $facts): bool
    {
        $store = (int) ($facts['order']['minimarket_id'] ?? 0);
        return $this->any($facts['reservations'], static fn (array $reservation): bool => isset($reservation['minimarket_id']) && (int) $reservation['minimarket_id'] !== $store);
    }

    private function orderSetMismatch(array $facts): bool
    {
        $checkout = $this->ids($facts['checkout_order_links'], 'order_id');
        foreach ([$this->ids($facts['payment_order_links'], 'order_id'), $this->ids($facts['business_order_links'], 'order_id')] as $set) {
            if ($set !== [] && $set !== $checkout) {
                return true;
            }
        }
        return false;
    }

    private function paymentFlowMismatch(array $facts): bool
    {
        $checkoutId = $facts['checkout']['id'] ?? null;
        foreach (['payment_session', 'reconciliation', 'payment'] as $key) {
            if ($facts[$key] !== null && isset($facts[$key]['checkout_id']) && $facts[$key]['checkout_id'] !== $checkoutId) {
                return true;
            }
        }
        return false;
    }

    private function paymentAmountMismatch(array $facts): bool
    {
        if ($facts['payment'] === null || $facts['checkout'] === null) {
            return false;
        }
        return $this->money($facts['payment']['amount'] ?? '0') !== $this->money($facts['checkout']['total_amount'] ?? '0')
            || (($facts['payment']['currency'] ?? 'CLP') !== ($facts['checkout']['currency'] ?? 'CLP'));
    }

    private function leaseExpired(array $facts, string $observedAt): bool
    {
        foreach (['reconciliation', 'business_completion', 'delivery_completion', 'fulfillment_completion'] as $key) {
            $row = $facts[$key];
            if (($row['status'] ?? null) === 'processing' && isset($row['lease_expires_at'])) {
                try {
                    if (new \DateTimeImmutable($row['lease_expires_at']) <= new \DateTimeImmutable($observedAt)) {
                        return true;
                    }
                } catch (\Exception) {
                    return false;
                }
            }
        }
        return false;
    }

    private function hasBlockingError(array $findings): bool
    {
        return $this->any(
            $findings,
            static fn (ConsistencyFinding $finding): bool =>
                $finding->code !== 'read_failure'
                && $finding->blocker
                && in_array($finding->severity, ['error', 'critical'], true)
        );
    }

    private function safeEvidence(string $code, array $facts): array
    {
        return [
            'order_id' => (int) ($facts['order']['id'] ?? 0),
            'code' => $code,
            'reservation_count' => count($facts['reservations']),
            'delivery_count' => count($facts['deliveries']),
        ];
    }

    private function ids(array $rows, string $key): array
    {
        $ids = array_values(array_unique(array_map(static fn (array $row): int => (int) ($row[$key] ?? 0), $rows)));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private function reservationStatuses(array $facts): array
    {
        return array_values(array_unique(array_map(static fn (array $reservation): string => (string) ($reservation['status'] ?? ''), $facts['reservations'])));
    }

    private function money(mixed $value): int
    {
        $normalized = trim((string) $value);
        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $normalized, $matches)) {
            return 0;
        }
        $fraction = str_pad($matches[3] ?? '', 2, '0');
        $minor = ((int) $matches[2] * 100) + (int) $fraction;

        return ($matches[1] ?? '') === '-' ? -$minor : $minor;
    }

    private function moneyMultiply(mixed $value, int $quantity): int
    {
        return $this->money($value) * $quantity;
    }

    private function canonicalMoney(mixed $value): string
    {
        $minor = $this->money($value);
        $sign = $minor < 0 ? '-' : '';
        $absolute = abs($minor);

        return $sign . intdiv($absolute, 100) . '.' . str_pad((string) ($absolute % 100), 2, '0');
    }

    private function flag(array $facts, string $key): bool
    {
        return ($facts['diagnostics'][$key] ?? false) === true;
    }

    private function any(array $values, callable $predicate): bool
    {
        foreach ($values as $value) {
            if ($predicate($value)) {
                return true;
            }
        }
        return false;
    }
}
