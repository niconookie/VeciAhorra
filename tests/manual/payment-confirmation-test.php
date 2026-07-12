<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Core\Application;
use VeciAhorra\Modules\Inventory\Repositories\InventoryRepository;
use VeciAhorra\Modules\Orders\Services\OrderService;
use VeciAhorra\Modules\Payments\Repository\PaymentRepository;
use VeciAhorra\Modules\Payments\Service\PaymentConfirmationService;
use VeciAhorra\Modules\Payments\Service\PaymentService;
use VeciAhorra\Modules\Payments\Service\PaymentSessionService;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Reservations\Service\ReservationService;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertPaymentConfirmation(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertPaymentConfirmationSame(mixed $expected, mixed $actual): void
{
    assertPaymentConfirmation(
        $expected === $actual,
        sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        )
    );
}

function paymentConfirmationRequest(array $payload): WP_REST_Response
{
    $request = new WP_REST_Request('POST', '/veciahorra/v1/payments/confirm');
    $request->set_header('content-type', 'application/json');
    $request->set_body(wp_json_encode($payload));

    return rest_do_request($request);
}

function paymentConfirmationRouteAccepts(array $routes): bool
{
    foreach ($routes['/veciahorra/v1/payments/confirm'] ?? [] as $handler) {
        if (($handler['methods']['POST'] ?? false) === true) {
            return true;
        }
    }

    return false;
}

global $wpdb;

$prefix = $wpdb->prefix . Config::TABLE_PREFIX;
$paymentsTable = $prefix . 'payments';
$paymentOrdersTable = $prefix . 'payment_orders';
$ordersTable = $prefix . 'orders';
$orderItemsTable = $prefix . 'order_items';
$reservationsTable = $prefix . 'reservations';
$inventoryTable = $prefix . 'inventory';
$inventoryRepository = new InventoryRepository();
$orderService = new OrderService();
$paymentRepository = new PaymentRepository();
$paymentService = new PaymentService($paymentRepository);
$application = new Application();
$sessionService = $application->container()->make(PaymentSessionService::class);
$confirmationService = $application->container()->make(
    PaymentConfirmationService::class
);
assertPaymentConfirmation(
    $confirmationService instanceof PaymentConfirmationService,
    'Application no resolvio PaymentConfirmationService.'
);
assertPaymentConfirmation(
    paymentConfirmationRouteAccepts(rest_get_server()->get_routes()),
    'POST /payments/confirm no esta registrada.'
);

$administrators = get_users([
    'role' => 'administrator',
    'number' => 1,
    'fields' => 'ids',
]);
assertPaymentConfirmation($administrators !== [], 'Falta administrador.');
wp_set_current_user((int) $administrators[0]);

$inventoryIds = [];
$orderIds = [];
$paymentIds = [];

