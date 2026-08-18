<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\ZonalAdmin\Repositories;

use VeciAhorra\Core\Config;
use VeciAhorra\Exceptions\PersistenceException;

final class ZonalAdminServiceZoneRepository
{
    public function assign(int $userId, int $zoneId, int $createdBy, string $createdAt): int
    {
        $this->assertUser($userId);
        $this->assertUser($createdBy);
        $this->assertZone($zoneId);
        global $wpdb;
        $result = $wpdb->insert($this->table('zonal_admin_service_zones'), [
            'user_id' => $userId,
            'service_zone_id' => $zoneId,
            'created_at' => $createdAt,
            'created_by' => $createdBy,
        ]);
        if ($result !== 1) {
            throw new PersistenceException('No fue posible crear la asignacion territorial.');
        }
        return (int) $wpdb->insert_id;
    }

    public function unassign(int $userId, int $zoneId): int
    {
        global $wpdb;
        $result = $wpdb->delete($this->table('zonal_admin_service_zones'), [
            'user_id' => $userId,
            'service_zone_id' => $zoneId,
        ]);
        if ($result === false || $result > 1) {
            throw new PersistenceException('No fue posible retirar la asignacion territorial.');
        }
        return (int) $result;
    }

    public function zoneIdsForUser(int $userId): array
    {
        global $wpdb;
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            'SELECT service_zone_id FROM ' . $this->table('zonal_admin_service_zones')
            . ' WHERE user_id = %d ORDER BY service_zone_id ASC, id ASC',
            $userId
        )));
    }

    public function authorizesStore(int $userId, int $zoneId, int $storeId): bool
    {
        global $wpdb;
        $sql = 'SELECT COUNT(*) FROM ' . $this->table('zonal_admin_service_zones') . ' ua'
            . ' JOIN ' . $this->table('store_service_zones') . ' sz'
            . ' ON sz.zone_id = ua.service_zone_id AND sz.store_id = %d'
            . ' WHERE ua.user_id = %d AND ua.service_zone_id = %d';
        return (int) $wpdb->get_var($wpdb->prepare($sql, $storeId, $userId, $zoneId)) === 1;
    }

    private function assertUser(int $userId): void
    {
        if ($userId <= 0 || get_userdata($userId) === false) {
            throw new \InvalidArgumentException('El usuario no existe.');
        }
    }

    private function assertZone(int $zoneId): void
    {
        global $wpdb;
        if ($zoneId <= 0 || (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $this->table('service_zones') . ' WHERE id = %d',
            $zoneId
        )) !== 1) {
            throw new \InvalidArgumentException('La zona de servicio no existe.');
        }
    }

    private function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . Config::TABLE_PREFIX . $name;
    }
}
