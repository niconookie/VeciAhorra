<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Core\Application;
use VeciAhorra\Database\MigrationManager;
use VeciAhorra\Database\Migrations\CreatePaymentsTables;
use VeciAhorra\Modules\Payments\Requests\PaymentRequest;
use VeciAhorra\Modules\Payments\Routes\PaymentRoutes;
use VeciAhorra\Modules\Inventory\Repositories\InventoryRepository;
use VeciAhorra\Modules\Orders\Services\OrderService;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertPaymentFoundation(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertPaymentFoundationSame(mixed $expected, mixed $actual): void
{
    assertPaymentFoundation(
        $expected === $actual,
        sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        )
    );
}

function paymentRequest(
    string $method,
    string $route,
    ?array $payload = null
): WP_REST_Response {
    $request = new WP_REST_Request($method, $route);

    if ($payload !== null) {
        $request->set_header('content-type', 'application/json');
        $request->set_body(wp_json_encode($payload));
    }

    return rest_do_request($request);
}

function paymentRouteAccepts(
    array $routes,
    string $route,
    string $method
): bool {
    foreach ($routes[$route] ?? [] as $handler) {
        if (($handler['methods'][$method] ?? false) === true) {
            return true;
        }
    }

    return false;
}

function paymentColumns(string $table): array
{
    global $wpdb;

    return array_column(
        $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A),
        null,
        'Field'
    );
}

function paymentIndexes(string $table): array
{
    global $wpdb;

    return array_values(array_unique(array_column(
        $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A),
        'Key_name'
    )));
}

global $wpdb;

$paymentsTable = $wpdb->prefix . Config::TABLE_PREFIX . 'payments';
$paymentOrdersTable = $wpdb->prefix . Config::TABLE_PREFIX . 'payment_orders';
$ordersTable = $wpdb->prefix . Config::TABLE_PREFIX . 'orders';
$reservationsTable = $wpdb->prefix . Config::TABLE_PREFIX . 'reservations';
$inventoryTable = $wpdb->prefix . Config::TABLE_PREFIX . 'inventory';

$migrationsMethod = new ReflectionMethod(MigrationManager::class, 'migrations');
$migrationsMethod->setAccessible(true);
$registered = $migrationsMethod->invoke(null);
assertPaymentFoundationSame(
    1,
    count(array_filter(
        $registered,
        static fn (object $migration): bool =>
            $migration instanceof CreatePaymentsTables
    ))
);
assertPaymentFoundation(
    version_compare(Config::SCHEMA_VERSION, '0.9.0', '>='),
    'SCHEMA_VERSION no activa Payments.'
);

$migration = new CreatePaymentsTables();
$migration->up();
$migration->up();

foreach ([$paymentsTable, $paymentOrdersTable] as $table) {
    assertPaymentFoundationSame(
        $table,
        $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))
    );
    $status = $wpdb->get_row(
        $wpdb->prepare('SHOW TABLE STATUS LIKE %s', $table),
        ARRAY_A
    );
    assertPaymentFoundationSame(
        'innodb',
        strtolower((string) ($status['Engine'] ?? ''))
    );
}

$paymentColumns = paymentColumns($paymentsTable);
foreach (
    [
        'id', 'payment_reference', 'customer_id', 'amount', 'currency',
        'status', 'provider', 'provider_reference', 'expires_at', 'paid_at',
        'created_at', 'updated_at',
    ] as $column
) {
    assertPaymentFoundation(
        isset($paymentColumns[$column]),
        "Falta payments.{$column}."
    );
}
assertPaymentFoundationSame('decimal(10,2)', $paymentColumns['amount']['Type']);
assertPaymentFoundationSame('CLP', $paymentColumns['currency']['Default']);
assertPaymentFoundationSame('pending', $paymentColumns['status']['Default']);
assertPaymentFoundationSame('YES', $paymentColumns['provider']['Null']);