try {
    $now = current_time('mysql');
    $makeOrder = static function (
        int $stock,
        int $quantity,
        int $customerId
    ) use (
        $inventoryRepository,
        $orderService,
        &$inventoryIds,
        &$orderIds,
        $now
    ): array {
        $inventoryId = $inventoryRepository->create([
            'product_id' => random_int(970000000, 979999999),
            'minimarket_id' => random_int(980000000, 989999999),
            'price' => 1250.0,
            'stock' => $stock,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $inventoryIds[] = $inventoryId;
        $inventory = $inventoryRepository->find($inventoryId);
        $order = $orderService->create([
            'customer_id' => $customerId,
            'minimarket_id' => (int) $inventory['minimarket_id'],
            'items' => [[
                'product_id' => (int) $inventory['product_id'],
                'inventory_id' => $inventoryId,
                'quantity' => $quantity,
                'unit_price' => 1250.0,
            ]],
        ]);
        $orderIds[] = (int) $order['id'];

        return [
            'order' => $order,
            'inventory_id' => $inventoryId,
            'stock_after_lock' => $stock - $quantity,
        ];
    };

    $successfulCustomerId = random_int(900000000, 909999999);
    $first = $makeOrder(10, 2, $successfulCustomerId);
    $second = $makeOrder(7, 3, $successfulCustomerId);
    $successfulPayment = $paymentService->create([
        'customer_id' => $successfulCustomerId,
        'amount' => '6250.00',
        'currency' => 'CLP',
        'provider' => null,
        'order_ids' => [
            (int) $first['order']['id'],
            (int) $second['order']['id'],
        ],
    ]);
    $paymentIds[] = (int) $successfulPayment['id'];
    $session = $sessionService->create((int) $successfulPayment['id']);
    $response = paymentConfirmationRequest([
        'provider' => 'mock',
        'provider_reference' => $session['provider_reference'],
    ]);
    $result = $response->get_data()['data'] ?? [];

    assertPaymentConfirmationSame(200, $response->get_status());
    assertPaymentConfirmationSame('paid', $result['status'] ?? null);
    assertPaymentConfirmationSame(2, $result['orders_updated'] ?? null);
    assertPaymentConfirmationSame(
        2,
        $result['reservations_confirmed'] ?? null
    );
    assertPaymentConfirmation(
        is_string($result['paid_at'] ?? null),
        'paid_at no fue registrado.'
    );
    $storedPaid = $paymentRepository->find((int) $successfulPayment['id']);
    assertPaymentConfirmationSame('paid', $storedPaid['status'] ?? null);
    assertPaymentConfirmationSame(
        $result['paid_at'],
        $storedPaid['paid_at'] ?? null
    );

    foreach ([$first, $second] as $created) {
        $orderId = (int) $created['order']['id'];
        assertPaymentConfirmationSame(
            'paid',
            $orderService->find($orderId)['status'] ?? null
        );
        $statuses = $wpdb->get_col($wpdb->prepare(
            "SELECT status FROM {$reservationsTable} WHERE order_id = %d",
            $orderId
        ));
        assertPaymentConfirmationSame(['consumed'], $statuses);
        assertPaymentConfirmationSame(
            $created['stock_after_lock'],
            (int) $wpdb->get_var($wpdb->prepare(
                "SELECT stock FROM {$inventoryTable} WHERE id = %d",
                $created['inventory_id']
            ))
        );
    }

    $idempotent = paymentConfirmationRequest([
        'provider' => 'mock',
        'provider_reference' => $session['provider_reference'],
    ]);
    assertPaymentConfirmationSame(200, $idempotent->get_status());
    assertPaymentConfirmationSame(
        'paid',
        $idempotent->get_data()['data']['status'] ?? null
    );
    assertPaymentConfirmationSame(
        0,
        $idempotent->get_data()['data']['orders_updated'] ?? null
    );
    assertPaymentConfirmationSame(
        0,
        $idempotent->get_data()['data']['reservations_confirmed'] ?? null
    );
    foreach ([$first, $second] as $created) {
        assertPaymentConfirmationSame(
            $created['stock_after_lock'],
            (int) $wpdb->get_var($wpdb->prepare(
                "SELECT stock FROM {$inventoryTable} WHERE id = %d",
                $created['inventory_id']
            ))
        );
    }

    $failedCustomerId = random_int(910000000, 919999999);
    $failedOrder = $makeOrder(9, 2, $failedCustomerId);
    $failedPayment = $paymentService->create([
        'customer_id' => $failedCustomerId,
        'amount' => '2500.00',
        'currency' => 'CLP',
        'provider' => null,
        'order_ids' => [(int) $failedOrder['order']['id']],
    ]);
    $paymentIds[] = (int) $failedPayment['id'];
    $failedReference = 'DUMMY-FAIL-' . strtoupper(bin2hex(random_bytes(4)));
    $paymentRepository->updateSessionData(
        (int) $failedPayment['id'],
        'dummy',
        $failedReference,
        current_datetime()->modify('+15 minutes')->format('Y-m-d H:i:s'),
        current_time('mysql')
    );
    $failedResponse = paymentConfirmationRequest([
        'provider' => 'dummy',
        'provider_reference' => $failedReference,
    ]);
    assertPaymentConfirmationSame(200, $failedResponse->get_status());
    assertPaymentConfirmationSame(
        'failed',
        $failedResponse->get_data()['data']['status'] ?? null
    );
    $storedFailed = $paymentRepository->find((int) $failedPayment['id']);
    assertPaymentConfirmationSame('failed', $storedFailed['status'] ?? null);
    assertPaymentConfirmationSame(null, $storedFailed['paid_at'] ?? null);
    assertPaymentConfirmationSame(
        'reserved',
        $orderService->find((int) $failedOrder['order']['id'])['status'] ?? null
    );
    assertPaymentConfirmationSame(
        ['active'],
        $wpdb->get_col($wpdb->prepare(
            "SELECT status FROM {$reservationsTable} WHERE order_id = %d",
            (int) $failedOrder['order']['id']
        ))
    );
    assertPaymentConfirmationSame(
        $failedOrder['stock_after_lock'],
        (int) $wpdb->get_var($wpdb->prepare(
            "SELECT stock FROM {$inventoryTable} WHERE id = %d",
            $failedOrder['inventory_id']
        ))
    );

    $failedAgain = paymentConfirmationRequest([
        'provider' => 'dummy',
        'provider_reference' => $failedReference,
    ]);
    assertPaymentConfirmationSame(200, $failedAgain->get_status());
    assertPaymentConfirmationSame(
        'failed',
        $failedAgain->get_data()['data']['status'] ?? null
    );
    assertPaymentConfirmationSame(
        0,
        $failedAgain->get_data()['data']['orders_updated'] ?? null
    );

    $persistenceCustomerId = random_int(920000000, 929999999);
    $persistenceOrder = $makeOrder(6, 1, $persistenceCustomerId);
    $failingRepository = new class extends PaymentRepository {
        public function attachOrders(
            int $paymentId,
            array $orderIds,
            string $createdAt
        ): void {
            throw new PersistenceException(
                'Fallo de asociacion de pago simulado.'
            );
        }
    };
    $failingService = new PaymentService(
        $failingRepository,
        new OrderService(),
        new ReservationService()
    );

    try {
        $failingService->create([
            'customer_id' => $persistenceCustomerId,
            'amount' => '1250.00',
            'currency' => 'CLP',
            'provider' => null,
            'order_ids' => [(int) $persistenceOrder['order']['id']],
        ]);
        throw new RuntimeException('Se esperaba fallo creando Payment.');
    } catch (PersistenceException) {
    }
    assertPaymentConfirmationSame(
        0,
        (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$paymentsTable} WHERE customer_id = %d",
            $persistenceCustomerId
        ))
    );

    assertPaymentConfirmationSame(
        404,
        paymentConfirmationRequest([
            'provider' => 'dummy',
            'provider_reference' => 'DUMMY-00000000',
        ])->get_status()
    );
    assertPaymentConfirmationSame(
        422,
        paymentConfirmationRequest([
            'provider' => 'dummy',
            'provider_reference' => 'referencia invalida',
        ])->get_status()
    );
    assertPaymentConfirmationSame(
        422,
        paymentConfirmationRequest([
            'provider' => 'otro',
            'provider_reference' => $session['provider_reference'],
        ])->get_status()
    );

    echo "PASS payment-confirmation-test\n";
} finally {
    foreach ($paymentIds as $paymentId) {
        $wpdb->delete($paymentOrdersTable, ['payment_id' => $paymentId]);
        $wpdb->delete($paymentsTable, ['id' => $paymentId]);
    }
    foreach ($orderIds as $orderId) {
        $wpdb->delete($reservationsTable, ['order_id' => $orderId]);
        $wpdb->delete($orderItemsTable, ['order_id' => $orderId]);
        $wpdb->delete($ordersTable, ['id' => $orderId]);
    }
    foreach ($inventoryIds as $inventoryId) {
        $wpdb->delete($inventoryTable, ['id' => $inventoryId]);
    }
    wp_set_current_user(0);
}
