<?php

declare(strict_types=1);

use VeciAhorra\Modules\Payments\Reconciliation\DTO\DurablePaymentOrigin;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\FinancialFingerprintComponents;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\ReconciliationReferences;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\ValidatedFinancialResult;
use VeciAhorra\Modules\Payments\Reconciliation\Service\PaymentReconciliationTechnicalEvaluator;
use VeciAhorra\Modules\Payments\Reconciliation\Support\WooCommerceTransactionReferenceFactory;
use VeciAhorra\Modules\Payments\WooCommerce\Contracts\WooCommerceOrderRepositoryInterface;
use VeciAhorra\Modules\Payments\WooCommerce\WooCommercePaymentAttemptService;
use VeciAhorra\Modules\Payments\WooCommerce\WooCommercePaymentCompletionHandler;
use VeciAhorra\Modules\Payments\WooCommerce\WooCommercePaymentCompletionOutcome;
use VeciAhorra\Modules\Payments\WooCommerce\WooCommercePaymentCompletionResult;
use VeciAhorra\Modules\Payments\Reconciliation\Model\PaymentReconciliation;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

if (! function_exists('get_current_blog_id')) {
    function get_current_blog_id(): int
    {
        return 1;
    }
}

function assertWooCompletionUnit(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

final class FakeWooCompletionStore
{
    /** @var array<string, mixed> */
    public array $data;
    public int $paymentCompleteCalls = 0;
    public bool $throwAfterCompletion = false;
    public bool $throwWithoutCompletion = false;
    public bool $completionNoOp = false;
    public bool $ambiguousNoEffect = false;

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }
}

final class FakeWooCompletionOrder
{
    /** @var array<string, mixed> */
    private array $data;

    public function __construct(private readonly FakeWooCompletionStore $store)
    {
        $this->data = $store->data;
    }

    public function get_id(): int { return (int) $this->data['id']; }
    public function get_payment_method(): string { return (string) $this->data['gateway']; }
    public function get_currency(): string { return (string) $this->data['currency']; }
    public function get_total(): string { return (string) $this->data['total']; }
    public function is_paid(): bool { return (bool) $this->data['paid']; }
    public function needs_payment(): bool { return (bool) $this->data['needs_payment']; }
    public function get_transaction_id(): string
    {
        return (string) $this->data['transaction_id'];
    }
    public function get_date_paid(string $context = 'view'): ?object
    {
        return $this->data['date_paid'] ? (object) ['utc' => true] : null;
    }
    public function get_meta(string $key): mixed
    {
        return $this->data['meta'][$key] ?? '';
    }
    public function update_meta_data(string $key, mixed $value): void
    {
        $this->data['meta'][$key] = $value;
    }
    public function save(): void
    {
        $this->store->data = $this->data;
    }
    public function payment_complete(string $reference): bool
    {
        $this->store->paymentCompleteCalls++;
        $this->data['meta'][
            WooCommercePaymentCompletionHandler::COMPLETION_ENTERED_META
        ] = substr($reference, strlen('va-wp-v1-'));
        $this->save();

        if ($this->store->throwWithoutCompletion) {
            throw new RuntimeException('simulated-pre-persistence-failure');
        }

        if ($this->store->ambiguousNoEffect) {
            $this->data['transaction_id'] = $reference;
            $this->save();
            return false;
        }

        if (! $this->store->completionNoOp) {
            $this->data['paid'] = true;
            $this->data['needs_payment'] = false;
            $this->data['transaction_id'] = $reference;
            $this->data['date_paid'] = true;
            $this->save();
        }

        if ($this->store->throwAfterCompletion) {
            throw new RuntimeException('simulated-hook-failure');
        }

        return ! $this->store->completionNoOp;
    }
}

final class FakeWooCompletionOrders implements WooCommerceOrderRepositoryInterface
{
    public function __construct(
        private readonly ?FakeWooCompletionStore $store,
        private readonly bool $ignoreRequestedId = false
    ) {
    }

    public function find(int $orderId): ?object
    {
        if (
            $this->store === null
            || (! $this->ignoreRequestedId
                && (int) $this->store->data['id'] !== $orderId)
        ) {
            return null;
        }

        return new FakeWooCompletionOrder($this->store);
    }
}

