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

echo "PASS durable-completion-orchestration-test actions=4 retry_backoff=120 capped=5 recovery_queries=4\n";
