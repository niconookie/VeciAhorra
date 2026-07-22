<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Inventory\Repositories\InventoryRepository;
use VeciAhorra\Modules\Products\Domain\ProductReferenceInspection;
use VeciAhorra\Modules\Products\Exceptions\ProductConcurrencyException;
use VeciAhorra\Modules\Products\Exceptions\ProductDeletionException;
use VeciAhorra\Modules\Products\Models\Product;
use VeciAhorra\Modules\Products\Repositories\ProductRepository;
use VeciAhorra\Modules\Products\Services\ProductService;
use VeciAhorra\Modules\Stores\Repositories\StoreRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function deletionSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            "%s\nEsperado: %s\nRecibido: %s",
            $message,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function deletionException(
    callable $operation,
    string $class,
    ?string $code = null
): Throwable {
    try {
        $operation();
    } catch (Throwable $exception) {
        if (! $exception instanceof $class) {
            throw $exception;
        }

        if ($code !== null) {
            deletionSame(
                $code,
                $exception->errorCode(),
                'Codigo de dominio inesperado.'
            );
        }

        return $exception;
    }

    throw new RuntimeException('Se esperaba una excepcion de dominio.');
}

global $wpdb;

$ownToken = bin2hex(random_bytes(6));
$ownNow = current_time('mysql');
$ownRepository = new ProductRepository();
$ownService = new ProductService();
$ownProduct = $ownRepository->create([
    'name' => 'Delete own transaction ' . $ownToken,
    'slug' => 'delete-own-transaction-' . $ownToken,
    'status' => Product::STATUS_DRAFT,
    'created_at' => $ownNow,
    'updated_at' => $ownNow,
]);
$ownService->delete($ownProduct, $ownNow);
deletionSame(null, $ownService->find($ownProduct), 'Transaccion propia no elimino Product.');
deletionSame(0, (int) $wpdb->get_var('SELECT @@in_transaction'), 'Transaccion propia quedo abierta.');

if ($wpdb->query('START TRANSACTION') === false) {
    throw new RuntimeException('No fue posible iniciar la transaccion.');
}

