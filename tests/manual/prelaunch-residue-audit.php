<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';
global $wpdb;

$checks = [
    'users' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'va_prelaunch_%'"),
    'stores' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}va_stores WHERE business_name LIKE 'aof_%'"),
    'products' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}va_products WHERE slug LIKE 'aof_%'"),
    'zones' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}va_service_zones WHERE commune LIKE 'aof_%'"),
];
foreach ($checks as $type => $count) {
    if ($count !== 0) throw new RuntimeException($type . ' fixture residue detected.');
}
echo 'PRELAUNCH_FIXTURE_RESIDUES=' . wp_json_encode($checks) . "\n";
