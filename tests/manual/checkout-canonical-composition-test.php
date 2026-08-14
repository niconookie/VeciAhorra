<?php

declare(strict_types=1);

use VeciAhorra\Core\Application;
use VeciAhorra\Core\Config;
use VeciAhorra\Exceptions\ConflictException;
use VeciAhorra\Modules\Cart\Repository\CartRepository;
use VeciAhorra\Modules\Cart\Service\CartService;
use VeciAhorra\Modules\Checkout\Controller\CheckoutController;
use VeciAhorra\Modules\Checkout\Routes\CheckoutRoutes;
use VeciAhorra\Modules\Checkout\Service\CheckoutService;
use VeciAhorra\Modules\Checkout\Service\CheckoutValidationService;
use VeciAhorra\Modules\Inventory\Repositories\InventoryRepository;
use VeciAhorra\Modules\Orders\Repositories\OrderRepository;
use VeciAhorra\Modules\Orders\Services\OrderService;
use VeciAhorra\Modules\Products\Models\Product;
use VeciAhorra\Modules\Products\Repositories\ProductRepository;
use VeciAhorra\Modules\Reservations\Service\ReservationService;
use VeciAhorra\Modules\Sectorization\CurrentSector;
use VeciAhorra\Modules\Sectorization\ServiceZoneRepository;
use VeciAhorra\Modules\Stores\Repositories\StoreRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

const CHECKOUT_CANONICAL_BASELINE = '79417aea5d1e1724500fabdf2bfb48ef5ce9bea1';
const CHECKOUT_CANONICAL_CERTIFICATION = 'e32e1466c24c0f3df281fd92873e028aa4008b8e';
const CHECKOUT_CANONICAL_FILE = 'tests/manual/checkout-canonical-composition-test.php';
const CHECKOUT_CANONICAL_FAILURE = 'CHECKOUT_CANONICAL_INDUCED_LATE_ROLLBACK';

/** @var list<string> */
$checkoutCanonicalPassed = [];

function canonicalAssert(bool $condition, string $code, string $message): void
{
    global $checkoutCanonicalPassed;
    if (! $condition) {
        throw new RuntimeException("{$code}: {$message}");
    }
    if (! in_array($code, $checkoutCanonicalPassed, true)) {
        $checkoutCanonicalPassed[] = $code;
        echo "PASS {$code} {$message}\n";
    }
}

function canonicalSame(mixed $expected, mixed $actual, string $code, string $message): void
{
    canonicalAssert(
        $expected === $actual,
        $code,
        $message . '; expected=' . var_export($expected, true)
            . '; actual=' . var_export($actual, true)
    );
}

/** @param list<string> $arguments */
function canonicalGit(array $arguments): string
{
    $root = dirname(__DIR__, 2);
    $command = ['git', '-C', $root, ...$arguments];
    $pipes = [];
    $process = proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Git command=' . implode(' ', $arguments) . ' no pudo iniciarse.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $logical = implode(' ', $arguments);
    if ($exitCode !== 0) {
        $safeError = substr(trim(str_replace(["\r\n", "\r"], "\n", (string) $stderr)), 0, 500);
        throw new RuntimeException("Git command={$logical}; exit={$exitCode}; stderr={$safeError}");
    }
    return trim(str_replace(["\r\n", "\r"], "\n", (string) $stdout));
}

function canonicalGitRegression(): void
{
    canonicalSame(CHECKOUT_CANONICAL_CERTIFICATION, canonicalGit(['rev-parse', CHECKOUT_CANONICAL_CERTIFICATION]), 'C01', 'Git exitoso con stdout');
    echo "PASS GIT_NONEMPTY_OUTPUT\n";
    canonicalSame('', canonicalGit(['diff', '--name-only', 'HEAD', '--', 'app/Core/Config.php']), 'C02', 'Git exitoso con stdout vacio');
    echo "PASS GIT_EMPTY_OUTPUT\n";
    try {
        canonicalGit(['rev-parse', '--verify', 'refs/heads/checkout-canonical-invalid-ref']);
        throw new RuntimeException('El comando Git invalido fue aceptado.');
    } catch (RuntimeException $exception) {
        canonicalAssert(str_contains($exception->getMessage(), 'rev-parse --verify')
            && str_contains($exception->getMessage(), 'exit=')
            && str_contains($exception->getMessage(), 'stderr='), 'C03', 'Git fallido conserva diagnostico');
    }
    echo "PASS GIT_NONZERO_EXIT\n";
}