foreach (
    [
        'PRIMARY', 'payments_reference_unique',
        'payments_customer_id_index', 'payments_status_index',
        'payments_provider_reference_index', 'payments_expires_at_index',
    ] as $index
) {
    assertPaymentFoundation(
        in_array($index, paymentIndexes($paymentsTable), true),
        "Falta el indice {$index}."
    );
}

$relationColumns = paymentColumns($paymentOrdersTable);
foreach (['id', 'payment_id', 'order_id', 'created_at'] as $column) {
    assertPaymentFoundation(
        isset($relationColumns[$column]),
        "Falta payment_orders.{$column}."
    );
}
foreach (
    [
        'PRIMARY', 'payment_orders_order_unique',
        'payment_orders_payment_order_unique',
        'payment_orders_payment_id_index',
    ] as $index
) {
    assertPaymentFoundation(
        in_array($index, paymentIndexes($paymentOrdersTable), true),
        "Falta el indice {$index}."
    );
}

$normalized = (new PaymentRequest([
    'customer_id' => '42',
    'amount' => '15000',
    'currency' => 'clp',
    'provider' => null,
    'order_ids' => ['101', 102],
]))->validated();
assertPaymentFoundationSame(42, $normalized['customer_id']);
assertPaymentFoundationSame('15000.00', $normalized['amount']);
assertPaymentFoundationSame('CLP', $normalized['currency']);
assertPaymentFoundationSame(null, $normalized['provider']);
assertPaymentFoundationSame([101, 102], $normalized['order_ids']);

foreach (
    [
        ['amount' => 0],
        ['currency' => 'CL'],
        ['order_ids' => []],
        ['order_ids' => [1, 1]],
    ] as $invalid
) {
    try {
        (new PaymentRequest(array_replace([
            'customer_id' => 1,
            'amount' => 100,
            'currency' => 'CLP',
            'order_ids' => [1],
        ], $invalid)))->validated();
        throw new RuntimeException('Se esperaba validacion fallida.');
    } catch (InvalidArgumentException) {
    }
}

assertPaymentFoundation(
    (new Application())->container()->make(PaymentRoutes::class)
        instanceof PaymentRoutes,
    'Container no resolvio PaymentRoutes.'
);

$routes = rest_get_server()->get_routes();
$collection = '/veciahorra/v1/payments';
$detail = '/veciahorra/v1/payments/(?P<id>\d+)';
assertPaymentFoundation(
    paymentRouteAccepts($routes, $collection, 'GET'),
    'GET /payments no esta registrada.'
);
assertPaymentFoundation(
    paymentRouteAccepts($routes, $collection, 'POST'),
    'POST /payments no esta registrada.'
);
assertPaymentFoundation(
    paymentRouteAccepts($routes, $detail, 'GET'),
    'GET /payments/{id} no esta registrada.'
);

wp_set_current_user(0);
assertPaymentFoundation(
    in_array(paymentRequest('GET', $collection)->get_status(), [401, 403], true),
    'Payments no protege sus rutas.'
);
$administrators = get_users([
    'role' => 'administrator',
    'number' => 1,
    'fields' => 'ids',
]);
assertPaymentFoundation($administrators !== [], 'Falta administrador.');
wp_set_current_user((int) $administrators[0]);

$transaction = $wpdb->query('START TRANSACTION');
assertPaymentFoundation($transaction !== false, 'No se inicio transaccion.');

