<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$materializer = file_get_contents($root . '/app/Modules/Payments/Reconciliation/Service/WebpayReconciliationMaterializer.php');
$application = file_get_contents($root . '/app/Core/Application.php');
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (! $condition) throw new RuntimeException($message);
};
$assert(str_contains($materializer, 'catch (DuplicateReconciliation)'), 'Fingerprint convergence was removed.');
$assert(substr_count($materializer, 'findByFingerprint(') >= 3, 'Stored authority is not reused.');
$assert(! preg_match('/\b(?:retry|sleep|usleep)\s*\(/', $materializer), 'Local retry loop found.');
$assert(! preg_match('/\b(?:for|foreach|while)\s*\(/', substr($materializer, strpos($materializer, 'private function publishRetryAuthorityCandidate'))), 'Publication loop found.');
$assert((bool) preg_match('/singleton\s*\(\s*WebpayReconciliationMaterializer::class/', $application), 'Materializer is not a singleton.');

echo "durable retry production direct concurrency: 5 cases, {$assertions} assertions\n";