function canonicalGitGuard(): void
{
    $head = canonicalGit(['rev-parse', 'HEAD']);
    $staged = array_values(array_filter(explode("\n", canonicalGit(['diff', '--cached', '--name-only']))));
    $tracked = canonicalGit(['status', '--short', '--untracked-files=no']);
    if ($head === CHECKOUT_CANONICAL_CERTIFICATION) {
        $clean = $staged === [] && $tracked === '';
        $precommit = $staged === [CHECKOUT_CANONICAL_FILE]
            && $tracked === 'M  ' . CHECKOUT_CANONICAL_FILE;
        canonicalAssert($clean || $precommit, 'C01', 'guardia Git del commit publicado o correccion precommit');
        canonicalAssert($clean || $tracked === 'M  ' . CHECKOUT_CANONICAL_FILE, 'C02', 'sin delta rastreado ajeno');
        return;
    }
    canonicalSame(CHECKOUT_CANONICAL_CERTIFICATION, canonicalGit(['rev-parse', 'HEAD^']), 'C01', 'parent correctivo postcommit');
    canonicalSame('', $tracked, 'C02', 'working tree postcommit limpio');
    canonicalSame('', canonicalGit(['diff', '--cached', '--name-only']), 'C03', 'staging postcommit vacio');
    canonicalSame(CHECKOUT_CANONICAL_FILE, canonicalGit(['diff-tree', '--no-commit-id', '--name-only', '-r', 'HEAD']), 'C04', 'commit limitado al harness');
}

function canonicalProperty(object $object, string $name): object
{
    $property = (new ReflectionClass($object))->getProperty($name);
    $value = $property->getValue($object);
    if (! is_object($value)) {
        throw new RuntimeException("La propiedad {$name} no contiene un objeto.");
    }
    return $value;
}

/** @return array{0: CheckoutRoutes, 1: CheckoutService, 2: array<string,object>} */
function canonicalGraph(Application $application): array
{
    $routes = $application->container()->make(CheckoutRoutes::class);
    canonicalAssert($routes instanceof CheckoutRoutes, 'C08', 'Application resuelve CheckoutRoutes');
    $controller = canonicalProperty($routes, 'controller');
    $service = canonicalProperty($controller, 'service');
    $validation = canonicalProperty($service, 'validationService');
    $reservations = canonicalProperty($service, 'reservationService');
    $orders = canonicalProperty($service, 'orderService');
    $repository = canonicalProperty($service, 'orderRepository');
    canonicalAssert($controller instanceof CheckoutController, 'C09', 'CheckoutRoutes -> CheckoutController');
    canonicalAssert($service instanceof CheckoutService, 'C10', 'CheckoutController -> CheckoutService');
    canonicalAssert($validation instanceof CheckoutValidationService, 'C11', 'CheckoutService -> ValidationService');
    canonicalAssert($reservations instanceof ReservationService, 'C12', 'CheckoutService -> ReservationService');
    canonicalAssert($orders instanceof OrderService, 'C13', 'CheckoutService -> OrderService');
    canonicalAssert($repository instanceof OrderRepository, 'C14', 'CheckoutService -> OrderRepository');
    return [$routes, $service, compact('controller', 'validation', 'reservations', 'orders', 'repository')];
}

final class CheckoutLateRollbackOrderRepository extends OrderRepository
{
    public function __construct(private readonly Closure $observe)
    {
        parent::__construct();
    }

