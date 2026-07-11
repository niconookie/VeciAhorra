<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Core\Container;
use VeciAhorra\Database\MigrationManager;
use VeciAhorra\Database\Migrations\CreateCartItemsTable;
use VeciAhorra\Modules\Cart\Repository\CartRepository;
use VeciAhorra\Modules\Cart\Requests\CartItemCreateRequest;
use VeciAhorra\Modules\Cart\Requests\CartItemQuantityRequest;
use VeciAhorra\Modules\Cart\Routes\CartRoutes;
use VeciAhorra\Modules\Cart\Service\CartService;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertCartFoundation(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertCartFoundationSame(mixed $expected, mixed $actual): void
{
    assertCartFoundation(
        $expected === $actual,
        sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        )
    );
}

function assertCartFoundationInvalid(callable $callback): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException('Se esperaba InvalidArgumentException.');
}

global $wpdb;

$migration = new CreateCartItemsTable();
$migration->up();
$migration->up();
$table = $wpdb->prefix . Config::TABLE_PREFIX . 'cart_items';
assertCartFoundation(
    $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table,
    'No existe la tabla cart_items.'
);
$columns = array_column(
    $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A),
    'Field'
);

foreach ([
    'id', 'session_id', 'user_id', 'inventory_id', 'product_id',
    'minimarket_id', 'quantity', 'unit_price_snapshot',
    'created_at', 'updated_at',
] as $column) {
    assertCartFoundation(
        in_array($column, $columns, true),
        "Falta cart_items.{$column}."
    );
}

$method = new ReflectionMethod(MigrationManager::class, 'migrations');
$method->setAccessible(true);
assertCartFoundationSame(
    1,
    count(array_filter(
        $method->invoke(null),
        static fn (object $item): bool =>
            $item instanceof CreateCartItemsTable
    ))
);

$repository = new CartRepository();
$service = new CartService($repository);
$transaction = $wpdb->query('START TRANSACTION');
assertCartFoundation($transaction !== false, 'No se inicio la transaccion.');

try {
    $now = current_time('mysql');
    $sessionId = 'guest-' . bin2hex(random_bytes(8));
    $otherSessionId = 'guest-' . bin2hex(random_bytes(8));
    $userId = random_int(47000000, 47999999);
    $sessionItemId = $repository->create([
        'session_id' => $sessionId,
        'user_id' => null,
        'inventory_id' => 101,
        'product_id' => 201,
        'minimarket_id' => 301,
        'quantity' => 2,
        'unit_price_snapshot' => 1290.0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $otherSessionItemId = $repository->create([
        'session_id' => $otherSessionId,
        'user_id' => null,
        'inventory_id' => 102,
        'product_id' => 202,
        'minimarket_id' => 302,
        'quantity' => 1,
        'unit_price_snapshot' => 990.0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $userItemId = $repository->create([
        'session_id' => null,
        'user_id' => $userId,
        'inventory_id' => 103,
        'product_id' => 203,
        'minimarket_id' => 303,
        'quantity' => 4,
        'unit_price_snapshot' => 500.0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    assertCartFoundation($sessionItemId > 0, 'Create no retorno ID.');
    assertCartFoundation($userItemId > 0, 'No se creo item de usuario.');
    $sessionCart = $repository->findBySession($sessionId);
    assertCartFoundationSame(1, count($sessionCart));
    assertCartFoundationSame($sessionItemId, (int) $sessionCart[0]['id']);
    assertCartFoundationSame(2, (int) $sessionCart[0]['quantity']);

    $userCart = $repository->findByUser($userId);
    assertCartFoundationSame(1, count($userCart));
    assertCartFoundationSame($userItemId, (int) $userCart[0]['id']);

    assertCartFoundationSame(
        true,
        $repository->updateQuantity(
            $sessionItemId,
            5,
            '1290.00',
            $sessionId,
            null
        )
    );
    assertCartFoundationSame(
        5,
        (int) $repository->findBySession($sessionId)[0]['quantity']
    );

    assertCartFoundationSame(
        true,
        $repository->delete($sessionItemId, $sessionId, null)
    );
    assertCartFoundationSame([], $repository->findBySession($sessionId));
    assertCartFoundationSame(
        false,
        $repository->delete($sessionItemId, $sessionId, null)
    );

    assertCartFoundationSame(1, $repository->clear(null, $userId));
    assertCartFoundationSame([], $repository->findByUser($userId));
    assertCartFoundationSame(0, $repository->clear(null, $userId));
    assertCartFoundationSame(
        1,
        $repository->clear($otherSessionId, null)
    );
    assertCartFoundationSame(
        false,
        $repository->delete($otherSessionItemId, $otherSessionId, null)
    );

    $validated = (new CartItemCreateRequest([
        'inventory_id' => '55',
        'quantity' => '3',
    ]))->validated();
    assertCartFoundationSame(55, $validated['inventory_id']);
    assertCartFoundationSame(3, $validated['quantity']);
    assertCartFoundationSame(
        ['quantity' => 7],
        (new CartItemQuantityRequest(['quantity' => '7']))->validated()
    );

    foreach ([0, -1, '0', '1.5', null, []] as $invalid) {
        assertCartFoundationInvalid(fn () =>
            (new CartItemCreateRequest([
                'inventory_id' => 1,
                'quantity' => $invalid,
            ]))->validated()
        );
        assertCartFoundationInvalid(fn () =>
            (new CartItemCreateRequest([
                'inventory_id' => $invalid,
                'quantity' => 1,
            ]))->validated()
        );
    }

    assertCartFoundation(
        (new Container())->make(CartRoutes::class) instanceof CartRoutes,
        'Container no resolvio CartRoutes.'
    );
    $application = file_get_contents(
        dirname(__DIR__, 2) . '/app/Core/Application.php'
    );
    assertCartFoundationSame(
        1,
        substr_count($application, '$cartRoutes = $this->container->make')
    );
    assertCartFoundationSame(
        1,
        substr_count($application, "[\$cartRoutes, 'register']")
    );

    echo "PASS cart-foundation-test\n";
} finally {
    $wpdb->query('ROLLBACK');
}
