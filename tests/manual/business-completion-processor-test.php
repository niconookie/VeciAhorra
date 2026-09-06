<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Payments\BusinessCompletion\DTO\BusinessCompletionResult;
use VeciAhorra\Modules\Payments\BusinessCompletion\Repository\BusinessCompletionRepository;
use VeciAhorra\Modules\Payments\BusinessCompletion\Service\BusinessCompletionProcessor;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\CreatePaymentReconciliation;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\DurablePaymentOrigin;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\FinancialFingerprintComponents;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\ValidatedFinancialResult;
use VeciAhorra\Modules\Payments\Reconciliation\Model\PaymentReconciliation;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentOriginContextRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentReconciliationRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\ValidatedFinancialResultRepository;

// Standalone local test: real repositories, SQLite in memory, no WordPress or network.
// FOR UPDATE is checked for transaction membership, then removed for SQLite.
// This verifies SQL effects and rollback, not MySQL concurrent lock scheduling.
if (defined('ABSPATH')) { throw new RuntimeException('Run in a fresh standalone PHP process.'); }
define('ARRAY_A', 'ARRAY_A');
function current_time(string $type, bool $gmt = false): string { return gmdate('Y-m-d H:i:s'); }
function wp_json_encode(mixed $value, int $flags = 0): string|false { return json_encode($value, $flags); }
final class wpdb
{
    public string $prefix = 'test_';
    public string $last_error = '';
    public int $insert_id = 0;
    public PDO $pdo;
    public array $queries = [];
    private bool $suppressed = false;
    public function __construct() { $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }
    public function suppress_errors(bool $value = true): bool { $old = $this->suppressed; $this->suppressed = $value; return $old; }
    public function prepare(string $sql, mixed ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        $i = 0;
        return preg_replace_callback('/%[dsf]/', function ($m) use ($args, &$i) {
            $value = $args[$i++];
            return $m[0] === '%s' ? $this->pdo->quote((string) $value) : (string) (int) $value;
        }, $sql);
    }
    private function sql(string $sql): string
    {
        $this->queries[] = $sql;
        if (str_contains($sql, 'FOR UPDATE')) {
            if (! $this->pdo->inTransaction()) { throw new RuntimeException('Lock outside transaction.'); }
            $sql = str_replace(' FOR UPDATE', '', $sql);
        }
        return $sql;
    }
    public function query(string $sql): int|false
    {
        $this->last_error = '';
        $sql = $this->sql($sql);
        try {
            if ($sql === 'START TRANSACTION') { $this->pdo->beginTransaction(); return 0; }
            if ($sql === 'COMMIT') { $this->pdo->commit(); return 0; }
            if ($sql === 'ROLLBACK') { $this->pdo->rollBack(); return 0; }
            return $this->pdo->exec($sql);
        } catch (PDOException $e) {
            $this->last_error = $e->getMessage();
            if (! $this->suppressed) { throw $e; }
            return false;
        }
    }
    public function get_results(string $sql, string $output = ARRAY_A): array
    {
        $this->last_error = '';
        return $this->pdo->query($this->sql($sql))->fetchAll(PDO::FETCH_ASSOC);
    }
    public function get_row(string $sql, string $output = ARRAY_A): ?array { return $this->get_results($sql)[0] ?? null; }
    public function get_col(string $sql): array { return array_map(static fn ($r) => array_values($r)[0], $this->get_results($sql)); }
    public function get_var(string $sql): mixed
    {
        if ($sql === 'SELECT @@in_transaction') { return (int) $this->pdo->inTransaction(); }
        $row = $this->get_row($sql);
        return $row === null ? null : array_values($row)[0];
    }
    private function where(array $where): string
    {
        $parts = [];
        foreach ($where as $key => $value) { $parts[] = $key . ($value === null ? ' IS NULL' : ' = ' . $this->pdo->quote((string) $value)); }
        return implode(' AND ', $parts);
    }
    public function insert(string $table, array $data): int|false
    {
        $values = array_map(fn ($v) => $v === null ? 'NULL' : $this->pdo->quote((string) $v), array_values($data));
        $result = $this->query('INSERT INTO ' . $table . ' (' . implode(',', array_keys($data)) . ') VALUES (' . implode(',', $values) . ')');
        if ($result !== false) { $this->insert_id = (int) $this->pdo->lastInsertId(); }
        return $result;
    }
    public function update(string $table, array $data, array $where): int|false
    {
        $sets = [];
        foreach ($data as $key => $value) { $sets[] = $key . ' = ' . ($value === null ? 'NULL' : $this->pdo->quote((string) $value)); }
        return $this->query('UPDATE ' . $table . ' SET ' . implode(',', $sets) . ' WHERE ' . $this->where($where));
    }
    public function delete(string $table, array $where): int|false { return $this->query('DELETE FROM ' . $table . ' WHERE ' . $this->where($where)); }
}
$testRoot = getenv('VECIAHORRA_TEST_PLUGIN_ROOT') ?: dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($testRoot): void {
    if (str_starts_with($class, 'VeciAhorra\\')) {
        $path = $testRoot . '/app/' . str_replace('\\', '/', substr($class, 10)) . '.php';
        if (is_file($path)) { require $path; }
    }
});
if ($source = getenv('VECIAHORRA_TEST_BUSINESS_SOURCE')) { require $source; }
$wpdb = new wpdb();
// Build only this test's tables from the existing schema metadata. Never run migrations.
foreach (['Checkout', 'CheckoutOrder', 'Order', 'OrderItem', 'Inventory', 'Reservation', 'PaymentSession', 'Payment', 'PaymentOrder', 'PaymentOriginContext', 'WebpayReturn', 'PaymentReconciliation', 'BusinessCompletion', 'BusinessCompletionOrder'] as $name) {
    $class = 'VeciAhorra\\Database\\Schemas\\' . $name . 'Schema';
    $schema = new $class();
    $builder = \VeciAhorra\Database\Builder\TableBuilder::make('test_' . \VeciAhorra\Core\Config::TABLE_PREFIX . $schema->name());
    $schema->define($builder);
    $mysql = $builder->build('');
    $columns = [];
    foreach (explode("\n", $mysql) as $line) {
        $line = trim($line, " \r\t,");
        if (preg_match('/^`?(\w+)`?\s+(BIGINT|INT|TINYINT|VARCHAR|TEXT|LONGTEXT|DATETIME|DECIMAL|CHAR|TIMESTAMP)\b/i', $line, $m)) {
            $column = $m[1];
            $type = str_contains(strtoupper($m[2]), 'INT') ? 'INTEGER' : 'TEXT';
            $default = preg_match('/DEFAULT\s+(\x27[^\x27]*\x27|\d+|NULL)/i', $line, $d) ? ' DEFAULT ' . $d[1] : '';
            $columns[] = $column === 'id' ? 'id INTEGER PRIMARY KEY AUTOINCREMENT' : '"' . $column . '" ' . $type . $default;
        } elseif (preg_match('/^UNIQUE KEY\s+\S+\s*\(([^)]+)\)/i', $line, $m)) {
            $columns[] = 'UNIQUE (' . $m[1] . ')';
        }
    }
    $table = 'test_' . \VeciAhorra\Core\Config::TABLE_PREFIX . $schema->name();
    $wpdb->query('CREATE TABLE ' . $table . ' (' . implode(',', $columns) . ')');
    $wpdb->query("INSERT INTO sqlite_sequence(name,seq) VALUES ('{$table}',980000)");
}


