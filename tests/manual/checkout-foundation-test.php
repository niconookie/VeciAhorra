<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Core\Container;
use VeciAhorra\Modules\Checkout\Requests\CheckoutRequest;
use VeciAhorra\Modules\Checkout\Routes\CheckoutRoutes;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertCheckoutFoundation(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertCheckoutFoundationSame(mixed $expected, mixed $actual): void
{
    assertCheckoutFoundation(
        $expected === $actual,
        sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        )
    );
}

function checkoutFoundationRequest(
    string $route,
    array $body,
    string $sessionId = 'checkout-foundation-session'
): WP_REST_Response {
    $request = new WP_REST_Request('POST', $route);
    $request->set_query_params(['session_id' => $sessionId]);
    $request->set_header('content-type', 'application/json');
    $request->set_body(wp_json_encode((object) $body));

    return rest_do_request($request);
}

function checkoutPostMethodCount(array $routes, string $route): int
{
    $count = 0;

    foreach ($routes[$route] ?? [] as $handler) {
        if (($handler['methods']['POST'] ?? false) === true) {
            $count++;
        }
    }

    return $count;
}

global $wpdb;

assertCheckoutFoundationSame([], (new CheckoutRequest([]))->validated());

try {
    (new CheckoutRequest(['items' => []]))->validated();
    throw new RuntimeException('Se esperaba payload invalido.');
} catch (InvalidArgumentException) {
}

assertCheckoutFoundation(
    (new Container())->make(CheckoutRoutes::class) instanceof CheckoutRoutes,
    'Container no resolvio CheckoutRoutes.'
);

$routes = rest_get_server()->get_routes();
$validateRoute = '/veciahorra/v1/checkout/validate';
$checkoutRoute = '/veciahorra/v1/checkout';
assertCheckoutFoundationSame(
    1,
    checkoutPostMethodCount($routes, $validateRoute)
);
assertCheckoutFoundationSame(
    1,
    checkoutPostMethodCount($routes, $checkoutRoute)
);

$ordersTable = $wpdb->prefix . Config::TABLE_PREFIX . 'orders';
$reservationsTable = $wpdb->prefix . Config::TABLE_PREFIX . 'reservations';
$inventoryTable = $wpdb->prefix . Config::TABLE_PREFIX . 'inventory';
$ordersBefore = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$ordersTable}"
);
$reservationsBefore = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$reservationsTable}"
);
$stockBefore = (string) $wpdb->get_var(
    "SELECT COALESCE(SUM(stock), 0) FROM {$inventoryTable}"
);

$validated = checkoutFoundationRequest($validateRoute, []);
assertCheckoutFoundationSame(200, $validated->get_status());
assertCheckoutFoundationSame(true, $validated->get_data()['success'] ?? null);
assertCheckoutFoundationSame(
    false,
    $validated->get_data()['data']['valid'] ?? null
);
assertCheckoutFoundationSame(
    'empty_cart',
    $validated->get_data()['data']['errors'][0]['code'] ?? null
);

$initialized = checkoutFoundationRequest($checkoutRoute, []);
assertCheckoutFoundationSame(200, $initialized->get_status());
assertCheckoutFoundationSame(
    false,
    $initialized->get_data()['data']['valid'] ?? null
);
assertCheckoutFoundationSame(
    false,
    $initialized->get_data()['data']['reservation_created'] ?? null
);
assertCheckoutFoundationSame(
    false,
    $initialized->get_data()['data']['order_created'] ?? null
);

foreach ([$validateRoute, $checkoutRoute] as $route) {
    $invalid = checkoutFoundationRequest($route, [
        'items' => [],
    ]);
    assertCheckoutFoundationSame(422, $invalid->get_status());
    assertCheckoutFoundationSame(
        'validation_error',
        $invalid->get_data()['error']['code'] ?? null
    );
}

assertCheckoutFoundationSame(
    $ordersBefore,
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$ordersTable}")
);
assertCheckoutFoundationSame(
    $reservationsBefore,
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$reservationsTable}")
);
assertCheckoutFoundationSame(
    $stockBefore,
    (string) $wpdb->get_var(
        "SELECT COALESCE(SUM(stock), 0) FROM {$inventoryTable}"
    )
);

$application = file_get_contents(
    dirname(__DIR__, 2) . '/app/Core/Application.php'
);
assertCheckoutFoundationSame(
    1,
    substr_count($application, '$checkoutRoutes = $this->container->make')
);
assertCheckoutFoundationSame(
    1,
    substr_count($application, "[\$checkoutRoutes, 'register']")
);

$moduleFiles = glob(
    dirname(__DIR__, 2) . '/app/Modules/Checkout/*/*.php'
) ?: [];
$moduleSource = '';

foreach ($moduleFiles as $file) {
    $moduleSource .= (string) file_get_contents($file);
}

assertCheckoutFoundation(
    ! str_contains($moduleSource, '$wpdb')
    && ! str_contains($moduleSource, 'START TRANSACTION')
    && ! str_contains($moduleSource, 'InventoryLockService'),
    'Checkout foundation contiene efectos laterales fuera de alcance.'
);

echo "PASS checkout-foundation-test\n";
