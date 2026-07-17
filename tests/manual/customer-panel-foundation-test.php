<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertCustomerPanel(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$routes = rest_get_server()->get_routes();
assertCustomerPanel(
    isset($routes['/veciahorra/v1/customer-panel/purchases']),
    'Falta la ruta publica basada en Checkout.'
);
assertCustomerPanel(
    isset($routes['/veciahorra/v1/customer-panel/purchases/(?P<checkout_public_id>[^/]+)']),
    'Falta el detalle publico basado en Checkout.public_id.'
);
assertCustomerPanel(
    ! isset($routes['/veciahorra/v1/me/orders'])
        && ! isset($routes['/veciahorra/v1/me/orders/(?P<id>\d+)']),
    'Las rutas legadas exponen Order IDs como raiz publica paralela.'
);

$moduleFiles = glob(dirname(__DIR__, 2) . '/app/Modules/CustomerPanel/*/*.php') ?: [];
$source = '';
foreach ($moduleFiles as $file) {
    $source .= (string) file_get_contents($file);
}
assertCustomerPanel(
    preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE)\b/i', $source) !== 1,
    'CustomerPanel contiene escrituras.'
);
assertCustomerPanel(
    ! str_contains($source, "'/me/orders'")
        && ! str_contains($source, 'function listOrders(')
        && ! str_contains($source, 'function getOrder('),
    'Permanece codigo del contrato incremental retirado.'
);

echo "PASS customer-panel-foundation-test\n";