try {
    $inventoryRepository = new InventoryRepository();
    $orderService = new OrderService();
    $now = current_time('mysql');
    $orderIds = [];

    foreach ([5000.0, 10000.0] as $index => $price) {
        $inventoryId = $inventoryRepository->create([
            'product_id' => 810000000 + $index,
            'minimarket_id' => 820000000 + $index,
            'price' => $price,
            'stock' => 2,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $order = $orderService->create([
            'customer_id' => 77,
            'minimarket_id' => 820000000 + $index,
            'items' => [[
                'product_id' => 810000000 + $index,
                'inventory_id' => $inventoryId,
                'quantity' => 1,
                'unit_price' => $price,
            ]],
        ]);
        $orderIds[] = (int) $order['id'];
    }

    $ordersBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$ordersTable}");
    $reservationsBefore = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$reservationsTable}"
    );
    $stockBefore = (string) $wpdb->get_var(
        "SELECT COALESCE(SUM(stock), 0) FROM {$inventoryTable}"
    );
    $created = paymentRequest('POST', $collection, [
        'customer_id' => 77,
        'amount' => 15000,
        'currency' => 'clp',
        'provider' => null,
        'order_ids' => $orderIds,
    ]);
    $body = $created->get_data();
    assertPaymentFoundationSame(201, $created->get_status());
    assertPaymentFoundationSame(true, $body['success'] ?? null);
    $payment = $body['data'] ?? [];
    $paymentId = (int) ($payment['id'] ?? 0);
    assertPaymentFoundation($paymentId > 0, 'No se creo Payment.');
    assertPaymentFoundation(
        str_starts_with((string) $payment['payment_reference'], 'PAY-'),
        'PaymentService no genero la referencia.'
    );
    assertPaymentFoundationSame('pending', $payment['status']);
    assertPaymentFoundationSame('15000.00', $payment['amount']);
    assertPaymentFoundationSame('CLP', $payment['currency']);
    assertPaymentFoundationSame($orderIds, $payment['order_ids']);

    $listed = paymentRequest('GET', $collection);
    assertPaymentFoundationSame(200, $listed->get_status());
    assertPaymentFoundation(
        in_array(
            $paymentId,
            array_map('intval', array_column(
                $listed->get_data()['data'] ?? [],
                'id'
            )),
            true
        ),
        'GET /payments no listo el pago.'
    );

    $shown = paymentRequest('GET', $collection . '/' . $paymentId);
    assertPaymentFoundationSame(200, $shown->get_status());
    assertPaymentFoundationSame(
        $paymentId,
        (int) ($shown->get_data()['data']['id'] ?? 0)
    );
    assertPaymentFoundationSame(
        404,
        paymentRequest('GET', $collection . '/' . PHP_INT_MAX)->get_status()
    );
    assertPaymentFoundationSame(
        422,
        paymentRequest('POST', $collection, [
            'customer_id' => 1,
            'amount' => 0,
            'order_ids' => [1],
        ])->get_status()
    );

    assertPaymentFoundationSame(
        $ordersBefore,
        (int) $wpdb->get_var("SELECT COUNT(*) FROM {$ordersTable}")
    );
    assertPaymentFoundationSame(
        $reservationsBefore,
        (int) $wpdb->get_var("SELECT COUNT(*) FROM {$reservationsTable}")
    );
    assertPaymentFoundationSame(
        $stockBefore,
        (string) $wpdb->get_var(
            "SELECT COALESCE(SUM(stock), 0) FROM {$inventoryTable}"
        )
    );

    $application = file_get_contents(
        dirname(__DIR__, 2) . '/app/Core/Application.php'
    );
    assertPaymentFoundationSame(
        1,
        substr_count($application, '$paymentRoutes = $this->container->make')
    );
    assertPaymentFoundationSame(
        1,
        substr_count($application, "[\$paymentRoutes, 'register']")
    );

    $moduleFiles = glob(
        dirname(__DIR__, 2) . '/app/Modules/Payments/*/*.php'
    ) ?: [];
    $moduleSource = '';

    foreach ($moduleFiles as $file) {
        if (basename($file) !== 'WebpayPaymentGateway.php') {
            $moduleSource .= (string) file_get_contents($file);
        }
    }
    foreach (
        [
            'InventoryLockService', 'Transbank\\', 'MercadoPago', 'Stripe',
            'webhook', 'commitStock',
        ] as $forbidden
    ) {
        assertPaymentFoundation(
            ! str_contains($moduleSource, $forbidden),
            "Payments contiene integracion fuera de alcance: {$forbidden}."
        );
    }

    echo "PASS payment-foundation-test\n";
} finally {
    $wpdb->query('ROLLBACK');
    wp_set_current_user(0);
}
