<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Core\Application;
use VeciAhorra\Modules\Payments\Gateway\DummyPaymentGateway;
use VeciAhorra\Modules\Payments\Gateway\PaymentGatewayInterface;
use VeciAhorra\Modules\Payments\Models\Payment;
use VeciAhorra\Modules\Payments\Repository\PaymentRepository;
use VeciAhorra\Modules\Payments\Service\PaymentService;
use VeciAhorra\Modules\Payments\Service\PaymentSessionService;
use VeciAhorra\Modules\Inventory\Repositories\InventoryRepository;
use VeciAhorra\Modules\Orders\Services\OrderService;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertPaymentSession(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertPaymentSessionSame(mixed $expected, mixed $actual): void
{
    assertPaymentSession(
        $expected === $actual,
        sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        )
    );
}

function paymentSessionRequest(string $route): WP_REST_Response
{
    return rest_do_request(new WP_REST_Request('POST', $route));
}

function paymentSessionRouteAccepts(array $routes, string $route): bool
{
    foreach ($routes[$route] ?? [] as $handler) {
        if (($handler['methods']['POST'] ?? false) === true) {
            return true;
        }
    }

    return false;
}

global $wpdb;

$paymentsTable = $wpdb->prefix . Config::TABLE_PREFIX . 'payments';
$ordersTable = $wpdb->prefix . Config::TABLE_PREFIX . 'orders';
$reservationsTable = $wpdb->prefix . Config::TABLE_PREFIX . 'reservations';
$inventoryTable = $wpdb->prefix . Config::TABLE_PREFIX . 'inventory';
$repository = new PaymentRepository();
$paymentService = new PaymentService($repository);
$sessionService = (new Application())->container()->make(
    PaymentSessionService::class
);
assertPaymentSession(
    $sessionService instanceof PaymentSessionService,
    'Application no resolvio PaymentSessionService.'
);
assertPaymentSession(
    (new DummyPaymentGateway()) instanceof PaymentGatewayInterface,
    'DummyPaymentGateway no implementa el contrato.'
);

$routePattern = '/veciahorra/v1/payments/(?P<id>\d+)/session';
assertPaymentSession(
    paymentSessionRouteAccepts(rest_get_server()->get_routes(), $routePattern),
    'POST /payments/{id}/session no esta registrada.'
);

$administrators = get_users([
    'role' => 'administrator',
    'number' => 1,
    'fields' => 'ids',
]);
assertPaymentSession($administrators !== [], 'Falta administrador.');
wp_set_current_user((int) $administrators[0]);

$transaction = $wpdb->query('START TRANSACTION');
assertPaymentSession($transaction !== false, 'No se inicio transaccion.');

