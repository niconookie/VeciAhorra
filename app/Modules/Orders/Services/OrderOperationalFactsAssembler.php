<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Services;

use VeciAhorra\Modules\Orders\Domain\Operational\OrderOperationalFacts;

final class OrderOperationalFactsAssembler
{
    public function assemble(array $base, array $bundle, string $observedAt): OrderOperationalFacts
    {
        $items = array_map(fn (array $row): array => $this->item($row), $bundle['order_items'] ?? []);
        $reservations = array_map(fn (array $row): array => $this->reservation($row), $bundle['reservations'] ?? []);
        $sessions = $this->rows($bundle, 'payment_sessions');
        $payments = $this->rows($bundle, 'payments');
        $reconciliations = $this->rows($bundle, 'reconciliations');
        $business = $this->rows($bundle, 'business_completions');
        $deliveryCompletions = $this->rows($bundle, 'delivery_completions');
        $fulfillmentCompletions = $this->rows($bundle, 'fulfillment_completions');
        $financialEvidence = $this->rows($bundle, 'financial_evidence');
        $paymentLinks = $this->rows($bundle, 'payment_order_links');
        $linkedPaymentIds = array_map(static fn (array $row): int => (int) ($row['payment_id'] ?? 0), $paymentLinks);
        $payment = $this->latest(array_values(array_filter(
            $payments,
            static fn (array $row): bool => in_array((int) ($row['id'] ?? 0), $linkedPaymentIds, true)
        ))) ?? $this->latest($payments);
        $session = $payment === null
            ? $this->latest($sessions)
            : $this->related($sessions, 'id', $payment['payment_session_id'] ?? null);
        $evidence = $session === null
            ? null
            : $this->related($financialEvidence, 'payment_session_id', $session['id'] ?? null);
        $reconciliation = $payment === null
            ? $this->latest($reconciliations)
            : $this->related($reconciliations, 'id', $payment['reconciliation_id'] ?? null);
        $businessCompletion = $payment === null
            ? $this->latest($business)
            : $this->related($business, 'payment_id', $payment['id'] ?? null);
        if ($businessCompletion === null && $reconciliation !== null) {
            $businessCompletion = $this->related($business, 'reconciliation_id', $reconciliation['id'] ?? null);
        }
        $deliveryCompletion = $businessCompletion === null ? null : $this->related(
            $deliveryCompletions,
            'business_completion_id',
            $businessCompletion['id']
        );
        $fulfillmentCompletion = $businessCompletion === null ? null : $this->related(
            $fulfillmentCompletions,
            'business_completion_id',
            $businessCompletion['id']
        );

        return new OrderOperationalFacts([
            'order' => [
                'id' => $this->integer($base['order_id'] ?? null),
                'customer_id' => $this->integer($base['customer_id'] ?? null),
                'minimarket_id' => $this->integer($base['minimarket_id'] ?? null),
                'status' => $this->text($base['order_status'] ?? null),
                'total' => $this->money($base['total'] ?? null),
                'currency' => $this->text($base['currency'] ?? 'CLP'),
                'reservation_expires_at' => $this->timestamp($base['reservation_expires_at'] ?? null),
                'created_at' => $this->timestamp($base['order_created_at'] ?? null),
                'updated_at' => $this->timestamp($base['order_updated_at'] ?? null),
                'current_store_exists' => ($base['resolved_store_id'] ?? null) !== null,
            ],
            'order_items' => $items,
            'checkout' => ($base['checkout_id'] ?? null) === null ? null : [
                'id' => $this->integer($base['checkout_id']),
                'customer_id' => ($base['owner_type'] ?? null) === 'user'
                    ? $this->nullableInteger($base['user_id'] ?? null)
                    : null,
                'public_id' => $this->nullableText($base['checkout_public_id'] ?? null),
                'status' => $this->text($base['checkout_status'] ?? null),
                'fulfillment_method' => $this->nullableText($base['fulfillment_method'] ?? null),
                'currency' => $this->text($base['currency'] ?? 'CLP'),
                'product_subtotal' => $this->money($base['product_subtotal'] ?? $base['total_amount'] ?? null),
                'platform_fee' => $this->money($base['platform_fee'] ?? '0.00'),
                'delivery_fee' => $this->money($base['delivery_fee'] ?? '0.00'),
                'fee_policy_version' => $this->nullableText($base['fee_policy_version'] ?? null),
                'total_amount' => $this->money($base['total_amount'] ?? null),
                'created_at' => $this->timestamp($base['checkout_created_at'] ?? null),
                'updated_at' => $this->timestamp($base['checkout_updated_at'] ?? null),
                'expires_at' => $this->timestamp($base['checkout_expires_at'] ?? null),
            ],
            'checkout_order_links' => ($base['checkout_order_link_id'] ?? null) === null ? [] : [[
                'id' => $this->integer($base['checkout_order_link_id']),
                'checkout_id' => $this->integer($base['checkout_id']),
                'order_id' => $this->integer($base['order_id']),
            ]],
            'reservations' => $reservations,
            'payment_attempts' => $sessions,
            'payment_session' => $session,
            'financial_evidence' => $this->financial($evidence),
            'reconciliation' => $reconciliation,
            'payment' => $payment,
            'payment_order_links' => $paymentLinks,
            'business_completion' => $businessCompletion,
            'business_order_links' => $this->rows($bundle, 'business_order_links'),
            'delivery_completion' => $deliveryCompletion,
            'deliveries' => $this->rows($bundle, 'deliveries'),
            'delivery_tracking' => array_values(array_filter(
                $this->rows($bundle, 'delivery_tracking'),
                static fn (array $row): bool => in_array($row['event'] ?? null, ['assigned', 'picked_up', 'delivered'], true)
            )),
            'fulfillment_completion' => $fulfillmentCompletion,
            'read_failures' => $this->rows($bundle, 'read_failures'),
            'historical_profile' => $this->text($base['historical_profile'] ?? 'none'),
        ], $observedAt);
    }

