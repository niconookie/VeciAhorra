<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\ZonalAdmin\Authorization;

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\ZonalAdmin\Identity\ZonalAdminRole;

final class StoreTerritoryAuthorizer
{
    public function canList(int $userId): bool
    {
        return $this->isGlobal($userId) || $this->can($userId, ZonalAdminRole::CAPABILITY_READ);
    }

    public function canReadStore(int $userId, int $storeId): bool
    {
        return $this->isGlobal($userId) || ($this->canList($userId) && $this->commonServiceZoneId($userId, $storeId) !== null);
    }

    public function canDecideStore(int $userId, int $storeId): bool
    {
        return $this->isGlobal($userId) || ($this->can($userId, ZonalAdminRole::CAPABILITY_DECIDE) && $this->commonServiceZoneId($userId, $storeId) !== null);
    }

    public function commonServiceZoneId(int $userId, int $storeId): ?int
    {
        if ($this->isGlobal($userId)) {
            return null;
        }
        global $wpdb;
        $prefix = $wpdb->prefix . Config::TABLE_PREFIX;
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT MIN(ua.service_zone_id) FROM {$prefix}zonal_admin_service_zones ua"
            . " INNER JOIN {$prefix}store_service_zones sz ON sz.zone_id = ua.service_zone_id"
            . " INNER JOIN {$prefix}service_zones z ON z.id = ua.service_zone_id AND z.status = 'active'"
            . ' WHERE ua.user_id = %d AND sz.store_id = %d',
            $userId,
            $storeId
        ));
        return $value === null ? null : (int) $value;
    }

    public function isGlobal(int $userId): bool
    {
        $user = get_userdata($userId);
        return $user instanceof \WP_User && user_can($user, 'manage_options');
    }

    private function can(int $userId, string $capability): bool
    {
        $user = get_userdata($userId);
        return $user instanceof \WP_User && user_can($user, $capability);
    }
}