/** @return array{0:ReconciliationReferences,1:DurablePaymentOrigin,2:ValidatedFinancialResult} */
function wooCompletionEvidence(
    int $orderId = 7001,
    string $siteScope = 'wp-blog:1'
): array
{
    $merchant = hash('sha256', 'merchant-unit');
    $token = hash('sha256', 'token-unit');
    $buyOrder = 'VA' . str_repeat('A', 24);
    $session = 'VA-' . str_repeat('B', 58);
    $now = '2026-07-13 18:00:00';
    $origin = new DurablePaymentOrigin(
        'poc_' . str_repeat('c', 40),
        $siteScope,
        DurablePaymentOrigin::ORIGIN_WOOCOMMERCE,
        (string) $orderId,
        'veciahorra_webpay_plus',
        'attempt_' . str_repeat('d', 32),
        15990,
        'integration',
        $merchant,
        $buyOrder,
        $session,
        $token,
        1,
        $now,
        $now,
        '2026-07-13 19:00:00'
    );
    $financial = new ValidatedFinancialResult(
        'wpr_' . str_repeat('e', 40),
        'approved',
        'commit',
        $token,
        'sha256:' . str_repeat('f', 16),
        new FinancialFingerprintComponents(
            'integration',
            $merchant,
            'AUTHORIZED',
            0,
            15990,
            $buyOrder,
            $session,
            '2026-07-13T18:00:00Z',
            hash('sha256', 'authorization-unit'),
            'VD',
            0,
            '0713'
        ),
        $now,
        $now
    );
    $references = new ReconciliationReferences(
        81,
        71,
        61,
        'webpay_plus',
        1,
        $financial->fingerprint(),
        $origin->originKey(),
        'processing'
    );

    return [$references, $origin, $financial];
}

/** @return array<string, mixed> */
function wooCompletionOrderData(
    DurablePaymentOrigin $origin,
    int $originContextId
): array {
    return [
        'id' => (int) $origin->originResourceId(),
        'gateway' => $origin->gatewayId(),
        'currency' => 'CLP',
        'total' => '15990.00',
        'paid' => false,
        'needs_payment' => true,
        'transaction_id' => '',
        'date_paid' => false,
        'meta' => [
            WooCommercePaymentAttemptService::ATTEMPT_META =>
                $origin->paymentAttemptId(),
            WooCommercePaymentAttemptService::ORIGIN_META =>
                (string) $originContextId,
            WooCommercePaymentAttemptService::GATEWAY_META =>
                $origin->gatewayId(),
        ],
    ];
}

[$references, $origin, $financial] = wooCompletionEvidence();
$technical = (new PaymentReconciliationTechnicalEvaluator())->evaluate(
    $references,
    $origin,
    $financial
);
$store = new FakeWooCompletionStore(
    wooCompletionOrderData($origin, $references->originContextId())
);
$handler = new WooCommercePaymentCompletionHandler(
    new FakeWooCompletionOrders($store)
);
$first = $handler->complete($references, $origin, $financial, $technical);
$replay = $handler->complete($references, $origin, $financial, $technical);
$reference = WooCommerceTransactionReferenceFactory::fromFinancialFingerprint(
    $financial->fingerprint()
);
assertWooCompletionUnit(
    $first->resultCode() === WooCommercePaymentCompletionResult::APPLIED_NOW
    && $first->durableReference() === $reference
    && $replay->resultCode()
        === WooCommercePaymentCompletionResult::ALREADY_APPLIED_SAME_PAYMENT
    && $store->paymentCompleteCalls === 1,
    'El primer pago o su replay no fueron idempotentes.'
);

$crashing = new FakeWooCompletionStore(
    wooCompletionOrderData($origin, $references->originContextId())
);
$crashing->throwAfterCompletion = true;
$crashHandler = new WooCommercePaymentCompletionHandler(
    new FakeWooCompletionOrders($crashing)
);
$afterThrow = $crashHandler->complete(
    $references,
    $origin,
    $financial,
    $technical
);
$afterThrowReplay = $crashHandler->complete(
    $references,
    $origin,
    $financial,
    $technical
);
assertWooCompletionUnit(
    $afterThrow->resultCode() === WooCommercePaymentCompletionResult::APPLIED_NOW
    && $afterThrowReplay->resultCode()
        === WooCommercePaymentCompletionResult::ALREADY_APPLIED_SAME_PAYMENT
    && $crashing->paymentCompleteCalls === 1,
    'Una excepcion posterior al write repitio payment_complete().'
);

