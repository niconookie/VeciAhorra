<?php

declare(strict_types=1);

use VeciAhorra\Core\Application;
use VeciAhorra\Core\Config;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Checkout\Repository\CheckoutOrderRepository;
use VeciAhorra\Modules\Checkout\Repository\CheckoutRepository;
use VeciAhorra\Modules\Payments\Contracts\OrderPaymentConfirmationInterface;
use VeciAhorra\Modules\Payments\Exceptions\AmbiguousPaymentCommit;
use VeciAhorra\Modules\Payments\Gateway\WebpayTransactionReference;
use VeciAhorra\Modules\Payments\Models\NormalizedFinancialApproval;
use VeciAhorra\Modules\Payments\Models\PaymentConfirmationAudit;
use VeciAhorra\Modules\Payments\Repository\PaymentConfirmationAuditRepository;
use VeciAhorra\Modules\Payments\Repository\PaymentRepository;
use VeciAhorra\Modules\Payments\Repository\PaymentSessionRepository;
use VeciAhorra\Modules\Payments\Repository\WebpayReturnRepository;
use VeciAhorra\Modules\Payments\Service\PaymentConfirmationTransaction;
use VeciAhorra\Modules\Payments\Service\TransactionalPaymentConfirmationService;
use VeciAhorra\Modules\Payments\Support\PaymentConfirmationFingerprint;
use VeciAhorra\Modules\Reservations\Repository\ReservationRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertTransactionalIntegration(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

final class FailingConfirmationAuditRepository extends
    PaymentConfirmationAuditRepository
{
    public function insert(PaymentConfirmationAudit $audit): int
    {
        throw new PersistenceException('Injected audit failure.');
    }
}

final class FailOnSucceededAuditRepository extends
    PaymentConfirmationAuditRepository
{
    public function insert(PaymentConfirmationAudit $audit): int
    {
        if ($audit->eventType === PaymentConfirmationAudit::EVENT_SUCCEEDED) {
            throw new PersistenceException('Injected success audit failure.');
        }

        return parent::insert($audit);
    }
}

final class CountingFailureConfirmationTransaction extends
    PaymentConfirmationTransaction
{
    public int $calls = 0;

    public function __construct(private string $code)
    {
    }

    public function run(callable $callback): mixed
    {
        $this->calls++;

        throw new \VeciAhorra\Modules\Payments\Exceptions\PaymentConfirmationFailure(
            $this->code,
            true,
            'warning'
        );
    }
}

final class AppliedAmbiguousConfirmationTransaction extends
    PaymentConfirmationTransaction
{
    public function run(callable $callback): mixed
    {
        parent::run($callback);

        throw new AmbiguousPaymentCommit();
    }
}

final class NotAppliedAmbiguousConfirmationTransaction extends
    PaymentConfirmationTransaction
{
    public function run(callable $callback): mixed
    {
        throw new AmbiguousPaymentCommit();
    }
}

global $wpdb;

$prefix = $wpdb->prefix . Config::TABLE_PREFIX;
$created = [
    'payment_confirmation_audits' => [], 'webpay_returns' => [],
    'payment_sessions' => [], 'payment_orders' => [], 'checkout_orders' => [],
    'reservations' => [], 'order_items' => [], 'orders' => [],
    'payments' => [], 'checkouts' => [], 'inventory' => [],
];
$makeFixture = static function () use ($wpdb, $prefix, &$created): array {
    $seed = bin2hex(random_bytes(8));
    $now = current_time('mysql');
    $expires = gmdate('Y-m-d H:i:s', time() + 3600);
    $orderIds = [];
    $reservationIds = [];

    foreach ([1000, 2000] as $index => $amount) {
        $wpdb->insert($prefix . 'inventory', [
            'product_id' => random_int(910000000, 919999999),
            'minimarket_id' => 920000000 + $index,
            'price' => number_format($amount, 2, '.', ''),
            'stock' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $inventoryId = (int) $wpdb->insert_id;
        $created['inventory'][] = $inventoryId;
        $wpdb->insert($prefix . 'orders', [
            'customer_id' => 990010,
            'minimarket_id' => 920000000 + $index,
            'total' => number_format($amount, 2, '.', ''),
            'status' => 'reserved',
            'reservation_expires_at' => $expires,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $orderId = (int) $wpdb->insert_id;
        $created['orders'][] = $orderId;
        $orderIds[] = $orderId;
        $wpdb->insert($prefix . 'order_items', [
            'order_id' => $orderId,
            'product_id' => 910000000 + $index,
            'inventory_id' => $inventoryId,
            'quantity' => 1,
            'unit_price' => number_format($amount, 2, '.', ''),
            'subtotal' => number_format($amount, 2, '.', ''),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $created['order_items'][] = (int) $wpdb->insert_id;
        $wpdb->insert($prefix . 'reservations', [
            'order_id' => $orderId,
            'inventory_id' => $inventoryId,
            'product_id' => 910000000 + $index,
            'minimarket_id' => 920000000 + $index,
            'quantity' => 1,
            'status' => 'active',
            'reserved_at' => $now,
            'expires_at' => $expires,
            'released_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $reservationIds[] = (int) $wpdb->insert_id;
        $created['reservations'][] = (int) $wpdb->insert_id;
    }

    sort($orderIds, SORT_NUMERIC);
    $checkoutPublicId = 'chk_' . substr(hash('sha256', $seed), 0, 43);
    $wpdb->insert($prefix . 'checkouts', [
        'public_id' => $checkoutPublicId,
        'owner_type' => 'user',
        'user_id' => 990010,
        'session_id' => null,
        'status' => 'payment_started',
        'currency' => 'CLP',
        'total_amount' => '3000.00',
        'created_at' => $now,
        'updated_at' => $now,
        'expires_at' => $expires,
    ]);
    $checkoutId = (int) $wpdb->insert_id;
    $created['checkouts'][] = $checkoutId;

    foreach ($orderIds as $orderId) {
        $wpdb->insert($prefix . 'checkout_orders', [
            'checkout_id' => $checkoutId,
            'order_id' => $orderId,
            'created_at' => $now,
        ]);
        $created['checkout_orders'][] = (int) $wpdb->insert_id;
    }

    $wpdb->insert($prefix . 'payments', [
        'payment_reference' => 'PAY-B2-' . strtoupper($seed),
        'customer_id' => 990010,
        'amount' => '3000.00',
        'currency' => 'CLP',
        'status' => 'pending',
        'provider' => 'webpay_plus',
        'provider_reference' => null,
        'expires_at' => $expires,
        'paid_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $paymentId = (int) $wpdb->insert_id;
    $created['payments'][] = $paymentId;

    foreach ($orderIds as $orderId) {
        $wpdb->insert($prefix . 'payment_orders', [
            'payment_id' => $paymentId,
            'order_id' => $orderId,
            'created_at' => $now,
        ]);
        $created['payment_orders'][] = (int) $wpdb->insert_id;
    }

    $token = strtoupper(hash('sha256', 'token-' . $seed));
    $tokenHash = hash('sha256', $token);
    $idempotencyKey = 'b2-key-' . $seed;
    $wpdb->insert($prefix . 'payment_sessions', [
        'public_id' => 'ps_' . substr(hash('sha256', 'session-' . $seed), 0, 43),
        'checkout_id' => $checkoutId,
        'payment_id' => $paymentId,
        'idempotency_key' => $idempotencyKey,
        'request_fingerprint' => hash('sha256', 'request-' . $seed),
        'status' => 'ready',
        'provider' => 'webpay_plus',
        'provider_session_id' => $token,
        'redirect_url' => null,
        'currency' => 'CLP',
        'amount' => '3000.00',
        'metadata' => null,
        'created_at' => $now,
        'updated_at' => $now,
        'expires_at' => $expires,
    ]);
    $sessionId = (int) $wpdb->insert_id;
    $created['payment_sessions'][] = $sessionId;
    $wpdb->insert($prefix . 'webpay_returns', [
        'token_hash' => $tokenHash,
        'payment_session_id' => $sessionId,
        'flow' => 'commit',
        'processing_status' => 'completed',
        'result_status' => 'approved',
        'result_json' => '{}',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $created['webpay_returns'][] = (int) $wpdb->insert_id;
    $financial = new NormalizedFinancialApproval(
        'webpay_plus',
        'AUTHORIZED',
        0,
        3000,
        'CLP',
        WebpayTransactionReference::buyOrder(
            $checkoutPublicId,
            $idempotencyKey
        ),
        WebpayTransactionReference::sessionId($checkoutPublicId),
        '2026-07-12T10:05:00Z',
        'sha256:' . substr($tokenHash, 0, 12),
        $tokenHash,
        'VD',
        'corr_' . substr(hash('sha256', $seed), 0, 24),
        'test'
    );

    return compact(
        'financial', 'sessionId', 'paymentId', 'checkoutId', 'orderIds',
        'reservationIds', 'tokenHash'
    );
};
$container = (new Application())->container();
$service = $container->make(TransactionalPaymentConfirmationService::class);

try {
    $fixture = $makeFixture();
    $result = $service->confirm($fixture['financial']);
    assertTransactionalIntegration($result->code === 'confirmed', 'No confirmo agregado valido.');
    $session = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$prefix}payment_sessions WHERE id = %d",
        $fixture['sessionId']
    ), ARRAY_A);
    assertTransactionalIntegration(
        ($session['status'] ?? null) === 'confirmed'
        && ! empty($session['confirmed_at'])
        && preg_match('/^[a-f0-9]{64}$/D', (string) $session['confirmation_fingerprint']) === 1,
        'PaymentSession no quedo confirmada.'
    );
    assertTransactionalIntegration(
        $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$prefix}payments WHERE id = %d",
            $fixture['paymentId']
        )) === 'paid',
        'Payment no quedo paid.'
    );
    assertTransactionalIntegration(
        (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}orders WHERE id IN ("
            . implode(',', array_fill(0, count($fixture['orderIds']), '%d'))
            . ") AND status = 'paid'",
            ...$fixture['orderIds']
        )) === count($fixture['orderIds']),
        'No se confirmaron todas las Orders.'
    );
    assertTransactionalIntegration(
        (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}reservations WHERE order_id IN ("
            . implode(',', array_fill(0, count($fixture['orderIds']), '%d'))
            . ") AND status = 'consumed'",
            ...$fixture['orderIds']
        )) === count($fixture['reservationIds']),
        'Reservations no quedaron consumed.'
    );
    assertTransactionalIntegration(
        (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}payment_confirmation_audits"
            . " WHERE payment_session_id = %d AND event_type = %s",
            $fixture['sessionId'],
            PaymentConfirmationAudit::EVENT_SUCCEEDED
        )) === 1,
        'No existe auditoria unica de exito.'
    );
    $successAudit = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$prefix}payment_confirmation_audits"
        . ' WHERE payment_session_id = %d AND event_type = %s LIMIT 1',
        $fixture['sessionId'],
        PaymentConfirmationAudit::EVENT_SUCCEEDED
    ), ARRAY_A);
    unset($successAudit['id']);
    $wpdb->suppress_errors(true);
    $duplicateSuccess = $wpdb->insert(
        $prefix . 'payment_confirmation_audits',
        $successAudit
    );
    $wpdb->suppress_errors(false);
    assertTransactionalIntegration(
        $duplicateSuccess === false,
        'La base de datos acepto dos auditorias de exito equivalentes.'
    );
    $repeated = $service->confirm($fixture['financial']);
    assertTransactionalIntegration(
        $repeated->code === 'already_confirmed' && $repeated->idempotent,
        'Replay identico no fue idempotente.'
    );
    $conflicting = new NormalizedFinancialApproval(
        ...array_values(array_replace(
            get_object_vars($fixture['financial']),
            ['transactionDate' => '2026-07-12T10:06:00Z']
        ))
    );
    assertTransactionalIntegration(
        $service->confirm($conflicting)->code === 'idempotency_conflict',
        'Evidencia diferente no produjo conflicto.'
    );

    $cases = [
        'amount_mismatch' => static function (array $f): NormalizedFinancialApproval {
            return new NormalizedFinancialApproval(...array_values(array_replace(
                get_object_vars($f['financial']), ['amount' => 3001]
            )));
        },
        'buy_order_mismatch' => static function (array $f): NormalizedFinancialApproval {
            return new NormalizedFinancialApproval(...array_values(array_replace(
                get_object_vars($f['financial']),
                ['buyOrder' => 'VA' . str_repeat('C', 24)]
            )));
        },
        'session_identifier_mismatch' => static function (array $f): NormalizedFinancialApproval {
            return new NormalizedFinancialApproval(...array_values(array_replace(
                get_object_vars($f['financial']),
                ['financialSessionId' => 'VA-' . str_repeat('D', 58)]
            )));
        },
    ];

    foreach ($cases as $expected => $financialFactory) {
        $case = $makeFixture();
        $caseResult = $service->confirm($financialFactory($case));
        assertTransactionalIntegration(
            $caseResult->code === $expected,
            "Clasificacion incorrecta para {$expected}: {$caseResult->code}."
        );
        assertTransactionalIntegration(
            $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$prefix}payment_sessions WHERE id = %d",
                $case['sessionId']
            )) === 'ready',
            "{$expected} modifico PaymentSession."
        );
    }

    $databaseCases = [
        'currency_mismatch' => static function (array $f) use ($wpdb, $prefix): void {
            $wpdb->update(
                $prefix . 'payments',
                ['currency' => 'USD'],
                ['id' => $f['paymentId']]
            );
        },
        'provider_mismatch' => static function (array $f) use ($wpdb, $prefix): void {
            $wpdb->update(
                $prefix . 'payments',
                ['provider' => 'mock'],
                ['id' => $f['paymentId']]
            );
        },
        'order_set_mismatch' => static function (array $f) use ($wpdb, $prefix): void {
            $wpdb->delete($prefix . 'payment_orders', [
                'payment_id' => $f['paymentId'],
                'order_id' => $f['orderIds'][0],
            ]);
        },
        'invalid_state' => static function (array $f) use ($wpdb, $prefix): void {
            $wpdb->update(
                $prefix . 'payments',
                ['status' => 'failed'],
                ['id' => $f['paymentId']]
            );
        },
        'partial_inconsistency' => static function (array $f) use ($wpdb, $prefix): void {
            $wpdb->update(
                $prefix . 'orders',
                ['status' => 'paid'],
                ['id' => $f['orderIds'][0]]
            );
        },
    ];

    foreach ($databaseCases as $expected => $mutate) {
        $case = $makeFixture();
        $mutate($case);
        $caseResult = $service->confirm($case['financial']);
        assertTransactionalIntegration(
            $caseResult->code === $expected,
            "Caso persistente {$expected} devolvio {$caseResult->code}."
        );
    }

    $expired = $makeFixture();
    $wpdb->update(
        $prefix . 'reservations',
        ['expires_at' => '2020-01-01 00:00:00'],
        ['id' => $expired['reservationIds'][0]]
    );
    assertTransactionalIntegration(
        $service->confirm($expired['financial'])->code === 'reservation_expired',
        'Reserva expirada no bloqueo confirmacion.'
    );
    assertTransactionalIntegration(
        (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}payment_confirmation_audits"
            . " WHERE payment_session_id = %d AND event_type = %s",
            $expired['sessionId'],
            PaymentConfirmationAudit::EVENT_RESERVATION_EXPIRED
        )) === 1
        && $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$prefix}payment_sessions WHERE id = %d",
            $expired['sessionId']
        )) === 'ready',
        'Reserva expirada no dejo auditoria segura sin cambiar estado.'
    );
    assertTransactionalIntegration(
        $service->confirm($expired['financial'])->code === 'reservation_expired'
        && (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}payment_confirmation_audits"
            . " WHERE payment_session_id = %d AND event_type = %s",
            $expired['sessionId'],
            PaymentConfirmationAudit::EVENT_RESERVATION_EXPIRED
        )) === 1,
        'Replay de reserva expirada duplico la incidencia.'
    );

    $incompleteReservations = $makeFixture();
    $wpdb->delete($prefix . 'reservations', [
        'id' => $incompleteReservations['reservationIds'][0],
    ]);
    assertTransactionalIntegration(
        $service->confirm($incompleteReservations['financial'])->code
            === 'reservation_expired',
        'Cardinalidad incompleta de Reservations fue aceptada.'
    );

    $expiredSession = $makeFixture();
    $wpdb->update(
        $prefix . 'payment_sessions',
        ['status' => 'expired'],
        ['id' => $expiredSession['sessionId']]
    );
    assertTransactionalIntegration(
        $service->confirm($expiredSession['financial'])->code === 'invalid_state',
        'Session expired fue confirmada.'
    );

    $failedIncident = $makeFixture();
    $wpdb->update(
        $prefix . 'reservations',
        ['expires_at' => '2020-01-01 00:00:00'],
        ['id' => $failedIncident['reservationIds'][0]]
    );
    $failedIncidentService = new TransactionalPaymentConfirmationService(
        new WebpayReturnRepository(),
        new PaymentSessionRepository(),
        new PaymentRepository(),
        new CheckoutRepository(),
        new CheckoutOrderRepository(),
        $container->make(OrderPaymentConfirmationInterface::class),
        new ReservationRepository(),
        new FailingConfirmationAuditRepository(),
        new PaymentConfirmationTransaction()
    );
    $logFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'va-confirmation-audit-' . bin2hex(random_bytes(6)) . '.log';
    $previousLog = (string) ini_get('error_log');
    ini_set('error_log', $logFile);
    $failedIncidentResult = $failedIncidentService->confirm(
        $failedIncident['financial']
    );
    ini_set('error_log', $previousLog);
    $technicalLog = is_file($logFile)
        ? (string) file_get_contents($logFile)
        : '';

    if (is_file($logFile)) {
        unlink($logFile);
    }

    assertTransactionalIntegration(
        $failedIncidentResult->code === 'reservation_expired'
        && ! str_contains(
            $technicalLog,
            $failedIncident['financial']->tokenHash
        )
        && $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$prefix}payment_sessions WHERE id = %d",
            $failedIncident['sessionId']
        )) === 'ready',
        'Fallo de auditoria secundaria altero el resultado principal.'
    );

    $withoutPayment = $makeFixture();
    $wpdb->update(
        $prefix . 'payment_sessions',
        ['payment_id' => null],
        ['id' => $withoutPayment['sessionId']]
    );
    assertTransactionalIntegration(
        $service->confirm($withoutPayment['financial'])->code
            === 'relationship_mismatch',
        'Sesion historica sin Payment fue confirmada.'
    );

    $rollback = $makeFixture();
    $failingService = new TransactionalPaymentConfirmationService(
        new WebpayReturnRepository(),
        new PaymentSessionRepository(),
        new PaymentRepository(),
        new CheckoutRepository(),
        new CheckoutOrderRepository(),
        $container->make(OrderPaymentConfirmationInterface::class),
        new ReservationRepository(),
        new FailingConfirmationAuditRepository(),
        new PaymentConfirmationTransaction()
    );
    assertTransactionalIntegration(
        $failingService->confirm($rollback['financial'])->code
            === 'permanent_database_error',
        'Fallo de auditoria no fue clasificado.'
    );
    assertTransactionalIntegration(
        $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$prefix}payment_sessions WHERE id = %d",
            $rollback['sessionId']
        )) === 'ready'
        && $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$prefix}payments WHERE id = %d",
            $rollback['paymentId']
        )) === 'pending',
        'Fallo de auditoria no hizo rollback.'
    );

    $failedAfterStarted = $makeFixture();
    $failAfterStartedService = new TransactionalPaymentConfirmationService(
        new WebpayReturnRepository(),
        new PaymentSessionRepository(),
        new PaymentRepository(),
        new CheckoutRepository(),
        new CheckoutOrderRepository(),
        $container->make(OrderPaymentConfirmationInterface::class),
        new ReservationRepository(),
        new FailOnSucceededAuditRepository(),
        new PaymentConfirmationTransaction()
    );
    assertTransactionalIntegration(
        $failAfterStartedService->confirm($failedAfterStarted['financial'])->code
            === 'permanent_database_error'
        && (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}payment_confirmation_audits"
            . " WHERE payment_session_id = %d",
            $failedAfterStarted['sessionId']
        )) === 0,
        'confirmation_started sobrevivio al rollback.'
    );

    $unknownHash = $makeFixture();
    $unknownFinancial = new NormalizedFinancialApproval(
        ...array_values(array_replace(
            get_object_vars($unknownHash['financial']),
            [
                'tokenHash' => str_repeat('f', 64),
                'safeFinancialReference' => 'sha256:' . str_repeat('f', 12),
            ]
        ))
    );
    assertTransactionalIntegration(
        $service->confirm($unknownFinancial)->code === 'session_not_found',
        'Hash desconocido utilizo fallback inseguro.'
    );

    $crossFirst = $makeFixture();
    $crossSecond = $makeFixture();
    $crossFinancial = new NormalizedFinancialApproval(
        ...array_values(array_replace(
            get_object_vars($crossFirst['financial']),
            [
                'tokenHash' => $crossSecond['financial']->tokenHash,
                'safeFinancialReference' =>
                    $crossSecond['financial']->safeFinancialReference,
            ]
        ))
    );
    assertTransactionalIntegration(
        in_array($service->confirm($crossFinancial)->code, [
            'buy_order_mismatch', 'session_identifier_mismatch',
        ], true),
        'Hash de otra Session atraveso las validaciones.'
    );

    $serviceWithTransaction = static function (
        PaymentConfirmationTransaction $transaction
    ) use ($container): TransactionalPaymentConfirmationService {
        return new TransactionalPaymentConfirmationService(
            new WebpayReturnRepository(),
            new PaymentSessionRepository(),
            new PaymentRepository(),
            new CheckoutRepository(),
            new CheckoutOrderRepository(),
            $container->make(OrderPaymentConfirmationInterface::class),
            new ReservationRepository(),
            new PaymentConfirmationAuditRepository(),
            $transaction
        );
    };
    $appliedAmbiguous = $makeFixture();
    $appliedResult = $serviceWithTransaction(
        new AppliedAmbiguousConfirmationTransaction()
    )->confirm($appliedAmbiguous['financial']);
    assertTransactionalIntegration(
        $appliedResult->code === 'already_confirmed'
        && $appliedResult->idempotent,
        'Commit aplicado ambiguo no se recupero.'
    );

    $notApplied = $makeFixture();
    $notAppliedResult = $serviceWithTransaction(
        new NotAppliedAmbiguousConfirmationTransaction()
    )->confirm($notApplied['financial']);
    assertTransactionalIntegration(
        $notAppliedResult->code === 'not_confirmed'
        && $notAppliedResult->retryable,
        'Commit no aplicado no fue clasificado.'
    );

    $partial = $makeFixture();
    $partialFingerprint = PaymentConfirmationFingerprint::make([
        'provider' => 'webpay_plus',
        'payment_session_id' => $partial['sessionId'],
        'payment_id' => $partial['paymentId'],
        'checkout_id' => $partial['checkoutId'],
        'order_ids' => $partial['orderIds'],
        'amount' => 3000,
        'currency' => 'CLP',
        'buy_order' => $partial['financial']->buyOrder,
        'financial_session_id' => $partial['financial']->financialSessionId,
        'safe_financial_reference' =>
            $partial['financial']->safeFinancialReference,
        'transaction_date' => $partial['financial']->transactionDate,
    ]);
    $wpdb->update($prefix . 'payment_sessions', [
        'status' => 'confirmed',
        'confirmation_fingerprint' => $partialFingerprint,
        'confirmation_fingerprint_version' => 1,
        'safe_financial_reference' =>
            $partial['financial']->safeFinancialReference,
        'confirmed_at' => current_time('mysql'),
    ], ['id' => $partial['sessionId']]);
    $partialResult = $serviceWithTransaction(
        new NotAppliedAmbiguousConfirmationTransaction()
    )->confirm($partial['financial']);
    assertTransactionalIntegration(
        $partialResult->code === 'partial_inconsistency'
        && ! $partialResult->retryable,
        'Estado parcial ambiguo no fue bloqueado.'
    );

    $missingAudit = $makeFixture();
    assertTransactionalIntegration(
        $service->confirm($missingAudit['financial'])->code === 'confirmed',
        'No se preparo fixture de auditoria ambigua.'
    );
    $wpdb->delete($prefix . 'payment_confirmation_audits', [
        'payment_session_id' => $missingAudit['sessionId'],
        'event_type' => PaymentConfirmationAudit::EVENT_SUCCEEDED,
    ]);
    assertTransactionalIntegration(
        $serviceWithTransaction(
            new NotAppliedAmbiguousConfirmationTransaction()
        )->confirm($missingAudit['financial'])->code === 'partial_inconsistency',
        'Commit ambiguo sin auditoria se considero confirmado.'
    );

    foreach (['deadlock', 'lock_timeout'] as $transientCode) {
        $transient = $makeFixture();
        $countingTransaction = new CountingFailureConfirmationTransaction(
            $transientCode
        );
        $transientResult = $serviceWithTransaction($countingTransaction)
            ->confirm($transient['financial']);
        assertTransactionalIntegration(
            $transientResult->code === $transientCode
            && $transientResult->retryable
            && $countingTransaction->calls === 2,
            "Retry limitado incorrecto para {$transientCode}."
        );
    }

    echo "PASS transactional-payment-confirmation-integration-test\n";
} finally {
    $sessionIds = array_values(array_unique(array_map(
        'intval',
        $created['payment_sessions']
    )));

    if ($sessionIds !== []) {
        $wpdb->query(sprintf(
            'DELETE FROM %spayment_confirmation_audits'
            . ' WHERE payment_session_id IN (%s)',
            $prefix,
            implode(',', $sessionIds)
        ));
    }

    foreach (array_keys($created) as $table) {
        $ids = array_values(array_unique(array_map('intval', $created[$table])));

        if ($ids !== []) {
            $wpdb->query(sprintf(
                'DELETE FROM %s%s WHERE id IN (%s)',
                $prefix,
                $table,
                implode(',', $ids)
            ));
        }
    }
}
