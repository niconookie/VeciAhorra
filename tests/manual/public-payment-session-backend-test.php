<?php

declare(strict_types=1);

use VeciAhorra\Core\Application;
use VeciAhorra\Core\Config;
use VeciAhorra\Database\Migrations\CreateCheckoutOrdersTable;
use VeciAhorra\Database\Migrations\CreateCheckoutsTable;
use VeciAhorra\Database\Migrations\CreatePaymentSessionsTable;
use VeciAhorra\Exceptions\ConflictException;
use VeciAhorra\Exceptions\RecordNotFoundException;
use VeciAhorra\Modules\Checkout\Models\Checkout;
use VeciAhorra\Modules\Checkout\Repository\CheckoutRepository;
use VeciAhorra\Modules\Checkout\Service\CheckoutService;
use VeciAhorra\Modules\Payments\Service\IdempotencyService;
use VeciAhorra\Modules\Payments\Service\PaymentSessionService;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertPublicPaymentBackend(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertPublicPaymentBackendSame(mixed $expected, mixed $actual): void
{
    assertPublicPaymentBackend(
        $expected === $actual,
        sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        )
    );
}

global $wpdb;

foreach ([
    new CreateCheckoutsTable(),
    new CreateCheckoutOrdersTable(),
    new CreatePaymentSessionsTable(),
] as $migration) {
    $migration->up();
    $migration->up();
}

$table = static fn (string $name): string =>
    $wpdb->prefix . Config::TABLE_PREFIX . $name;
$ordersTable = $table('orders');
$checkoutsTable = $table('checkouts');
$checkoutOrdersTable = $table('checkout_orders');
$sessionsTable = $table('payment_sessions');
$reservationsTable = $table('reservations');
$inventoryTable = $table('inventory');
$deliveriesTable = $table('deliveries');
$token = strtolower(wp_generate_password(12, false, false));
$ownerId = wp_insert_user([
    'user_login' => 'va_payment_owner_' . $token,
    'user_pass' => wp_generate_password(24),
    'user_email' => 'va-payment-owner-' . $token . '@example.test',
]);
$otherOwnerId = wp_insert_user([
    'user_login' => 'va_payment_other_' . $token,
    'user_pass' => wp_generate_password(24),
    'user_email' => 'va-payment-other-' . $token . '@example.test',
]);
assertPublicPaymentBackend(
    is_int($ownerId) && is_int($otherOwnerId),
    'No se pudieron crear los usuarios fixture.'
);
$now = current_time('mysql');
$expiresAt = current_datetime()->modify('+10 minutes')->format('Y-m-d H:i:s');
$createdOrderIds = [];
$createdCheckoutIds = [];

