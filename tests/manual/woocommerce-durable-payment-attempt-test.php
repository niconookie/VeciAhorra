<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Database\Migrations\CreatePaymentOriginContextsTable;
use VeciAhorra\Database\Migrations\CreatePaymentReconciliationsTable;
use VeciAhorra\Database\Migrations\CreateWebpayReturnsTable;
use VeciAhorra\Modules\Payments\Gateway\PaymentSessionContext;
use VeciAhorra\Modules\Payments\Gateway\WebpayCommitResult;
use VeciAhorra\Modules\Payments\Gateway\WebpayGatewayConfiguration;
use VeciAhorra\Modules\Payments\Gateway\WebpayReturnContext;
use VeciAhorra\Modules\Payments\Gateway\WebpayReturnGatewayInterface;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\PaymentOriginTokenBindResult;
use VeciAhorra\Modules\Payments\Reconciliation\Exception\ReconciliationMaterializationConflict;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentOriginContextRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Service\WebpayReconciliationMaterializer;
use VeciAhorra\Modules\Payments\Reconciliation\Support\WooCommerceTransactionReferenceFactory;
use VeciAhorra\Modules\Payments\Reconciliation\Support\WordPressSiteScope;
use VeciAhorra\Modules\Payments\Repository\WebpayReturnRepository;
use VeciAhorra\Modules\Payments\Repository\PaymentSessionRepository;
use VeciAhorra\Modules\Payments\Repository\TransientWebpayReturnContextRepository;
use VeciAhorra\Modules\Payments\Requests\WebpayReturnRequest;
use VeciAhorra\Modules\Payments\Service\WebpayReturnService;
use VeciAhorra\Modules\Payments\Support\WebpayTokenReference;
use VeciAhorra\Modules\Payments\WooCommerce\WooCommercePaymentAttemptService;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertDurableWooAttempt(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

final class DurableAttemptReturnGateway implements WebpayReturnGatewayInterface
{
    public int $commits = 0;

    public function __construct(private readonly WebpayCommitResult $result)
    {
    }

    public function commit(string $token): WebpayCommitResult
    {
        $this->commits++;
        return $this->result;
    }
}

global $wpdb;

(new CreatePaymentOriginContextsTable())->up();
(new CreateWebpayReturnsTable())->up();
(new CreatePaymentReconciliationsTable())->up();
$order = wc_create_order();
$order->set_currency('CLP');
$order->set_total(15990);
$order->set_payment_method('veciahorra_webpay_plus');
$order->save();
$configuration = new WebpayGatewayConfiguration(
    'integration',
    '597055555555',
    str_repeat('A', 32),
    'https://example.test/wp-json/veciahorra/v1/payments/webpay/return'
);
$attempts = new WooCommercePaymentAttemptService();
$origins = new PaymentOriginContextRepository();
$originTable = $wpdb->prefix . Config::TABLE_PREFIX . 'payment_origin_contexts';
$returnTable = $wpdb->prefix . Config::TABLE_PREFIX . 'webpay_returns';
$reconciliationTable = $wpdb->prefix . Config::TABLE_PREFIX . 'payment_reconciliations';
$originIds = [];
$returnIds = [];
$reconciliationIds = [];

try {
    assertDurableWooAttempt(
        WooCommerceTransactionReferenceFactory::fromFinancialFingerprint(
            str_repeat('a', 64)
        ) === 'va-wp-v1-' . str_repeat('a', 64),
        'El vector transaction_reference_v1 cambio.'
    );
    $attemptOne = $attempts->newAttemptId();
    $contextOne = new PaymentSessionContext(
        'wc-payment-' . hash('sha256', $attemptOne),
        'wc-order-' . $order->get_id(),
        '15990.00',
        'CLP',
        gmdate('Y-m-d H:i:s', time() + 600),
        hash('sha256', $attemptOne)
    );
    $createdOne = $attempts->create(
        $order,
        $configuration,
        $contextOne,
        $attemptOne
    );
    $originIds[] = $createdOne->originContextId();
    $repeatedOne = $attempts->create(
        $order,
        $configuration,
        $contextOne,
        $attemptOne
    );
    assertDurableWooAttempt(
        $createdOne->originContextId() === $repeatedOne->originContextId()
        && WordPressSiteScope::current() === 'wp-blog:' . get_current_blog_id()
        && WordPressSiteScope::isValid('wp-blog:1')
        && ! WordPressSiteScope::isValid('1')
        && ! WordPressSiteScope::isValid('wp-blog:0')
        && $order->get_meta(WooCommercePaymentAttemptService::ATTEMPT_META)
            === $attemptOne
        && (string) $order->get_meta(WooCommercePaymentAttemptService::ORIGIN_META)
            === (string) $createdOne->originContextId()
        && $order->get_meta(WooCommercePaymentAttemptService::GATEWAY_META)
            === 'veciahorra_webpay_plus',
        'El intento local no fue durable e idempotente.'
    );

    $attemptTwo = $attempts->newAttemptId();
    $contextTwo = new PaymentSessionContext(
        'wc-payment-' . hash('sha256', $attemptTwo),
        'wc-order-' . $order->get_id(),
        '15990.00',
        'CLP',
        gmdate('Y-m-d H:i:s', time() + 600),
        hash('sha256', $attemptTwo)
    );
    $createdTwo = $attempts->create(
        $order,
        $configuration,
        $contextTwo,
        $attemptTwo
    );
    $originIds[] = $createdTwo->originContextId();
    assertDurableWooAttempt(
        $attemptOne !== $attemptTwo
        && $createdOne->originContextId() !== $createdTwo->originContextId(),
        'Dos intentos reales reutilizaron identidad.'
    );

    $token = str_repeat('Q', 64);
    $tokenHash = WebpayTokenReference::hash($token);
    $attempts->bindToken($createdOne, $token);
    $safeOrderMetadata = [
        WooCommercePaymentAttemptService::ATTEMPT_META =>
            $order->get_meta(WooCommercePaymentAttemptService::ATTEMPT_META),
        WooCommercePaymentAttemptService::ORIGIN_META =>
            $order->get_meta(WooCommercePaymentAttemptService::ORIGIN_META),
        WooCommercePaymentAttemptService::GATEWAY_META =>
            $order->get_meta(WooCommercePaymentAttemptService::GATEWAY_META),
    ];
    $sameBind = $origins->bindTokenHash(
        $createdOne->originContextId(),
        $attemptOne,
        $tokenHash,
        current_time('mysql', true)
    );
    $conflictBind = $origins->bindTokenHash(
        $createdTwo->originContextId(),
        $attemptTwo,
        $tokenHash,
        current_time('mysql', true)
    );
    assertDurableWooAttempt(
        $sameBind->status() === PaymentOriginTokenBindResult::ALREADY_BOUND
        && $conflictBind->status() === PaymentOriginTokenBindResult::TOKEN_CONFLICT
        && ! str_contains(
            json_encode($safeOrderMetadata, JSON_THROW_ON_ERROR),
            $token
        )
        && $origins->findByTokenHash($tokenHash)?->paymentAttemptId()
            === $attemptOne,
        'El CAS permitio overwrite o apropiacion del token.'
    );

    $serviceToken = str_repeat('S', 64);
    $serviceTokenHash = WebpayTokenReference::hash($serviceToken);
    $attempts->bindToken($createdTwo, $serviceToken);
    $serviceOrigin = $origins->find($createdTwo->originContextId());
    assertDurableWooAttempt($serviceOrigin !== null, 'Origen de retorno ausente.');
    $serviceCommit = new WebpayCommitResult(
        'AUTHORIZED', 0, 15990, $serviceOrigin->buyOrder(),
        $serviceOrigin->financialSessionId(), 'AUTH-SERVICE', 'VD', 0,
        '0713', '2026-07-13T16:30:00Z', '1234', 0
    );
    $returnContexts = new TransientWebpayReturnContextRepository();
    $returnContexts->store(
        $serviceTokenHash,
        new WebpayReturnContext(
            WebpayReturnContext::SOURCE_WOOCOMMERCE,
            'integration',
            '597055555555',
            $serviceOrigin->buyOrder(),
            $serviceOrigin->financialSessionId(),
            15990,
            time() + 600
        ),
        600
    );
    $returnGateway = new DurableAttemptReturnGateway($serviceCommit);
    $returnService = new WebpayReturnService(
        $returnGateway,
        new PaymentSessionRepository(),
        new WebpayReturnRepository(),
        $returnContexts,
        null,
        $origins,
        new WebpayReconciliationMaterializer()
    );
    $serviceResult = $returnService->process(
        WebpayReturnRequest::fromArray(['token_ws' => $serviceToken])
    );
    $serviceRepeated = $returnService->process(
        WebpayReturnRequest::fromArray(['token_ws' => $serviceToken])
    );
    $serviceReturnId = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$returnTable} WHERE token_hash = %s",
        $serviceTokenHash
    ));
    $serviceReconciliationId = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$reconciliationTable} WHERE webpay_return_id = %d",
        $serviceReturnId
    ));
    $returnIds[] = $serviceReturnId;
    $reconciliationIds[] = $serviceReconciliationId;
    assertDurableWooAttempt(
        $serviceResult->result === 'approved'
        && $serviceRepeated->result === 'already_processed'
        && $returnGateway->commits === 1
        && $serviceReturnId > 0
        && $serviceReconciliationId > 0,
        'El retorno sin sesion WooCommerce no materializo una unica conciliacion.'
    );

    $returns = new WebpayReturnRepository();
    $claim = $returns->claim($tokenHash, null, 'commit', current_time('mysql', true));
    assertDurableWooAttempt(($claim['claimed'] ?? false) === true, 'No se creo inbox.');
    $commit = new WebpayCommitResult(
        'AUTHORIZED',
        0,
        15990,
        $origins->find($createdOne->originContextId())?->buyOrder() ?? '',
        $origins->find($createdOne->originContextId())?->financialSessionId() ?? '',
        'AUTH-TEST',
        'VD',
        0,
        '0713',
        '2026-07-13T16:30:00Z',
        '1234',
        0
    );
    $materializer = new WebpayReconciliationMaterializer();
    $materialized = $materializer->materialize(
        $tokenHash,
        $origins->find($createdOne->originContextId()),
        $commit,
        'approved'
    );
    $returnIds[] = $materialized->webpayReturnId();
    $reconciliationIds[] = $materialized->reconciliationId();
    $repeated = $materializer->resume(
        $tokenHash,
        $origins->find($createdOne->originContextId())
    );
    assertDurableWooAttempt(
        $repeated?->reconciliationId() === $materialized->reconciliationId()
        && $repeated->transactionReference()
            === WooCommerceTransactionReferenceFactory::fromFinancialFingerprint(
                substr($materialized->transactionReference(), strlen('va-wp-v1-'))
            )
        && (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$reconciliationTable} WHERE id = %d",
            $materialized->reconciliationId()
        )) === 1,
        'El retorno repetido duplico autoridades.'
    );

    try {
        $materializer->materialize(
            $tokenHash,
            $origins->find($createdOne->originContextId()),
            new WebpayCommitResult(
                'AUTHORIZED', 0, 15991, $commit->buyOrder, $commit->sessionId,
                'AUTH-OTHER', 'VD', 0, '0713', '2026-07-13T16:30:00Z',
                '1234', 0
            ),
            'approved'
        );
        throw new RuntimeException('Se acepto evidencia financiera incompatible.');
    } catch (ReconciliationMaterializationConflict) {
    }

    $wpdb->delete(
        $reconciliationTable,
        ['id' => $materialized->reconciliationId()],
        ['%d']
    );
    $resumedAfterCrash = $materializer->resume(
        $tokenHash,
        $origins->find($createdOne->originContextId())
    );
    $reconciliationIds[] = (int) $resumedAfterCrash?->reconciliationId();
    assertDurableWooAttempt(
        $resumedAfterCrash !== null
        && $resumedAfterCrash->webpayReturnId() === $materialized->webpayReturnId(),
        'La caida entre evidencia y conciliacion no fue reanudable.'
    );

    echo 'PASS woocommerce-durable-payment-attempt-test'
        . ' attempts=2 local_repeat=1 bind=cas reconciliation=resume' . PHP_EOL;
} finally {
    foreach (array_unique(array_filter($reconciliationIds)) as $id) {
        $wpdb->delete($reconciliationTable, ['id' => $id], ['%d']);
    }
    foreach (array_unique($returnIds) as $id) {
        $wpdb->delete($returnTable, ['id' => $id], ['%d']);
    }
    foreach (array_unique($originIds) as $id) {
        $wpdb->delete($originTable, ['id' => $id], ['%d']);
    }
    $order->delete(true);
}