$crashedBeforeVerification = new FakeWooCompletionStore(
    wooCompletionOrderData($origin, $references->originContextId())
);
$crashedBeforeVerification->data['paid'] = true;
$crashedBeforeVerification->data['needs_payment'] = false;
$crashedBeforeVerification->data['date_paid'] = true;
$crashedBeforeVerification->data['transaction_id'] = $reference;
$crashedBeforeVerification->data['meta'][
    WooCommercePaymentCompletionHandler::COMPLETION_STARTED_META
] = $financial->fingerprint();
$recoveredBeforeVerification = (new WooCommercePaymentCompletionHandler(
    new FakeWooCompletionOrders($crashedBeforeVerification)
))->complete($references, $origin, $financial, $technical);
assertWooCompletionUnit(
    $recoveredBeforeVerification->resultCode()
        === WooCommercePaymentCompletionResult::ALREADY_APPLIED_SAME_PAYMENT
    && $crashedBeforeVerification->paymentCompleteCalls === 0
    && $crashedBeforeVerification->data['meta'][
        WooCommercePaymentCompletionHandler::RECONCILED_FINGERPRINT_META
    ] === $financial->fingerprint(),
    'La caida inmediatamente posterior a payment_complete no fue recuperada.'
);

$uncertain = new FakeWooCompletionStore(
    wooCompletionOrderData($origin, $references->originContextId())
);
$uncertain->completionNoOp = true;
$uncertainHandler = new WooCommercePaymentCompletionHandler(
    new FakeWooCompletionOrders($uncertain)
);
$uncertainFirst = $uncertainHandler->complete(
    $references,
    $origin,
    $financial,
    $technical
);
$uncertainReplay = $uncertainHandler->complete(
    $references,
    $origin,
    $financial,
    $technical
);
assertWooCompletionUnit(
    $uncertainFirst->resultCode()
        === WooCommercePaymentCompletionResult::PAYMENT_COMPLETION_FAILED
    && $uncertainReplay->resultCode()
        === WooCommercePaymentCompletionResult::PAYMENT_COMPLETION_FAILED
    && $uncertain->paymentCompleteCalls === 1,
    'Una ausencia durable del efecto permitio una segunda invocacion.'
);

$throwsWithoutEffect = new FakeWooCompletionStore(
    wooCompletionOrderData($origin, $references->originContextId())
);
$throwsWithoutEffect->throwWithoutCompletion = true;
$throwWithoutEffectHandler = new WooCommercePaymentCompletionHandler(
    new FakeWooCompletionOrders($throwsWithoutEffect)
);
$throwWithoutEffect = $throwWithoutEffectHandler->complete(
    $references,
    $origin,
    $financial,
    $technical
);
$throwWithoutEffectReplay = $throwWithoutEffectHandler->complete(
    $references,
    $origin,
    $financial,
    $technical
);
assertWooCompletionUnit(
    $throwWithoutEffect->resultCode()
        === WooCommercePaymentCompletionResult::PAYMENT_COMPLETION_FAILED
    && $throwWithoutEffectReplay->resultCode()
        === WooCommercePaymentCompletionResult::PAYMENT_COMPLETION_FAILED
    && $throwsWithoutEffect->paymentCompleteCalls === 1,
    'Una excepcion sin efecto no fue clasificada de forma estable.'
);

$ambiguous = new FakeWooCompletionStore(
    wooCompletionOrderData($origin, $references->originContextId())
);
$ambiguous->ambiguousNoEffect = true;
$ambiguousOutcome = (new WooCommercePaymentCompletionHandler(
    new FakeWooCompletionOrders($ambiguous)
))->complete($references, $origin, $financial, $technical);
assertWooCompletionUnit(
    $ambiguousOutcome->resultCode()
        === WooCommercePaymentCompletionResult::PAYMENT_RESULT_UNVERIFIED
    && $ambiguous->paymentCompleteCalls === 1,
    'La evidencia parcial fue confundida con ausencia segura.'
);