    /** @return array<string, mixed> */
    public function safeDetail(array $base, array $bundle): array
    {
        $lines = $this->rows($bundle, 'order_items');
        usort(
            $lines,
            static fn (array $left, array $right): int =>
                (int) ($left['id'] ?? 0) <=> (int) ($right['id'] ?? 0)
        );

        return [
            'identity' => [
                'id' => $this->integer($base['order_id']),
                'persisted_status' => $this->text($base['order_status']),
                'created_at' => $this->timestamp($base['order_created_at'] ?? null),
                'updated_at' => $this->timestamp($base['order_updated_at'] ?? null),
            ],
            'customer' => [
                'relationship_status' => $this->customerRelationshipStatus($base['customer_id'] ?? null),
            ],
            'store' => [
                'id' => $this->integer($base['minimarket_id']),
                'exists' => ($base['resolved_store_id'] ?? null) !== null,
                'business_name' => $this->nullableText($base['store_name'] ?? null),
                'current_status' => $this->nullableText($base['store_status'] ?? null),
            ],
            'checkout' => ($base['checkout_id'] ?? null) === null ? null : [
                'id' => $this->integer($base['checkout_id']),
                'public_id' => $this->nullableText($base['checkout_public_id'] ?? null),
                'status' => $this->text($base['checkout_status'] ?? null),
                'fulfillment_method' => $this->nullableText($base['fulfillment_method'] ?? null),
                'product_subtotal' => $this->money($base['product_subtotal'] ?? $base['total_amount'] ?? null),
                'platform_fee' => $this->money($base['platform_fee'] ?? '0.00'),
                'delivery_fee' => $this->money($base['delivery_fee'] ?? '0.00'),
                'fee_policy_version' => $this->nullableText($base['fee_policy_version'] ?? null),
                'total' => $this->money($base['total_amount'] ?? null),
                'currency' => $this->text($base['currency'] ?? 'CLP'),
                'created_at' => $this->timestamp($base['checkout_created_at'] ?? null),
                'updated_at' => $this->timestamp($base['checkout_updated_at'] ?? null),
            ],
            'checkout_order' => ($base['checkout_order_link_id'] ?? null) === null ? null : [
                'id' => $this->integer($base['checkout_order_link_id']),
                'checkout_id' => $this->integer($base['checkout_id']),
                'order_id' => $this->integer($base['order_id']),
            ],
            'lines' => array_map(static fn (array $row): array => [
                'id' => (int) ($row['id'] ?? 0),
                'product_id' => (int) ($row['product_id'] ?? 0),
                'inventory_id' => (int) ($row['inventory_id'] ?? 0),
                'product_name_snapshot' => null,
                'snapshot_name_status' => 'not_persisted',
                'quantity' => (int) ($row['quantity'] ?? 0),
                'unit_price' => (string) ($row['unit_price'] ?? '0.00'),
                'subtotal' => (string) ($row['subtotal'] ?? '0.00'),
            ], $lines),
            'reservations' => $this->projectRows($this->rows($bundle, 'reservations'), [
                'id', 'order_id', 'product_id', 'inventory_id', 'minimarket_id',
                'quantity', 'status', 'reserved_at', 'expires_at', 'released_at', 'updated_at',
            ]),
            'payment' => [
                'session' => $this->project($this->latest($this->rows($bundle, 'payment_sessions')), [
                    'id', 'checkout_id', 'payment_id', 'status', 'create_version',
                    'create_lease_expires_at', 'create_started_at', 'amount', 'currency',
                    'confirmed_at', 'created_at', 'updated_at',
                ]),
                'financial_evidence' => $this->financial($this->latest($this->rows($bundle, 'financial_evidence'))),
                'payment' => $this->project($this->latest($this->rows($bundle, 'payments')), [
                    'id', 'checkout_id', 'payment_session_id', 'reconciliation_id',
                    'amount', 'currency', 'status', 'paid_at', 'created_at', 'updated_at',
                ]),
                'reconciliation' => $this->project($this->latest($this->rows($bundle, 'reconciliations')), [
                    'id', 'checkout_id', 'status', 'attempt_count', 'lease_expires_at',
                    'lease_version', 'last_attempt_at', 'completed_at', 'created_at', 'updated_at',
                ]),
            ],
            'processing' => [
                'business_completion' => $this->completionProjection($this->latest($this->rows($bundle, 'business_completions'))),
                'delivery_completion' => $this->completionProjection($this->latest($this->rows($bundle, 'delivery_completions'))),
                'fulfillment_completion' => $this->completionProjection($this->latest($this->rows($bundle, 'fulfillment_completions'))),
            ],
            'fulfillment' => [
                'mode' => $this->nullableText($base['fulfillment_method'] ?? null),
                'deliveries' => $this->projectRows($this->rows($bundle, 'deliveries'), [
                    'id', 'order_id', 'minimarket_id', 'courier_id',
                    'status', 'created_at', 'updated_at',
                ]),
                'tracking' => $this->projectRows(array_values(array_filter(
                    $this->rows($bundle, 'delivery_tracking'),
                    static fn (array $row): bool => in_array($row['event'] ?? null, ['assigned', 'picked_up', 'delivered'], true)
                )), ['id', 'delivery_id', 'event', 'created_at']),
            ],
            'totals' => [
                'line_count' => (int) ($base['line_count'] ?? count($bundle['order_items'] ?? [])),
                'unit_count' => (int) ($base['unit_count'] ?? 0),
                'total' => $this->money($base['total'] ?? null),
                'currency' => $this->text($base['currency'] ?? 'CLP'),
            ],
            'navigation' => [
                'order_id' => $this->integer($base['order_id']),
                'store_id' => $this->integer($base['minimarket_id']),
                'checkout_id' => $this->nullableInteger($base['checkout_id'] ?? null),
                'delivery_ids' => array_values(array_map(
                    static fn (array $row): int => (int) ($row['id'] ?? 0),
                    $this->rows($bundle, 'deliveries')
                )),
                'product_ids' => $this->uniqueIds($this->rows($bundle, 'order_items'), 'product_id'),
                'inventory_ids' => $this->uniqueIds($this->rows($bundle, 'order_items'), 'inventory_id'),
            ],
        ];
    }