try {
    $prefix = $wpdb->prefix . Config::TABLE_PREFIX;
    $now = '2020-01-01 00:00:00';
    $token = bin2hex(random_bytes(6));
    $products = new ProductRepository();
    $service = new ProductService();
    $sequence = 0;
    $createProduct = function (string $status = Product::STATUS_ACTIVE) use (
        &$sequence,
        $products,
        $now,
        $token
    ): int {
        $sequence++;

        return $products->create([
            'name' => 'Delete product ' . $sequence,
            'slug' => 'delete-product-' . $token . '-' . $sequence,
            'sku' => 'DEL-' . strtoupper($token) . '-' . $sequence,
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    };
    $storeId = (new StoreRepository())->create([
        'business_name' => 'Delete store ' . $token,
        'legal_name' => 'Delete store legal ' . $token,
        'owner_name' => 'Delete owner',
        'rut' => '1-9',
        'email' => $token . '@example.test',
        'phone' => '000000000',
        'status' => 'active',
        'onboarding_status' => 'draft',
        'approved_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $createInventory = function (
        int $productId,
        string $status = 'active'
    ) use ($storeId, $now): int {
        return (new InventoryRepository())->create([
            'product_id' => $productId,
            'minimarket_id' => $storeId,
            'price' => 1000,
            'stock' => 5,
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    };

    $missingId = PHP_INT_MAX;
    deletionException(
        fn () => $service->delete($missingId, $now),
        VeciAhorra\Exceptions\RecordNotFoundException::class
    );

    $deletableId = $createProduct();
    $productsBeforeDelete = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$prefix}products"
    );
    $emptyInspection = $service->inspectReferences($deletableId);
    deletionSame(
        ProductReferenceInspection::DELETABLE,
        $emptyInspection->classification(),
        'Product sin referencias no fue deletable.'
    );
    $deletedInspection = $service->delete($deletableId, $now);
    deletionSame(
        ProductReferenceInspection::DELETABLE,
        $deletedInspection->classification(),
        'Delete no conservo la clasificacion.'
    );
    deletionSame(null, $service->find($deletableId), 'Product no fue eliminado.');
    deletionSame(
        $productsBeforeDelete - 1,
        (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}products"),
        'Delete no afecto exactamente una fila.'
    );

    $staleId = $createProduct();
    deletionException(
        fn () => $products->delete($staleId),
        BadMethodCallException::class
    );
    deletionException(
        fn () => $service->delete($staleId, '2019-01-01 00:00:00'),
        ProductConcurrencyException::class
    );
    deletionSame(true, $service->find($staleId) !== null, 'CAS obsoleto elimino Product.');
    deletionSame(1, (int) $wpdb->get_var('SELECT @@in_transaction'), 'Rollback a savepoint cerro transaccion externa.');

    $activeInventoryProduct = $createProduct();
    $activeInventoryId = $createInventory($activeInventoryProduct);
    $activeInspection = $service->inspectReferences($activeInventoryProduct);
    deletionSame(1, $activeInspection->toArray()['inventory']['active'], 'No conto oferta activa.');
    deletionSame(
        ProductReferenceInspection::RETIRE_REQUIRED,
        $activeInspection->classification(),
        'Inventory activo no exigio retiro.'
    );
    deletionException(
        fn () => $service->delete($activeInventoryProduct, $now),
        ProductDeletionException::class,
        'product_delete_requires_retirement'
    );
    deletionSame(true, (new InventoryRepository())->find($activeInventoryId) !== null, 'Delete borro Inventory.');
    deletionSame(Product::STATUS_ACTIVE, $service->find($activeInventoryProduct)?->status, 'Delete altero lifecycle.');

    $inactiveInventoryProduct = $createProduct(Product::STATUS_INACTIVE);
    $inactiveInventoryId = $createInventory($inactiveInventoryProduct, 'inactive');
    $inactiveInspection = $service->inspectReferences($inactiveInventoryProduct);
    deletionSame(1, $inactiveInspection->toArray()['inventory']['inactive'], 'No conto oferta inactiva.');
    deletionSame(ProductReferenceInspection::RETIRE_REQUIRED, $inactiveInspection->classification(), 'Inventory inactivo permitio delete.');

    deletionSame(1, $wpdb->insert($prefix . 'cart_items', [
        'session_id' => 'residual-' . $token,
        'user_id' => null,
        'inventory_id' => $inactiveInventoryId,
        'product_id' => $inactiveInventoryProduct,
        'minimarket_id' => $storeId,
        'quantity' => 1,
        'unit_price_snapshot' => '1000.00',
        'created_at' => $now,
        'updated_at' => $now,
    ]), 'No creo Cart residual.');
    deletionSame(
        1,
        $service->inspectReferences($inactiveInventoryProduct)->toArray()['cart']['residual'],
        'No conto Cart residual.'
    );

    $cartProduct = $createProduct();
    $cartInventory = $createInventory($cartProduct);
    deletionSame(
        1,
        $wpdb->insert($prefix . 'cart_items', [
            'session_id' => 'delete-' . $token,
            'user_id' => null,
            'inventory_id' => $cartInventory,
            'product_id' => $cartProduct,
            'minimarket_id' => $storeId,
            'quantity' => 1,
            'unit_price_snapshot' => '1000.00',
            'created_at' => $now,
            'updated_at' => $now,
        ]),
        'No creo CartItem.'
    );
    $cartId = (int) $wpdb->insert_id;
    $cartInspection = $service->inspectReferences($cartProduct);
    deletionSame(1, $cartInspection->toArray()['cart']['current_items'], 'No conto Cart vigente.');
    deletionException(
        fn () => $service->delete($cartProduct, $now),
        ProductDeletionException::class,
        'product_delete_requires_retirement'
    );
    deletionSame(1, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}cart_items WHERE id = %d", $cartId)), 'Delete borro CartItem.');

    $activeReservationProduct = $createProduct();
    $activeReservationInventory = $createInventory($activeReservationProduct);
    $reservationData = [
        'order_id' => null,
        'inventory_id' => $activeReservationInventory,
        'product_id' => $activeReservationProduct,
        'minimarket_id' => $storeId,
        'quantity' => 1,
        'status' => 'active',
        'reserved_at' => $now,
        'expires_at' => '2030-01-01 00:00:00',
        'released_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ];
    deletionSame(1, $wpdb->insert($prefix . 'reservations', $reservationData), 'No creo reserva activa.');
    $activeReservationId = (int) $wpdb->insert_id;
    deletionException(
        fn () => $service->delete($activeReservationProduct, $now),
        ProductDeletionException::class,
        'product_delete_operational_references'
    );

    $historicalReservationProduct = $createProduct();
    $historicalReservationInventory = $createInventory($historicalReservationProduct);
    $reservationData['inventory_id'] = $historicalReservationInventory;
    $reservationData['product_id'] = $historicalReservationProduct;
    $reservationData['status'] = 'released';
    $reservationData['released_at'] = $now;
    deletionSame(1, $wpdb->insert($prefix . 'reservations', $reservationData), 'No creo reserva historica.');
    $historicalReservationId = (int) $wpdb->insert_id;
    deletionException(
        fn () => $service->delete($historicalReservationProduct, $now),
        ProductDeletionException::class,
        'product_delete_operational_references'
    );
    deletionSame(2, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}reservations WHERE id IN ({$activeReservationId}, {$historicalReservationId})"), 'Delete borro Reservation.');

    deletionSame(1, $wpdb->insert($prefix . 'cart_items', [
        'session_id' => 'cart-reservation-' . $token,
        'user_id' => null,
        'inventory_id' => $activeReservationInventory,
        'product_id' => $activeReservationProduct,
        'minimarket_id' => $storeId,
        'quantity' => 1,
        'unit_price_snapshot' => '1000.00',
        'created_at' => $now,
        'updated_at' => $now,
    ]), 'No creo combinacion Cart Reservation.');
    deletionSame(
        ProductReferenceInspection::DELETION_FORBIDDEN,
        $service->inspectReferences($activeReservationProduct)->classification(),
        'Cart oculto Reservation.'
    );

    $orderProduct = $createProduct();
    $orderInventory = $createInventory($orderProduct);
    deletionSame(1, $wpdb->insert($prefix . 'orders', [
        'customer_id' => 1,
        'minimarket_id' => $storeId,
        'total' => '1000.00',
        'status' => 'completed',
        'reservation_expires_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]), 'No creo Order.');
    $orderId = (int) $wpdb->insert_id;
    deletionSame(1, $wpdb->insert($prefix . 'order_items', [
        'order_id' => $orderId,
        'product_id' => $orderProduct,
        'inventory_id' => $orderInventory,
        'quantity' => 1,
        'unit_price' => '1000.00',
        'subtotal' => '1000.00',
        'created_at' => $now,
        'updated_at' => $now,
    ]), 'No creo OrderItem.');
    $orderItemId = (int) $wpdb->insert_id;
    $orderInspection = $service->inspectReferences($orderProduct);
    deletionSame(ProductReferenceInspection::DELETION_FORBIDDEN, $orderInspection->classification(), 'OrderItem no prohibio delete.');
    deletionException(
        fn () => $service->delete($orderProduct, $now),
        ProductDeletionException::class,
        'product_delete_historical_references'
    );
    deletionSame(1, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}order_items WHERE id = %d", $orderItemId)), 'Delete borro OrderItem.');
    deletionSame(true, (new InventoryRepository())->find($orderInventory) !== null, 'Delete borro Inventory combinado.');

    deletionSame(1, $wpdb->update(
        $prefix . 'inventory',
        ['status' => 'broken'],
        ['id' => $orderInventory]
    ), 'No preparo inconsistencia combinada.');
    deletionSame(
        ProductReferenceInspection::DELETION_FORBIDDEN,
        $service->inspectReferences($orderProduct)->classification(),
        'Inconsistencia secundaria oculto OrderItem.'
    );

    $raceProduct = $createProduct();
    deletionSame(ProductReferenceInspection::DELETABLE, $service->inspectReferences($raceProduct)->classification(), 'Inspeccion inicial no fue deletable.');
    $raceInventory = $createInventory($raceProduct);
    deletionException(
        fn () => $service->delete($raceProduct, $now),
        ProductDeletionException::class,
        'product_delete_requires_retirement'
    );
    deletionSame(true, $service->find($raceProduct) !== null, 'Carrera elimino Product.');
    deletionSame(true, (new InventoryRepository())->find($raceInventory) !== null, 'Carrera elimino referencia.');

    $inconsistentProduct = $createProduct();
    deletionSame(1, $wpdb->insert($prefix . 'inventory', [
        'product_id' => $inconsistentProduct,
        'minimarket_id' => $storeId,
        'price' => '1000.00',
        'stock' => 1,
        'status' => 'broken',
        'created_at' => $now,
        'updated_at' => $now,
    ]), 'No creo referencia inconsistente.');
    $inconsistent = $service->inspectReferences($inconsistentProduct);
    deletionSame(ProductReferenceInspection::INCONSISTENT, $inconsistent->classification(), 'No clasifico inconsistencia.');
    deletionException(
        fn () => $service->delete($inconsistentProduct, $now),
        ProductDeletionException::class,
        'product_reference_inconsistency'
    );

    $missingStoreProduct = $createProduct();
    deletionSame(1, $wpdb->insert($prefix . 'inventory', [
        'product_id' => $missingStoreProduct,
        'minimarket_id' => PHP_INT_MAX,
        'price' => '1000.00',
        'stock' => 1,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]), 'No creo Inventory sin Store.');
    deletionSame(
        ProductReferenceInspection::INCONSISTENT,
        $service->inspectReferences($missingStoreProduct)->classification(),
        'Inventory sin Store no fue inconsistente.'
    );

    $administratorIds = get_users([
        'role' => 'administrator',
        'number' => 1,
        'fields' => 'ids',
    ]);
    deletionSame(false, $administratorIds === [], 'Se requiere administrador para REST.');
    $restProduct = $createProduct();
    $route = '/veciahorra/v1/products/' . $restProduct;
    wp_set_current_user(0);
    $denied = new WP_REST_Request('DELETE', $route);
    $denied->set_header('content-type', 'application/json');
    $denied->set_body((string) wp_json_encode(['expected_updated_at' => $now]));
    deletionSame(true, in_array(rest_do_request($denied)->get_status(), [401, 403], true), 'DELETE permitio acceso publico.');

    wp_set_current_user((int) $administratorIds[0]);
    $missingVersionProduct = $createProduct();
    $missingVersion = new WP_REST_Request('DELETE', '/veciahorra/v1/products/' . $missingVersionProduct);
    $missingVersion->set_header('content-type', 'application/json');
    $missingVersion->set_body('{}');
    deletionSame(422, rest_do_request($missingVersion)->get_status(), 'DELETE acepto version ausente.');
    $invalidVersion = new WP_REST_Request('DELETE', '/veciahorra/v1/products/' . $missingVersionProduct);
    $invalidVersion->set_header('content-type', 'application/json');
    $invalidVersion->set_body((string) wp_json_encode(['expected_updated_at' => 'invalid']));
    deletionSame(422, rest_do_request($invalidVersion)->get_status(), 'DELETE acepto version invalida.');

    $allowed = new WP_REST_Request('DELETE', $route);
    $allowed->set_header('content-type', 'application/json');
    $allowed->set_body((string) wp_json_encode(['expected_updated_at' => $now]));
    $allowedResponse = rest_do_request($allowed);
    deletionSame(200, $allowedResponse->get_status(), 'DELETE administrativo fallo.');
    deletionSame('private, no-store', $allowedResponse->get_headers()['Cache-Control'] ?? null, 'DELETE no marco no-store.');
    deletionSame(null, $service->find($restProduct), 'DELETE REST no elimino Product.');

    echo "PASS product-reference-deletion-test\n";
} finally {
    wp_set_current_user(0);
    $wpdb->query('ROLLBACK');
}
