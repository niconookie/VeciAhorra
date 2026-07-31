<?php

declare(strict_types=1);

$effects = ['sql' => 0, 'options' => 0, 'external' => 0, 'legacy' => 0, 'hooks' => 0];
$legacyProvider = ['pending' => false, 'result' => 1, 'throw' => false];

if (! class_exists('wpdb')) {
    class wpdb
    {
        public string $prefix = 'wp_';
        public function query(string $sql): false { global $effects; ++$effects['sql']; return false; }
        public function prepare(string $sql, mixed ...$values): string { global $effects; ++$effects['sql']; return $sql; }
        public function get_results(string $sql, mixed $format = null): array { global $effects; ++$effects['sql']; return []; }
    }
}
if (! function_exists('as_schedule_single_action')) {
    function as_schedule_single_action(mixed ...$arguments): mixed
    {
        global $effects, $legacyProvider;
        ++$effects['external'];
        if ($legacyProvider['throw']) { throw new RuntimeException('legacy provider failure'); }
        return $legacyProvider['result'];
    }
}
if (! function_exists('as_get_scheduled_actions')) {
    function as_get_scheduled_actions(mixed ...$arguments): array { global $effects; ++$effects['external']; return []; }
}
if (! function_exists('as_unschedule_action')) {
    function as_unschedule_action(mixed ...$arguments): int { global $effects; ++$effects['external']; return 1; }
}
if (! function_exists('as_has_scheduled_action')) {
    function as_has_scheduled_action(mixed ...$arguments): mixed
    {
        global $effects, $legacyProvider;
        ++$effects['legacy'];
        return $legacyProvider['pending'];
    }
}
if (! function_exists('get_option')) {
    function get_option(mixed ...$arguments): mixed { global $effects; ++$effects['options']; return $arguments[1] ?? false; }
}
if (! function_exists('add_action')) {
    function add_action(mixed ...$arguments): void { global $effects; ++$effects['hooks']; }
}

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use VeciAhorra\Modules\Fulfillment\Orchestration\DurableCompletionScheduler;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryLegacySchedulerInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialProductionRoutingResult;
use VeciAhorra\Modules\Orders\Infrastructure\DurableRetry\ActionSchedulerDurableRetryAdapter;
use VeciAhorra\Modules\Orders\Infrastructure\DurableRetry\DurableRetryProductionComposition;
use VeciAhorra\Modules\Orders\Infrastructure\DurableRetry\WordPressOptionDurableRetryActivationConfigurationValueReader;
use VeciAhorra\Modules\Orders\Repositories\DurableRetryInitialTransferRepository;
use VeciAhorra\Modules\Orders\Repositories\DurableRetryLegacyAuthorityRepository;
use VeciAhorra\Modules\Orders\Repositories\DurableRetryScheduleRepository;
use VeciAhorra\Modules\Orders\Services\DurableRetryExternalScheduleCoordinator;
use VeciAhorra\Modules\Orders\Services\DurableRetryInitialAuthorityProducer;
use VeciAhorra\Modules\Orders\Services\DurableRetryInitialProductionRouter;
use VeciAhorra\Modules\Orders\Services\DurableRetryInitialScheduleCoordinator;
use VeciAhorra\Modules\Orders\Services\DurableRetryInitialScheduleResolver;
use VeciAhorra\Modules\Orders\Services\DurableRetryInitialTransferAuthority;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) { throw new RuntimeException($message); }
};
$property = static function (object $object, string $name): mixed {
    return (new ReflectionProperty($object, $name))->getValue($object);
};

$database = new wpdb();
$reader = new WordPressOptionDurableRetryActivationConfigurationValueReader();
$external = new ActionSchedulerDurableRetryAdapter();
$legacy = new DurableCompletionScheduler();
$clockCalls = 0;
$clock = static function () use (&$clockCalls): string { ++$clockCalls; return '2035-01-01 00:00:00'; };
$composition = new DurableRetryProductionComposition($database, $reader, $external, $legacy, $clock);
$router = $composition->router();

$assert($router instanceof DurableRetryInitialProductionRouter, 'real A8 router');
$assert($legacy instanceof DurableRetryLegacySchedulerInterface, 'legacy implements certified port');
for ($index = 0; $index < 10; ++$index) {
    $assert($composition->router() === $router, "real graph identity {$index}");
}

