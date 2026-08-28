<?php

declare(strict_types=1);

ob_start();

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Cart\Repository\CartRepository;
use VeciAhorra\Modules\Cart\Service\CartService;
use VeciAhorra\Modules\Inventory\Repositories\InventoryRepository;
use VeciAhorra\Modules\Products\Models\Product;
use VeciAhorra\Modules\Products\Repositories\ProductRepository;
use VeciAhorra\Modules\Sectorization\CurrentSector;
use VeciAhorra\Modules\Sectorization\ServiceZoneRepository;
use VeciAhorra\Modules\Stores\Repositories\StoreRepository;

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

function assertCartServiceOutOfSector(callable $callback): void
{
    try {
        $callback();
    } catch (InvalidArgumentException $exception) {
        assertCartServiceSame(
            'La oferta no está disponible en el sector actual.',
            $exception->getMessage()
        );
        return;
    }

    throw new RuntimeException('Se esperaba rechazo por sector.');
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

function cartServiceCommercialSnapshot(): string
{
    global $wpdb;
    $prefix = $wpdb->prefix . Config::TABLE_PREFIX;
    $snapshot = [];
    foreach (['service_zones', 'store_service_zones', 'stores', 'products', 'inventory', 'cart_items', 'orders'] as $suffix) {
        $snapshot[$suffix] = $wpdb->get_results("SELECT * FROM {$prefix}{$suffix} ORDER BY 1, 2", ARRAY_A);
    }
    return hash('sha256', serialize($snapshot));
}

$commercialBaseline = cartServiceCommercialSnapshot();
$oldSessionPath = session_save_path();
$sessionPath = sys_get_temp_dir() . '/va-cart-service-' . bin2hex(random_bytes(8));
assertCartService(mkdir($sessionPath, 0700), 'No se creo session.save_path aislado.');
assertCartService(session_status() === PHP_SESSION_NONE, 'La prueba heredo una sesion activa.');
session_save_path($sessionPath);
session_id('');
unset($_COOKIE[session_name()]);

$cartRepository = new CartRepository();
$inventoryRepository = new InventoryRepository();
$productRepository = new ProductRepository();
$storeRepository = new StoreRepository();
$service = new CartService($cartRepository);
$transaction = $wpdb->query('START TRANSACTION');
assertCartService($transaction !== false, 'No se inicio la transaccion.');

try {
    $now = current_time('mysql');
    $token = 'cart-service-' . bin2hex(random_bytes(5));
    $minimarketId = $storeRepository->create([
        'business_name' => $token, 'legal_name' => $token,
        'owner_name' => 'Owner', 'rut' => '1-9',
        'email' => $token . '@example.test', 'phone' => '000',
        'mobile' => null, 'address' => null, 'commune' => null,
        'city' => null, 'region' => null, 'status' => 'active',
        'onboarding_status' => 'complete', 'approved_at' => $now,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $otherMinimarketId = $storeRepository->create([
        'business_name' => $token . '-other', 'legal_name' => $token . '-other',
        'owner_name' => 'Owner', 'rut' => '2-7',
        'email' => $token . '-other@example.test', 'phone' => '000',
        'mobile' => null, 'address' => null, 'commune' => null,
        'city' => null, 'region' => null, 'status' => 'active',
        'onboarding_status' => 'complete', 'approved_at' => $now,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $makeProduct = static function (string $suffix) use ($productRepository, $token, $now): int {
        return $productRepository->create([
            'woo_product_id' => null, 'name' => "{$token} {$suffix}",
            'slug' => "{$token}-{$suffix}", 'sku' => null,
            'description' => null, 'category_id' => null, 'brand_id' => null,
            'unit_id' => null, 'image_id' => null, 'status' => Product::STATUS_ACTIVE,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    };
    $firstProductId = $makeProduct('first');
    $secondProductId = $makeProduct('second');
    $firstInventoryId = $inventoryRepository->create([
        'product_id' => $firstProductId,
        'minimarket_id' => $minimarketId,
        'price' => 1290.50,
        'stock' => 20,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $secondInventoryId = $inventoryRepository->create([
        'product_id' => $secondProductId,
        'minimarket_id' => $minimarketId,
        'price' => 800.0,
        'stock' => 20,
        'status' => 'active',
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

    assertCartServiceSame(0, (new CurrentSector())->id());
    assertCartServiceOutOfSector(fn () =>
        $service->addItem($sessionOwner, $firstInventoryId, 1)
    );

    $prefix = $wpdb->prefix . Config::TABLE_PREFIX;
    $zoneId = (int) $wpdb->get_var(
        "SELECT id FROM {$prefix}service_zones WHERE status='active' ORDER BY id LIMIT 1"
    );
    assertCartService($zoneId > 0, 'No existe una zona activa para el fixture.');
    (new CurrentSector())->set($zoneId);
    assertCartServiceSame($zoneId, (new CurrentSector())->id());
    assertCartServiceOutOfSector(fn () =>
        $service->addItem($sessionOwner, $firstInventoryId, 1)
    );

    assertCartServiceSame(1, $wpdb->insert(
        $prefix . 'store_service_zones',
        ['zone_id' => $zoneId, 'store_id' => $otherMinimarketId]
    ));
    assertCartServiceOutOfSector(fn () =>
        $service->addItem($sessionOwner, $firstInventoryId, 1)
    );
    assertCartServiceSame(1, $wpdb->delete(
        $prefix . 'store_service_zones',
        ['zone_id' => $zoneId, 'store_id' => $otherMinimarketId]
    ));

    assertCartServiceSame(1, $wpdb->insert(
        $prefix . 'store_service_zones',
        ['zone_id' => $zoneId, 'store_id' => $minimarketId]
    ));
    assertCartService((new ServiceZoneRepository())->storeAllowed($zoneId, $minimarketId), 'Vinculo sectorial fixture inactivo.');
    assertCartServiceSame(1, (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}store_service_zones WHERE zone_id=%d AND store_id=%d",
        $zoneId,
        $minimarketId
    )));

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
    assertCartServiceSame('1990.00', $sessionCart[0]['unit_price_snapshot']);

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

} finally {
    $wpdb->query('ROLLBACK');
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }
    session_id('');
    session_save_path($oldSessionPath);
    foreach (glob($sessionPath . '/*') ?: [] as $sessionFile) {
        unlink($sessionFile);
    }
    rmdir($sessionPath);
}

assertCartServiceSame($commercialBaseline, cartServiceCommercialSnapshot());
assertCartServiceSame(0, (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . $wpdb->prefix . Config::TABLE_PREFIX . 'stores WHERE business_name IN (%s,%s)',
    $token,
    $token . '-other'
)));
assertCartService(! is_dir($sessionPath), 'Quedo session.save_path temporal.');

echo "PASS cart-service-test anti_false_pass=4 rollback=pass\n";
ob_end_flush();