function bcAssert(bool $condition, string $message): void
{
    $GLOBALS['bcAssertions'] = ($GLOBALS['bcAssertions'] ?? 0) + 1;
    if (! $condition) { throw new RuntimeException($message); }
}

global $wpdb;
$prefix = $wpdb->prefix . Config::TABLE_PREFIX;

$created = [];

try {
    $processor = new BusinessCompletionProcessor();
    bcAssert($processor->process(PHP_INT_MAX, 'business_' . str_repeat('a', 32))->reason === 'reconciliation_missing', 'No rechazo conciliacion inexistente.');

    $nonce = bin2hex(random_bytes(10));
    $now = gmdate('Y-m-d H:i:s');
    $checkoutPublic = 'chk_' . substr(hash('sha256', 'checkout-' . $nonce), 0, 43);
    $sessionPublic = 'ps_' . substr(hash('sha256', 'session-' . $nonce), 0, 43);
    $wpdb->insert($prefix . 'checkouts', [
        'public_id' => $checkoutPublic, 'owner_type' => 'user', 'user_id' => 980001,
        'session_id' => null, 'status' => 'payment_started',
        'fulfillment_method' => 'pickup', 'currency' => 'CLP',
        'product_subtotal' => '1500.00', 'platform_fee' => '700.00', 'delivery_fee' => '0.00', 'total_amount' => '2200.00', 'created_at' => $now, 'updated_at' => $now,
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
    ]);
    $checkoutId = (int) $wpdb->insert_id; $created['checkout'] = $checkoutId;
    $orderIds = [];
    foreach ([1500] as $i => $amount) {
        $wpdb->insert($prefix . 'orders', [
            'customer_id' => 980001, 'minimarket_id' => 980100 + $i,
            'total' => $amount . '.00', 'status' => 'reserved',
            'reservation_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $orderIds[] = (int) $wpdb->insert_id;
    }
    $created['orders'] = $orderIds;

    $wpdb->insert($prefix . 'inventory', ['product_id' => 980200, 'minimarket_id' => 980100, 'price' => '1500.00', 'stock' => 4]);
    $inventoryId = $wpdb->insert_id;
    $wpdb->insert($prefix . 'order_items', ['order_id' => $orderIds[0], 'product_id' => 980200, 'inventory_id' => $inventoryId, 'quantity' => 1, 'unit_price' => '1500.00', 'subtotal' => '1500.00']);
    $wpdb->insert($prefix . 'reservations', ['order_id' => $orderIds[0], 'product_id' => 980200, 'inventory_id' => $inventoryId, 'minimarket_id' => 980100, 'quantity' => 1, 'status' => 'active', 'released_at' => null, 'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);
    $reservationId = $wpdb->insert_id;
    // An unrelated order's reservation must never be consumed.
    $wpdb->insert($prefix . 'reservations', ['order_id' => 990999, 'product_id' => 980201, 'inventory_id' => 990999, 'minimarket_id' => 980101, 'quantity' => 2, 'status' => 'active']);
    $unrelatedId = $wpdb->insert_id;
    $stockBefore = $wpdb->get_results("SELECT * FROM {$prefix}inventory ORDER BY id");

    foreach ($orderIds as $orderId) {
        $wpdb->insert($prefix . 'checkout_orders', ['checkout_id' => $checkoutId, 'order_id' => $orderId, 'created_at' => $now]);
    }
    $wpdb->insert($prefix . 'payment_sessions', [
        'public_id' => $sessionPublic, 'checkout_id' => $checkoutId, 'payment_id' => null,
        'idempotency_key' => hash('sha256', 'key-' . $nonce),
        'request_fingerprint' => hash('sha256', 'request-' . $nonce), 'status' => 'ready',
        'provider' => 'webpay_plus', 'provider_session_id' => hash('sha256', 'provider-' . $nonce),
        'redirect_url' => null, 'currency' => 'CLP', 'amount' => '2200.00', 'metadata' => null,
        'created_at' => $now, 'updated_at' => $now, 'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
    ]);
    $created['session'] = (int) $wpdb->insert_id;
    $merchant = hash('sha256', 'merchant-' . $nonce);
    $buyOrder = 'VA' . strtoupper(substr(hash('sha256', 'buy-' . $nonce), 0, 24));
    $financialSession = 'VA-' . strtoupper(substr(hash('sha256', 'financial-' . $nonce), 0, 58));
    $origin = new DurablePaymentOrigin(
        'poc_' . substr(hash('sha256', 'origin-' . $nonce), 0, 40), 'veciahorra:checkout',
        DurablePaymentOrigin::ORIGIN_VECIAHORRA, $checkoutPublic, 'webpay_plus', $sessionPublic,
        2200, 'integration', $merchant, $buyOrder, $financialSession,
        hash('sha256', 'token-' . $nonce), 1, $now, $now, gmdate('Y-m-d H:i:s', time() + 3600)
    );
    $components = new FinancialFingerprintComponents(
        'integration', $merchant, 'AUTHORIZED', 0, 2200, $buyOrder, $financialSession,
        '2026-07-14T12:00:00Z', hash('sha256', 'authorization-' . $nonce), 'VD', 0, '0714'
    );
    $financial = new ValidatedFinancialResult(
        'wpr_' . substr(hash('sha256', 'return-' . $nonce), 0, 40), 'approved', 'commit',
        hash('sha256', 'token-' . $nonce), 'sha256:' . substr(hash('sha256', 'safe-' . $nonce), 0, 16),
        $components, $now, $now
    );
    $origins = new PaymentOriginContextRepository(); $returns = new ValidatedFinancialResultRepository();
    $originId = $origins->create($origin); $returnId = $returns->create($financial);
    $created['origin'] = $originId; $created['return'] = $returnId;
    $reconciliations = new PaymentReconciliationRepository($origins, $returns);
    $reconciliationId = $reconciliations->create(new CreatePaymentReconciliation(
        'pr_' . substr(hash('sha256', 'reconciliation-' . $nonce), 0, 40), $returnId, $originId,
        $financial, $origin, PaymentReconciliation::STATUS_PENDING, null, 0, null, null,
        $now, null, null, $now
    ));
    $created['reconciliation'] = $reconciliationId;
    bcAssert($processor->process($reconciliationId, 'business_' . str_repeat('b', 32))->reason === 'reconciliation_not_completed', 'Proceso conciliacion no completada.');
    $wpdb->update($prefix . 'payment_reconciliations', ['reconciliation_status' => 'completed', 'reconciled_at' => $now], ['id' => $reconciliationId]);
    $completionRepository = new BusinessCompletionRepository();
    $completionKey = hash('sha256', 'business-completion-v1|' . $reconciliationId . '|' . $financial->fingerprint());
    $completion = $completionRepository->ensure($reconciliationId, $completionKey, $now);
    $claim = $completionRepository->acquire((int) $completion['id'], 'business_' . str_repeat('1', 32), $now, gmdate('Y-m-d H:i:s', time() + 30));
    $competingClaim = $completionRepository->acquire((int) $completion['id'], 'business_' . str_repeat('2', 32), $now, gmdate('Y-m-d H:i:s', time() + 30));
    bcAssert($claim !== null && $competingClaim === null && (int) $claim['lease_version'] === 1, 'El claim concurrente no fue exclusivo o versionado.');
    $completionRepository->fail((int) $completion['id'], 'business_' . str_repeat('2', 32), 1, 'retryable', 'wrong_owner', $now);
    bcAssert(($completionRepository->findByReconciliation($reconciliationId)['status'] ?? null) === 'processing', 'Un owner ajeno libero el claim.');
    $completionRepository->fail((int) $completion['id'], 'business_' . str_repeat('1', 32), 1, 'retryable', 'fixture_release', $now);

    $baseline = [];
    foreach ($wpdb->get_col("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'test_%'") as $table) {
        $baseline[$table] = $wpdb->get_results('SELECT * FROM ' . $table);
    }
    $restore = static function () use ($wpdb, $baseline): void {
        foreach ($baseline as $table => $rows) {
            $wpdb->query('DELETE FROM ' . $table);
            foreach ($rows as $row) { $wpdb->insert($table, $row); }
        }
        $wpdb->queries = [];
    };

    $snapshot = static function () use ($wpdb, $baseline): array {
        $state = [];
        foreach (array_keys($baseline) as $table) { $state[$table] = $wpdb->get_results('SELECT * FROM ' . $table . ' ORDER BY id'); }
        return $state;
    };
    $assertReadOnly = static function (array $before, int $queryOffset, string $case) use ($wpdb, $snapshot): void {
        bcAssert($snapshot() === $before, 'REPLAY_STATE_CHANGED: ' . $case);
        $writes = array_filter(array_slice($wpdb->queries, $queryOffset), static fn ($q) => preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP)\b/i', $q));
        bcAssert($writes === [], 'REPLAY_WRITE_ATTEMPT: ' . $case);
    };

    $unchanged = static function () use ($wpdb, $prefix, $created, $orderIds, $stockBefore, $reconciliationId): void {
        bcAssert((int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}payments") === 0, 'Payment rolled back.');
        $session = $wpdb->get_row("SELECT * FROM {$prefix}payment_sessions WHERE id = " . $created['session']);
        bcAssert($session['status'] === 'ready' && $session['payment_id'] === null && $session['confirmed_at'] === null, 'Session rolled back.');
        bcAssert($wpdb->get_var("SELECT status FROM {$prefix}orders WHERE id = " . $orderIds[0]) === 'reserved', 'Order rolled back.');
        bcAssert($wpdb->get_var("SELECT status FROM {$prefix}business_completions WHERE reconciliation_id = " . $reconciliationId) !== 'completed', 'No successful completion.');
        bcAssert((int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}payment_orders") === 0, 'Payment links rolled back.');
        bcAssert((int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}business_completion_orders") === 0, 'Snapshot rolled back.');
        bcAssert($wpdb->get_results("SELECT * FROM {$prefix}inventory ORDER BY id") === $stockBefore, 'No stock movement.');
        bcAssert($wpdb->get_var("SELECT reconciliation_status FROM {$prefix}payment_reconciliations WHERE id = " . $reconciliationId) === 'completed', 'Reconciliation preserved.');
    };
    $smoke = $processor->process($reconciliationId, 'business_' . str_repeat('7', 32));
    bcAssert($smoke->status === BusinessCompletionResult::COMPLETED, 'Approved local business completes.');
    bcAssert($wpdb->get_var("SELECT status FROM {$prefix}reservations WHERE id = {$reservationId}") === 'consumed', 'Regression: approved payment must consume reservation.');
    foreach (['missing', 'released', 'expired', 'consumed', 'quantity', 'inventory_id', 'product_id', 'minimarket_id', 'order_id', 'released_at'] as $bad) {
        $restore();
        if ($bad === 'missing') { $wpdb->delete($prefix . 'reservations', ['id' => $reservationId]); }
        elseif (in_array($bad, ['released', 'expired', 'consumed'], true)) { $wpdb->update($prefix . 'reservations', ['status' => $bad], ['id' => $reservationId]); }
        else { $wpdb->update($prefix . 'reservations', [$bad => $bad === 'released_at' ? $now : 999999], ['id' => $reservationId]); }
        $blocked = $processor->process($reconciliationId, 'business_' . str_repeat('e', 32));
        bcAssert($blocked->status === BusinessCompletionResult::MANUAL_REVIEW, 'Invalid reservation blocked: ' . $bad);
        $unchanged();
    }
    $restore();

    $restore();
    (new \VeciAhorra\Modules\Orders\Repositories\OrderRepository())->markDelivered($orderIds[0], $now);
    $initialDelivered = $processor->process($reconciliationId, 'business_' . str_repeat('4', 32));
    bcAssert($initialDelivered->status === BusinessCompletionResult::MANUAL_REVIEW, 'INITIAL_DELIVERED_ACCEPTED');
    bcAssert((int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}payments") === 0, 'Initial delivered created Payment.');
    bcAssert($wpdb->get_var("SELECT status FROM {$prefix}orders WHERE id = " . $orderIds[0]) === 'delivered', 'Initial delivered changed Order.');
    bcAssert($wpdb->get_var("SELECT status FROM {$prefix}reservations WHERE id = {$reservationId}") === 'active', 'Initial delivered consumed reservation.');
    bcAssert($wpdb->get_results("SELECT * FROM {$prefix}inventory ORDER BY id") === $stockBefore, 'Initial delivered moved stock.');

    $restore();
    $failingReservations = new class extends \VeciAhorra\Modules\Reservations\Repository\ReservationRepository {
        public bool $called = false;
        public function markConsumed(array $ids, string $updatedAt): int {
            $this->called = true;
            parent::markConsumed($ids, $updatedAt);
            throw new \VeciAhorra\Exceptions\PersistenceException('Injected failure after consume SQL.');
        }
    };
    $failed = (new BusinessCompletionProcessor(reservations: $failingReservations))->process($reconciliationId, 'business_' . str_repeat('f', 32));
    bcAssert($failingReservations->called && $failed->status === BusinessCompletionResult::RETRYABLE, 'Consume failure propagated.');
    bcAssert(in_array('ROLLBACK', $wpdb->queries, true), 'Real transaction rollback executed.');
    bcAssert($wpdb->get_var("SELECT status FROM {$prefix}reservations WHERE id = {$reservationId}") === 'active', 'Consumption rolled back too.');
    $unchanged();
    $restore();

    $result = $processor->process($reconciliationId, 'business_' . str_repeat('c', 32));
    bcAssert($result->status === BusinessCompletionResult::COMPLETED && $result->paymentId !== null, 'No completo la materializacion.');
    bcAssert((int) ($completionRepository->findByReconciliation($reconciliationId)['lease_version'] ?? 0) === 2, 'El reacquire no incremento la version anti-ABA.');
    $created['payment'] = $result->paymentId;
    $payment = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}payments WHERE id = %d", $result->paymentId), ARRAY_A);
    bcAssert($payment['status'] === 'paid' && $payment['financial_fingerprint'] === $financial->fingerprint(), 'Payment no quedo aprobado con evidencia durable.');
    $confirmedSession = $wpdb->get_row($wpdb->prepare(
        "SELECT status, confirmed_at, confirmation_fingerprint, confirmation_fingerprint_version, safe_financial_reference FROM {$prefix}payment_sessions WHERE id = %d",
        $created['session']
    ), ARRAY_A);
    bcAssert(
        ($confirmedSession['status'] ?? null) === 'confirmed'
            && is_string($confirmedSession['confirmed_at'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/D', (string) ($confirmedSession['confirmation_fingerprint'] ?? '')) === 1
            && (int) ($confirmedSession['confirmation_fingerprint_version'] ?? 0) === 1
            && ($confirmedSession['safe_financial_reference'] ?? null) === $financial->safeFinancialReference(),
        'PaymentSession no quedo confirmada con evidencia completa.'
    );
    bcAssert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}payment_orders WHERE payment_id = %d", $result->paymentId)) === 1, 'No vinculo multiples Orders.');
    $completionRow = $completionRepository->findByReconciliation($reconciliationId);
    bcAssert(($completionRow['fulfillment_method'] ?? null) === 'pickup', 'BusinessCompletion no sello fulfillment.');
    bcAssert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}business_completion_orders WHERE business_completion_id = %d", (int) $completionRow['id'])) === 1, 'BusinessCompletion no sello todas las Orders.');
    bcAssert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}orders WHERE id IN (%d) AND status = 'paid'", ...$orderIds)) === 1, 'Orders no quedaron paid.');
    $paidSnapshot = $snapshot(); $paidQueryOffset = count($wpdb->queries);
    $replay = $processor->process($reconciliationId, 'business_' . str_repeat('d', 32));
    $assertReadOnly($paidSnapshot, $paidQueryOffset, 'paid');
    bcAssert($replay->status === BusinessCompletionResult::ALREADY_COMPLETED && $replay->paymentId === $result->paymentId, 'Replay no fue idempotente.');
    bcAssert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}payments WHERE reconciliation_id = %d", $reconciliationId)) === 1, 'Replay duplico Payment.');
    bcAssert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}payment_orders WHERE payment_id = %d", $result->paymentId)) === 1, 'Replay duplico relaciones.');
    $stored = json_encode([$payment, $result, $replay], JSON_THROW_ON_ERROR);
    bcAssert(! str_contains($stored, $financial->tokenHash()), 'Se expuso el token hash como referencia de negocio.');

    bcAssert($wpdb->get_var("SELECT status FROM {$prefix}reservations WHERE id = {$reservationId}") === 'consumed', 'active -> consumed after approval.');
    bcAssert($wpdb->get_var("SELECT released_at FROM {$prefix}reservations WHERE id = {$reservationId}") === null, 'Consumed is not released.');
    bcAssert($wpdb->get_var("SELECT status FROM {$prefix}reservations WHERE id = {$unrelatedId}") === 'active', 'Unrelated reservation untouched.');
    bcAssert($wpdb->get_results("SELECT * FROM {$prefix}inventory ORDER BY id") === $stockBefore, 'Stock exactly unchanged.');
    bcAssert($payment['amount'] === '2200.00' && $wpdb->get_var("SELECT total FROM {$prefix}orders WHERE id = " . $orderIds[0]) === '1500.00', 'Payment 2200, Order 1500.');
    bcAssert($wpdb->get_var("SELECT platform_fee FROM {$prefix}checkouts WHERE id = {$checkoutId}") === '700.00', 'Platform fee 700 preserved.');
    $consumeQueries = array_filter($wpdb->queries, static fn ($q) => str_contains($q, "'consumed'") && str_starts_with(ltrim($q), 'UPDATE'));
    bcAssert(count($consumeQueries) === 1, 'Replay performs no consume SQL.');
    $lockIndex = null; $consumeIndex = null;
    foreach ($wpdb->queries as $i => $q) {
        if (str_contains($q, 'FROM ' . $prefix . 'reservations') && str_contains($q, 'FOR UPDATE') && $lockIndex === null) { $lockIndex = $i; }
        if (str_contains($q, "'consumed'") && str_starts_with(ltrim($q), 'UPDATE')) { $consumeIndex = $i; }
    }
    bcAssert($lockIndex !== null && $consumeIndex !== null && $lockIndex < $consumeIndex, 'Reservations locked and reread before consumption.');
    $wpdb->update($prefix . 'reservations', ['status' => 'active'], ['id' => $reservationId]);
    $historical = $processor->process($reconciliationId, 'business_' . str_repeat('9', 32));
    bcAssert($historical->status === BusinessCompletionResult::MANUAL_REVIEW, 'Historical active replay is not success.');
    bcAssert($wpdb->get_var("SELECT status FROM {$prefix}reservations WHERE id = {$reservationId}") === 'active', 'Historical replay does not repair.');
    bcAssert($wpdb->get_results("SELECT * FROM {$prefix}inventory ORDER BY id") === $stockBefore, 'Historical replay does not move stock.');
    $wpdb->update($prefix . 'reservations', ['status' => 'consumed'], ['id' => $reservationId]);
    $wpdb->update($prefix . 'payments', ['payment_attempt_id' => 'other-attempt'], ['id' => $result->paymentId]);
    bcAssert($processor->process($reconciliationId, 'business_' . str_repeat('8', 32))->status === BusinessCompletionResult::MANUAL_REVIEW, 'Consumed replay rejects another payment identity.');
    // Keep multi-Order coverage with a 1500 product subtotal and a single 700 fee.
    $restore();
    $wpdb->update($prefix . 'checkouts', ['fulfillment_method' => 'delivery'], ['id' => $checkoutId]);
    $wpdb->update($prefix . 'orders', ['total' => '1000.00'], ['id' => $orderIds[0]]);
    $wpdb->update($prefix . 'order_items', ['unit_price' => '1000.00', 'subtotal' => '1000.00'], ['order_id' => $orderIds[0]]);
    $wpdb->insert($prefix . 'orders', ['customer_id' => 980001, 'minimarket_id' => 980102, 'total' => '500.00', 'status' => 'reserved']);
    $secondOrder = $wpdb->insert_id;
    $wpdb->insert($prefix . 'checkout_orders', ['checkout_id' => $checkoutId, 'order_id' => $secondOrder]);
    $wpdb->insert($prefix . 'inventory', ['product_id' => 980202, 'minimarket_id' => 980102, 'price' => '500.00', 'stock' => 7]);
    $secondInventory = $wpdb->insert_id;
    $wpdb->insert($prefix . 'order_items', ['order_id' => $secondOrder, 'product_id' => 980202, 'inventory_id' => $secondInventory, 'quantity' => 1, 'unit_price' => '500.00', 'subtotal' => '500.00']);
    $wpdb->insert($prefix . 'reservations', ['order_id' => $secondOrder, 'product_id' => 980202, 'inventory_id' => $secondInventory, 'minimarket_id' => 980102, 'quantity' => 1, 'status' => 'active']);
    $secondReservation = $wpdb->insert_id;
    $multiStock = $wpdb->get_results("SELECT * FROM {$prefix}inventory ORDER BY id");
    $multi = $processor->process($reconciliationId, 'business_' . str_repeat('6', 32));
    bcAssert($multi->status === BusinessCompletionResult::COMPLETED, 'Multiple Orders complete.');
    bcAssert((int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}reservations WHERE id IN ({$reservationId},{$secondReservation}) AND status = 'consumed'") === 2, 'All exact checkout reservations consumed.');
    bcAssert($wpdb->get_results("SELECT * FROM {$prefix}inventory ORDER BY id") === $multiStock, 'Multi-Order stock unchanged.');
    bcAssert($processor->process($reconciliationId, 'business_' . str_repeat('5', 32))->status === BusinessCompletionResult::ALREADY_COMPLETED, 'Multi-Order replay is coherent.');
    bcAssert((int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}payments") === 1, 'Multi-Order replay has one payment.');

    (new \VeciAhorra\Modules\Orders\Repositories\OrderRepository())->markDelivered($orderIds[0], $now);
    $deliveredSnapshot = $snapshot(); $deliveredOffset = count($wpdb->queries);
    $deliveredReplay = $processor->process($reconciliationId, 'business_' . str_repeat('3', 32));
    bcAssert($deliveredReplay->status === BusinessCompletionResult::ALREADY_COMPLETED, 'REPLAY_DELIVERED_REJECTED');
    bcAssert($deliveredReplay->paymentId === $multi->paymentId && $deliveredReplay->completionId === $multi->completionId, 'Delivered replay changed business/payment identity.');
    $assertReadOnly($deliveredSnapshot, $deliveredOffset, 'delivered');
    bcAssert($wpdb->get_var("SELECT status FROM {$prefix}orders WHERE id = " . $orderIds[0]) === 'delivered', 'Delivered replay regressed Order.');
    bcAssert($wpdb->get_var("SELECT status FROM {$prefix}reservations WHERE id = {$reservationId}") === 'consumed', 'Delivered replay changed reservation.');
    foreach (['reserved', 'cancelled', 'failed', 'refunded', 'invented'] as $state) {
        $wpdb->update($prefix . 'orders', ['status' => $state], ['id' => $orderIds[0]]);
        $before = $snapshot(); $offset = count($wpdb->queries);
        $badReplay = $processor->process($reconciliationId, 'business_' . str_repeat('2', 32));
        bcAssert($badReplay->status === BusinessCompletionResult::MANUAL_REVIEW, 'REPLAY_INCOMPATIBLE_ACCEPTED: ' . $state);
        $assertReadOnly($before, $offset, $state);
    }
    $wpdb->update($prefix . 'orders', ['status' => 'delivered'], ['id' => $orderIds[0]]);
    foreach (['active', 'released', 'expired'] as $state) {
        $wpdb->update($prefix . 'reservations', ['status' => $state], ['id' => $reservationId]);
        $before = $snapshot(); $offset = count($wpdb->queries);
        bcAssert($processor->process($reconciliationId, 'business_' . str_repeat('1', 32))->status === BusinessCompletionResult::MANUAL_REVIEW, 'REPLAY_RESERVATION_ACCEPTED: ' . $state);
        $assertReadOnly($before, $offset, 'reservation ' . $state);
    }
    $wpdb->update($prefix . 'reservations', ['status' => 'consumed'], ['id' => $reservationId]);
    $businessId = $multi->completionId;
    foreach ([
        ['payments', $multi->paymentId, 'amount', '2201.00'],
        ['payments', $multi->paymentId, 'payment_attempt_id', 'another-attempt'],
        ['payments', $multi->paymentId, 'checkout_id', 999999],
        ['payments', $multi->paymentId, 'payment_session_id', 999999],
        ['payments', $multi->paymentId, 'provider', 'another-gateway'],
        ['payments', $multi->paymentId, 'currency', 'USD'],
        ['payments', $multi->paymentId, 'financial_fingerprint', str_repeat('0', 64)],
        ['payment_sessions', $created['session'], 'payment_id', 999999],
        ['payment_sessions', $created['session'], 'amount', '2201.00'],
        ['payment_sessions', $created['session'], 'provider', 'another-gateway'],
        ['payment_sessions', $created['session'], 'currency', 'USD'],
        ['checkouts', $checkoutId, 'total_amount', '2201.00'],
        ['checkouts', $checkoutId, 'currency', 'USD'],
        ['business_completions', $businessId, 'payment_id', 999999],
        ['business_completions', $businessId, 'idempotency_key', str_repeat('0', 64)],
    ] as [$table, $id, $field, $badValue]) {
        $original = $wpdb->get_var("SELECT {$field} FROM {$prefix}{$table} WHERE id = {$id}");
        $wpdb->update($prefix . $table, [$field => $badValue], ['id' => $id]);
        $before = $snapshot(); $offset = count($wpdb->queries);
        bcAssert($processor->process($reconciliationId, 'business_' . str_repeat('0', 32))->status === BusinessCompletionResult::MANUAL_REVIEW, 'REPLAY_IDENTITY_ACCEPTED: ' . $table . '.' . $field);
        $assertReadOnly($before, $offset, $table . '.' . $field);
        $wpdb->update($prefix . $table, [$field => $original], ['id' => $id]);
    }

    $projector = new ReflectionClass(\VeciAhorra\Modules\Payments\Service\PublicPaymentStatusService::class);
    $ambiguous = $projector->getMethod('projectAttempt')->invoke($projector->newInstanceWithoutConstructor(), ['session_status' => 'create_ambiguous']);
    bcAssert($ambiguous['terminal'] === true && $ambiguous['poll_after_ms'] === null && $ambiguous['payment_status'] === 'manual_review', 'create_ambiguous stays terminal without polling.');
    echo 'PASS business-completion-processor assertions=' . $GLOBALS['bcAssertions'] . ' external_calls=0 stock_delta=0' . PHP_EOL;

} finally {
    if (isset($created['payment'])) { $wpdb->delete($prefix . 'payment_orders', ['payment_id' => $created['payment']]); }
    if (isset($created['reconciliation'])) {
        $completionId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$prefix}business_completions WHERE reconciliation_id = %d", $created['reconciliation']));
        if ($completionId > 0) { $wpdb->delete($prefix . 'business_completion_orders', ['business_completion_id' => $completionId]); }
        $wpdb->delete($prefix . 'business_completions', ['reconciliation_id' => $created['reconciliation']]);
    }
    if (isset($created['session'])) { $wpdb->delete($prefix . 'payment_sessions', ['id' => $created['session']]); }
    if (isset($created['payment'])) { $wpdb->delete($prefix . 'payments', ['id' => $created['payment']]); }
    if (isset($created['checkout'])) { $wpdb->delete($prefix . 'checkout_orders', ['checkout_id' => $created['checkout']]); }
    foreach ($created['orders'] ?? [] as $id) { $wpdb->delete($prefix . 'orders', ['id' => $id]); }
    if (isset($created['reconciliation'])) { $wpdb->delete($prefix . 'payment_reconciliations', ['id' => $created['reconciliation']]); }
    if (isset($created['return'])) { $wpdb->delete($prefix . 'webpay_returns', ['id' => $created['return']]); }
    if (isset($created['origin'])) { $wpdb->delete($prefix . 'payment_origin_contexts', ['id' => $created['origin']]); }
    if (isset($created['checkout'])) { $wpdb->delete($prefix . 'checkouts', ['id' => $created['checkout']]); }
}
