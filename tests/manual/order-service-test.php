<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Orders\Services\OrderService;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertOrderService(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertOrderServiceSame(mixed $expected, mixed $actual): void
{
    assertOrderService(
        $expected === $actual,
        sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        )
    );
}

function assertOrderServiceInvalid(callable $callback): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException('Se esperaba InvalidArgumentException.');
}

global $wpdb;

$service = new OrderService();
$transaction = $wpdb->query('START TRANSACTION');
assertOrderService($transaction !== false, 'No se inicio la transaccion.');

try {
    $before = current_datetime()->getTimestamp();
    $created = $service->create([
        'customer_id' => random_int(11000000, 11999999),
        'minimarket_id' => random_int(12000000, 12999999),
        'items' => [
            [
                'product_id' => 101,
                'inventory_id' => 201,
                'quantity' => 3,
                'unit_price' => 1250.50,
            ],
            [
                'product_id' => 102,
                'inventory_id' => 202,
                'quantity' => 2,
                'unit_price' => 499.99,
            ],
        ],
    ]);
    $after = current_datetime()->getTimestamp();

    $orderId = (int) ($created['id'] ?? 0);
    assertOrderService($orderId > 0, 'Create no retorno un pedido valido.');
    assertOrderServiceSame('reserved', $created['status']);
    assertOrderServiceSame('4751.48', $created['total']);
    assertOrderServiceSame(2, count($created['items']));
    assertOrderServiceSame(3751.50, $created['items'][0]['subtotal']);
    assertOrderServiceSame(999.98, $created['items'][1]['subtotal']);
    assertOrderServiceSame(1250.50, $created['items'][0]['unit_price']);

    $createdDate = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i:s',
        $created['created_at'],
        wp_timezone()
    );
    $expirationDate = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i:s',
        $created['reservation_expires_at'],
        wp_timezone()
    );
    assertOrderService(
        $createdDate instanceof DateTimeImmutable
            && $expirationDate instanceof DateTimeImmutable,
        'Las fechas del pedido no tienen formato MySQL valido.'
    );
    $createdTimestamp = $createdDate->getTimestamp();
    $expirationTimestamp = $expirationDate->getTimestamp();
    assertOrderService(
        $createdTimestamp >= $before && $createdTimestamp <= $after,
        'created_at no coincide con la hora de creacion.'
    );
    assertOrderServiceSame(
        15 * MINUTE_IN_SECONDS,
        $expirationTimestamp - $createdTimestamp
    );

    $itemsTable = $wpdb->prefix . Config::TABLE_PREFIX . 'order_items';
    $persistedItems = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$itemsTable} WHERE order_id = %d ORDER BY id ASC",
            $orderId
        ),
        ARRAY_A
    );
    assertOrderServiceSame(2, count($persistedItems));
    assertOrderServiceSame('1250.50', $persistedItems[0]['unit_price']);
    assertOrderServiceSame('3751.50', $persistedItems[0]['subtotal']);
    assertOrderServiceSame('999.98', $persistedItems[1]['subtotal']);

    assertOrderServiceInvalid(fn () => $service->create([
        'customer_id' => 1,
        'minimarket_id' => 2,
        'items' => [],
    ]));
    assertOrderServiceInvalid(fn () => $service->create([
        'customer_id' => 1,
        'minimarket_id' => 2,
        'items' => [[
            'product_id' => 1,
            'inventory_id' => 1,
            'quantity' => 1,
        ]],
    ]));

    $originalPrefix = $wpdb->prefix;
    $wpdb->suppress_errors(true);

    try {
        $wpdb->prefix = 'missing_orders_' . uniqid() . '_';

        try {
            $service->create([
                'customer_id' => 1,
                'minimarket_id' => 2,
                'items' => [[
                    'product_id' => 1,
                    'inventory_id' => 1,
                    'quantity' => 1,
                    'unit_price' => 1.0,
                ]],
            ]);
            throw new RuntimeException('Se esperaba error de persistencia.');
        } catch (RuntimeException $exception) {
            assertOrderService(
                ! $exception instanceof PersistenceException,
                'OrderService expuso PersistenceException.'
            );
            assertOrderService(
                $exception->getPrevious() instanceof PersistenceException,
                'No se conservo la causa de persistencia.'
            );
        }
    } finally {
        $wpdb->prefix = $originalPrefix;
        $wpdb->suppress_errors(false);
    }

    $source = file_get_contents(
        dirname(__DIR__, 2)
        . '/app/Modules/Orders/Services/OrderService.php'
    );
    assertOrderService(
        is_string($source) && ! str_contains($source, '$wpdb'),
        'OrderService contiene acceso a $wpdb.'
    );
    assertOrderService(
        preg_match('/\b(SELECT|INSERT INTO|UPDATE|DELETE FROM)\b/i', $source)
            !== 1,
        'OrderService contiene SQL.'
    );

    echo "PASS order-service-test\n";
} finally {
    $wpdb->query('ROLLBACK');
}
