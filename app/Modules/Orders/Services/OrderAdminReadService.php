<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Services;

use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Orders\Contracts\OrderAdminReadRepositoryInterface;
use VeciAhorra\Modules\Orders\Domain\Operational\OrderOperationalResolution;
use VeciAhorra\Modules\Orders\Domain\Operational\OrderOperationalStateResolver;
use VeciAhorra\Modules\Orders\DTO\Admin\OrderAdminDetail;
use VeciAhorra\Modules\Orders\DTO\Admin\OrderAdminListItem;
use VeciAhorra\Modules\Orders\DTO\Admin\OrderAdminListQuery;
use VeciAhorra\Modules\Orders\DTO\Admin\OrderAdminListResult;
use VeciAhorra\Modules\Orders\Exceptions\OrderAdminReadException;

final class OrderAdminReadService
{
    public function __construct(
        private OrderAdminReadRepositoryInterface $repository,
        private OrderOperationalFactsAssembler $assembler,
        private OrderOperationalStateResolver $resolver,
        private string $observedAt
    ) {
    }

    public function listOrders(OrderAdminListQuery $query): OrderAdminListResult
    {
        try {
            $total = $this->repository->count($query);
        } catch (PersistenceException) {
            throw new OrderAdminReadException('count_failed');
        }
        try {
            $rows = $this->repository->paginate($query);
            $bundles = $rows === [] ? [] : $this->repository->loadFacts(array_map(
                static fn (array $row): int => (int) $row['order_id'],
                $rows
            ));
        } catch (PersistenceException) {
            throw new OrderAdminReadException('list_failed');
        }

        $items = [];
        foreach ($rows as $row) {
            $orderId = (int) $row['order_id'];
            $resolution = $this->resolver->resolve(
                $this->assembler->assemble($row, $bundles[$orderId] ?? [], $this->observedAt)
            );
            $items[] = new OrderAdminListItem($this->listItem($row, $resolution));
        }

        return new OrderAdminListResult($items, $total, $query->page, $query->perPage);
    }

    public function getOrderDetail(int $orderId): OrderAdminDetail
    {
        if ($orderId < 1) {
            throw new OrderAdminReadException('not_found');
        }
        try {
            $base = $this->repository->findBase($orderId);
            if ($base === null) {
                throw new OrderAdminReadException('not_found');
            }
            $bundle = $this->repository->loadFacts([$orderId])[$orderId] ?? [];
        } catch (OrderAdminReadException $exception) {
            throw $exception;
        } catch (PersistenceException) {
            throw new OrderAdminReadException('read_failed');
        }

        $resolution = $this->resolver->resolve(
            $this->assembler->assemble($base, $bundle, $this->observedAt)
        );
        $resolved = $resolution->toArray();
        $data = $this->assembler->safeDetail($base, $bundle);
        $data['operational'] = [
            'primary_state' => $resolved['primary_state'],
            'dimensions' => $resolved['dimensions'],
            'consistency' => $resolved['consistency'],
            'timeline' => $resolved['timeline'],
            'operational_version' => $resolved['concurrency']['operational_version'],
            'allowed_actions' => $resolved['allowed_actions'],
            'mutable_actions' => $resolved['mutable_actions'],
            'requires_attention' => count(array_filter(
                $resolved['consistency']['findings'],
                static fn (array $finding): bool => $finding['blocker'] || in_array($finding['severity'], ['error', 'critical'], true)
            )) > 0,
        ];
        $data['inspector'] = $this->inspector($resolution);

        return new OrderAdminDetail($data);
    }

    private function listItem(array $row, OrderOperationalResolution $resolution): array
    {
        $resolved = $resolution->toArray();
        $findings = $resolved['consistency']['findings'];
        return [
            'id' => (int) $row['order_id'],
            'checkout_id' => isset($row['checkout_id']) ? (int) $row['checkout_id'] : null,
            'checkout_public_id' => $row['checkout_public_id'] ?? null,
            'store' => [
                'id' => (int) $row['minimarket_id'],
                'business_name' => $row['store_name'] ?? null,
            ],
            'total' => (string) $row['total'],
            'currency' => (string) ($row['currency'] ?? 'CLP'),
            'line_count' => (int) ($row['line_count'] ?? 0),
            'unit_count' => (int) ($row['unit_count'] ?? 0),
            'fulfillment_mode' => $row['fulfillment_method'] ?? null,
            'persisted_order_status' => (string) $row['order_status'],
            'primary_state' => $resolved['primary_state'],
            'dimensions' => $resolved['dimensions'],
            'consistency_state' => $resolved['consistency']['classification'],
            'warning_count' => count($resolved['consistency']['warnings']),
            'blocker_count' => count($resolved['consistency']['blockers']),
            'requires_attention' => count(array_filter(
                $findings,
                static fn (array $finding): bool => $finding['blocker'] || in_array($finding['severity'], ['error', 'critical'], true)
            )) > 0,
            'created_at' => $row['order_created_at'] ?? null,
            'updated_at' => $row['order_updated_at'] ?? null,
            'operational_version' => $resolved['concurrency']['operational_version'],
            'allowed_actions' => ['view'],
            'mutable_actions' => [],
        ];
    }

    private function inspector(OrderOperationalResolution $resolution): array
    {
        $resolved = $resolution->toArray();
        $groups = [];
        foreach ($resolved['consistency']['findings'] as $finding) {
            $groups[$finding['affected_dimension']][] = $finding;
        }
        ksort($groups);
        return [
            'classification' => $resolved['consistency']['classification'],
            'finding_count' => count($resolved['consistency']['findings']),
            'blocker_count' => count($resolved['consistency']['blockers']),
            'warning_count' => count($resolved['consistency']['warnings']),
            'by_dimension' => $groups,
        ];
    }
}
