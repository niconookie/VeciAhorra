<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;

require_once dirname(__DIR__, 5) . '/wp-load.php';

global $wpdb;
$table = $wpdb->prefix . Config::TABLE_PREFIX . 'stores';
$approved = "approved_at IS NOT NULL AND approved_at <> ''"
    . " AND approved_at <> '0000-00-00 00:00:00'"
    . " AND approved_at REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$'";
$unapproved = "(approved_at IS NULL OR approved_at = '')";
$valid = "((status = 'pending' AND onboarding_status = 'draft' AND {$unapproved})"
    . " OR (status = 'pending' AND onboarding_status = 'complete' AND {$unapproved})"
    . " OR (status = 'rejected' AND onboarding_status = 'complete' AND {$unapproved})"
    . " OR (status = 'inactive' AND onboarding_status = 'complete' AND {$approved})"
    . " OR (status = 'active' AND onboarding_status = 'complete' AND {$approved}))";
$queries = [
    'unfiltered' => "SELECT * FROM {$table} ORDER BY business_name ASC, id ASC LIMIT 20",
    'search' => "SELECT * FROM {$table} WHERE business_name LIKE '%mercado%' OR legal_name LIKE '%mercado%' OR rut LIKE '%mercado%' OR email LIKE '%mercado%' OR commune LIKE '%mercado%' OR city LIKE '%mercado%' ORDER BY business_name ASC, id ASC LIMIT 20",
    'active' => "SELECT * FROM {$table} WHERE status = 'active' AND onboarding_status = 'complete' AND {$approved} ORDER BY business_name ASC, id ASC LIMIT 20",
    'approved_inactive' => "SELECT * FROM {$table} WHERE status = 'inactive' AND onboarding_status = 'complete' AND {$approved} ORDER BY business_name ASC, id ASC LIMIT 20",
    'search_active' => "SELECT * FROM {$table} WHERE (business_name LIKE '%mercado%' OR legal_name LIKE '%mercado%' OR rut LIKE '%mercado%' OR email LIKE '%mercado%' OR commune LIKE '%mercado%' OR city LIKE '%mercado%') AND status = 'active' AND onboarding_status = 'complete' AND {$approved} ORDER BY business_name ASC, id ASC LIMIT 20",
    'invalid' => "SELECT * FROM {$table} WHERE NOT ({$valid}) OR status IS NULL OR onboarding_status IS NULL ORDER BY business_name ASC, id ASC LIMIT 20",
];
$plans = [];
foreach ($queries as $name => $sql) {
    $rows = $wpdb->get_results('EXPLAIN ' . $sql, ARRAY_A);
    if (! is_array($rows) || $rows === [] || $wpdb->last_error !== '') {
        throw new RuntimeException('EXPLAIN fallo para ' . $name . '.');
    }
    $plans[$name] = array_map(static fn (array $row): array => [
        'type' => $row['type'] ?? null,
        'possible_keys' => $row['possible_keys'] ?? null,
        'key' => $row['key'] ?? null,
        'rows' => isset($row['rows']) ? (int) $row['rows'] : null,
        'extra' => $row['Extra'] ?? null,
    ], $rows);
}
echo 'PASS store-admin-operational-list-explain-test ' . wp_json_encode($plans) . "\n";
