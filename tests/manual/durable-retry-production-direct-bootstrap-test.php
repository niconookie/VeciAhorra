<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$application = file_get_contents($root . '/app/Core/Application.php');
$registrar = file_get_contents($root . '/app/Modules/Orders/Infrastructure/DurableRetry/DurableRetryActionHookRegistrar.php');
$callback = file_get_contents($root . '/app/Modules/Orders/Infrastructure/DurableRetry/DurableRetryActionCallback.php');
$catalog = file_get_contents($root . '/app/Modules/Orders/Domain/DurableRetry/DurableRetryExternalScheduleCatalog.php');
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (! $condition) throw new RuntimeException($message);
};
$assert(str_contains($application, 'private static bool $registered = false;'), 'Global hook guard missing.');
$assert(str_contains($application, 'if (self::$registered)'), 'Global hook no-op missing.');
$assert(str_contains($application, 'self::$registered = false;'), 'Hook guard failure restoration missing.');
$assert(str_contains($application, 'private ?DurableRetryInitialProductionRouter'), 'Application does not retain A8.');
$assert(str_contains($catalog, "'veciahorra_durable_retry_reconciliation'"), 'Durable callback hook changed.');
$assert(str_contains($registrar, 'private const PRIORITY = 10;')
    && str_contains($registrar, 'private const ACCEPTED_ARGUMENTS = 2;'), 'Durable callback priority/arity changed.');
$assert(substr_count($callback, '$this->executor->execute(') === 1, 'Callback delegation count changed.');

echo "durable retry production direct bootstrap: 7 cases, {$assertions} assertions\n";