    private function customerRelationshipStatus(mixed $customerId): string
    {
        if (is_int($customerId)) {
            return $customerId > 0 ? 'linked' : 'unknown';
        }
        if (
            ! is_string($customerId)
            || preg_match('/^[1-9][0-9]*$/D', $customerId) !== 1
            || (string) (int) $customerId !== $customerId
        ) {
            return 'unknown';
        }

        return 'linked';
    }

    private function item(array $row): array
    {
        return [
            'id' => $this->integer($row['id'] ?? null),
            'order_id' => $this->integer($row['order_id'] ?? null),
            'product_id' => $this->integer($row['product_id'] ?? null),
            'inventory_id' => $this->integer($row['inventory_id'] ?? null),
            'quantity' => $this->integer($row['quantity'] ?? null),
            'unit_price' => $this->money($row['unit_price'] ?? null),
            'subtotal' => $this->money($row['subtotal'] ?? null),
            'created_at' => $this->timestamp($row['created_at'] ?? null),
            'updated_at' => $this->timestamp($row['updated_at'] ?? null),
        ];
    }

    private function reservation(array $row): array
    {
        foreach (['id', 'order_id', 'product_id', 'inventory_id', 'minimarket_id', 'quantity'] as $key) {
            $row[$key] = $this->integer($row[$key] ?? null);
        }
        foreach (['reserved_at', 'expires_at', 'released_at', 'updated_at'] as $key) {
            $row[$key] = $this->timestamp($row[$key] ?? null);
        }
        $row['status'] = $this->text($row['status'] ?? null);
        return $row;
    }

