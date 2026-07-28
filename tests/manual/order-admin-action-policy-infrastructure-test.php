<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$files = [
    dirname(__DIR__, 2) . '/app/Modules/Orders/Domain/Policies/OrderAdminActionPolicy.php',
    dirname(__DIR__, 2) . '/app/Modules/Orders/Domain/Policies/OrderAdminActionDecision.php',
    dirname(__DIR__, 2) . '/app/Modules/Orders/Services/OrderAdminActionPolicyIntegration.php',
];
$source = implode("\n", array_map(static fn (string $file): string => (string) file_get_contents($file), $files));
$forbidden = [
    '$wpdb', 'Repository', 'wp_remote_', 'rest_', 'wpdb', 'INSERT ', 'UPDATE ',
    'DELETE ', 'current_time(', 'time()', 'microtime(', 'DateTimeImmutable(\'now',
    'register_rest_route', 'add_action(', 'wp_', 'Scheduler', 'Worker',
    '->schedule(', '->retry(',
];
foreach ($forbidden as $needle) {
    if (stripos($source, $needle) !== false) {
        throw new RuntimeException("Policy infrastructure contains forbidden dependency: {$needle}");
    }
}
$reflection = new ReflectionClass(VeciAhorra\Modules\Orders\Domain\Policies\OrderAdminActionPolicy::class);
if ($reflection->getConstructor() !== null) {
    throw new RuntimeException('Policy must not have constructor dependencies.');
}
foreach ($reflection->getProperties() as $property) {
    if (! $property->isStatic()) {
        throw new RuntimeException('Policy must not retain mutable state.');
    }
}
$readService = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Orders/Services/OrderAdminReadService.php');
$resolution = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Orders/Domain/Operational/OrderOperationalResolution.php');
if (str_contains($readService, 'OrderAdminActionPolicy') || ! str_contains($resolution, "'mutable_actions' => []")) {
    throw new RuntimeException('Private policy was accidentally exposed through the read/REST model.');
}

echo "order-admin-action-policy-infrastructure-test: OK (zero persistence/transport dependencies; private; zero SQL)\n";
