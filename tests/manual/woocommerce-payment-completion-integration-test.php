<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Database\Migrations\CreatePaymentOriginContextsTable;
use VeciAhorra\Database\Migrations\CreatePaymentReconciliationsTable;
use VeciAhorra\Database\Migrations\CreateWebpayReturnsTable;
use VeciAhorra\Modules\Payments\Gateway\PaymentSessionContext;
use VeciAhorra\Modules\Payments\Gateway\WebpayGatewayConfiguration;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\CreatePaymentReconciliation;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\FinancialFingerprintComponents;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\LeaseAcquireResult;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\PaymentReconciliationProcessingResult;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\StatusTransitionResult;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\ValidatedFinancialResult;
use VeciAhorra\Modules\Payments\Reconciliation\Model\PaymentReconciliation;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentOriginContextRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentReconciliationClaimRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentReconciliationRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\ValidatedFinancialResultRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Service\PaymentReconciliationProcessor;
use VeciAhorra\Modules\Payments\Reconciliation\Service\PaymentReconciliationTechnicalEvaluator;
use VeciAhorra\Modules\Payments\Reconciliation\Support\WooCommerceTransactionReferenceFactory;
use VeciAhorra\Modules\Payments\WooCommerce\WooCommercePaymentAttemptService;
use VeciAhorra\Modules\Payments\WooCommerce\WooCommercePaymentCompletionHandler;
use VeciAhorra\Modules\Payments\WooCommerce\WooCommercePaymentCompletionResult;
use VeciAhorra\Modules\Payments\WooCommerce\WooCommerceOrderRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertWooCompletionIntegration(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @return array{
 *   order:WC_Order,
 *   origin_id:int,
 *   return_id:int,
 *   reconciliation_id:int,
 *   reference:string
 * }
 */
function createWooCompletionIntegrationFixture(string $suffix): array
{
    $order = wc_create_order();
    $order->set_currency('CLP');
    $order->set_total(15990);
    $order->set_payment_method('veciahorra_webpay_plus');
    $order->save();
    $attempts = new WooCommercePaymentAttemptService();
    $attemptId = $attempts->newAttemptId();
    $context = new PaymentSessionContext(
        'wc-completion-' . hash('sha256', $attemptId),
        'wc-order-' . $order->get_id(),
        '15990.00',
        'CLP',
        gmdate('Y-m-d H:i:s', time() + 600),
        hash('sha256', 'idempotency-' . $attemptId)
    );
    $attempt = $attempts->create(
        $order,
        new WebpayGatewayConfiguration(
            'integration',
            '597055555555',
            str_repeat('A', 32),
            'https://example.test/wp-json/veciahorra/v1/payments/webpay/return'
        ),
        $context,
        $attemptId
    );
    $providerToken = 'test-provider-token-' . bin2hex(random_bytes(16));
    $attempts->bindToken($attempt, $providerToken);
    $origins = new PaymentOriginContextRepository();
    $origin = $origins->find($attempt->originContextId());

    if ($origin === null || $origin->tokenHash() === null) {
        throw new RuntimeException('No se creo el origen durable de prueba.');
    }

    $now = current_time('mysql', true);
    $components = new FinancialFingerprintComponents(
        $origin->environment(),
        $origin->merchantIdentityHash(),
        'AUTHORIZED',
        0,
        $origin->amountClp(),
        $origin->buyOrder(),
        $origin->financialSessionId(),
        '2026-07-13T18:30:00Z',
        hash('sha256', 'authorization-' . $suffix),
        'VD',
        0,
        '0713'
    );
    $financial = new ValidatedFinancialResult(
        'wpr_' . substr(hash('sha256', 'result-' . $suffix), 0, 40),
        'approved',
        'commit',
        $origin->tokenHash(),
        'sha256:' . substr(hash('sha256', 'safe-' . $suffix), 0, 16),
        $components,
        $now,
        $now
    );
    $returns = new ValidatedFinancialResultRepository();
    $returnId = $returns->create($financial);
    $reconciliationId = (new PaymentReconciliationRepository(
        $origins,
        $returns
    ))->create(new CreatePaymentReconciliation(
        'pr_' . substr(hash('sha256', 'reconciliation-' . $suffix), 0, 40),
        $returnId,
        $attempt->originContextId(),
        $financial,
        $origin,
        PaymentReconciliation::STATUS_PENDING,
        null,
        0,
        null,
        null,
        $now,
        null,
        null,
        $now
    ));

    return [
        'order' => $order,
        'origin_id' => $attempt->originContextId(),
        'return_id' => $returnId,
        'reconciliation_id' => $reconciliationId,
        'reference' => WooCommerceTransactionReferenceFactory::
            fromFinancialFingerprint($financial->fingerprint()),
    ];
}

/** @param array<string, mixed> $fixture */
function deleteWooCompletionIntegrationFixture(array $fixture): void
{
    global $wpdb;

    $wpdb->delete(
        $wpdb->prefix . Config::TABLE_PREFIX . 'payment_reconciliations',
        ['id' => $fixture['reconciliation_id']],
        ['%d']
    );
    $wpdb->delete(
        $wpdb->prefix . Config::TABLE_PREFIX . 'webpay_returns',
        ['id' => $fixture['return_id']],
        ['%d']
    );
    $wpdb->delete(
        $wpdb->prefix . Config::TABLE_PREFIX . 'payment_origin_contexts',
        ['id' => $fixture['origin_id']],
        ['%d']
    );
    $fixture['order']->delete(true);
}

(new CreatePaymentOriginContextsTable())->up();
(new CreateWebpayReturnsTable())->up();
(new CreatePaymentReconciliationsTable())->up();
$fixtures = [];
$paymentCompleteCalls = [];
$observer = static function (int $orderId) use (&$paymentCompleteCalls): void {
    $paymentCompleteCalls[$orderId] = ($paymentCompleteCalls[$orderId] ?? 0) + 1;
};
add_action('woocommerce_pre_payment_complete', $observer, 10, 1);

try {
    $first = createWooCompletionIntegrationFixture(
        'first-' . bin2hex(random_bytes(8))
    );
    $fixtures[] = $first;
    $claims = new PaymentReconciliationClaimRepository();
    $lease = $claims->acquireLease(
        $first['reconciliation_id'],
        PaymentReconciliationClaimRepository::ownerId(),
        60
    )->lease();
    assertWooCompletionIntegration($lease !== null, 'No se adquirio el lease.');
    $processed = (new PaymentReconciliationProcessor())->process($lease);
    $persisted = wc_get_order($first['order']->get_id());
    $state = $claims->findLease($first['reconciliation_id']);
    $freshOrders = new WooCommerceOrderRepository();
    $freshOne = $freshOrders->find($first['order']->get_id());
    $freshTwo = $freshOrders->find($first['order']->get_id());
    assertWooCompletionIntegration(
        $processed->status() === PaymentReconciliationProcessingResult::PROCESSED
        && $processed->completionOutcome()?->resultCode()
            === WooCommercePaymentCompletionResult::APPLIED_NOW
        && $paymentCompleteCalls[$first['order']->get_id()] === 1
        && $persisted instanceof WC_Order
        && $persisted->is_paid()
        && $persisted->get_date_paid('edit') !== null
        && $persisted->get_transaction_id() === $first['reference']
        && $persisted->get_meta(
            WooCommercePaymentCompletionHandler::RECONCILED_FINGERPRINT_META
        ) === substr($first['reference'], strlen('va-wp-v1-'))
        && $state?->reconciliationStatus()
            === PaymentReconciliation::STATUS_COMPLETED
        && $state->businessResultCode()
            === WooCommercePaymentCompletionResult::APPLIED_NOW
        && $freshOne !== null
        && $freshTwo !== null
        && $freshOne !== $freshTwo,
        'El pago real no quedo durable antes del CAS exitoso.'
    );

    $preparedCrash = createWooCompletionIntegrationFixture(
        'prepared-crash-' . bin2hex(random_bytes(8))
    );
    $fixtures[] = $preparedCrash;
    $preparedOwner = PaymentReconciliationClaimRepository::ownerId();
    $preparedLeaseOne = $claims->acquireLease(
        $preparedCrash['reconciliation_id'],
        $preparedOwner,
        1
    )->lease();
    assertWooCompletionIntegration(
        $preparedLeaseOne !== null,
        'No se adquirio el lease previo al crash preparado.'
    );
    $preparedOrder = wc_get_order($preparedCrash['order']->get_id());
    assertWooCompletionIntegration(
        $preparedOrder instanceof WC_Order,
        'No se resolvio el pedido para persistir la marca previa.'
    );
    $preparedOrder->update_meta_data(
        WooCommercePaymentCompletionHandler::COMPLETION_STARTED_META,
        substr($preparedCrash['reference'], strlen('va-wp-v1-'))
    );
    $preparedOrder->save();
    unset($preparedOrder);
    sleep(2);
    $preparedLeaseTwo = $claims->acquireLease(
        $preparedCrash['reconciliation_id'],
        PaymentReconciliationClaimRepository::ownerId(),
        60
    )->lease();
    assertWooCompletionIntegration(
        $preparedLeaseTwo !== null
        && $preparedLeaseOne->version() === 1
        && $preparedLeaseTwo->version() === 2,
        'El crash preparado no produjo una nueva generacion del lease.'
    );
    $preparedRecovered = (new PaymentReconciliationProcessor())->process(
        $preparedLeaseTwo
    );
    $preparedPersisted = wc_get_order($preparedCrash['order']->get_id());
    assertWooCompletionIntegration(
        $preparedRecovered->processed()
        && $preparedRecovered->completionOutcome()?->resultCode()
            === WooCommercePaymentCompletionResult::APPLIED_NOW
        && $paymentCompleteCalls[$preparedCrash['order']->get_id()] === 1
        && $preparedPersisted instanceof WC_Order
        && $preparedPersisted->is_paid()
        && $preparedPersisted->get_meta(
            WooCommercePaymentCompletionHandler::COMPLETION_ENTERED_META
        ) === substr($preparedCrash['reference'], strlen('va-wp-v1-')),
        'La marca previa produjo falso positivo o bloqueo la primera invocacion.'
    );

    $crash = createWooCompletionIntegrationFixture(
        'crash-' . bin2hex(random_bytes(8))
    );
    $fixtures[] = $crash;
    $crashOwner = PaymentReconciliationClaimRepository::ownerId();
    $crashLease = $claims->acquireLease(
        $crash['reconciliation_id'],
        $crashOwner,
        60
    )->lease();
    assertWooCompletionIntegration(
        $crashLease !== null,
        'No se adquirio el lease del crash simulado.'
    );
    $reconciliations = new PaymentReconciliationRepository();
    $references = $reconciliations->findReferences(
        $crash['reconciliation_id']
    );
    $origin = (new PaymentOriginContextRepository())->find(
        $crash['origin_id']
    );
    $financial = (new ValidatedFinancialResultRepository())->find(
        $crash['return_id']
    );
    assertWooCompletionIntegration(
        $references !== null && $origin !== null && $financial !== null,
        'No se resolvio evidencia para el crash simulado.'
    );
    $technical = (new PaymentReconciliationTechnicalEvaluator())->evaluate(
        $references,
        $origin,
        $financial
    );
    $effectBeforeCrash = (new WooCommercePaymentCompletionHandler())->complete(
        $references,
        $origin,
        $financial,
        $technical
    );
    assertWooCompletionIntegration(
        $effectBeforeCrash->resultCode()
            === WooCommercePaymentCompletionResult::APPLIED_NOW
        && $claims->findLease($crash['reconciliation_id'])?->reconciliationStatus()
            === PaymentReconciliation::STATUS_PROCESSING,
        'La simulacion no quedo entre payment_complete y CAS.'
    );
    $recovered = (new PaymentReconciliationProcessor())->process($crashLease);
    assertWooCompletionIntegration(
        $recovered->processed()
        && $recovered->completionOutcome()?->resultCode()
            === WooCommercePaymentCompletionResult::ALREADY_APPLIED_SAME_PAYMENT
        && $paymentCompleteCalls[$crash['order']->get_id()] === 1
        && $claims->findLease($crash['reconciliation_id'])?->businessResultCode()
            === WooCommercePaymentCompletionResult::ALREADY_APPLIED_SAME_PAYMENT,
        'El crash entre efecto y CAS repitio payment_complete o no cerro.'
    );

    $casRejected = createWooCompletionIntegrationFixture(
        'cas-rejected-' . bin2hex(random_bytes(8))
    );
    $fixtures[] = $casRejected;
    $casOwner = PaymentReconciliationClaimRepository::ownerId();
    $casLease = $claims->acquireLease(
        $casRejected['reconciliation_id'],
        $casOwner,
        60
    )->lease();
    assertWooCompletionIntegration(
        $casLease !== null,
        'No se adquirio lease para CAS rechazado.'
    );
    $casReferences = $reconciliations->findReferences(
        $casRejected['reconciliation_id']
    );
    $casOrigin = (new PaymentOriginContextRepository())->find(
        $casRejected['origin_id']
    );
    $casFinancial = (new ValidatedFinancialResultRepository())->find(
        $casRejected['return_id']
    );
    assertWooCompletionIntegration(
        $casReferences !== null && $casOrigin !== null && $casFinancial !== null,
        'No se resolvio evidencia para CAS rechazado.'
    );
    $casTechnical = (new PaymentReconciliationTechnicalEvaluator())->evaluate(
        $casReferences,
        $casOrigin,
        $casFinancial
    );
    $casEffect = (new WooCommercePaymentCompletionHandler())->complete(
        $casReferences,
        $casOrigin,
        $casFinancial,
        $casTechnical
    );
    assertWooCompletionIntegration(
        $casEffect->successful()
        && $claims->releaseLease(
            $casLease->reconciliationId(),
            $casLease->owner(),
            $casLease->version()
        )->released(),
        'No se preparo la perdida de autoridad posterior al efecto.'
    );
    $rejectedTransition = $claims->compareAndSetStatus(
        $casLease->reconciliationId(),
        $casLease->owner(),
        $casLease->version(),
        PaymentReconciliation::STATUS_PROCESSING,
        PaymentReconciliation::STATUS_COMPLETED,
        WooCommercePaymentCompletionResult::APPLIED_NOW
    );
    assertWooCompletionIntegration(
        ! $rejectedTransition->applied()
        && $rejectedTransition->status() === StatusTransitionResult::NOT_OWNER
        && $claims->findLease($casRejected['reconciliation_id'])?->reconciliationStatus()
            === PaymentReconciliation::STATUS_PROCESSING
        && $paymentCompleteCalls[$casRejected['order']->get_id()] === 1,
        'El CAS sin autoridad se aplico o repitio el efecto.'
    );
    $replacementLease = $claims->acquireLease(
        $casRejected['reconciliation_id'],
        PaymentReconciliationClaimRepository::ownerId(),
        60
    )->lease();
    assertWooCompletionIntegration(
        $replacementLease !== null
        && $replacementLease->version() === $casLease->version() + 1,
        'No se adquirio una nueva generacion tras CAS rechazado.'
    );
    $casRecovered = (new PaymentReconciliationProcessor())->process(
        $replacementLease
    );
    assertWooCompletionIntegration(
        $casRecovered->processed()
        && $casRecovered->completionOutcome()?->resultCode()
            === WooCommercePaymentCompletionResult::ALREADY_APPLIED_SAME_PAYMENT
        && $paymentCompleteCalls[$casRejected['order']->get_id()] === 1,
        'La recuperacion del CAS rechazado duplico payment_complete.'
    );

    $expired = createWooCompletionIntegrationFixture(
        'expired-' . bin2hex(random_bytes(8))
    );
    $fixtures[] = $expired;
    $expiredLease = $claims->acquireLease(
        $expired['reconciliation_id'],
        PaymentReconciliationClaimRepository::ownerId(),
        1
    );
    assertWooCompletionIntegration(
        $expiredLease->status() === LeaseAcquireResult::ACQUIRED,
        'No se preparo lease expirable.'
    );
    sleep(2);
    $expiredResult = (new PaymentReconciliationProcessor())->process(
        $expiredLease->lease()
    );
    $expiredOrder = wc_get_order($expired['order']->get_id());
    assertWooCompletionIntegration(
        $expiredResult->status()
            === PaymentReconciliationProcessingResult::AUTHORITY_LOST
        && $expiredOrder instanceof WC_Order
        && ! $expiredOrder->is_paid()
        && ($paymentCompleteCalls[$expired['order']->get_id()] ?? 0) === 0,
        'Un lease expirado ejecuto el efecto WooCommerce.'
    );

    $mismatch = createWooCompletionIntegrationFixture(
        'mismatch-' . bin2hex(random_bytes(8))
    );
    $fixtures[] = $mismatch;
    $mismatchOrder = wc_get_order($mismatch['order']->get_id());
    assertWooCompletionIntegration(
        $mismatchOrder instanceof WC_Order,
        'No se recargo pedido para mismatch.'
    );
    $mismatchOrder->set_total(15991);
    $mismatchOrder->save();
    $mismatchLease = $claims->acquireLease(
        $mismatch['reconciliation_id'],
        PaymentReconciliationClaimRepository::ownerId(),
        60
    )->lease();
    assertWooCompletionIntegration(
        $mismatchLease !== null,
        'No se adquirio lease para mismatch.'
    );
    $mismatchResult = (new PaymentReconciliationProcessor())->process(
        $mismatchLease
    );
    $mismatchState = $claims->findLease($mismatch['reconciliation_id']);
    $mismatchPersisted = wc_get_order($mismatch['order']->get_id());
    assertWooCompletionIntegration(
        $mismatchResult->status()
            === PaymentReconciliationProcessingResult::COMPLETION_REJECTED
        && $mismatchResult->completionOutcome()?->resultCode()
            === WooCommercePaymentCompletionResult::AMOUNT_MISMATCH
        && $mismatchState?->reconciliationStatus()
            === PaymentReconciliation::STATUS_PERMANENT_FAILURE
        && $mismatchState->businessResultCode()
            === WooCommercePaymentCompletionResult::AMOUNT_MISMATCH
        && $mismatchPersisted instanceof WC_Order
        && ! $mismatchPersisted->is_paid()
        && ($paymentCompleteCalls[$mismatch['order']->get_id()] ?? 0) === 0,
        'El mismatch no fue cerrado por CAS sin efecto.'
    );

    echo 'PASS woocommerce-payment-completion-integration-test'
        . ' first=applied crash=already calls=1 expired=no_effect'
        . ' prepared_crash=version_2_applied cas_rejected=recovered'
        . ' mismatch=permanent_failure cas=completed'
        . PHP_EOL;
} finally {
    remove_action('woocommerce_pre_payment_complete', $observer, 10);

    foreach (array_reverse($fixtures) as $fixture) {
        deleteWooCompletionIntegrationFixture($fixture);
    }
}
