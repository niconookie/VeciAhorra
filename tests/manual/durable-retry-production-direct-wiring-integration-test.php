<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use VeciAhorra\Modules\Payments\Reconciliation\Service\WebpayReconciliationMaterializer;
use VeciAhorra\Modules\Payments\Service\WebpayReturnService;
use VeciAhorra\Modules\Payments\Orchestration\WebpayReturnRecovery;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (! $condition) throw new RuntimeException($message);
};
$materializer = new ReflectionMethod(WebpayReconciliationMaterializer::class, '__construct');
$parameters = $materializer->getParameters();
$assert(count($parameters) === 3, 'Materializer constructor must have three dependencies.');
$assert($parameters[2]->getName() === 'initialProductionRouter' && ! $parameters[2]->isOptional(), 'A8 must be third and required.');
$service = (new ReflectionMethod(WebpayReturnService::class, '__construct'))->getParameters();
$assert($service[3]->getName() === 'materializer' && ! $service[3]->isOptional(), 'Return service materializer must be fourth and required.');
$recovery = (new ReflectionMethod(WebpayReturnRecovery::class, '__construct'))->getParameters();
$assert($recovery[0]->getName() === 'materializer' && ! $recovery[0]->isOptional(), 'Recovery materializer must be first and required.');
$source = file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Payments/Reconciliation/Service/WebpayReconciliationMaterializer.php');
$assert(substr_count($source, '$this->publishRetryAuthorityCandidate($materialized);') === 2, 'Both non-null paths must publish.');
$assert(substr_count($source, 'return $materialized;') === 2, 'Both paths must return materialized evidence.');

echo "durable retry production direct wiring integration: 6 cases, {$assertions} assertions\n";
