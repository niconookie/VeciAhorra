<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Database\Migrations\CreateOrdersTables;
use VeciAhorra\Database\MigrationManager;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertOrdersSchema(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function ordersColumns(string $table): array
{
    global $wpdb;

    return array_column(
        $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A),
        null,
        'Field'
    );
}

function ordersIndexes(string $table): array
{
    global $wpdb;

    return array_values(array_unique(array_column(
        $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A),
        'Key_name'
    )));
}

function assertColumns(
    array $columns,
    array $expected,
    string $table
): void {
    foreach ($expected as $name => $definition) {
        assertOrdersSchema(
            isset($columns[$name]),
            "Falta {$table}.{$name}."
        );
        assertOrdersSchema(
            strtolower($columns[$name]['Type']) === $definition['type'],
            "Tipo incorrecto para {$table}.{$name}."
        );

        if (array_key_exists('null', $definition)) {
            assertOrdersSchema(
                $columns[$name]['Null'] === $definition['null'],
                "Nullability incorrecta para {$table}.{$name}."
            );
        }

        if (array_key_exists('default', $definition)) {
            assertOrdersSchema(
                $columns[$name]['Default'] === $definition['default'],
                "Default incorrecto para {$table}.{$name}."
            );
        }
    }
}

global $wpdb;

$ordersTable = $wpdb->prefix . Config::TABLE_PREFIX . 'orders';
$itemsTable = $wpdb->prefix . Config::TABLE_PREFIX . 'order_items';
$migration = new CreateOrdersTables();

$migrationsMethod = new ReflectionMethod(MigrationManager::class, 'migrations');
$migrationsMethod->setAccessible(true);
$registeredMigrations = $migrationsMethod->invoke(null);
assertOrdersSchema(
    count(array_filter(
        $registeredMigrations,
        static fn (object $registered): bool =>
            $registered instanceof CreateOrdersTables
    )) === 1,
    'CreateOrdersTables debe estar registrada exactamente una vez.'
);
assertOrdersSchema(
    version_compare(Config::SCHEMA_VERSION, '0.5.0', '>='),
    'SCHEMA_VERSION no activa la migracion de Orders.'
);

$migration->up();
$migration->up();

assertOrdersSchema(
    $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $ordersTable))
        === $ordersTable,
    "No existe la tabla fisica {$ordersTable}."
);
assertOrdersSchema(
    $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $itemsTable))
        === $itemsTable,
    "No existe la tabla fisica {$itemsTable}."
);

$orders = ordersColumns($ordersTable);
assertColumns(
    $orders,
    [
        'id' => ['type' => 'bigint(20) unsigned', 'null' => 'NO'],
        'customer_id' => ['type' => 'bigint(20) unsigned', 'null' => 'NO'],
        'minimarket_id' => ['type' => 'bigint(20) unsigned', 'null' => 'NO'],
        'total' => [
            'type' => 'decimal(10,2)',
            'null' => 'NO',
            'default' => '0.00',
        ],
        'status' => [
            'type' => 'varchar(20)',
            'null' => 'NO',
            'default' => 'reserved',
        ],
        'reservation_expires_at' => ['type' => 'datetime', 'null' => 'YES'],
        'created_at' => ['type' => 'datetime', 'null' => 'NO'],
        'updated_at' => ['type' => 'datetime', 'null' => 'NO'],
    ],
    'orders'
);
assertOrdersSchema(
    str_contains(strtolower($orders['id']['Extra']), 'auto_increment'),
    'orders.id no es autoincremental.'
);

foreach (
    [
        'PRIMARY',
        'orders_customer_id_index',
        'orders_minimarket_id_index',
        'orders_status_index',
        'orders_reservation_expires_at_index',
    ] as $index
) {
    assertOrdersSchema(
        in_array($index, ordersIndexes($ordersTable), true),
        "Falta el indice {$index}."
    );
}

$items = ordersColumns($itemsTable);
assertColumns(
    $items,
    [
        'id' => ['type' => 'bigint(20) unsigned', 'null' => 'NO'],
        'order_id' => ['type' => 'bigint(20) unsigned', 'null' => 'NO'],
        'product_id' => ['type' => 'bigint(20) unsigned', 'null' => 'NO'],
        'inventory_id' => ['type' => 'bigint(20) unsigned', 'null' => 'NO'],
        'quantity' => ['type' => 'int(10) unsigned', 'null' => 'NO'],
        'unit_price' => ['type' => 'decimal(10,2)', 'null' => 'NO'],
        'subtotal' => ['type' => 'decimal(10,2)', 'null' => 'NO'],
        'created_at' => ['type' => 'datetime', 'null' => 'NO'],
        'updated_at' => ['type' => 'datetime', 'null' => 'NO'],
    ],
    'order_items'
);
assertOrdersSchema(
    str_contains(strtolower($items['id']['Extra']), 'auto_increment'),
    'order_items.id no es autoincremental.'
);

foreach (
    [
        'PRIMARY',
        'order_items_order_id_index',
        'order_items_product_id_index',
        'order_items_inventory_id_index',
    ] as $index
) {
    assertOrdersSchema(
        in_array($index, ordersIndexes($itemsTable), true),
        "Falta el indice {$index}."
    );
}

foreach ([$ordersTable, $itemsTable] as $table) {
    $status = $wpdb->get_row(
        $wpdb->prepare('SHOW TABLE STATUS LIKE %s', $table),
        ARRAY_A
    );
    assertOrdersSchema(
        strtolower((string) ($status['Engine'] ?? '')) === 'innodb',
        "{$table} no usa InnoDB."
    );
}

echo "PASS orders-migration-test\n";
