<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\ZonalAdmin\Repositories;

use VeciAhorra\Core\Config;
use VeciAhorra\Exceptions\PersistenceException;

final class ZonalStoreRepository
{
    public function onboardingApplication(int $storeId): ?array
    {
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare('SELECT public_id,terms_version,terms_accepted_at,status FROM '.$this->table('store_onboarding_applications').' WHERE store_id=%d LIMIT 1',$storeId),ARRAY_A);
        if($wpdb->last_error!=='')throw new PersistenceException('No fue posible cargar la autoridad de onboarding.');
        return is_array($row)?$row:null;
    }
    public function paginate(int $userId, bool $global, int $page, int $perPage, ?string $search, ?string $state): array
    {
        [$joins, $where, $params] = $this->scope($userId, $global, $search, $state);
        $params[] = $perPage;
        $params[] = ($page - 1) * $perPage;
        global $wpdb;
        $sql = "SELECT DISTINCT s.* FROM {$this->table('stores')} s {$joins} {$where}"
            . ' ORDER BY s.business_name ASC, s.id ASC LIMIT %d OFFSET %d';
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        if (! is_array($rows) || $wpdb->last_error !== '') {
            throw new PersistenceException('No fue posible listar los minimarkets zonales.');
        }
        return $rows;
    }

    public function count(int $userId, bool $global, ?string $search, ?string $state): int
    {
        [$joins, $where, $params] = $this->scope($userId, $global, $search, $state);
        global $wpdb;
        $sql = "SELECT COUNT(DISTINCT s.id) FROM {$this->table('stores')} s {$joins} {$where}";
        $value = $params === [] ? $wpdb->get_var($sql) : $wpdb->get_var($wpdb->prepare($sql, ...$params));
        if ($value === null || $wpdb->last_error !== '') {
            throw new PersistenceException('No fue posible contar los minimarkets zonales.');
        }
        return (int) $value;
    }

    public function findVisible(int $userId, bool $global, int $storeId): ?array
    {
        global $wpdb;
        if ($global) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table('stores')} WHERE id = %d", $storeId), ARRAY_A);
        } else {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT s.* FROM {$this->table('stores')} s"
                . " INNER JOIN {$this->table('store_service_zones')} sz ON sz.store_id = s.id"
                . " INNER JOIN {$this->table('zonal_admin_service_zones')} ua ON ua.service_zone_id = sz.zone_id AND ua.user_id = %d"
                . " INNER JOIN {$this->table('service_zones')} z ON z.id = sz.zone_id AND z.status = 'active'"
                . ' WHERE s.id = %d GROUP BY s.id LIMIT 1',
                $userId,
                $storeId
            ), ARRAY_A);
        }
        return is_array($row) ? $row : null;
    }

    public function zonesForStore(int $storeId, ?int $userId, bool $global): array
    {
        return $this->zonesForStores([$storeId], $userId, $global)[$storeId] ?? [];
    }

    /**
     * @param list<int> $storeIds
     * @return array<int, list<array{id:int,name:string}>>
     */
    public function zonesForStores(array $storeIds, ?int $userId, bool $global): array
    {
        $storeIds = array_values(array_unique(array_filter(
            array_map('intval', $storeIds),
            static fn (int $id): bool => $id > 0
        )));
        $result = array_fill_keys($storeIds, []);
        if ($storeIds === []) {
            return $result;
        }
        global $wpdb;
        $join = $global ? '' : " INNER JOIN {$this->table('zonal_admin_service_zones')} ua ON ua.service_zone_id = z.id AND ua.user_id = %d";
        $params = $global ? [] : [(int) $userId];
        $placeholders = implode(',', array_fill(0, count($storeIds), '%d'));
        array_push($params, ...$storeIds);
        $sql = "SELECT DISTINCT sz.store_id,z.id,z.name FROM {$this->table('service_zones')} z"
            . " INNER JOIN {$this->table('store_service_zones')} sz ON sz.zone_id = z.id{$join}"
            . " WHERE z.status = 'active' AND sz.store_id IN ({$placeholders})"
            . ' ORDER BY z.name ASC,z.id ASC';
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        if (! is_array($rows) || $wpdb->last_error !== '') {
            throw new PersistenceException('No fue posible proyectar las zonas autorizadas.');
        }
        foreach ($rows as $row) {
            $storeId = (int) ($row['store_id'] ?? 0);
            $zoneId = (int) ($row['id'] ?? 0);
            if (isset($result[$storeId]) && $zoneId > 0) {
                $result[$storeId][] = ['id' => $zoneId, 'name' => (string) ($row['name'] ?? '')];
            }
        }
        return $result;
    }

    private function scope(int $userId, bool $global, ?string $search, ?string $state): array
    {
        $joins = '';
        $conditions = [];
        $params = [];
        if (! $global) {
            $joins = "INNER JOIN {$this->table('store_service_zones')} sz ON sz.store_id = s.id"
                . " INNER JOIN {$this->table('zonal_admin_service_zones')} ua ON ua.service_zone_id = sz.zone_id AND ua.user_id = %d"
                . " INNER JOIN {$this->table('service_zones')} z ON z.id = sz.zone_id AND z.status = 'active'";
            $params[] = $userId;
        }
        if ($search !== null) {
            global $wpdb;
            $like = '%' . $wpdb->esc_like($search) . '%';
            $conditions[] = '(s.business_name LIKE %s OR s.legal_name LIKE %s OR s.rut LIKE %s OR s.commune LIKE %s)';
            array_push($params, $like, $like, $like, $like);
        }
        if ($state !== null) {
            $conditions[] = match ($state) {
                'in_review' => "s.status='pending' AND s.onboarding_status='complete' AND s.approved_at IS NULL",
                'observed' => "s.status='observed' AND s.onboarding_status='complete' AND s.approved_at IS NULL",
                'rejected' => "s.status='rejected' AND s.onboarding_status='complete' AND s.approved_at IS NULL",
                'approved_inactive' => "s.status='inactive' AND s.onboarding_status='complete' AND s.approved_at IS NOT NULL",
                default => '1=0',
            };
        }
        return [$joins, $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions), $params];
    }

    private function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . Config::TABLE_PREFIX . $name;
    }
}
