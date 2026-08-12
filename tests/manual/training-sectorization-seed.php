<?php
declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Database\Installer;

require_once dirname(__DIR__, 5) . '/wp-load.php';

global $wpdb;
Installer::install();
$prefix = $wpdb->prefix . Config::TABLE_PREFIX;
$zoneTable = $prefix . 'service_zones';
$assignmentTable = $prefix . 'store_service_zones';
$storeTable = $prefix . 'stores';
$now = current_time('mysql', true);
$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
$assignedBy = (int) ($admins[0] ?? 0);

$specification = [
    ['commune' => 'Santiago', 'name' => 'República', 'stores' => ['Minimarket Los Vecinos', 'Minimarket Central']],
    ['commune' => 'Santiago', 'name' => 'Centro Sur', 'stores' => ['Minimarket Central', 'Minimarket Plaza Sur']],
];

$wpdb->query('START TRANSACTION');
try {
    foreach ($specification as $zone) {
        $zoneId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$zoneTable} WHERE commune=%s AND name=%s LIMIT 1",
            $zone['commune'],
            $zone['name']
        ));
        if ($zoneId > 0) {
            if ($wpdb->update($zoneTable, ['status' => 'active'], ['id' => $zoneId]) === false) {
                throw new RuntimeException('No fue posible activar el sector ' . $zone['name']);
            }
        } else {
            if ($wpdb->insert($zoneTable, [
                'commune' => $zone['commune'], 'name' => $zone['name'], 'status' => 'active',
                'created_at' => $now, 'updated_at' => $now,
            ]) !== 1) throw new RuntimeException('No fue posible crear el sector ' . $zone['name']);
            $zoneId = (int) $wpdb->insert_id;
        }

        $storeIds = [];
        foreach ($zone['stores'] as $businessName) {
            $matches = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$storeTable} WHERE business_name=%s ORDER BY id",
                $businessName
            ));
            if (count($matches) !== 1) throw new RuntimeException('Minimarket no unívoco o ausente: ' . $businessName);
            $storeIds[] = (int) $matches[0];
        }
        $wpdb->delete($assignmentTable, ['zone_id' => $zoneId]);
        foreach ($storeIds as $storeId) {
            if ($wpdb->insert($assignmentTable, [
                'zone_id' => $zoneId, 'store_id' => $storeId,
                'assigned_by' => $assignedBy, 'assigned_at' => $now,
            ]) !== 1) throw new RuntimeException('No fue posible asignar un minimarket.');
        }
    }
    $wpdb->query('COMMIT');
} catch (Throwable $exception) {
    $wpdb->query('ROLLBACK');
    throw $exception;
}

$activeZones = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$zoneTable} WHERE status='active'");
$assignments = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$assignmentTable}");
$duplicates = (int) $wpdb->get_var("SELECT COUNT(*) FROM (SELECT zone_id,store_id FROM {$assignmentTable} GROUP BY zone_id,store_id HAVING COUNT(*)>1) duplicate_assignments");
if ($activeZones !== 2 || $assignments !== 4 || $duplicates !== 0) {
    throw new RuntimeException("Estado sectorial inesperado: zones={$activeZones}, assignments={$assignments}, duplicates={$duplicates}");
}

echo wp_json_encode(['database' => DB_NAME, 'active_zones' => $activeZones, 'assignments' => $assignments, 'duplicates' => $duplicates], JSON_UNESCAPED_UNICODE), PHP_EOL;