$a5 = $property($router, 'authorityProducer');
$a6 = $property($router, 'scheduleResolver');
$a7 = $property($router, 'scheduleCoordinator');
$assert($a5 instanceof DurableRetryInitialAuthorityProducer, 'real A5');
$assert($a6 instanceof DurableRetryInitialScheduleResolver, 'real A6');
$assert($a7 instanceof DurableRetryInitialScheduleCoordinator, 'real A7');
$assert($property($router, 'legacyScheduler') === $legacy, 'A8 exact legacy scheduler');

$a3 = $property($a5, 'authority');
$policy = $property($a5, 'activation');
$source = $property($policy, 'source');
$transfer = $property($a5, 'transfer');
$a4 = $property($transfer, 'repository');
$durableRepository = $property($a6, 'repository');
$externalCoordinator = $property($a7, 'coordinator');
$assert($a3 instanceof DurableRetryLegacyAuthorityRepository, 'real A3');
$assert($transfer instanceof DurableRetryInitialTransferAuthority, 'real A4 authority');
$assert($a4 instanceof DurableRetryInitialTransferRepository, 'real A4 repository');
$assert($durableRepository instanceof DurableRetryScheduleRepository, 'real durable repository');
$assert($externalCoordinator instanceof DurableRetryExternalScheduleCoordinator, 'real external coordinator');
$assert($property($source, 'reader') === $reader, 'single configuration reader');
$assert($property($a3, 'database') === $database, 'A3 shares database');
$assert($property($a4, 'database') === $database, 'A4 shares database');
$assert($property($durableRepository, 'database') === $database, 'A6 shares database');
$assert($property($externalCoordinator, 'repository') === $durableRepository, 'A6/A7 share durable repository');
$assert($property($externalCoordinator, 'scheduler') === $external, 'A7 exact external adapter');
$assert($property($externalCoordinator, 'utcNow') === $clock, 'A7 exact clock');

$assert($effects === ['sql' => 0, 'options' => 0, 'external' => 0, 'legacy' => 0, 'hooks' => 0], 'composition has zero effects');
$assert($clockCalls === 0, 'composition does not call clock');

$assert($legacy->scheduleReconciliation(0) === false, 'legacy invalid ID is not scheduled');
$assert($legacy->scheduleReconciliation(42) === true, 'legacy positive action ID confirms scheduling');
$legacyProvider['pending'] = 77;
$assert($legacy->scheduleReconciliation(42) === true, 'legacy pending identity confirms scheduling');
$legacyProvider['pending'] = false;
$legacyProvider['result'] = 0;
$assert($legacy->scheduleReconciliation(42) === false, 'legacy unconfirmed result is false');
$legacyProvider['throw'] = true;
$legacyError = null;
try {
    $legacy->scheduleReconciliation(42);
} catch (Throwable $error) {
    $legacyError = $error;
}
$assert($legacyError?->getMessage() === 'legacy provider failure', 'legacy provider exception propagates');

$states = [
    'LEGACY_SCHEDULED', 'LEGACY_UNAVAILABLE', 'DURABLE_SYNCHRONIZED',
    'DURABLE_ALREADY_SYNCHRONIZED', 'DURABLE_EXTERNAL_UNAVAILABLE',
    'DURABLE_COORDINATION_FAILED', 'DURABLE_COORDINATION_UNCERTAIN',
    'AUTHORITY_CLOSED', 'RESOLUTION_FAILED', 'INVALID_INPUT',
    'DEPENDENCY_FAILURE',
];
$constants = (new ReflectionClass(DurableRetryInitialProductionRoutingResult::class))->getConstants();
$assert(count(array_intersect_key($constants, array_flip($states))) === 11, 'A8 catalog remains eleven states');
$assert(count(array_filter($constants, 'is_string')) === 11, 'no A8 state aliases');

$compositionSource = (string) file_get_contents(
    dirname(__DIR__, 2) . '/app/Modules/Orders/Infrastructure/DurableRetry/DurableRetryProductionComposition.php'
);
$assert(substr_count($compositionSource, 'new DurableRetryInitialProductionRouter(') === 1, 'one product router site');
$assert(substr_count($compositionSource, 'new DurableRetryProductionActivationConfigurationSource(') === 1, 'one configuration authority site');
$assert(substr_count($compositionSource, 'new DurableRetryScheduleRepository(') === 1, 'one persistence authority site');
$assert(substr_count($compositionSource, '$this->externalScheduler') === 1, 'one injected scheduling authority');

echo "durable retry production composition integration: 20 scenarios, {$assertions} assertions\n";
