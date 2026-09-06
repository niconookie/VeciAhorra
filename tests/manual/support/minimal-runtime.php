<?php
declare(strict_types=1);
if (!defined('MINIMAL_PREBOOTSTRAP_ISOLATED') || MINIMAL_PREBOOTSTRAP_ISOLATED !== true
    || !function_exists('minimalRuntimeAuthority')) {
    throw new RuntimeException('PREBOOTSTRAP_REQUIRED');
}
$minimalAuthority = minimalRuntimeAuthority();
if (DB_NAME !== $minimalAuthority['database'] || DB_HOST !== $minimalAuthority['host']) {
    throw new RuntimeException('PREBOOTSTRAP_DB_AUTHORITY');
}
require_once $minimalAuthority['root'] . '/wp-load.php';
if (\VeciAhorra\Modules\Payments\Gateway\PaymentGatewayConfiguration::gateway() !== 'mock') {
    throw new RuntimeException('EFFECTIVE_MOCK_REQUIRED');
}
if (!(new \VeciAhorra\Core\Application())->container()->make(
    \VeciAhorra\Modules\Payments\Gateway\PaymentGatewayInterface::class
) instanceof \VeciAhorra\Modules\Payments\Gateway\MockPaymentGateway) {
    throw new RuntimeException('EFFECTIVE_FACTORY_REQUIRED');
}
wp_set_current_user(1);
