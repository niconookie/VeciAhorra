<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';

use VeciAhorra\Modules\ZonalAdmin\Repositories\ZonalAdminServiceZoneRepository;

function zaAssert(bool $condition, string $message): void { if (! $condition) { throw new RuntimeException($message); } }
global $wpdb;
$repo = new ZonalAdminServiceZoneRepository();
$userId = (int)$wpdb->get_var("SELECT ID FROM {$wpdb->users} ORDER BY ID LIMIT 1");
$zones = array_map('intval', $wpdb->get_col("SELECT id FROM {$wpdb->prefix}va_service_zones ORDER BY id LIMIT 2"));
zaAssert($userId > 0 && count($zones) === 2, 'Fixture base insuficiente.');
$before = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}va_zonal_admin_service_zones");
$wpdb->query('START TRANSACTION');
try {
    $repo->assign($userId, $zones[0], $userId, current_time('mysql'));
    $repo->assign($userId, $zones[1], $userId, current_time('mysql'));
    zaAssert($repo->zoneIdsForUser($userId) === $zones, 'Consulta territorial no determinista.');
    $previousSuppression = $wpdb->suppress_errors(true);
    try { $repo->assign($userId, $zones[0], $userId, current_time('mysql')); throw new RuntimeException('Duplicado aceptado.'); } catch (VeciAhorra\Exceptions\PersistenceException) {}
    finally { $wpdb->suppress_errors($previousSuppression); }
    try { $repo->assign(PHP_INT_MAX, $zones[0], $userId, current_time('mysql')); throw new RuntimeException('Usuario inexistente aceptado.'); } catch (InvalidArgumentException) {}
    try { $repo->assign($userId, PHP_INT_MAX, $userId, current_time('mysql')); throw new RuntimeException('Zona inexistente aceptada.'); } catch (InvalidArgumentException) {}
    zaAssert($repo->unassign($userId, $zones[0]) === 1, 'Unassign fallo.');
} finally { $wpdb->query('ROLLBACK'); }
zaAssert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}va_zonal_admin_service_zones") === $before, 'Residuo de asignacion.');
echo "ZONAL_ADMIN_Z1_ASSIGNMENT=PASS fixture_cleanup=PASS\n";
