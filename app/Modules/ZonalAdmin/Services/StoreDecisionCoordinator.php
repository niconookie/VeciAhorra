<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\ZonalAdmin\Services;

use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Stores\Domain\StoreLifecycleContract;
use VeciAhorra\Modules\Stores\Exceptions\StoreLifecycleException;
use VeciAhorra\Modules\Stores\Models\Store;
use VeciAhorra\Modules\Stores\Repositories\StoreRepository;
use VeciAhorra\Modules\ZonalAdmin\Identity\ZonalAdminRole;
use VeciAhorra\Modules\ZonalAdmin\Repositories\StoreDecisionHistoryRepository;
use VeciAhorra\Modules\ZonalAdmin\Repositories\ZonalAdminServiceZoneRepository;

final class StoreDecisionCoordinator
{
    public function __construct(
        private StoreRepository $stores = new StoreRepository(),
        private StoreDecisionHistoryRepository $history = new StoreDecisionHistoryRepository(),
        private ZonalAdminServiceZoneRepository $assignments = new ZonalAdminServiceZoneRepository(),
        private StoreLifecycleContract $lifecycle = new StoreLifecycleContract()
    ) {}

    public function decide(int $storeId, int $actorUserId, string $action, ?string $reason, ?int $zoneId): Store
    {
        if (! in_array($action, [StoreLifecycleContract::ACTION_APPROVE, StoreLifecycleContract::ACTION_OBSERVE, StoreLifecycleContract::ACTION_REJECT], true)) {
            throw new \InvalidArgumentException('La accion no es una decision Store.');
        }
        $reason = $reason === null ? null : sanitize_textarea_field($reason);
        if (in_array($action, [StoreLifecycleContract::ACTION_OBSERVE, StoreLifecycleContract::ACTION_REJECT], true) && ($reason === null || trim($reason) === '')) {
            throw new \InvalidArgumentException('El motivo es obligatorio.');
        }
        if ($action === StoreLifecycleContract::ACTION_APPROVE && $reason !== null && trim($reason) === '') {
            $reason = null;
        }
        $actor = get_userdata($actorUserId);
        if (! $actor instanceof \WP_User) {
            throw new \InvalidArgumentException('El actor no existe.');
        }
        $global = user_can($actor, 'manage_options');
        if (! $global) {
            if (! user_can($actor, ZonalAdminRole::CAPABILITY_DECIDE) || $zoneId === null || ! $this->assignments->authorizesStore($actorUserId, $zoneId, $storeId)) {
                throw new \DomainException('El actor no posee autoridad territorial sobre el minimarket.');
            }
        } elseif ($zoneId !== null) {
            throw new \InvalidArgumentException('La decision global debe declarar autoridad zonal null.');
        }
        $actorRole = $global ? 'administrator' : ZonalAdminRole::ROLE;
        global $wpdb;
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new PersistenceException('No fue posible iniciar la decision Store.');
        }
        try {
            $store = $this->stores->find($storeId);
            if (! $store instanceof Store) {
                throw new StoreLifecycleException('store_not_found', 'El minimarket no existe.', 'id');
            }
            $expected = ['status'=>(string)$store->status, 'onboarding_status'=>(string)$store->onboarding_status, 'approved_at'=>$store->approved_at];
            $from = $this->lifecycle->validate(...array_values($expected));
            $target = $this->lifecycle->transitionAuthorities(
                $action,
                $expected['status'],
                $expected['onboarding_status'],
                $expected['approved_at'],
                $action === StoreLifecycleContract::ACTION_APPROVE ? current_time('mysql') : null
            );
            $to = $this->lifecycle->validate(...array_values($target));
            if ($this->stores->compareAndSetLifecycle($storeId, $expected, $target, current_time('mysql')) !== 1) {
                throw new StoreLifecycleException('concurrent_modification', 'El minimarket fue modificado concurrentemente.', null, $from, $action);
            }
            $this->history->append([
                'store_id'=>$storeId, 'actor_user_id'=>$actorUserId, 'actor_role'=>$actorRole,
                'action'=>$action, 'from_state'=>$from, 'to_state'=>$to, 'reason'=>$reason,
                'authority_service_zone_id'=>$global ? null : $zoneId, 'created_at'=>current_time('mysql'),
            ]);
            if ($wpdb->query('COMMIT') === false) {
                throw new PersistenceException('No fue posible confirmar la decision Store.');
            }
            $updated = $this->stores->find($storeId);
            if (! $updated instanceof Store) { throw new PersistenceException('No fue posible comprobar la decision Store.'); }
            return $updated;
        } catch (\Throwable $exception) {
            $wpdb->query('ROLLBACK');
            throw $exception;
        }
    }
}
