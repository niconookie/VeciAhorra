<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'page' => $root . '/app/Modules/Orders/Admin/OrdersPage.php',
    'route' => $root . '/app/Modules/Orders/Routes/OrdersAdminRoutes.php',
    'view' => $root . '/app/Modules/Orders/Views/admin-list.php',
    'app' => $root . '/assets/admin/js/modules/orders/app.js',
    'api' => $root . '/assets/admin/js/modules/orders/api.js',
    'state' => $root . '/assets/admin/js/modules/orders/state.js',
    'ui' => $root . '/assets/admin/js/modules/orders/view.js',
    'navigation' => $root . '/assets/admin/js/modules/orders/navigation.js',
    'detailNavigation' => $root . '/assets/admin/js/modules/orders/detail-navigation.js',
    'application' => $root . '/app/Core/Application.php',
];
$source = array_map(static fn (string $file): string => file_get_contents($file) ?: '', $files);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (! $condition) throw new RuntimeException($message);
    $assertions++;
};
$assert(str_contains($source['page'], "'veciahorra-orders'"), 'Slug ausente.');
$assert(str_contains($source['page'], "'manage_options'"), 'Capability ausente.');
$assert(str_contains($source['page'], '$hookSuffix !== $this->pageHook'), 'Assets no acotados.');
$assert(str_contains($source['view'], '<h1>') && substr_count($source['view'], '<h1>') === 1, 'H1 invalido.');
$assert(str_contains($source['view'], 'aria-live') && str_contains($source['view'], '<noscript>'), 'Shell no accesible.');
$assert(! str_contains($source['view'], 'listOrders('), 'Shell consulta Orders.');
$assert(str_contains($source['route'], "'/orders/admin'") && str_contains($source['route'], 'WP_REST_Server::READABLE'), 'Ruta GET ausente.');
$assert(str_contains($source['route'], "header('Cache-Control', 'private, no-store')"), 'Cache insegura.');
$assert(str_contains($source['route'], "wp_verify_nonce(\$nonce, 'wp_rest')"), 'Nonce ausente.');
$assert(str_contains($source['route'], '$this->service->listOrders($query)'), 'No usa servicio certificado.');
$assert(! preg_match('/\\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE)\\b/', $source['route']), 'Ruta mutable.');
$assert(str_contains($source['api'], "'X-WP-Nonce': config.nonce"), 'API sin nonce.');
$assert(str_contains($source['api'], "method: 'GET'"), 'API no usa GET.');
$assert(str_contains($source['state'], 'AbortController') && str_contains($source['state'], 'current !== sequence'), 'Concurrencia incompleta.');
$assert(substr_count($source['app'], 'state.load(') === 2, 'Carga fuera de inicial/popstate.');
$assert(! str_contains($source['ui'], 'fetch('), 'Vista realiza solicitudes.');
$assert(! str_contains($source['ui'], 'innerHTML'), 'Vista usa HTML no confiable.');
$assert(str_contains($source['ui'], "link.textContent = 'Ver'"), 'Accion view ausente.');
$assert(! str_contains($source['ui'], 'return_search'), 'Vista duplica contexto de retorno.');
$assert(str_contains($source['detailNavigation'], 'buildOrderDetailUrl'), 'Constructor de detalle ausente.');
$assert(str_contains($source['navigation'], "url.searchParams.set('page', 'veciahorra-orders')"), 'URL no canonica.');
$assert(str_contains($source['application'], 'OrderAdminReadRepositoryInterface::class'), 'Binding ausente.');
$assert(str_contains($source['application'], 'OrdersAdminRoutes::class') && str_contains($source['application'], 'OrdersPage::class'), 'Bootstrap incompleto.');

echo "PASS order-admin-list-infrastructure-test assertions={$assertions}\n";
