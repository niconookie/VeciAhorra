<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use VeciAhorra\Core\LaunchGate;

function prelaunchAssert(bool $condition, string $message): void
{
    if (! $condition) throw new RuntimeException($message);
}

foreach (['local', 'development', 'staging'] as $environment) {
    prelaunchAssert(LaunchGate::evaluate(false, null, $environment), $environment . ' debe conservar el comportamiento actual.');
}
prelaunchAssert(! LaunchGate::evaluate(false, null, 'production'), 'Producci&oacute;n sin constante debe cerrar.');
prelaunchAssert(LaunchGate::evaluate(true, true, 'production'), 'true nativo debe habilitar.');
foreach ([false, 'false', 'yes', '1', 1, 0, null] as $hostile) {
    prelaunchAssert(! LaunchGate::evaluate(true, $hostile, 'local'), 'S&oacute;lo true nativo debe habilitar.');
}

$root = dirname(__DIR__, 2);
$contracts = [
    'app/Modules/CustomerAccess/CustomerAccessModule.php' => ['registrationEnabled', 'status_header(503)', 'option_users_can_register', 'option_woocommerce_enable_myaccount_registration'],
    'app/Modules/Minimarket/Onboarding/PublicIntake/PublicOnboardingController.php' => ['registrationEnabled', 'PublicOnboardingResponse(503'],
    'app/Modules/ServiceProviders/Routes/ServiceProviderRoutes.php' => ['registrationEnabled', 'registration_disabled'],
    'app/Modules/Cart/Routes/CartRoutes.php' => ['commerceEnabled', 'commerce_disabled'],
    'app/Modules/Checkout/Routes/CheckoutRoutes.php' => ['commerceEnabled', 'commerce_disabled'],
    'app/Modules/Payments/Routes/PaymentRoutes.php' => ['commerceEnabled', 'commerce_disabled'],
    'app/Modules/Payments/WooCommerce/WebpayPlusGateway.php' => ['commerceEnabled', 'paymentFailure'],
];
foreach ($contracts as $path => $needles) {
    $source = file_get_contents($root . '/' . $path);
    prelaunchAssert(is_string($source), 'No se pudo leer ' . $path);
    foreach ($needles as $needle) prelaunchAssert(str_contains($source, $needle), $path . ' no contiene ' . $needle);
}

echo "PRELAUNCH_GATE_CONTRACT=PASS\n";
