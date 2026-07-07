<?php

declare(strict_types=1);

use VeciAhorra\Modules\Cart\Repository\CartRepository;
use VeciAhorra\Modules\Cart\Service\CartService;
use VeciAhorra\Modules\Inventory\Repositories\InventoryRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertCartService(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertCartServiceSame(mixed $expected, mixed $actual): void
{
    assertCartService(
        $expected === $actual,
        sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        )
    );
}

function assertCartServiceInvalid(callable $callback): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException('Se esperaba InvalidArgumentException.');
}

function assertCartServiceNotFound(callable $callback): void
{
    try {
        $callback();
    } catch (VeciAhorra\Exceptions\RecordNotFoundException) {
        return;
    }

    throw new RuntimeException('Se esperaba RecordNotFoundException.');
}

global $wpdb;

$cartRepository = new CartRepository();
$inventoryRepository = new InventoryRepository();
$service = new CartService($cartRepository);
$transaction = $wpdb->query('START TRANSACTION');
assertCartService($transaction !== false, 'No se inicio la transaccion.');

try {
    $now = current_time('mysql');
    $minimarketId = random_int(48000000, 48999999);
    $firstProductId = random_int(49000000, 49999999);
    $secondProductId = random_int(50000000, 50999999);
    $firstInventoryId = $inventoryRepository->create([
        'product_id' => $firstProductId,
        'minimarket_id' => $minimarketId,
        'price' => 1290.50,
        'stock' => 0,
        'status' => 'inactive',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $secondInventoryId = $inventoryRepository->create([
        'product_id' => $secondProductId,
        'minimarket_id' => $minimarketId,
        'price' => 800.0,
        'stock' => 0,
        'status' => 'inactive',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $sessionOwner = [
        'session_id' => 'cart-' . bin2hex(random_bytes(8)),
        'user_id' => null,
    ];
    $userOwner = [
        'session_id' => null,
        'user_id' => random_int(51000000, 51999999),
    ];

    $sessionResult = $service->addItem(
        $sessionOwner,
        $firstInventoryId,
        2
    );
    $sessionItemId = $sessionResult['id'];
    assertCartServiceSame(true, $sessionResult['created']);
    $sessionCart = $service->getCart($sessionOwner);
    assertCartServiceSame(1, count($sessionCart));
    assertCartServiceSame($sessionItemId, (int) $sessionCart[0]['id']);
    assertCartServiceSame(2, (int) $sessionCart[0]['quantity']);
    assertCartServiceSame(
        $firstProductId,
        (int) $sessionCart[0]['product_id']
    );
    assertCartServiceSame(
        $minimarketId,
        (int) $sessionCart[0]['minimarket_id']
    );
    assertCartServiceSame('1290.50', $sessionCart[0]['unit_price_snapshot']);

    $inventoryRepository->update($firstInventoryId, [
        'price' => 1990.0,
        'updated_at' => current_time('mysql'),
    ]);
    $sameResult = $service->addItem(
        $sessionOwner,
        $firstInventoryId,
        3
    );
    $sessionCart = $service->getCart($sessionOwner);
    assertCartServiceSame($sessionItemId, $sameResult['id']);
    assertCartServiceSame(false, $sameResult['created']);
    assertCartServiceSame(1, count($sessionCart));
    assertCartServiceSame(5, (int) $sessionCart[0]['quantity']);
    assertCartServiceSame('1290.50', $sessionCart[0]['unit_price_snapshot']);

    $userResult = $service->addItem($userOwner, $secondInventoryId, 4);
    $userItemId = $userResult['id'];
    assertCartServiceSame(true, $userResult['created']);
    $userCart = $service->getCart($userOwner);
    assertCartServiceSame(1, count($userCart));
    assertCartServiceSame($userItemId, (int) $userCart[0]['id']);
    assertCartServiceSame(4, (int) $userCart[0]['quantity']);
    assertCartServiceSame(
        $userItemId,
        $service->addItem($userOwner, $secondInventoryId, 1)['id']
    );
    $userCart = $service->getCart($userOwner);
    assertCartServiceSame(1, count($userCart));
    assertCartServiceSame(5, (int) $userCart[0]['quantity']);

    assertCartServiceSame(
        true,
        $service->updateQuantity($sessionOwner, $sessionItemId, 7)
    );
    assertCartServiceSame(
        7,
        (int) $service->getCart($sessionOwner)[0]['quantity']
    );
    assertCartServiceNotFound(fn () =>
        $service->updateQuantity($userOwner, $sessionItemId, 8)
    );

    assertCartServiceSame(
        true,
        $service->removeItem($sessionOwner, $sessionItemId)
    );
    assertCartServiceSame([], $service->getCart($sessionOwner));
    assertCartServiceNotFound(fn () =>
        $service->removeItem($userOwner, $sessionItemId)
    );

    assertCartServiceSame(1, $service->clearCart($userOwner));
    assertCartServiceSame([], $service->getCart($userOwner));
    assertCartServiceSame(0, $service->clearCart($userOwner));

    assertCartServiceInvalid(fn () =>
        $service->addItem([], $firstInventoryId, 1)
    );
    assertCartServiceInvalid(fn () =>
        $service->getCart(['session_id' => '', 'user_id' => null])
    );
    assertCartServiceInvalid(fn () =>
        $service->addItem($sessionOwner, 0, 1)
    );
    assertCartServiceInvalid(fn () =>
        $service->addItem($sessionOwner, $firstInventoryId, 0)
    );
    assertCartServiceInvalid(fn () =>
        $service->updateQuantity($sessionOwner, 1, -1)
    );
    assertCartServiceInvalid(fn () =>
        $service->addItem($sessionOwner, PHP_INT_MAX, 1)
    );

    echo "PASS cart-service-test\n";
} finally {
    $wpdb->query('ROLLBACK');
}
