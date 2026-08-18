<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\ZonalAdmin\Controllers;

use VeciAhorra\Modules\Stores\Domain\StoreLifecycleContract;
use VeciAhorra\Modules\Stores\Exceptions\StoreLifecycleException;
use VeciAhorra\Modules\ZonalAdmin\Authorization\StoreTerritoryAuthorizer;
use VeciAhorra\Modules\ZonalAdmin\Repositories\StoreDecisionHistoryRepository;
use VeciAhorra\Modules\ZonalAdmin\Repositories\ZonalStoreRepository;
use VeciAhorra\Modules\ZonalAdmin\Services\StoreDecisionCoordinator;

final class ZonalStoreController
{
    public function __construct(
        private ZonalStoreRepository $stores,
        private StoreDecisionHistoryRepository $history,
        private StoreDecisionCoordinator $decisions,
        private StoreTerritoryAuthorizer $territory,
        private StoreLifecycleContract $lifecycle
    ) {}

    public function index(int $userId, array $query): array
    {
        $global = $this->territory->isGlobal($userId);
        $items = $this->stores->paginate($userId, $global, $query['page'], $query['per_page'], $query['search'], $query['state']);
        $total = $this->stores->count($userId, $global, $query['search'], $query['state']);
        $zones = $this->stores->zonesForStores(array_map(static fn(array $row): int => (int) $row['id'], $items), $global ? null : $userId, $global);
        $data = array_map(function (array $row) use ($zones): array {
            $item = $this->serialize($row, false);
            $item['service_zones'] = $zones[(int) $row['id']] ?? [];
            return $item;
        }, $items);
        return ['success'=>true, 'data'=>$data, 'meta'=>[
            'page'=>$query['page'], 'per_page'=>$query['per_page'], 'total'=>$total,
            'total_pages'=>$total === 0 ? 0 : (int) ceil($total / $query['per_page']),
        ]];
    }

    public function show(int $userId, int $storeId): array
    {
        $global = $this->territory->isGlobal($userId);
        $row = $this->stores->findVisible($userId, $global, $storeId);
        if ($row === null) {
            throw new StoreLifecycleException('store_not_found', 'El minimarket no existe.', 'id');
        }
        $data = $this->serialize($row, true);
        $data['service_zones'] = $this->stores->zonesForStore($storeId, $global ? null : $userId, $global);
        $data['decision_history'] = $this->history->forStore($storeId);
        return ['success'=>true, 'data'=>$data];
    }

    public function transition(int $userId, int $storeId, array $payload): array
    {
        $store = $this->decisions->decideAuthorized($storeId, $userId, $payload['action'], $payload['reason'], $payload['expected_updated_at']);
        return ['success'=>true, 'data'=>$this->serialize($store->toArray(), true)];
    }

    private function serialize(array $row, bool $detail): array
    {
        $state = $this->lifecycle->classify((string)$row['status'], (string)$row['onboarding_status'], $row['approved_at']);
        $data = [
            'id'=>(int)$row['id'], 'business_name'=>(string)$row['business_name'],
            'commune'=>$row['commune'] === null ? null : (string)$row['commune'],
            'city'=>$row['city'] === null ? null : (string)$row['city'],
            'status'=>(string)$row['status'], 'onboarding_status'=>(string)$row['onboarding_status'],
            'lifecycle_state'=>$state, 'updated_at'=>(string)$row['updated_at'],
        ];
        if ($detail) {
            $data += ['legal_name'=>(string)$row['legal_name'], 'owner_name'=>(string)$row['owner_name'],
                'rut'=>(string)$row['rut'], 'email'=>(string)$row['email'], 'phone'=>(string)$row['phone'],
                'mobile'=>$row['mobile'], 'address'=>$row['address'], 'region'=>$row['region'],
                'approved_at'=>$row['approved_at'], 'created_at'=>(string)$row['created_at']];
        }
        return $data;
    }
}