$preparedOnly = new FakeWooCompletionStore(
    wooCompletionOrderData($origin, $references->originContextId())
);
$preparedOnly->data['meta'][
    WooCommercePaymentCompletionHandler::COMPLETION_STARTED_META
] = $financial->fingerprint();
$preparedRetry = (new WooCommercePaymentCompletionHandler(
    new FakeWooCompletionOrders($preparedOnly)
))->complete($references, $origin, $financial, $technical);
assertWooCompletionUnit(
    $preparedRetry->resultCode() === WooCommercePaymentCompletionResult::APPLIED_NOW
    && $preparedOnly->paymentCompleteCalls === 1,
    'La marca preparada sin entrada bloqueo la primera invocacion real.'
);

$different = new FakeWooCompletionStore(
    wooCompletionOrderData($origin, $references->originContextId())
);
$different->data['paid'] = true;
$different->data['needs_payment'] = false;
$different->data['date_paid'] = true;
$different->data['transaction_id'] = 'another-safe-payment';
$differentOutcome = (new WooCommercePaymentCompletionHandler(
    new FakeWooCompletionOrders($different)
))->complete($references, $origin, $financial, $technical);
assertWooCompletionUnit(
    $differentOutcome->resultCode()
        === WooCommercePaymentCompletionResult::PAYMENT_ALREADY_DIFFERENT
    && $different->paymentCompleteCalls === 0,
    'Un pago diferente fue sobrescrito.'
);

$amount = new FakeWooCompletionStore(
    wooCompletionOrderData($origin, $references->originContextId())
);
$amount->data['total'] = '15991.00';
$amountOutcome = (new WooCommercePaymentCompletionHandler(
    new FakeWooCompletionOrders($amount)
))->complete($references, $origin, $financial, $technical);
$gateway = new FakeWooCompletionStore(
    wooCompletionOrderData($origin, $references->originContextId())
);
$gateway->data['gateway'] = 'other_gateway';
$gatewayOutcome = (new WooCommercePaymentCompletionHandler(
    new FakeWooCompletionOrders($gateway)
))->complete($references, $origin, $financial, $technical);
$missingOutcome = (new WooCommercePaymentCompletionHandler(
    new FakeWooCompletionOrders(null)
))->complete($references, $origin, $financial, $technical);
$orderMismatch = new FakeWooCompletionStore(
    wooCompletionOrderData($origin, $references->originContextId())
);
$orderMismatch->data['meta'][WooCommercePaymentAttemptService::ORIGIN_META] = '62';
$orderMismatchOutcome = (new WooCommercePaymentCompletionHandler(
    new FakeWooCompletionOrders($orderMismatch)
))->complete($references, $origin, $financial, $technical);
$notPayable = new FakeWooCompletionStore(
    wooCompletionOrderData($origin, $references->originContextId())
);
$notPayable->data['needs_payment'] = false;
$notPayableOutcome = (new WooCommercePaymentCompletionHandler(
    new FakeWooCompletionOrders($notPayable)
))->complete($references, $origin, $financial, $technical);
assertWooCompletionUnit(
    $amountOutcome->resultCode()
        === WooCommercePaymentCompletionResult::AMOUNT_MISMATCH
    && $gatewayOutcome->resultCode()
        === WooCommercePaymentCompletionResult::GATEWAY_MISMATCH
    && $missingOutcome->resultCode()
        === WooCommercePaymentCompletionResult::ORDER_NOT_FOUND
    && $orderMismatchOutcome->resultCode()
        === WooCommercePaymentCompletionResult::ORDER_MISMATCH
    && $notPayableOutcome->resultCode()
        === WooCommercePaymentCompletionResult::PAYMENT_COMPLETION_FAILED
    && $amount->paymentCompleteCalls === 0
    && $gateway->paymentCompleteCalls === 0,
    'Una precondicion invalida ejecuto el efecto.'
);

$currency = new FakeWooCompletionStore(
    wooCompletionOrderData($origin, $references->originContextId())
);
$currency->data['currency'] = 'USD';
$currencyOutcome = (new WooCommercePaymentCompletionHandler(
    new FakeWooCompletionOrders($currency)
))->complete($references, $origin, $financial, $technical);
$attemptMismatch = new FakeWooCompletionStore(
    wooCompletionOrderData($origin, $references->originContextId())
);
$attemptMismatch->data['meta'][WooCommercePaymentAttemptService::ATTEMPT_META] =
    'attempt_' . str_repeat('1', 32);
