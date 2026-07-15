<?php

declare(strict_types=1);

use VeciAhorra\Core\Application;
use VeciAhorra\Core\Config;
use VeciAhorra\Database\Migrations\CreateCheckoutOrdersTable;
use VeciAhorra\Database\Migrations\CreateCheckoutsTable;
use VeciAhorra\Database\Migrations\CreatePaymentSessionsTable;
use VeciAhorra\Database\Migrations\CreatePaymentOriginContextsTable;
use VeciAhorra\Database\Migrations\AddDurableWebpayCreateState;
use VeciAhorra\Exceptions\ConflictException;
use VeciAhorra\Exceptions\RecordNotFoundException;
use VeciAhorra\Modules\Checkout\Models\Checkout;
use VeciAhorra\Modules\Checkout\Repository\CheckoutRepository;
use VeciAhorra\Modules\Checkout\Service\CheckoutService;
use VeciAhorra\Modules\Payments\Service\IdempotencyService;
use VeciAhorra\Modules\Payments\Service\PaymentSessionService;
use VeciAhorra\Modules\Payments\Repository\PaymentRepository;
use VeciAhorra\Modules\Payments\Repository\PaymentSessionRepository;
use VeciAhorra\Modules\Payments\Orchestration\WebpayCreateRecovery;
use VeciAhorra\Modules\Payments\Orchestration\WebpayReturnRecovery;
use VeciAhorra\Modules\Payments\Gateway\PaymentGatewayInterface;
use VeciAhorra\Modules\Payments\Gateway\PaymentSessionContext;
use VeciAhorra\Modules\Payments\Gateway\GatewaySessionResult;
use VeciAhorra\Modules\Payments\Gateway\WebpayReturnGatewayInterface;
use VeciAhorra\Modules\Payments\Gateway\WebpayCommitResult;
use VeciAhorra\Modules\Payments\Repository\WebpayReturnRepository;
use VeciAhorra\Modules\Payments\Service\WebpayReturnService;
use VeciAhorra\Modules\Payments\Service\PublicPaymentStatusService;
use VeciAhorra\Modules\Payments\Requests\WebpayReturnRequest;

putenv('webpay_environment=integration');
putenv('webpay_commerce_code=597055555532');
putenv('webpay_api_key=' . str_repeat('A', 32));
putenv('webpay_return_url=https://example.test/webpay/return');
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

final class PublicAttemptGatewayFake implements PaymentGatewayInterface
{
    public int $calls = 0;
    public function createSession(PaymentSessionContext $context): GatewaySessionResult
    {
        $this->calls++;
        return new GatewaySessionResult(
            'webpay_plus',
            substr(hash('sha256', $context->paymentSessionId), 0, 40),
            GatewaySessionResult::STATUS_READY,
            'https://webpay3gint.transbank.cl/webpayserver/initTransaction',
            $context->expiresAt
        );
    }
    public function recoverSession(string $providerSessionId): GatewaySessionResult
    {
        $this->calls++;
        throw new RuntimeException('El intento local invoco recovery remoto.');
    }
}

final class AmbiguousPublicAttemptGateway implements PaymentGatewayInterface
{
    public int $calls = 0;
    public function createSession(PaymentSessionContext $context): GatewaySessionResult
    { $this->calls++; throw new RuntimeException('simulated timeout'); }
    public function recoverSession(string $providerSessionId): GatewaySessionResult
    { throw new RuntimeException('Recovery remoto inesperado.'); }
}

final class PublicReturnGatewayFake implements WebpayReturnGatewayInterface
{
    public int $commits = 0;
    public function __construct(private WebpayCommitResult $result) {}
    public function commit(string $token): WebpayCommitResult
    { $this->commits++; return $this->result; }
}

global $wpdb;

