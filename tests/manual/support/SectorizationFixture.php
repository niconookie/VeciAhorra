<?php
declare(strict_types=1);

use VeciAhorra\Core\Session;
use VeciAhorra\Modules\Sectorization\CurrentSector;
use VeciAhorra\Modules\Sectorization\ServiceZoneRepository;

function sectorizationFixtureClearCurrent(): void
{
    wp_set_current_user(0);
    Session::forget('veciahorra_service_zone_id');
}

/** @param list<int> $storeIds */
function sectorizationFixtureSelect(array $storeIds, string $identity): int
{
    $name = 'Fixture ' . sanitize_key($identity);
    global $wpdb;
    $normalizedStoreIds = array_values(array_unique(array_map('intval', $storeIds)));

    if ((int) $wpdb->get_var('SELECT @@in_transaction') === 1) {
        $now = current_time('mysql', true);
        $inserted = $wpdb->insert($wpdb->prefix . 'va_service_zones', [
            'commune' => 'Test', 'name' => $name, 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        if ($inserted !== 1) throw new RuntimeException('No se pudo crear la zona fixture transaccional.');
        $zoneId = (int) $wpdb->insert_id;
        foreach ($normalizedStoreIds as $storeId) {
            $assigned = $wpdb->insert($wpdb->prefix . 'va_store_service_zones', [
                'store_id' => $storeId, 'zone_id' => $zoneId,
                'assigned_by' => 0, 'assigned_at' => $now,
            ]);
            if ($assigned !== 1) throw new RuntimeException('No se pudo asignar la tienda fixture.');
        }
    } else {
        $zoneId = (new ServiceZoneRepository())->save([
            'commune' => 'Test', 'name' => $name, 'status' => 'active',
            'stores' => $normalizedStoreIds,
        ], 0);
    }
    sectorizationFixtureClearCurrent();
    (new CurrentSector())->set($zoneId);
    if ((new CurrentSector())->id() !== $zoneId) throw new RuntimeException('No se pudo seleccionar la zona fixture.');
    return $zoneId;
}
