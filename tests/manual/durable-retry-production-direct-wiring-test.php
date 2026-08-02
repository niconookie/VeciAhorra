<?php

declare(strict_types=1);

use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialProductionRoutingResult as Result;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$source = file_get_contents(dirname(__DIR__, 2)
    . '/app/Modules/Payments/Reconciliation/Service/WebpayReconciliationMaterializer.php');
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$states = [
    Result::LEGACY_SCHEDULED,
    Result::LEGACY_UNAVAILABLE,
    Result::DURABLE_SYNCHRONIZED,
    Result::DURABLE_ALREADY_SYNCHRONIZED,
    Result::DURABLE_EXTERNAL_UNAVAILABLE,
    Result::DURABLE_COORDINATION_FAILED,
    Result::DURABLE_COORDINATION_UNCERTAIN,
    Result::AUTHORITY_CLOSED,
    Result::RESOLUTION_FAILED,
    Result::INVALID_INPUT,
    Result::DEPENDENCY_FAILURE,
];
foreach ($states as $state) {
    $assert(substr_count($source, '::' . strtoupper($state) . ' => null') === 1,
        "State {$state} is not consumed exactly once.");
}
$method = substr($source, strpos($source, 'private function publishRetryAuthorityCandidate'));
$assert(substr_count($method, "gmdate('Y-m-d H:i:s')") === 1, 'UTC time must be captured once.');
$assert(substr_count($method, '->routeReconciliation(') === 1, 'A8 must be invoked once.');
$assert(str_contains($method, "new DateTimeZone('UTC')"), 'UTC timezone is required.');
$assert(str_contains($method, "format('u') !== '000000'"), 'Microseconds must be zero.');
$assert(! preg_match('/\bdefault\s*=>/', $method), 'The result match must not have default.');

echo "durable retry production direct wiring: 11 cases, {$assertions} assertions\n";