$insertOrder = static function (int $customerId, string $total) use (
    $wpdb,
    $ordersTable,
    $now,
    $expiresAt,
    &$createdOrderIds,
    $reservationsTable
): int {
    $result = $wpdb->insert($ordersTable, [
        'customer_id' => $customerId,
        'minimarket_id' => random_int(730000000, 739999999),
        'total' => $total,
        'status' => 'reserved',
        'reservation_expires_at' => $expiresAt,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    assertPublicPaymentBackend($result !== false, 'No se creo la Order fixture.');
    $id = (int) $wpdb->insert_id;
    $createdOrderIds[] = $id;
    $reservation = $wpdb->insert($reservationsTable, [
        'order_id' => $id,
        'inventory_id' => random_int(740000000, 749999999),
        'product_id' => random_int(750000000, 759999999),
        'minimarket_id' => random_int(760000000, 769999999),
        'quantity' => 1,
        'status' => 'active',
        'reserved_at' => $now,
        'expires_at' => $expiresAt,
        'released_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    assertPublicPaymentBackend(
        $reservation !== false,
        'No se creo la Reservation fixture.'
    );

    return $id;
};

$application = new Application();
$checkoutService = $application->container()->make(CheckoutService::class);
$paymentSessionService = $application->container()->make(
    PaymentSessionService::class
);
assertPublicPaymentBackend(
    $checkoutService instanceof CheckoutService,
    'No se resolvio CheckoutService.'
);
assertPublicPaymentBackend(
    $paymentSessionService instanceof PaymentSessionService,
    'No se resolvio PaymentSessionService.'
);

try {
    $orderOne = $insertOrder($ownerId, '1000.00');
    $orderTwo = $insertOrder($ownerId, '2000.00');
    $orderThree = $insertOrder($ownerId, '3000.00');
    $otherOrder = $insertOrder($otherOwnerId, '4000.00');
    $snapshots = [
        'orders' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$ordersTable}"),
        'reservations' => (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$reservationsTable}"
        ),
        'inventory' => (string) $wpdb->get_var(
            "SELECT COALESCE(SUM(stock), 0) FROM {$inventoryTable}"
        ),
        'deliveries' => (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$deliveriesTable}"
        ),
    ];

    $single = $checkoutService->createPersistent(
        ['user_id' => $ownerId, 'session_id' => null],
        [$orderOne]
    );
    assertPublicPaymentBackendSame(1, $single['order_count']);
    assertPublicPaymentBackendSame('1000.00', $single['total_amount']);

    $multiple = $checkoutService->createPersistent(
        ['user_id' => $ownerId, 'session_id' => null],
        [$orderTwo, $orderThree]
    );
    assertPublicPaymentBackendSame(2, $multiple['order_count']);
    assertPublicPaymentBackendSame('5000.00', $multiple['total_amount']);

    try {
        $checkoutService->createPersistent(
            ['user_id' => $ownerId, 'session_id' => null],
            [$otherOrder]
        );
        throw new RuntimeException('Se esperaba rechazo por owner diferente.');
    } catch (InvalidArgumentException) {
    }

    try {
        $checkoutService->createPersistent(
            ['user_id' => $ownerId, 'session_id' => null],
            [$orderOne]
        );
        throw new RuntimeException('Se esperaba Order ya asociada.');
    } catch (ConflictException $exception) {
        assertPublicPaymentBackendSame(
            'order_already_attached',
            $exception->errorCode()
        );
    }

    $key = 'payment-session-test-key-0001';
    $worker = __DIR__ . '/public-payment-session-concurrency-worker.php';
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $processes = [];

    for ($index = 0; $index < 2; $index++) {
        $pipes = [];
        $process = proc_open([
            PHP_BINARY,
            '-d',
            'session.save_path=' . sys_get_temp_dir(),
            $worker,
            $multiple['checkout_id'],
            $key,
            (string) $ownerId,
        ], $descriptors, $pipes);
        assertPublicPaymentBackend(
            is_resource($process),
            'No se inicio el worker concurrente.'
        );
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }

    $concurrentResults = [];

    foreach ($processes as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        assertPublicPaymentBackendSame(0, $exitCode);
        assertPublicPaymentBackend(
            $stderr === '',
            'Worker concurrente escribio error: ' . $stderr
        );
        $decoded = json_decode((string) $stdout, true);
        assertPublicPaymentBackend(
            is_array($decoded),
            'Worker concurrente no devolvio JSON.'
        );
        $concurrentResults[] = $decoded;
    }

    assertPublicPaymentBackendSame(
        $concurrentResults[0]['payment_session_id'],
        $concurrentResults[1]['payment_session_id']
    );
    $firstSession = $concurrentResults[0];
    $replayedSession = $paymentSessionService->start(
        $multiple['checkout_id'],
        $key,
        ['user_id' => $ownerId, 'session_id' => null]
    );
    assertPublicPaymentBackendSame(
        $firstSession['payment_session_id'],
        $replayedSession['payment_session_id']
    );
    assertPublicPaymentBackendSame(true, $replayedSession['reused']);
    assertPublicPaymentBackendSame(
        1,
        (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$sessionsTable} WHERE checkout_id = ("
            . "SELECT id FROM {$checkoutsTable} WHERE public_id = %s)",
            $multiple['checkout_id']
        ))
    );

    $wpdb->update($ordersTable, ['total' => '3001.00'], ['id' => $orderThree]);

    try {
        $paymentSessionService->start(
            $multiple['checkout_id'],
            $key,
            ['user_id' => $ownerId, 'session_id' => null]
        );
        throw new RuntimeException('Se esperaba conflicto de fingerprint.');
    } catch (ConflictException $exception) {
        assertPublicPaymentBackendSame(
            'idempotency_conflict',
            $exception->errorCode()
        );
    } finally {
        $wpdb->update($ordersTable, ['total' => '3000.00'], ['id' => $orderThree]);
    }

    $recovered = $paymentSessionService->get(
        $firstSession['payment_session_id'],
        ['user_id' => $ownerId, 'session_id' => null]
    );
    assertPublicPaymentBackendSame(
        $firstSession['payment_session_id'],
        $recovered['payment_session_id']
    );

    wp_set_current_user($ownerId);
    $checkoutRest = rest_do_request(new WP_REST_Request(
        'GET',
        '/veciahorra/v1/checkout/' . $multiple['checkout_id']
    ));
    assertPublicPaymentBackendSame(200, $checkoutRest->get_status());
    $sessionRest = rest_do_request(new WP_REST_Request(
        'GET',
        '/veciahorra/v1/payments/session/'
            . $firstSession['payment_session_id']
    ));
    assertPublicPaymentBackendSame(200, $sessionRest->get_status());
    $startRestRequest = new WP_REST_Request(
        'POST',
        '/veciahorra/v1/payments/session'
    );
    $startRestRequest->set_header('content-type', 'application/json');
    $startRestRequest->set_header(
        'Idempotency-Key',
        'payment-session-test-key-0002'
    );
    $startRestRequest->set_body(wp_json_encode([
        'checkout_id' => $multiple['checkout_id'],
    ]));
    $startRest = rest_do_request($startRestRequest);
    assertPublicPaymentBackendSame(200, $startRest->get_status());
    assertPublicPaymentBackendSame(
        $firstSession['payment_session_id'],
        $startRest->get_data()['data']['payment_session_id'] ?? null
    );
    assertPublicPaymentBackendSame(
        400,
        rest_do_request(new WP_REST_Request(
            'GET',
            '/veciahorra/v1/payments/session/not-valid'
        ))->get_status()
    );
    wp_set_current_user(0);

    try {
        $paymentSessionService->get(
            $firstSession['payment_session_id'],
            ['user_id' => $otherOwnerId, 'session_id' => null]
        );
        throw new RuntimeException('Se esperaba proteccion de user ownership.');
    } catch (RecordNotFoundException) {
    }

    $sessionToken = bin2hex(random_bytes(32));
    $owner = (new IdempotencyService())->owner([
        'user_id' => null,
        'session_id' => $sessionToken,
    ]);
    $checkoutRepository = new CheckoutRepository();
    $guestId = $checkoutRepository->create([
        'public_id' => Checkout::publicId(),
        'owner_type' => 'session',
        'user_id' => null,
        'session_id' => $owner['session_id'],
        'status' => 'pending',
        'currency' => 'CLP',
        'total_amount' => '1.00',
        'created_at' => $now,
        'updated_at' => $now,
        'expires_at' => $expiresAt,
    ]);
    $createdCheckoutIds[] = $guestId;
    $guest = $checkoutRepository->find($guestId);
    assertPublicPaymentBackend(is_array($guest), 'No se creo Checkout invitado.');
    assertPublicPaymentBackendSame(
        $guest['public_id'],
        $checkoutService->get(
            $guest['public_id'],
            ['user_id' => null, 'session_id' => $sessionToken]
        )['checkout_id']
    );

    try {
        $checkoutService->get(
            $guest['public_id'],
            ['user_id' => null, 'session_id' => bin2hex(random_bytes(32))]
        );
        throw new RuntimeException('Se esperaba proteccion de session ownership.');
    } catch (RecordNotFoundException) {
    }

    assertPublicPaymentBackendSame(
        $snapshots['orders'],
        (int) $wpdb->get_var("SELECT COUNT(*) FROM {$ordersTable}")
    );
    assertPublicPaymentBackendSame(
        $snapshots['reservations'],
        (int) $wpdb->get_var("SELECT COUNT(*) FROM {$reservationsTable}")
    );
    assertPublicPaymentBackendSame(
        $snapshots['inventory'],
        (string) $wpdb->get_var(
            "SELECT COALESCE(SUM(stock), 0) FROM {$inventoryTable}"
        )
    );
    assertPublicPaymentBackendSame(
        $snapshots['deliveries'],
        (int) $wpdb->get_var("SELECT COUNT(*) FROM {$deliveriesTable}")
    );

    echo "PASS public-payment-session-backend-test\n";
} finally {
    wp_set_current_user(0);
    if ($createdOrderIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($createdOrderIds), '%d'));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$sessionsTable} WHERE checkout_id IN ("
            . "SELECT checkout_id FROM {$checkoutOrdersTable}"
            . " WHERE order_id IN ({$placeholders}))",
            ...$createdOrderIds
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$checkoutOrdersTable} WHERE order_id IN ({$placeholders})",
            ...$createdOrderIds
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$reservationsTable} WHERE order_id IN ({$placeholders})",
            ...$createdOrderIds
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$ordersTable} WHERE id IN ({$placeholders})",
            ...$createdOrderIds
        ));
    }

    foreach ($createdCheckoutIds as $checkoutId) {
        $wpdb->delete($checkoutsTable, ['id' => $checkoutId]);
    }

    $wpdb->query(
        "DELETE c FROM {$checkoutsTable} c LEFT JOIN {$checkoutOrdersTable} co"
        . ' ON co.checkout_id = c.id WHERE co.id IS NULL'
    );
    wp_delete_user($ownerId);
    wp_delete_user($otherOwnerId);
}