$attemptMismatchOutcome = (new WooCommercePaymentCompletionHandler(
    new FakeWooCompletionOrders($attemptMismatch)
))->complete($references, $origin, $financial, $technical);
$wrongOrder = new FakeWooCompletionStore(
    wooCompletionOrderData($origin, $references->originContextId())
);
$wrongOrder->data['id'] = 7002;
$wrongOrderOutcome = (new WooCommercePaymentCompletionHandler(
    new FakeWooCompletionOrders($wrongOrder, true)
))->complete($references, $origin, $financial, $technical);
[$siteReferences, $siteOrigin, $siteFinancial] = wooCompletionEvidence(
    7001,
    'wp-blog:2'
);
$siteTechnical = (new PaymentReconciliationTechnicalEvaluator())->evaluate(
    $siteReferences,
    $siteOrigin,
    $siteFinancial
);
$wrongSite = new FakeWooCompletionStore(
    wooCompletionOrderData($siteOrigin, $siteReferences->originContextId())
);
$wrongSiteOutcome = (new WooCommercePaymentCompletionHandler(
    new FakeWooCompletionOrders($wrongSite)
))->complete($siteReferences, $siteOrigin, $siteFinancial, $siteTechnical);
$fingerprintMismatch = new FakeWooCompletionStore(
    wooCompletionOrderData($origin, $references->originContextId())
);
$fingerprintMismatch->data['paid'] = true;
$fingerprintMismatch->data['needs_payment'] = false;
$fingerprintMismatch->data['date_paid'] = true;
$fingerprintMismatch->data['transaction_id'] = $reference;
$fingerprintMismatch->data['meta'][
    WooCommercePaymentCompletionHandler::RECONCILED_FINGERPRINT_META
] = str_repeat('0', 64);
$fingerprintMismatchOutcome = (new WooCommercePaymentCompletionHandler(
    new FakeWooCompletionOrders($fingerprintMismatch)
))->complete($references, $origin, $financial, $technical);
assertWooCompletionUnit(
    $currencyOutcome->resultCode()
        === WooCommercePaymentCompletionResult::AMOUNT_MISMATCH
    && $attemptMismatchOutcome->resultCode()
        === WooCommercePaymentCompletionResult::ORDER_MISMATCH
    && $wrongOrderOutcome->resultCode()
        === WooCommercePaymentCompletionResult::ORDER_MISMATCH
    && $wrongSiteOutcome->resultCode()
        === WooCommercePaymentCompletionResult::ORDER_MISMATCH
    && $fingerprintMismatchOutcome->resultCode()
        === WooCommercePaymentCompletionResult::PAYMENT_ALREADY_DIFFERENT
    && $currency->paymentCompleteCalls === 0
    && $attemptMismatch->paymentCompleteCalls === 0
    && $wrongOrder->paymentCompleteCalls === 0
    && $wrongSite->paymentCompleteCalls === 0,
    'Las validaciones explicitas de identidad permitieron un efecto.'
);

$retryableBeforeEffect = new WooCommercePaymentCompletionOutcome(
    WooCommercePaymentCompletionResult::PAYMENT_COMPLETION_FAILED,
    7001,
    null,
    'completion_marker_failed'
);
$ambiguousAfterEntry = new WooCommercePaymentCompletionOutcome(
    WooCommercePaymentCompletionResult::PAYMENT_COMPLETION_FAILED,
    7001,
    null,
    'payment_completion_without_effect'
);
assertWooCompletionUnit(
    $retryableBeforeEffect->targetReconciliationStatus()
        === PaymentReconciliation::STATUS_RETRYABLE
    && $ambiguousAfterEntry->targetReconciliationStatus()
        === PaymentReconciliation::STATUS_MANUAL_REVIEW,
    'La clasificacion retryable/manual review no respeta la frontera del efecto.'
);

echo 'PASS woocommerce-payment-completion-unit-test'
    . ' first=applied replay=already crash=recovered calls=1' . PHP_EOL;
