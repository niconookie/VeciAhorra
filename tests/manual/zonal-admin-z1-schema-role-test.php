<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';

use VeciAhorra\Database\Migrations\CreateZonalAdminFoundationTables;
use VeciAhorra\Modules\ZonalAdmin\Identity\ZonalAdminRole;

function z1Assert(bool $condition, string $message): void { if (! $condition) { throw new RuntimeException($message); } }

global $wpdb;
$prefix = $wpdb->prefix . 'va_';
$inventory = static function () use ($wpdb, $prefix): array {
    return [
        'zonal_users' => $wpdb->get_results($wpdb->prepare("SELECT u.ID,u.user_login,u.display_name,um.meta_value capabilities FROM {$wpdb->users} u JOIN {$wpdb->usermeta} um ON um.user_id=u.ID AND um.meta_key=%s WHERE um.meta_value LIKE %s ORDER BY u.ID", $wpdb->prefix . 'capabilities', '%veciahorra_zonal_admin%'), ARRAY_A),
        'assignments' => $wpdb->get_results("SELECT * FROM {$prefix}zonal_admin_service_zones ORDER BY id", ARRAY_A),
        'stores' => $wpdb->get_results("SELECT * FROM {$prefix}stores ORDER BY id", ARRAY_A),
        'store_zones' => $wpdb->get_results("SELECT * FROM {$prefix}store_service_zones ORDER BY id", ARRAY_A),
        'history' => $wpdb->get_results("SELECT * FROM {$prefix}store_decision_history ORDER BY id", ARRAY_A),
    ];
};
$initialInventory = $inventory();
$expected = [
    'zonal_admin_service_zones' => ['id','user_id','service_zone_id','created_at','created_by'],
    'store_decision_history' => ['id','store_id','actor_user_id','actor_role','action','from_state','to_state','reason','authority_service_zone_id','created_at'],
];
foreach ($expected as $table => $columns) {
    z1Assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $prefix . $table)) === $prefix . $table, "Falta {$table}.");
    z1Assert(array_column($wpdb->get_results("SHOW COLUMNS FROM {$prefix}{$table}", ARRAY_A), 'Field') === $columns, "Columnas invalidas en {$table}.");
}
$assignmentIndexes = array_column($wpdb->get_results("SHOW INDEX FROM {$prefix}zonal_admin_service_zones", ARRAY_A), 'Key_name');
$historyIndexes = array_column($wpdb->get_results("SHOW INDEX FROM {$prefix}store_decision_history", ARRAY_A), 'Key_name');
foreach (['zonal_admin_service_zones_unique','zonal_admin_service_zones_user_index','zonal_admin_service_zones_zone_index'] as $index) { z1Assert(in_array($index, $assignmentIndexes, true), "Falta indice {$index}."); }
foreach (['store_decision_history_store_order','store_decision_history_actor_index','store_decision_history_zone_index'] as $index) { z1Assert(in_array($index, $historyIndexes, true), "Falta indice {$index}."); }
z1Assert(get_option('veciahorra_db_version') === '0.29.0', 'Version de esquema incorrecta.');
$rolesOptionBefore = get_option($wpdb->prefix . 'user_roles');
$migration = new CreateZonalAdminFoundationTables();
$migration->up(); $migration->up();
ZonalAdminRole::register(); ZonalAdminRole::register();
z1Assert(get_option($wpdb->prefix . 'user_roles') === $rolesOptionBefore, 'El registro idempotente reescribio roles.');
$role = get_role(ZonalAdminRole::ROLE);
z1Assert($role instanceof WP_Role, 'Falta rol zonal.');
$granted = array_keys(array_filter($role->capabilities)); sort($granted);
$exact = ['read',ZonalAdminRole::CAPABILITY_DECIDE,ZonalAdminRole::CAPABILITY_READ]; sort($exact);
z1Assert($granted === $exact, 'Capabilities zonales no exactas.');
$admin = get_role('administrator');
z1Assert($admin?->has_cap(ZonalAdminRole::CAPABILITY_READ) && $admin?->has_cap(ZonalAdminRole::CAPABILITY_DECIDE), 'Administrador sin autoridad global nueva.');
foreach (['customer','veciahorra_minimarket','veciahorra_courier','veciahorra_service_provider'] as $other) {
    $candidate = get_role($other);
    z1Assert(! $candidate?->has_cap(ZonalAdminRole::CAPABILITY_READ) && ! $candidate?->has_cap(ZonalAdminRole::CAPABILITY_DECIDE), "Capability filtrada a {$other}.");
}
z1Assert($inventory() === $initialInventory, 'La migracion altero el baseline productivo.');
echo "ZONAL_ADMIN_Z1_SCHEMA_ROLE=PASS migration_idempotence=PASS role_idempotence=PASS baseline_structural_equality=PASS adversarial_baseline=PASS\n";
