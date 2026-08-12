<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$product = [
    'app/Core/Application.php',
    'app/Modules/Payments/Reconciliation/Service/WebpayReconciliationMaterializer.php',
    'app/Modules/Payments/Service/WebpayReturnService.php',
    'app/Modules/Payments/Orchestration/WebpayReturnRecovery.php',
];
$source = '';
foreach ($product as $path) {
    $source .= file_get_contents($root . '/' . $path);
}
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (! $condition) throw new RuntimeException($message);
};
$assert(! str_contains($source, 'veciahorra_durable_retry_initial_reconciliation'), 'Initial action hook found.');
$assert(! str_contains($source, 'DurableRetryProductionHookRegistrar'), 'Obsolete registrar found.');
$assert(! str_contains($source, 'do_action('), 'Initial publication cannot use do_action.');
$assert(substr_count($source, 'new DurableRetryProductionComposition(') === 1, 'A9 construction count changed.');
$assert(substr_count($source, '$composition->router()') === 1, 'router() call count changed.');
$materializer = file_get_contents($root . '/' . $product[1]);
$assert(! str_contains($materializer, 'new DurableCompletionScheduler'), 'Direct legacy scheduler found.');
$publicationStart = strpos($materializer, 'private function publishRetryAuthorityCandidate');
$publication = substr(
    $materializer,
    $publicationStart,
    strpos($materializer, 'private function originId', $publicationStart) - $publicationStart
);
$assert(! preg_match('/\b(?:SELECT|INSERT|UPDATE|DELETE)\b/i', $publication), 'A10 publication introduced SQL.');

echo "durable retry production direct wiring infrastructure: 7 cases, {$assertions} assertions\n";
