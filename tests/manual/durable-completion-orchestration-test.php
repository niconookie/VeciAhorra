<?php

declare(strict_types=1);

$actions = [];

function as_has_scheduled_action(string $hook, array $args = [], string $group = ''): int|false
{
    global $actions;
    $key = $hook . '|' . $group . '|' . json_encode($args);
    return isset($actions[$key]) ? 1 : false;
}

function as_schedule_single_action(int $timestamp, string $hook, array $args = [], string $group = '', bool $unique = false): int
{
    global $actions;
    $key = $hook . '|' . $group . '|' . json_encode($args);
    $actions[$key] = compact('timestamp', 'hook', 'args', 'group', 'unique');
    return count($actions);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use VeciAhorra\Modules\Fulfillment\Orchestration\DurableCompletionScheduler;
use VeciAhorra\Modules\Fulfillment\Orchestration\DurableCompletionRecovery;
use VeciAhorra\Modules\Fulfillment\Orchestration\CompletionBranchPolicy;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\DurablePaymentOrigin;
use VeciAhorra\Modules\Fulfillment\Orchestration\DurableCompletionWorkers;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryLegacyExclusionInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryAuthorityIdentity;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryAuthorityIdentityCollection;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryIndeterminateReason;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryLegacyAuthorityBatchResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryLegacyAuthorityResult;

final class A11HistoricalAuthorityDouble implements DurableRetryLegacyExclusionInterface
{
    public array $calls = [];
    public DurableRetryLegacyAuthorityResult|Throwable $next;
    public function classify(DurableRetryAuthorityIdentity $identity): DurableRetryLegacyAuthorityResult
    {
        $this->calls[] = $identity;
        if ($this->next instanceof Throwable) { throw $this->next; }
        return $this->next;
    }
    public function classifyBatch(DurableRetryAuthorityIdentityCollection $identities): DurableRetryLegacyAuthorityBatchResult
    {
        throw new LogicException('Worker must classify one identity.');
    }
}

$scheduler = new DurableCompletionScheduler();
$scheduler->reconciliation(519);
$scheduler->reconciliation(519);
$scheduler->business(519);
$scheduler->delivery(801);
$scheduler->fulfillment(801);

if (count($actions) !== 4) {
    throw new RuntimeException('El scheduling no es unico por etapa y autoridad.');
}
foreach ($actions as $action) {
    if ($action['group'] !== DurableCompletionScheduler::GROUP
        || $action['unique'] !== true
        || array_keys($action['args']) !== ['authority_id']) {
        throw new RuntimeException('La identidad durable del job no es coherente.');
    }
}

$before = count($actions);
$scheduler->retry(DurableCompletionScheduler::FULFILLMENT, 802, 5);
if (count($actions) !== $before) {
    throw new RuntimeException('Se creo un retry despues del limite.');
}
$scheduler->retry(DurableCompletionScheduler::FULFILLMENT, 802, 2);
$retry = end($actions);
if (($retry['timestamp'] - time()) < 119 || ($retry['timestamp'] - time()) > 120) {
    throw new RuntimeException('El backoff no es acotado o determinista.');
}

$root = dirname(__DIR__, 2) . '/app/Modules/Fulfillment/Orchestration/';
$worker = file_get_contents($root . 'DurableCompletionWorkers.php');
$recovery = file_get_contents($root . 'DurableCompletionRecovery.php');
foreach (['PaymentSessionRepository', 'CheckoutRepository', 'OrderRepository', 'WebpayReturnRepository'] as $forbidden) {
    if (str_contains($worker . $recovery, $forbidden)) {
        throw new RuntimeException('La orquestacion reconstruye autoridad: ' . $forbidden);
    }
}

$wpdb = new class {
    public string $prefix = 'wp_';
    public array $queries = [];
    public function prepare(string $query, mixed ...$args): string
    {
        foreach ($args as $arg) {
            $query = preg_replace('/%s/', "'" . addslashes((string) $arg) . "'", $query, 1);
        }
        return $query;
    }
    public function get_col(string $query): array { $this->queries[] = $query; return []; }
};
(new DurableCompletionRecovery($scheduler))->recover();
if (count($wpdb->queries) !== 4) {
    throw new RuntimeException('Recovery no consulto las cuatro fronteras durables.');
}
foreach (['r.attempt_count', 'b.attempt_count', 'd.attempt_count', 'f.attempt_count'] as $index => $qualified) {
    if (! str_contains($wpdb->queries[$index], $qualified)) {
        throw new RuntimeException('Recovery contiene una columna ambigua: ' . $qualified);
    }
}
if (! str_contains($wpdb->queries[1], "o.origin='" . CompletionBranchPolicy::businessOrigin() . "'")) {
    throw new RuntimeException('Recovery no limita BusinessCompletion al origen durable interno.');
}
$workerSource = file_get_contents($root . 'DurableCompletionWorkers.php');
if (! str_contains($workerSource, 'nextAfterReconciliation($row)')) {
    throw new RuntimeException('Worker no aplica la politica durable de origen.');
}
$branches = new CompletionBranchPolicy();
if ($branches->nextForOrigin(DurablePaymentOrigin::ORIGIN_VECIAHORRA) !== CompletionBranchPolicy::BUSINESS_COMPLETION
    || $branches->nextForOrigin(DurablePaymentOrigin::ORIGIN_WOOCOMMERCE) !== CompletionBranchPolicy::BRANCH_COMPLETED
    || $branches->nextForOrigin('unknown') !== CompletionBranchPolicy::UNSUPPORTED) {
    throw new RuntimeException('La politica durable de ramas no es exhaustiva o segura.');
}

$authority = new A11HistoricalAuthorityDouble();
$workers = new DurableCompletionWorkers($authority, $scheduler, $branches);
foreach ([
    DurableRetryLegacyAuthorityResult::durable(),
    DurableRetryLegacyAuthorityResult::indeterminate(DurableRetryIndeterminateReason::QUERY_FAILED),
] as $blocked) {
    $authority->next = $blocked;
    $before = count($actions);
    $workers->reconciliation(519);
    if (count($actions) !== $before) {
        throw new RuntimeException('Fail-closed authority scheduled legacy work.');
    }
}
if (count($authority->calls) !== 2
    || $authority->calls[0]->diagnosticKey() !== 'reconciliation:519'
    || $authority->calls[1]->diagnosticKey() !== 'reconciliation:519') {
    throw new RuntimeException('Worker did not classify the exact A3 identity.');
}
$failure = new RuntimeException('A3 test failure');
$authority->next = $failure;
$caught = null;
try { $workers->reconciliation(519); } catch (RuntimeException $exception) { $caught = $exception; }
if ($caught !== $failure) { throw new RuntimeException('A3 exception did not propagate.'); }
$caught = null;
try { $workers->reconciliation(0); } catch (Throwable $exception) { $caught = $exception; }
if ($caught === null || count($authority->calls) !== 3) {
    throw new RuntimeException('Invalid identity did not fail before A3.');
}
$classify = strpos($workerSource, '$this->legacyAuthority->classify(');
$claim = strpos($workerSource, 'new PaymentReconciliationClaimRepository()');
if ($classify === false || $claim === false || $classify > $claim
    || substr_count($workerSource, '$this->legacyAuthority->classify(') !== 1) {
    throw new RuntimeException('A3 is not the first reconciliation authority.');
}

echo "PASS durable-completion-orchestration-test actions=4 retry_backoff=120 capped=5 recovery_queries=4 a11_guard=4\n";