    public function findManyForUpdate(array $ids): array
    {
        $orders = parent::findManyForUpdate($ids);
        ($this->observe)($ids, $orders);
        throw new RuntimeException(CHECKOUT_CANONICAL_FAILURE);
    }
}

/** @return list<array<string,mixed>> */
function canonicalRows(string $table, string $where, mixed ...$values): array
{
    global $wpdb;
    $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY id";
    return $wpdb->get_results($values === [] ? $sql : $wpdb->prepare($sql, ...$values), ARRAY_A);
}

function canonicalCount(string $table, string $where = '1=1', mixed ...$values): int
{
    global $wpdb;
    $sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
    return (int) $wpdb->get_var($values === [] ? $sql : $wpdb->prepare($sql, ...$values));
}

/** @return array<string,mixed> */
function canonicalFixture(string $token): array
{
    global $wpdb;
    $now = current_time('mysql');
    $userId = wp_insert_user([
        'user_login' => 'va_checkout_' . substr($token, -20),
        'user_pass' => bin2hex(random_bytes(18)),
        'user_email' => $token . '@example.test',
        'role' => 'customer',
    ]);
    if (is_wp_error($userId)) {
        throw new RuntimeException($userId->get_error_message());
    }
    $stores = new StoreRepository();
    $storeIds = [];
    foreach ([1, 2] as $index) {
        $storeIds[] = $stores->create([
            'business_name' => "Canonical store {$index} {$token}",
            'legal_name' => "Canonical legal {$index} {$token}",
            'owner_name' => 'Canonical Owner', 'rut' => "{$index}-9",
            'email' => "store{$index}-{$token}@example.test", 'phone' => '000000000',
            'mobile' => null, 'address' => 'Local fixture', 'commune' => 'Santiago',
            'city' => 'Santiago', 'region' => 'RM', 'status' => 'active',
            'onboarding_status' => 'complete', 'approved_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
    $zoneId = (new ServiceZoneRepository())->save([
        'commune' => 'Santiago', 'name' => "Canonical {$token}",
        'status' => 'active', 'stores' => $storeIds,
    ], (int) $userId);
    update_user_meta((int) $userId, '_veciahorra_service_zone_id', $zoneId);
    wp_set_current_user((int) $userId);
    canonicalSame($zoneId, (new CurrentSector())->id(), 'C06', 'sector fixture confirmado');

    $products = new ProductRepository();
    $inventory = new InventoryRepository();
    $cart = new CartService(new CartRepository());
    $productIds = $inventoryIds = $cartIds = [];
    foreach ([5000, 4500] as $index => $price) {
        $productIds[] = $productId = $products->create([
            'woo_product_id' => null, 'name' => 'Canonical product ' . ($index + 1),
            'slug' => $token . '-' . ($index + 1), 'sku' => null, 'description' => $token,
            'category_id' => null, 'brand_id' => null, 'unit_id' => null,
            'image_id' => null, 'status' => Product::STATUS_ACTIVE,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $inventoryIds[] = $inventoryId = $inventory->create([
            'product_id' => $productId, 'minimarket_id' => $storeIds[$index],
            'price' => (float) $price, 'stock' => 5, 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $cartIds[] = $cart->addItem(['user_id' => (int) $userId], $inventoryId, 1)['id'];
    }
    return compact('token', 'userId', 'zoneId', 'storeIds', 'productIds', 'inventoryIds', 'cartIds');
}

/** @return array<string,mixed> */
function canonicalSnapshot(array $fixture, array $tables): array
{
    global $wpdb;
    $ids = implode(',', array_map('intval', $fixture['inventoryIds']));
    return [
        'stock' => $wpdb->get_results("SELECT id,stock FROM {$tables['inventory']} WHERE id IN ({$ids}) ORDER BY id", ARRAY_A),
        'cart' => canonicalRows($tables['cart_items'], 'user_id=%d', $fixture['userId']),
        'reservations' => canonicalCount($tables['reservations'], 'product_id IN (' . implode(',', array_map('intval', $fixture['productIds'])) . ')'),
        'orders' => canonicalCount($tables['orders'], 'customer_id=%d', $fixture['userId']),
        'order_items' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['order_items']} oi JOIN {$tables['orders']} o ON o.id=oi.order_id WHERE o.customer_id=" . (int) $fixture['userId']),
        'checkouts' => canonicalCount($tables['checkouts'], 'user_id=%d', $fixture['userId']),
        'checkout_orders' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['checkout_orders']} co JOIN {$tables['checkouts']} c ON c.id=co.checkout_id WHERE c.user_id=" . (int) $fixture['userId']),
        'payment_sessions' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['payment_sessions']} ps JOIN {$tables['checkouts']} c ON c.id=ps.checkout_id WHERE c.user_id=" . (int) $fixture['userId']),
    ];
}

function canonicalCleanup(array $fixture, array $tables, array $stockBefore): bool
{
    global $wpdb;
    $userId = (int) ($fixture['userId'] ?? 0);
    $orderIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$tables['orders']} WHERE customer_id=%d", $userId));
    $checkoutIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$tables['checkouts']} WHERE user_id=%d", $userId));
    foreach ($checkoutIds as $id) $wpdb->delete($tables['payment_sessions'], ['checkout_id' => (int) $id]);
    foreach ($checkoutIds as $id) $wpdb->delete($tables['checkout_orders'], ['checkout_id' => (int) $id]);
    foreach ($checkoutIds as $id) $wpdb->delete($tables['checkouts'], ['id' => (int) $id]);
    foreach ($fixture['productIds'] ?? [] as $id) $wpdb->delete($tables['reservations'], ['product_id' => (int) $id]);
    foreach ($orderIds as $id) $wpdb->delete($tables['order_items'], ['order_id' => (int) $id]);
    foreach ($orderIds as $id) $wpdb->delete($tables['orders'], ['id' => (int) $id]);
    $wpdb->delete($tables['cart_items'], ['user_id' => $userId]);
    $repaired = false;
    foreach ($stockBefore as $row) {
        $current = $wpdb->get_var($wpdb->prepare("SELECT stock FROM {$tables['inventory']} WHERE id=%d", $row['id']));
        if ($current !== null && (string) $current !== (string) $row['stock']) {
            $wpdb->update($tables['inventory'], ['stock' => (int) $row['stock']], ['id' => (int) $row['id']]);
            $repaired = true;
        }
    }
    foreach ($fixture['storeIds'] ?? [] as $id) $wpdb->delete($tables['store_service_zones'], ['store_id' => (int) $id]);
    foreach ($fixture['inventoryIds'] ?? [] as $id) $wpdb->delete($tables['inventory'], ['id' => (int) $id]);
    foreach ($fixture['productIds'] ?? [] as $id) $wpdb->delete($tables['products'], ['id' => (int) $id]);
    foreach ($fixture['storeIds'] ?? [] as $id) $wpdb->delete($tables['stores'], ['id' => (int) $id]);
    if (isset($fixture['zoneId'])) $wpdb->delete($tables['service_zones'], ['id' => (int) $fixture['zoneId']]);
    if ($userId > 0) {
        delete_user_meta($userId, '_veciahorra_service_zone_id');
        wp_delete_user($userId);
    }
    clean_user_cache($userId);
    return $repaired;
}

global $wpdb;
$originalUser = get_current_user_id();
$originalServer = $_SERVER;
$fixture = [];
$rollbackStock = [];
$repaired = false;
$prefix = $wpdb->prefix . Config::TABLE_PREFIX;
$suffixes = ['inventory','reservations','orders','order_items','cart_items','checkouts','checkout_orders','payment_sessions','store_service_zones','products','stores','service_zones'];
$tables = array_combine($suffixes, array_map(static fn (string $s): string => $prefix . $s, $suffixes));

try {
    canonicalGitRegression();
    canonicalGitGuard();
    canonicalSame('main', canonicalGit(['branch', '--show-current']), 'C03', 'rama main');
    canonicalSame('0.28.0', Config::SCHEMA_VERSION, 'C04', 'schema esperado');
    canonicalAssert(strtolower((string) DB_HOST) === 'localhost' && str_contains(strtolower(home_url('/')), 'localhost'), 'C05', 'entorno WordPress localhost');
    foreach (['inventory','reservations','orders','order_items','cart_items','checkouts','checkout_orders','payment_sessions'] as $suffix) {
        $engine = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $tables[$suffix]));
        canonicalSame('InnoDB', $engine, 'C07', 'tablas transaccionales InnoDB');
    }

    $fixture = canonicalFixture('checkout-canonical-' . bin2hex(random_bytes(12)));
    $initial = canonicalSnapshot($fixture, $tables);
    [, $service, $graph] = canonicalGraph(new Application());
    canonicalAssert(canonicalProperty($graph['orders'], 'repository') !== $graph['repository'], 'C15', 'repositorios canonicos independientes por autowiring');
    $delivery = ['recipient_name'=>'Cliente Canonico','contact_phone'=>'+56955550101','address_line1'=>'Calle Canonica 123','commune'=>'Santiago','reference'=>'Puerta azul','notes'=>'Sin contacto externo'];
    $payload = ['user_id'=>(int)$fixture['userId'],'fulfillment_method'=>'delivery','delivery'=>$delivery,'idempotency_key'=>'canonical-' . bin2hex(random_bytes(12))];
    $validation = $service->validate($payload);
    canonicalAssert($validation['valid'] === true, 'C16', 'validate valido');
    canonicalSame(2, count($validation['items']), 'C17', 'dos items validos');
    canonicalSame('9500.00', $validation['summary']['total'], 'C18', 'total 9500 CLP');
    canonicalSame(2, count(array_unique(array_column($validation['items'], 'minimarket_id'))), 'C19', 'dos minimarkets permitidos');
    $result = $service->initialize($payload);
    canonicalAssert($result['valid'] === true, 'C20', 'initialize exitoso');
    canonicalSame(2, count($result['reservations']), 'C21', 'dos reservas activas');
    foreach ($result['reservations'] as $reservation) {
        canonicalSame(900, strtotime($reservation['expires_at']) - strtotime($reservation['reserved_at']), 'C22', 'expiracion exacta 15 minutos');
    }
    $orderIds = array_map('intval', array_column($result['orders'], 'id'));
    $orderStores = array_map('intval', array_column($result['orders'], 'minimarket_id'));
    sort($orderStores, SORT_NUMERIC);
    $expectedStores = $fixture['storeIds'];
    sort($expectedStores, SORT_NUMERIC);
    canonicalAssert(count($orderIds) === 2 && $orderStores === $expectedStores
        && count(array_filter($result['orders'], static fn (array $order): bool => $order['status'] === 'reserved')) === 2,
        'C23', 'dos Orders reserved, una por minimarket');
    canonicalAssert(count(array_filter($result['reservations'], static fn (array $reservation): bool =>
        $reservation['status'] === 'active' && in_array((int) $reservation['order_id'], $orderIds, true))) === 2,
        'C23', 'reservas activas vinculadas a sus Orders');
    $items = [];
    foreach ($orderIds as $id) $items = [...$items, ...canonicalRows($tables['order_items'], 'order_id=%d', $id)];
    $itemPrices = array_values(array_unique(array_column($items, 'unit_price')));
    sort($itemPrices, SORT_STRING);
    canonicalSame(['4500.00','5000.00'], $itemPrices, 'C24', 'dos order items con snapshots');
    canonicalSame([], canonicalRows($tables['cart_items'], 'user_id=%d', $fixture['userId']), 'C25', 'carrito vacio');
    $checkout = canonicalRows($tables['checkouts'], 'user_id=%d', $fixture['userId']);
    canonicalAssert(count($checkout) === 1 && $checkout[0]['status'] === 'pending' && (int)$checkout[0]['user_id'] === $fixture['userId'] && $checkout[0]['total_amount'] === '9500.00', 'C26', 'Checkout pending, owner y total correctos');
    canonicalSame($delivery, ['recipient_name'=>$checkout[0]['delivery_recipient_name'],'contact_phone'=>$checkout[0]['delivery_contact_phone'],'address_line1'=>$checkout[0]['delivery_address_line1'],'commune'=>$checkout[0]['delivery_commune'],'reference'=>$checkout[0]['delivery_reference'],'notes'=>$checkout[0]['delivery_notes']], 'C27', 'delivery snapshot exacto');
    canonicalSame(2, canonicalCount($tables['checkout_orders'], 'checkout_id=%d', $checkout[0]['id']), 'C28', 'dos checkout_orders');
    canonicalSame(0, canonicalCount($tables['payment_sessions'], 'checkout_id=%d', $checkout[0]['id']), 'C29', 'ninguna PaymentSession automatica');

    $afterSuccess = canonicalSnapshot($fixture, $tables);
    $replay = $service->initialize($payload);
    canonicalAssert(($replay['reused'] ?? false) === true && $replay['checkout']['checkout_id'] === $result['checkout']['checkout_id'], 'C20', 'replay reutiliza Checkout');
    canonicalSame($afterSuccess, canonicalSnapshot($fixture, $tables), 'C21', 'replay sin cambios persistentes');
    try {
        $service->initialize([...$payload, 'delivery' => [...$delivery, 'notes' => 'distinto']]);
        throw new RuntimeException('No se produjo idempotency_conflict.');
    } catch (ConflictException $exception) {
        canonicalSame('idempotency_conflict', $exception->errorCode(), 'C22', 'conflicto idempotente esperado');
    }
    canonicalSame($afterSuccess, canonicalSnapshot($fixture, $tables), 'C23', 'conflicto sin cambios persistentes');

    $cart = new CartService(new CartRepository());
    foreach ($fixture['inventoryIds'] as $id) $cart->addItem(['user_id'=>(int)$fixture['userId']], (int)$id, 1);
    $rollbackBefore = canonicalSnapshot($fixture, $tables);
    $rollbackStock = $rollbackBefore['stock'];
    $rollbackKey = 'rollback-' . bin2hex(random_bytes(12));
    $observed = false;
    $observer = static function (array $ids, array $orders) use (&$observed, $fixture, $tables, $rollbackBefore, $rollbackKey, $wpdb): void {
        canonicalSame(1, (int)$wpdb->get_var('SELECT @@in_transaction'), 'C24', 'transaccion activa dentro del observer');
        canonicalSame(2, count($ids), 'C25', 'dos Order IDs fixture observados');
        $expectedStock = array_map(
            static fn (array $row): int => (int) $row['stock'] - 1,
            $rollbackBefore['stock']
        );
        canonicalSame($expectedStock, array_map('intval', array_column(canonicalSnapshot($fixture, $tables)['stock'], 'stock')), 'C26', 'stock reducido dentro de transaccion');
        $reservations = canonicalRows($tables['reservations'], 'product_id IN (' . implode(',', array_map('intval', $fixture['productIds'])) . ')');
        canonicalAssert(count($reservations) === $rollbackBefore['reservations'] + 2 && count(array_filter($reservations, static fn(array $r): bool => $r['status']==='active' && (int)$r['order_id']>0)) >= 2, 'C27', 'reservas activas vinculadas dentro de transaccion');
        $observedItems = [];
        foreach ($ids as $id) $observedItems = [...$observedItems, ...canonicalRows($tables['order_items'], 'order_id=%d', $id)];
        $observedPrices = array_column($observedItems, 'unit_price');
        sort($observedPrices, SORT_STRING);
        canonicalAssert(count($orders) === 2 && count(array_filter($orders, static fn(array $o): bool => $o['status']==='reserved'))===2
            && $observedPrices === ['4500.00', '5000.00'], 'C28', 'Orders reserved y order_items con snapshots observados');
        canonicalSame([], canonicalRows($tables['cart_items'], 'user_id=%d', $fixture['userId']), 'C29', 'carrito vacio antes del fallo tardio');
        canonicalSame($rollbackBefore['checkouts'], canonicalCount($tables['checkouts'], 'user_id=%d', $fixture['userId']), 'C29', 'Checkout nuevo aun no alcanzado');
        canonicalSame(0, canonicalCount($tables['checkouts'], 'user_id=%d AND idempotency_key=%s', $fixture['userId'], $rollbackKey), 'C29', 'cero Checkout para nueva clave');
        canonicalSame($rollbackBefore['checkout_orders'], canonicalSnapshot($fixture, $tables)['checkout_orders'], 'C29', 'checkout_orders nuevos aun no alcanzados');
        canonicalSame($rollbackBefore['payment_sessions'], canonicalSnapshot($fixture, $tables)['payment_sessions'], 'C29', 'PaymentSession nueva no creada');
        $observed = true;
    };
    $double = new CheckoutLateRollbackOrderRepository($observer);
    $lateApplication = new Application();
    $lateApplication->container()->bind(OrderRepository::class, static fn(): OrderRepository => $double);
    [, $lateService, $lateGraph] = canonicalGraph($lateApplication);
    canonicalAssert($lateGraph['repository'] === $double && canonicalProperty($lateGraph['orders'], 'repository') === $double, 'C15', 'misma instancia doble en CheckoutService y OrderService');
    try {
        $lateService->initialize([...$payload, 'idempotency_key'=>$rollbackKey]);
        throw new RuntimeException('No se produjo el fallo tardio inducido.');
    } catch (RuntimeException $exception) {
        canonicalSame(CHECKOUT_CANONICAL_FAILURE, $exception->getMessage(), 'C28', 'excepcion inducida exclusiva');
    }
    canonicalAssert($observed, 'C29', 'observer tardio ejecutado');
    canonicalSame(0, (int)$wpdb->get_var('SELECT @@in_transaction'), 'C24', 'transaccion cerrada tras rollback');
    canonicalSame($rollbackBefore, canonicalSnapshot($fixture, $tables), 'C29', 'rollback restaura inventario, reservas, Orders, items y carrito');
    echo "ROLLBACK_OBSERVED: inventory,reservations,orders,order_items,cart_items\n";
    echo "NOT_REACHED_BEFORE_FAILURE: checkouts,checkout_orders,payment_sessions\n";
} finally {
    wp_set_current_user($originalUser);
    $_SERVER = $originalServer;
    if ($fixture !== []) {
        $cleanupStock = $rollbackStock !== [] ? $rollbackStock : ($initial['stock'] ?? []);
        $repaired = canonicalCleanup($fixture, $tables, $cleanupStock) || $repaired;
        $residue = 0;
        foreach (['inventoryIds','productIds','storeIds'] as $key) {
            foreach ($fixture[$key] ?? [] as $id) {
                $table = $key === 'inventoryIds' ? $tables['inventory'] : ($key === 'productIds' ? $tables['products'] : $tables['stores']);
                $residue += canonicalCount($table, 'id=%d', $id);
            }
        }
        $residue += canonicalCount($tables['service_zones'], 'id=%d', $fixture['zoneId']);
        $residue += get_userdata((int)$fixture['userId']) === false ? 0 : 1;
        canonicalSame(0, $residue, 'C29', 'cleanup con residuo cero');
    }
}

canonicalAssert(! $repaired, 'C29', 'cleanup no reparo stock defensivamente');
sort($checkoutCanonicalPassed);
canonicalSame(array_map(static fn(int $i): string => sprintf('C%02d', $i), range(1,29)), $checkoutCanonicalPassed, 'C29', 'codigos C01-C29 publicados');
echo "PASS checkout-canonical-composition-test\n";