    private function financial(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $row['validated'] = filter_var($row['validated'] ?? false, FILTER_VALIDATE_BOOL);
        return $this->project($row, [
            'id', 'payment_session_id', 'status', 'validated', 'amount', 'currency',
            'obtained_at', 'validated_at', 'updated_at',
        ]);
    }

    private function completionProjection(?array $row): ?array
    {
        return $this->project($row, [
            'id', 'reconciliation_id', 'payment_id', 'business_completion_id',
            'status', 'fulfillment_method', 'attempt_count', 'lease_expires_at',
            'lease_version', 'completed_at', 'created_at', 'updated_at',
        ]);
    }

    private function project(?array $row, array $keys): ?array
    {
        return $row === null ? null : array_intersect_key($row, array_flip($keys));
    }

    private function projectRows(array $rows, array $keys): array
    {
        return array_map(fn (array $row): array => $this->project($row, $keys) ?? [], $rows);
    }

    /** @return list<array<string, mixed>> */
    private function rows(array $bundle, string $key): array
    {
        return array_values(array_filter($bundle[$key] ?? [], 'is_array'));
    }

    private function latest(array $rows): ?array
    {
        if ($rows === []) {
            return null;
        }
        usort($rows, static fn (array $a, array $b): int =>
            [(string) ($a['created_at'] ?? $a['updated_at'] ?? ''), (int) ($a['id'] ?? 0)]
            <=> [(string) ($b['created_at'] ?? $b['updated_at'] ?? ''), (int) ($b['id'] ?? 0)]
        );
        return $rows[array_key_last($rows)];
    }

    private function related(array $rows, string $field, mixed $value): ?array
    {
        if ($value !== null) {
            $matches = array_values(array_filter(
                $rows,
                static fn (array $row): bool => (string) ($row[$field] ?? '') === (string) $value
            ));
            if ($matches !== []) {
                return $this->latest($matches);
            }
            return null;
        }
        return $this->latest($rows);
    }

    private function uniqueIds(array $rows, string $field): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row[$field] ?? 0),
            $rows
        ), static fn (int $id): bool => $id > 0)));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private function integer(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function nullableInteger(mixed $value): ?int
    {
        $integer = $this->integer($value);
        return $integer > 0 ? $integer : null;
    }

    private function money(mixed $value): string
    {
        $text = trim((string) ($value ?? '0'));
        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/D', $text, $match)) {
            return $text;
        }
        $whole = ltrim($match[2], '0');
        return ($match[1] ?? '') . ($whole === '' ? '0' : $whole) . '.' . str_pad($match[3] ?? '', 2, '0');
    }

    private function timestamp(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            $utc = new \DateTimeZone('UTC');
            $date = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) === 1
                ? \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $utc)
                : new \DateTimeImmutable($value);
            return $date === false ? $value : $date->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
        } catch (\Exception) {
            return $value;
        }
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private function nullableText(mixed $value): ?string
    {
        $text = $this->text($value);
        return $text === '' ? null : $text;
    }
}
