<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';

global $wpdb;
$testPrefix = 'vatf_' . strtolower(wp_generate_password(8, false, false)) . '_';
$schemas = [
    new \VeciAhorra\Database\Schemas\CheckoutSchema(),
    new \VeciAhorra\Database\Schemas\CheckoutRefundSchema(),
    new \VeciAhorra\Database\Schemas\InventorySchema(),
    new \VeciAhorra\Database\Tables\ProductsTable(),
    new \VeciAhorra\Database\Tables\StoresTable(),
];
$tables = array_map(static fn ($schema): string => $testPrefix . $schema->name(), $schemas);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

try {
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    foreach ([1, 2] as $pass) {
        foreach ($schemas as $index => $schema) {
            $builder = \VeciAhorra\Database\Builder\TableBuilder::make($tables[$index]);
            $schema->define($builder);
            dbDelta($builder->build($wpdb->get_charset_collate()));
        }
    }

    foreach ($tables as $table) {
        $assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table, "Missing {$table}");
    }
    $checkoutColumns = array_map('strval', $wpdb->get_col('SHOW COLUMNS FROM `' . $tables[0] . '`'));
    foreach (['product_subtotal', 'platform_fee', 'delivery_fee', 'fee_policy_version'] as $column) {
        $assert(in_array($column, $checkoutColumns, true), "Missing checkout column {$column}");
    }
    foreach ([2, 3, 4] as $index) {
        $columns = array_map('strval', $wpdb->get_col('SHOW COLUMNS FROM `' . $tables[$index] . '`'));
        $assert(in_array('delivery_enabled', $columns, true), "Missing delivery flag in {$tables[$index]}");
    }
} finally {
    foreach ($tables as $table) {
        $wpdb->query('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
    }
}

foreach ($tables as $table) {
    $assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === null, "Rollback failed for {$table}");
}

echo "PASS checkout-fees-migration fresh=1 upgrade=1 idempotent=1 rollback=1 assertions={$assertions}\n";