foreach ([
    new CreateCheckoutsTable(),
    new CreateCheckoutOrdersTable(),
    new CreatePaymentSessionsTable(),
    new AddDurableWebpayCreateState(),
    new CreatePaymentOriginContextsTable(),
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
$originsTable = $table('payment_origin_contexts');
$reservationsTable = $table('reservations');
$inventoryTable = $table('inventory');
$deliveriesTable = $table('deliveries');
$reconciliationsTable = $table('payment_reconciliations');
$businessTable = $table('business_completions');
$deliveryCompletionsTable = $table('delivery_completions');
$fulfillmentTable = $table('fulfillment_completions');
$paymentsTable = $table('payments');
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
$forbiddenGateway = new PublicAttemptGatewayFake();
$paymentSessionService = new PaymentSessionService(
    new PaymentRepository(),
    $forbiddenGateway
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
    $rollbackOrder = $insertOrder($ownerId, '1500.00');
    $ambiguousOrder = $insertOrder($ownerId, '1700.00');
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
        'payments' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$paymentsTable}"),
        'reconciliations' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$reconciliationsTable}"),
        'business' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$businessTable}"),
        'delivery_completions' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$deliveryCompletionsTable}"),
        'fulfillment' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$fulfillmentTable}"),
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

    $rollbackCheckout = $checkoutService->createPersistent(
        ['user_id' => $ownerId, 'session_id' => null],
        [$rollbackOrder]
    );
    putenv('webpay_commerce_code=invalid');
    try {
        $paymentSessionService->start(
            $rollbackCheckout['checkout_id'],
            'payment-session-rollback-0001',
            ['user_id' => $ownerId, 'session_id' => null]
        );
        throw new RuntimeException('Se esperaba rollback del intento local.');
    } catch (InvalidArgumentException) {
    } finally {
        putenv('webpay_commerce_code=597055555532');
    }
    assertPublicPaymentBackendSame(0, (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$sessionsTable} s JOIN {$checkoutsTable} c"
        . ' ON c.id=s.checkout_id WHERE c.public_id=%s',
        $rollbackCheckout['checkout_id']
    )));
    assertPublicPaymentBackendSame(0, (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$originsTable} WHERE origin_resource_id=%s",
        $rollbackCheckout['checkout_id']
    )));

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
    $callCounter = tempnam(sys_get_temp_dir(), 'va-webpay-create-');
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
            $callCounter,
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
    assertPublicPaymentBackend(
        count(array_filter($concurrentResults, static fn (array $item): bool =>
            $item['status'] === 'ready')) === 1,
        'La concurrencia no produjo un unico ganador remoto.'
    );
    $firstSession = $paymentSessionService->get(
        $concurrentResults[0]['payment_session_id'],
        ['user_id' => $ownerId, 'session_id' => null]
    );
    assertPublicPaymentBackendSame('ready', $firstSession['status']);
    assertPublicPaymentBackendSame('webpay_plus', $firstSession['provider']);
    assertPublicPaymentBackend(isset($firstSession['redirect_url']), 'Falta redirect durable.');
    assertPublicPaymentBackend(! isset($firstSession['token_ws']), 'GET interno expuso token_ws.');
    assertPublicPaymentBackendSame('1', trim((string) file_get_contents($callCounter)));
    $statusProjection = new PublicPaymentStatusService();
    $redirectProjection = $statusProjection->project(
        $multiple['checkout_id'],
        ['user_id' => $ownerId, 'session_id' => null]
    );
    assertPublicPaymentBackendSame('redirect_ready', $redirectProjection['payment_status']);
    assertPublicPaymentBackendSame('redirect_to_webpay', $redirectProjection['next_action']);
    assertPublicPaymentBackend(isset($redirectProjection['redirect_url']), 'Proyeccion sin URL durable.');
    wp_set_current_user($ownerId);
    $statusResponse = rest_do_request(new WP_REST_Request(
        'GET',
        '/veciahorra/v1/checkout/' . $multiple['checkout_id'] . '/payment-status'
    ));
    assertPublicPaymentBackendSame(200, $statusResponse->get_status());
    assertPublicPaymentBackend(
        str_contains((string) $statusResponse->get_headers()['Cache-Control'], 'no-store'),
        'La proyeccion publica permite cache compartida.'
    );
    $encodedStatus = wp_json_encode($statusResponse->get_data());
    foreach (['token_hash', 'lease_owner', 'fingerprint', 'buy_order'] as $secret) {
        assertPublicPaymentBackend(! str_contains((string) $encodedStatus, $secret), 'Respuesta expuso ' . $secret);
    }
    wp_set_current_user(0);
    try {
        $statusProjection->project(
            $multiple['checkout_id'],
            ['user_id' => $otherOwnerId, 'session_id' => null]
        );
        throw new RuntimeException('La proyeccion expuso un Checkout ajeno.');
    } catch (RecordNotFoundException) {
    }
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
    assertPublicPaymentBackend(
        isset($replayedSession['token_ws'])
            && preg_match('/^[A-Za-z0-9]{16,191}$/D', $replayedSession['token_ws']) === 1,
        'POST ready no proyecto token_ws.'
    );
    assertPublicPaymentBackendSame(
        1,
        (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$sessionsTable} WHERE checkout_id = ("
            . "SELECT id FROM {$checkoutsTable} WHERE public_id = %s)",
            $multiple['checkout_id']
        ))
    );
    $origin = $wpdb->get_row($wpdb->prepare(
        "SELECT o.* FROM {$originsTable} o JOIN {$sessionsTable} s"
        . ' ON s.public_id=o.payment_attempt_id WHERE s.public_id=%s',
        $firstSession['payment_session_id']
    ), ARRAY_A);
    assertPublicPaymentBackend(is_array($origin), 'No se creo PaymentOriginContext.');
    assertPublicPaymentBackendSame('veciahorra_checkout', $origin['origin']);
    assertPublicPaymentBackendSame($multiple['checkout_id'], $origin['origin_resource_id']);
    assertPublicPaymentBackendSame('5000', (string) $origin['amount_clp']);
    assertPublicPaymentBackend(
        is_string($origin['token_hash'])
            && preg_match('/^[a-f0-9]{64}$/D', $origin['token_hash']) === 1,
        'No se vinculo el hash durable del token.'
    );
    assertPublicPaymentBackend(
        ! str_contains(wp_json_encode($firstSession), $origin['token_hash']),
        'La respuesta publica expuso token_hash.'
    );

    $sessionRowId = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$sessionsTable} WHERE public_id=%s",
        $firstSession['payment_session_id']
    ));
    $wpdb->update(
        $sessionsTable,
        ['redirect_url' => 'https://example.test/not-webpay'],
        ['id' => $sessionRowId]
    );
    $invalidUrlProjection = $paymentSessionService->start(
        $multiple['checkout_id'],
        $key,
        ['user_id' => $ownerId, 'session_id' => null]
    );
    assertPublicPaymentBackend(
        ! isset($invalidUrlProjection['token_ws']),
        'Una URL no permitida expuso token_ws.'
    );
    $wpdb->update($sessionsTable, [
        'redirect_url' => $replayedSession['redirect_url'],
        'provider_session_id' => '',
    ], ['id' => $sessionRowId]);
    $emptyTokenProjection = $paymentSessionService->start(
        $multiple['checkout_id'],
        $key,
        ['user_id' => $ownerId, 'session_id' => null]
    );
    assertPublicPaymentBackend(
        ! isset($emptyTokenProjection['token_ws']),
        'Un token vacio fue proyectado.'
    );
    $wpdb->update($sessionsTable, [
        'provider_session_id' => $replayedSession['token_ws'],
        'redirect_url' => $replayedSession['redirect_url'],
    ], ['id' => $sessionRowId]);

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
    assertPublicPaymentBackend(! isset($recovered['token_ws']), 'GET de PaymentSession expuso token_ws.');

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
    assertPublicPaymentBackend(
        ! isset($sessionRest->get_data()['data']['token_ws']),
        'GET REST de PaymentSession expuso token_ws.'
    );
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
        $replayedSession['token_ws'],
        $startRest->get_data()['data']['token_ws'] ?? null
    );
    assertPublicPaymentBackendSame(
        400,
        rest_do_request(new WP_REST_Request(
            'GET',
            '/veciahorra/v1/payments/session/not-valid'
        ))->get_status()
    );
    wp_set_current_user(0);

    $wpdb->update(
        $sessionsTable,
        ['status' => 'expired', 'updated_at' => current_time('mysql')],
        ['public_id' => $firstSession['payment_session_id']]
    );
    $expired = $paymentSessionService->get(
        $firstSession['payment_session_id'],
        ['user_id' => $ownerId, 'session_id' => null]
    );
    assertPublicPaymentBackendSame('expired', $expired['status']);
    $replacement = $paymentSessionService->start(
        $multiple['checkout_id'],
        'payment-session-test-key-0003',
        ['user_id' => $ownerId, 'session_id' => null]
    );
    assertPublicPaymentBackend(
        $replacement['payment_session_id'] !== $firstSession['payment_session_id'],
        'Un intento vencido fue sobrescrito o reutilizado.'
    );
    assertPublicPaymentBackendSame('ready', $replacement['status']);

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
    foreach ([
        'payments' => $paymentsTable,
        'reconciliations' => $reconciliationsTable,
        'business' => $businessTable,
        'delivery_completions' => $deliveryCompletionsTable,
        'fulfillment' => $fulfillmentTable,
    ] as $snapshot => $authorityTable) {
        assertPublicPaymentBackendSame(
            $snapshots[$snapshot],
            (int) $wpdb->get_var("SELECT COUNT(*) FROM {$authorityTable}")
        );
    }
    assertPublicPaymentBackendSame(1, $forbiddenGateway->calls);

    $ambiguousCheckout = $checkoutService->createPersistent(
        ['user_id' => $ownerId, 'session_id' => null], [$ambiguousOrder]
    );
    $ambiguousGateway = new AmbiguousPublicAttemptGateway();
    $ambiguousService = new PaymentSessionService(new PaymentRepository(), $ambiguousGateway);
    $ambiguous = $ambiguousService->start(
        $ambiguousCheckout['checkout_id'], 'payment-session-ambiguous-0001',
        ['user_id' => $ownerId, 'session_id' => null]
    );
    assertPublicPaymentBackendSame('create_ambiguous', $ambiguous['status']);
    assertPublicPaymentBackend(! isset($ambiguous['redirect_url']), 'Ambiguo expuso URL.');
    $ambiguousReplay = $ambiguousService->start(
        $ambiguousCheckout['checkout_id'], 'payment-session-ambiguous-0001',
        ['user_id' => $ownerId, 'session_id' => null]
    );
    assertPublicPaymentBackendSame('create_ambiguous', $ambiguousReplay['status']);
    assertPublicPaymentBackendSame(1, $ambiguousGateway->calls);
    $ambiguousRow = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$sessionsTable} WHERE public_id=%s",
        $ambiguous['payment_session_id']
    ), ARRAY_A);
    assertPublicPaymentBackendSame(null, $ambiguousRow['create_owner']);
    assertPublicPaymentBackendSame(null, $ambiguousRow['create_lease_expires_at']);
    $past = current_datetime()->modify('-5 minutes')->format('Y-m-d H:i:s');
    $wpdb->update($sessionsTable, [
        'status' => 'create_processing', 'create_owner' => str_repeat('a', 48),
        'create_version' => (int) $ambiguousRow['create_version'] + 1,
        'create_lease_expires_at' => $past,
        'create_remote_started_at' => null,
    ], ['id' => (int) $ambiguousRow['id']]);
    (new WebpayCreateRecovery())->recoverOne((int) $ambiguousRow['id']);
    assertPublicPaymentBackendSame('create_retryable', (string) $wpdb->get_var(
        $wpdb->prepare("SELECT status FROM {$sessionsTable} WHERE id=%d", $ambiguousRow['id'])
    ));
    assertPublicPaymentBackendSame(false, (new CheckoutRepository())->transaction(
        static fn (): bool => (new PaymentSessionRepository())->completeCreate(
            (int) $ambiguousRow['id'], str_repeat('a', 48),
            (int) $ambiguousRow['create_version'], 'webpay_plus', str_repeat('b', 40),
            'https://webpay3gint.transbank.cl/webpayserver/initTransaction',
            $expiresAt, current_time('mysql')
        )
    ));

    $publicToken = substr(hash('sha256', $firstSession['payment_session_id']), 0, 40);
    $returnGateway = new PublicReturnGatewayFake(new WebpayCommitResult(
        'AUTHORIZED', 0, (int) $origin['amount_clp'],
        (string) $origin['buy_order'], (string) $origin['financial_session_id'],
        'AUTH123', 'VD', 0, '0715', '2026-07-15T16:00:00Z', '1234', 0
    ));
    $returnService = new WebpayReturnService(
        $returnGateway, new PaymentSessionRepository(), new WebpayReturnRepository()
    );
    $publicReturn = $returnService->process(WebpayReturnRequest::fromArray([
        'token_ws' => $publicToken,
    ]));
    assertPublicPaymentBackendSame('approved', $publicReturn->result);
    $publicReplay = $returnService->process(WebpayReturnRequest::fromArray([
        'token_ws' => $publicToken,
    ]));
    assertPublicPaymentBackendSame('already_processed', $publicReplay->result);
    assertPublicPaymentBackendSame(1, $returnGateway->commits);
    $publicTokenHash = hash('sha256', $publicToken);
    assertPublicPaymentBackendSame(1, (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}" . Config::TABLE_PREFIX
            . 'webpay_returns WHERE token_hash=%s', $publicTokenHash
    )));
    $verifyingProjection = $statusProjection->project(
        $multiple['checkout_id'],
        ['user_id' => $ownerId, 'session_id' => null]
    );
    assertPublicPaymentBackendSame('payment_verifying', $verifyingProjection['payment_status']);
    assertPublicPaymentBackendSame('wait', $verifyingProjection['next_action']);
    assertPublicPaymentBackendSame(null, $verifyingProjection['redirect_url']);
    assertPublicPaymentBackendSame(1, (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}" . Config::TABLE_PREFIX
            . 'payment_reconciliations r JOIN ' . $originsTable
            . ' o ON o.id=r.origin_context_id WHERE o.payment_attempt_id=%s',
        $firstSession['payment_session_id']
    )));
    $wpdb->query($wpdb->prepare(
        "DELETE r FROM {$wpdb->prefix}" . Config::TABLE_PREFIX
            . 'payment_reconciliations r JOIN ' . $originsTable
            . ' o ON o.id=r.origin_context_id WHERE o.payment_attempt_id=%s',
        $firstSession['payment_session_id']
    ));
    (new WebpayReturnRecovery())->recover();
    assertPublicPaymentBackendSame(1, (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}" . Config::TABLE_PREFIX
            . 'payment_reconciliations r JOIN ' . $originsTable
            . ' o ON o.id=r.origin_context_id WHERE o.payment_attempt_id=%s',
        $firstSession['payment_session_id']
    )));
    $wpdb->query($wpdb->prepare(
        "DELETE r FROM {$wpdb->prefix}" . Config::TABLE_PREFIX
            . 'payment_reconciliations r JOIN ' . $originsTable
            . ' o ON o.id=r.origin_context_id WHERE o.payment_attempt_id=%s',
        $firstSession['payment_session_id']
    ));
    $wpdb->delete(
        $wpdb->prefix . Config::TABLE_PREFIX . 'webpay_returns',
        ['token_hash' => $publicTokenHash]
    );

    echo "PASS public-payment-session-backend-test\n";
} finally {
    wp_set_current_user(0);
    if ($createdOrderIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($createdOrderIds), '%d'));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$originsTable} WHERE origin_resource_id IN ("
            . "SELECT c.public_id FROM {$checkoutsTable} c JOIN {$checkoutOrdersTable} co"
            . " ON co.checkout_id=c.id WHERE co.order_id IN ({$placeholders}))",
            ...$createdOrderIds
        ));
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
