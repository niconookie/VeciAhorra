<?php
declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Gateway {
    // Policy input double: only the closed deployment is simulated; process settings remain Mock.
    function getenv(string $name): string|false {
        if (($GLOBALS['minimalClosedWebpay'] ?? false) === true) {
            return match ($name) {
                'VECIAHORRA_PAYMENT_GATEWAY' => 'webpay',
                'VECIAHORRA_WEBPAY_ENVIRONMENT' => 'production',
                'VECIAHORRA_WEBPAY_PRODUCTION_ENABLED' => '0',
                default => \getenv($name),
            };
        }
        return \getenv($name);
    }
    if (($argv[1] ?? '') === 'classification') {
        // Only the external redirect allowlist is doubled for loopback. The actual
        // PaymentSessionService still claims, marks, classifies and persists each result.
        final class WebpayPaymentGateway {
            public static function isAllowedPaymentUrl(string $environment, mixed $url): bool {
                return is_string($url) && str_starts_with($url, 'http://127.0.0.1/');
            }
        }
    }
}
namespace {
require __DIR__ . '/support/minimal-runtime.php';

use VeciAhorra\Core\Application;
use VeciAhorra\Exceptions\ConflictException;
use VeciAhorra\Modules\Checkout\Service\CheckoutService;
use VeciAhorra\Modules\Orders\Repositories\OrderAdminReadRepository;
use VeciAhorra\Modules\Orders\Services\OrderOperationalFactsAssembler;
use VeciAhorra\Modules\Orders\Domain\Operational\OrderOperationalFacts;
use VeciAhorra\Modules\Orders\Domain\Operational\OrderOperationalStateResolver;
use VeciAhorra\Modules\Payments\Gateway\PaymentGatewayInterface;
use VeciAhorra\Modules\Payments\Gateway\PaymentSessionContext;
use VeciAhorra\Modules\Payments\Gateway\GatewaySessionResult;
use VeciAhorra\Modules\Payments\Gateway\WebpayGatewayConfiguration;
use VeciAhorra\Modules\Payments\Gateway\WebpayPaymentGateway;
use VeciAhorra\Modules\Payments\Gateway\WebpayTransactionReference;
use VeciAhorra\Modules\Payments\Repository\PaymentRepository;
use VeciAhorra\Modules\Payments\Repository\PaymentSessionRepository;
use VeciAhorra\Modules\Payments\Service\PaymentSessionService;
use VeciAhorra\Modules\Payments\Service\PublicPaymentStatusService;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\DurablePaymentOrigin;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentOriginContextRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Support\WordPressSiteScope;

global $wpdb;
$count = 0;
$assert = static function (bool $ok, string $message) use (&$count): void {
    ++$count;
    if (!$ok) { throw new RuntimeException($message); }
};
$app = new Application();
$checkout = $app->container()->make(CheckoutService::class);
$local = new class implements PaymentGatewayInterface {
    public string $mode = 'success';
    public int $calls = 0;
    public function createSession(PaymentSessionContext $context): GatewaySessionResult {
        ++$this->calls;
        if ($this->mode === 'uncertain') { throw new RuntimeException('LOCAL_POST_SEND_UNCERTAINTY'); }
        return new GatewaySessionResult('webpay_plus', 'LOCAL' . bin2hex(random_bytes(16)),
            $this->mode === 'success' ? 'ready' : $this->mode,
            $this->mode === 'success' ? 'http://127.0.0.1/minimal-0314-final-fix' : null,
            $context->expiresAt, $this->mode === 'success' ? null : 'local_known_result');
    }
    public function recoverSession(string $id): GatewaySessionResult { throw new RuntimeException('UNUSED'); }
};
$service = new PaymentSessionService(new PaymentRepository(), $local);
$snapshot = static function () use ($wpdb): array {
    $out = [];
    foreach ($wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}va_%'") as $table) {
        $rows = $wpdb->get_results("SELECT * FROM `$table`", ARRAY_A);
        usort($rows, static fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
        $out[$table] = hash('sha256', json_encode($rows));
    }
    ksort($out);
    return $out;
};
$insert = static function (string $table, array $row) use ($wpdb): int {
    if ($wpdb->insert($wpdb->prefix . 'va_' . $table, $row) !== 1) {
        throw new RuntimeException('FIXTURE_INSERT_' . $table);
    }
    return (int) $wpdb->insert_id;
};
$fixture = static function (array $prices = [1500], int $platform = 700, int $delivery = 0)
    use ($insert, $checkout, $wpdb): array {
    $now = current_time('mysql');
    $future = gmdate('Y-m-d H:i:s', time() + 3600);
    $orders = [];
    foreach ($prices as $price) {
        $seed = random_int(200000000, 800000000);
        $inventory = $insert('inventory', ['id' => $seed, 'product_id' => $seed, 'minimarket_id' => $seed,
            'price' => "$price.00", 'stock' => 4, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $order = $insert('orders', ['id' => $seed, 'customer_id' => 1, 'minimarket_id' => $seed,
            'total' => "$price.00", 'status' => 'reserved', 'reservation_expires_at' => $future,
            'created_at' => $now, 'updated_at' => $now]);
        $insert('order_items', ['order_id' => $order, 'product_id' => $seed, 'inventory_id' => $inventory,
            'quantity' => 1, 'unit_price' => "$price.00", 'subtotal' => "$price.00", 'created_at' => $now, 'updated_at' => $now]);
        $insert('reservations', ['order_id' => $order, 'inventory_id' => $inventory, 'product_id' => $seed,
            'minimarket_id' => $seed, 'quantity' => 1, 'status' => 'active', 'reserved_at' => $now,
            'expires_at' => $future, 'created_at' => $now, 'updated_at' => $now]);
        $orders[] = $order;
    }
    $subtotal = array_sum($prices); $total = $subtotal + $platform + $delivery;
    $method = $delivery ? 'delivery' : 'pickup';
    $owner = ['user_id' => 1, 'fulfillment_method' => $method, 'financial_snapshot' => [
        'fulfillment_method' => $method, 'product_subtotal' => "$subtotal.00", 'platform_fee' => "$platform.00",
        'delivery_fee' => "$delivery.00", 'total' => "$total.00", 'fee_policy_version' => 'minimal-0314-final-fix']];
    if ($delivery) { $owner['delivery'] = ['recipient_name' => 'Local', 'contact_phone' => '+56900000000',
        'address_line1' => 'Local 1', 'commune' => 'Local']; }
    $created = $checkout->createPersistent($owner, $orders);
    $public = $created['checkout_id'] ?? $created['public_id'];
    $id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}va_checkouts WHERE public_id=%s", $public));
    return compact('orders', 'public', 'id', 'total', 'future', 'now', 'inventory');
};
$attempt = static function (array $f, string $status = 'pending', ?string $token = null): array {
    $public = 'ps_' . bin2hex(random_bytes(20)); $key = 'minimal-' . bin2hex(random_bytes(12));
    $repo = new PaymentSessionRepository();
    $id = $repo->create(['public_id' => $public, 'checkout_id' => $f['id'], 'idempotency_key' => $key,
        'request_fingerprint' => hash('sha256', $key), 'status' => $status,
        'provider' => $token === null ? null : 'webpay_plus', 'provider_session_id' => $token,
        'redirect_url' => null, 'currency' => 'CLP', 'amount' => $f['total'] . '.00', 'metadata' => null,
        'created_at' => $f['now'], 'updated_at' => $f['now'], 'expires_at' => $f['future']]);
    $origin = new DurablePaymentOrigin('poc_' . bin2hex(random_bytes(20)), WordPressSiteScope::current(),
        DurablePaymentOrigin::ORIGIN_VECIAHORRA, $f['public'], 'webpay_plus', $public, $f['total'],
        'production', hash('sha256', 'local-fixture-identity'),
        WebpayTransactionReference::buyOrder($f['public'], $key), WebpayTransactionReference::sessionId($f['public']),
        $token === null ? null : hash('sha256', $token), 1, $f['now'], $f['now'], $f['future']);
    (new PaymentOriginContextRepository())->create($origin);
    return compact('public', 'id', 'origin');
};
$mode = $argv[1] ?? 'totals';
$wpdb->query('START TRANSACTION');
try {
    if (in_array($mode, ['commerce_closed', 'webpay_closed'], true)) {
        $f = $fixture(); // Represents a page/checkout created before the gate closes.
        if ($mode === 'commerce_closed') { define('VECIAHORRA_PUBLIC_COMMERCE_ENABLED', false); }
        else { $GLOBALS['minimalClosedWebpay'] = true; }
        $expected = $mode === 'commerce_closed' ? 'commerce_disabled' : 'payment_temporarily_unavailable';
        foreach (['initialize' => fn () => $checkout->initialize(['user_id' => 1, 'fulfillment_method' => 'pickup']),
            'persistent' => fn () => $checkout->createPersistent(['user_id' => 1, 'fulfillment_method' => 'pickup'], $f['orders']),
            'existing_pending_start' => fn () => $service->start($f['public'], 'minimal-closed-gate-key', ['user_id' => 1])] as $name => $call) {
            $before = $snapshot(); $assert(count($before) === 34, 'TABLE_COVERAGE'); $code = null;
            try { $call(); } catch (ConflictException $e) { $code = $e->errorCode(); }
            $assert($code === $expected, 'GATE_REASON_' . $name);
            $assert($before === $snapshot(), 'GATE_DELTA_' . $name);
            $assert($local->calls === 0, 'GATE_TRANSPORT_' . $name);
            echo "DELTA_ZERO=$name tables=34 stock_unchanged=yes transport_calls=0\n";
        }
        foreach (['/veciahorra/v1/checkout', '/veciahorra/v1/payments/session'] as $route) {
            $before = $snapshot(); $request = new \WP_REST_Request('POST', $route);
            $request->set_header('Content-Type', 'application/json');
            $request->set_header('Idempotency-Key', 'minimal-gate-' . bin2hex(random_bytes(12)));
            $request->set_body(json_encode($route === '/veciahorra/v1/checkout'
                ? ['fulfillment_method' => 'pickup'] : ['checkout_id' => $f['public']]));
            $response = rest_do_request($request);
            $assert($response->get_status() === 503, 'REST_STATUS');
            $assert(($response->get_data()['error']['code'] ?? '') === $expected, 'REST_CODE');
            $assert($before === $snapshot(), 'REST_DELTA');
            $assert(!str_contains(json_encode($response->get_data()), 'webpay_production_disabled'), 'INTERNAL_REASON_EXPOSED');
            echo "PUBLIC_GATE HTTP=503 code=$expected tables=34 transport_calls=0\n";
        }
    } elseif ($mode === 'totals') {
        $resolver = new OrderOperationalStateResolver();
        $codes = static fn (array $facts): array => array_column($resolver->resolve(
            new OrderOperationalFacts($facts, gmdate('c')))->toArray()['consistency']['blockers'], 'code');
        foreach ([[[1500],700,0], [[8100],700,0], [[8000],700,1000], [[8100],700,1000],
            [[4000,4100],700,1000], [[1500],0,0]] as [$prices,$platform,$delivery]) {
            $f = $fixture($prices, $platform, $delivery); $session = $attempt($f);
            foreach ($f['orders'] as $order) {
                $repo = new OrderAdminReadRepository(); $bundles = $repo->loadFacts([$order]);
                $facts = (new OrderOperationalFactsAssembler())->assemble($repo->findBase($order), $bundles[$order] ?? [], gmdate('c'))->all();
                $assert(!in_array('checkout_total_mismatch', $codes($facts), true), 'TOTAL_FALSE_POSITIVE');
                foreach (['orders_total','product_subtotal','platform_fee','delivery_fee','total_amount'] as $field) {
                    $bad = $facts; $bad['checkout'][$field] = '1.00';
                    $assert(in_array('checkout_total_mismatch', $codes($bad), true), 'CHECKOUT_MUTATION_' . $field);
                }
                foreach ([['order','total','order_total_mismatch'], ['payment_session','amount','checkout_total_mismatch']] as [$group,$field,$code]) {
                    $bad = $facts; $bad[$group][$field] = '1.00'; $assert(in_array($code, $codes($bad), true), 'MUTATION_' . $group);
                }
                $bad = $facts; $bad['order_items'][0]['subtotal'] = '1.00';
                $assert(in_array('order_item_subtotal_mismatch', $codes($bad), true), 'LINE_MUTATION');
            }
            $assert((new PaymentSessionRepository())->find($session['id'])['amount'] === $f['total'] . '.00', 'SESSION_TOTAL');
            echo 'TOTAL_CASE=' . $f['total'] . ' stores=' . count($prices) . PHP_EOL;
        }
        $money = new ReflectionMethod($resolver, 'money');
        $assert($money->invoke($resolver, '0.10') + $money->invoke($resolver, '0.20') === $money->invoke($resolver, '0.30'), 'DECIMAL_EXACT');
    } elseif ($mode === 'classification') {
        foreach (['success'=>'ready', 'uncertain'=>'create_ambiguous', 'rejected'=>'create_retryable', 'expired'=>'create_failed'] as $state=>$expected) {
            $f = $fixture(); $session = $attempt($f); $local->mode = $state; $before = $local->calls;
            $result = $service->initializePublicAttempt($session['public'], ['user_id'=>1]);
            $assert($result['status'] === $expected, 'CLASSIFICATION_' . $state);
            $assert($local->calls === $before + 1, 'EXACT_LOCAL_TRANSPORT');
            if ($state !== 'rejected') {
                $service->initializePublicAttempt($session['public'], ['user_id'=>1]);
                $assert($local->calls === $before + 1, 'REPLAY_TRANSPORT');
            }
            echo "CLASSIFICATION=$state status=$expected local_transport_calls=1\n";
        }
    } elseif ($mode === 'return') {
        $f = $fixture(); $token = 'LOCAL' . bin2hex(random_bytes(16)); $session = $attempt($f, 'ready', $token);
        $wpdb->update($wpdb->prefix . 'va_checkouts', ['status'=>'payment_started'], ['id'=>$f['id']]);
        define('VECIAHORRA_PUBLIC_COMMERCE_ENABLED', false); $GLOBALS['minimalClosedWebpay'] = true;
        $config = (new ReflectionClass(WebpayGatewayConfiguration::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty($config, 'environment'))->setValue($config, 'production');
        (new ReflectionProperty($config, 'productionCreationEnabled'))->setValue($config, false);
        $transaction = new class($session['origin']) {
            public int $creates=0, $commits=0, $statuses=0;
            public function __construct(private DurablePaymentOrigin $origin) {}
            public function create() { ++$this->creates; throw new RuntimeException('CREATE_FORBIDDEN'); }
            public function commit($token) { ++$this->commits; return $this->result(); }
            public function status($token) { ++$this->statuses; return $this->result(); }
            private function result() { return new class($this->origin) {
                public function __construct(private DurablePaymentOrigin $origin) {}
                public function getStatus() { return 'AUTHORIZED'; } public function getResponseCode() { return 0; }
                public function getAmount() { return $this->origin->amountClp(); }
                public function getBuyOrder() { return $this->origin->buyOrder(); }
                public function getSessionId() { return $this->origin->financialSessionId(); }
                public function getTransactionDate() { return gmdate('c'); }
            }; }
        };
        $gateway = new WebpayPaymentGateway($config, $transaction);
        try { $gateway->createSession(new PaymentSessionContext('local','local','2200.00','CLP',$f['future'],'minimal-local-key')); }
        catch (ConflictException $e) { $assert($e->errorCode() === 'payment_temporarily_unavailable', 'DIRECT_TYPED_REASON'); }
        $returns = new \VeciAhorra\Modules\Payments\Service\WebpayReturnService($gateway, new PaymentSessionRepository(),
            new \VeciAhorra\Modules\Payments\Repository\WebpayReturnRepository(), $app->container()->make(
                \VeciAhorra\Modules\Payments\Reconciliation\Service\WebpayReconciliationMaterializer::class));
        $request = \VeciAhorra\Modules\Payments\Requests\WebpayReturnRequest::fromArray(['token_ws'=>$token]);
        $assert($returns->process($request)->result === 'approved', 'RETURN_APPROVED');
        $assert($returns->process($request)->result === 'already_processed', 'RETURN_REPLAY');
        $assert($gateway->recoverSession($token)->status === 'ready', 'RETURN_STATUS');
        $id = (int) $wpdb->get_var('SELECT id FROM ' . $wpdb->prefix . 'va_payment_reconciliations ORDER BY id DESC LIMIT 1');
        $claims = new \VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentReconciliationClaimRepository();
        $lease = $claims->acquireLease($id, $claims->newOwner())->lease();
        $assert((new \VeciAhorra\Modules\Payments\Reconciliation\Service\PaymentReconciliationProcessor())->process($lease)->processed(), 'RECONCILIATION');
        $business = new \VeciAhorra\Modules\Payments\BusinessCompletion\Service\BusinessCompletionProcessor();
        $assert($business->process($id, 'business_' . bin2hex(random_bytes(16)))->status === 'completed', 'BUSINESS_COMPLETION');
        $assert($business->process($id, 'business_' . bin2hex(random_bytes(16)))->status === 'already_completed', 'BUSINESS_REPLAY');
        $payments = $wpdb->get_results($wpdb->prepare('SELECT amount,status FROM ' . $wpdb->prefix . 'va_payments WHERE payment_session_id=%d', $session['id']), ARRAY_A);
        $assert(count($payments) === 1 && $payments[0]['amount'] === '2200.00' && $payments[0]['status'] === 'paid', 'ONE_PAYMENT_EXACT_AMOUNT');
        $assert((int) $wpdb->get_var($wpdb->prepare('SELECT stock FROM ' . $wpdb->prefix . 'va_inventory WHERE id=%d', $f['inventory'])) === 4, 'RETURN_STOCK');
        $assert($transaction->creates === 0 && $transaction->commits === 1 && $transaction->statuses === 1, 'RETURN_TRANSPORT_COUNTS');
    } else { throw new RuntimeException('UNKNOWN_MINIMAL_MODE'); }
    if ($mode === 'classification' || $mode === 'totals') {
        $status = (new ReflectionClass(PublicPaymentStatusService::class))->newInstanceWithoutConstructor();
        foreach (['2000-01-01 00:00:00','2099-01-01 00:00:00'] as $expiry) {
            $value = (new ReflectionMethod($status, 'projectAttempt'))->invoke($status, ['session_status'=>'create_ambiguous','session_expires_at'=>$expiry]);
            $assert($value['payment_status'] === 'manual_review' && $value['terminal'] === true
                && $value['next_action'] === 'contact_support' && $value['poll_after_ms'] === null, 'AMBIGUITY_TERMINAL');
        }
    }
    echo "PASS minimal_safety_$mode assertions=$count external_payment_attempts=0\n";
} finally { $wpdb->query('ROLLBACK'); }
}