try {
    $inventoryRepository = new InventoryRepository();
    $orderService = new OrderService();
    $now = current_time('mysql');
    $makeOrder = static function (
        int $customerId,
        int $token,
        float $price
    ) use ($inventoryRepository, $orderService, $now): int {
        $inventoryId = $inventoryRepository->create([
            'product_id' => $token,
            'minimarket_id' => $token + 100,
            'price' => $price,
            'stock' => 2,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $orderService->create([
            'customer_id' => $customerId,
            'minimarket_id' => $token + 100,
            'items' => [[
                'product_id' => $token,
                'inventory_id' => $inventoryId,
                'quantity' => 1,
                'unit_price' => $price,
            ]],
        ])['id'];
    };
    $customerId = random_int(930000000, 939999999);
    $orderId = $makeOrder($customerId, 830000001, 4500.0);
    $otherCustomerId = random_int(950000000, 959999999);
    $otherOrderId = $makeOrder($otherCustomerId, 830000002, 1000.0);
    $ordersBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$ordersTable}");
    $reservationsBefore = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$reservationsTable}"
    );
    $stockBefore = (string) $wpdb->get_var(
        "SELECT COALESCE(SUM(stock), 0) FROM {$inventoryTable}"
    );
    $payment = $paymentService->create([
        'customer_id' => $customerId,
        'amount' => '4500.00',
        'currency' => 'CLP',
        'provider' => null,
        'order_ids' => [$orderId],
    ]);
    $paymentId = (int) $payment['id'];

    $first = $sessionService->create($paymentId);
    assertPaymentSessionSame($paymentId, $first['payment_id']);
    assertPaymentSessionSame('pending', $first['status']);
    assertPaymentSessionSame('dummy', $first['provider']);
    assertPaymentSession(
        preg_match('/^DUMMY-[A-F0-9]{8}$/', $first['provider_reference']) === 1,
        'Referencia Dummy invalida.'
    );
    assertPaymentSessionSame(
        'https://dummy.veciahorra/pay/' . $first['provider_reference'],
        $first['payment_url']
    );
    assertPaymentSession(
        DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i:s',
            $first['expires_at'],
            wp_timezone()
        ) !== false,
        'expires_at no usa el formato esperado.'
    );

    $stored = $repository->find($paymentId);
    assertPaymentSessionSame('dummy', $stored['provider'] ?? null);
    assertPaymentSessionSame(
        $first['provider_reference'],
        $stored['provider_reference'] ?? null
    );
    assertPaymentSessionSame(
        str_replace('T', ' ', $first['expires_at']),
        $stored['expires_at'] ?? null
    );
    assertPaymentSessionSame(
        $paymentId,
        (int) ($repository->findByReference(
            (string) $payment['payment_reference']
        )['id'] ?? 0)
    );

    $second = $sessionService->create($paymentId);
    assertPaymentSessionSame(
        $first['provider_reference'],
        $second['provider_reference']
    );
    assertPaymentSessionSame(
        $second['provider_reference'],
        $repository->find($paymentId)['provider_reference'] ?? null
    );
    assertPaymentSessionSame('pending', $repository->find($paymentId)['status']);

    $rest = paymentSessionRequest(
        '/veciahorra/v1/payments/' . $paymentId . '/session'
    );
    assertPaymentSessionSame(200, $rest->get_status());
    assertPaymentSessionSame(true, $rest->get_data()['success'] ?? null);
    assertPaymentSessionSame(
        'dummy',
        $rest->get_data()['data']['provider'] ?? null
    );
    assertPaymentSessionSame(
        404,
        paymentSessionRequest(
            '/veciahorra/v1/payments/' . PHP_INT_MAX . '/session'
        )->get_status()
    );

    $nonPending = $paymentService->create([
        'customer_id' => $otherCustomerId,
        'amount' => '1000.00',
        'currency' => 'CLP',
        'provider' => null,
        'order_ids' => [$otherOrderId],
    ]);
    $wpdb->update(
        $paymentsTable,
        ['status' => 'paid'],
        ['id' => (int) $nonPending['id']]
    );
    assertPaymentSessionSame(
        422,
        paymentSessionRequest(
            '/veciahorra/v1/payments/' . $nonPending['id'] . '/session'
        )->get_status()
    );

    assertPaymentSessionSame(
        $ordersBefore,
        (int) $wpdb->get_var("SELECT COUNT(*) FROM {$ordersTable}")
    );
    assertPaymentSessionSame(
        $reservationsBefore,
        (int) $wpdb->get_var("SELECT COUNT(*) FROM {$reservationsTable}")
    );
    assertPaymentSessionSame(
        $stockBefore,
        (string) $wpdb->get_var(
            "SELECT COALESCE(SUM(stock), 0) FROM {$inventoryTable}"
        )
    );

    $serviceSource = file_get_contents(
        dirname(__DIR__, 2)
        . '/app/Modules/Payments/Service/PaymentSessionService.php'
    );
    assertPaymentSession(
        is_string($serviceSource)
        && str_contains($serviceSource, 'PaymentGatewayInterface')
        && ! str_contains($serviceSource, 'DummyPaymentGateway')
        && ! str_contains($serviceSource, '$wpdb'),
        'PaymentSessionService no esta desacoplado.'
    );

    echo "PASS payment-session-test\n";
} finally {
    $wpdb->query('ROLLBACK');
    wp_set_current_user(0);
}
