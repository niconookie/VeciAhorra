<?php

declare(strict_types=1);

$base = 'https://localhost/Minimarket';
$ssl = ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true];

function p0Request(string $url, array $ssl, string $method = 'GET', array $fields = [], array $headers = []): array
{
    $content = $fields === [] ? '' : http_build_query($fields);
    if ($content !== '') {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $headers[] = 'Content-Length: ' . strlen($content);
    }
    $context = stream_context_create(['ssl' => $ssl, 'http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $content,
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 20,
    ]]);
    $body = file_get_contents($url, false, $context);
    return ['body' => is_string($body) ? $body : '', 'headers' => $http_response_header ?? []];
}

function p0Cookies(array $headers): string
{
    $cookies = [];
    foreach ($headers as $header) {
        if (stripos($header, 'Set-Cookie:') !== 0) continue;
        $pair = trim(explode(';', substr($header, 11), 2)[0]);
        if ($pair !== '') $cookies[] = $pair;
    }
    return implode('; ', $cookies);
}

function p0Assert(bool $condition, string $message): void
{
    if (! $condition) throw new RuntimeException($message);
}

$home = p0Request($base . '/', $ssl);
p0Assert(str_contains($home['headers'][0] ?? '', '200'), 'Home no respondió 200.');
p0Assert(str_contains($home['body'], 'Registrarse'), 'Home no expone Registrarse.');
p0Assert(str_contains($home['body'], 'Iniciar sesión'), 'Home no expone Iniciar sesión.');

$login = p0Request($base . '/wp-login.php', $ssl, 'POST', [
    'log' => 'va_demo_minimarket_vecinos',
    'pwd' => 'VA-Vecinos-2026!',
    'wp-submit' => 'Acceder',
    'redirect_to' => $base . '/panel-minimarket/',
    'testcookie' => '1',
], ['Cookie: wordpress_test_cookie=WP%20Cookie%20check']);
$cookie = p0Cookies($login['headers']);
p0Assert(str_contains($login['headers'][0] ?? '', '302'), 'Login store no respondió 302.');
p0Assert(str_contains($cookie, 'wordpress_logged_in_'), 'Login store no emitió cookie autenticada.');

$panel = p0Request($base . '/panel-minimarket/', $ssl, 'GET', [], ['Cookie: ' . $cookie]);
p0Assert(str_contains($panel['headers'][0] ?? '', '200'), 'Panel store no respondió 200.');
p0Assert(str_contains($panel['body'], 'Mis productos'), 'Panel store no contiene Mis productos.');
p0Assert(str_contains($panel['body'], 'Cerrar sesión'), 'Panel store no expone logout.');
p0Assert(preg_match('/"nonce":"([^"]+)"/', $panel['body'], $nonce) === 1, 'Panel store no expone nonce REST.');

$inventoryResponse = p0Request(
    $base . '/wp-json/veciahorra/v1/minimarket/inventory',
    $ssl,
    'GET',
    [],
    ['Cookie: ' . $cookie, 'X-WP-Nonce: ' . $nonce[1], 'Accept: application/json']
);
p0Assert(str_contains($inventoryResponse['headers'][0] ?? '', '200'), 'Inventory REST no respondió 200.');
$payload = json_decode($inventoryResponse['body'], true, 512, JSON_THROW_ON_ERROR);
$rows = $payload['data'] ?? [];
p0Assert(count($rows) === 20, 'Los Vecinos no recibió 20 inventories.');
$imageHttpSuccess = 0;
foreach ($rows as $row) {
    p0Assert(! empty($row['image_url']), 'Inventory sin image_url: ' . ($row['name'] ?? 'desconocido'));
    $image = p0Request((string) $row['image_url'], $ssl);
    p0Assert(str_contains($image['headers'][0] ?? '', '200'), 'Imagen no respondió 200: ' . $row['name']);
    p0Assert(strlen($image['body']) > 0, 'Imagen vacía: ' . $row['name']);
    $imageHttpSuccess++;
}

$official = [
    'Coca-Cola Original 1,5 L' => [2190, 12],
    'Tallarines Carozzi 400 g' => [1050, 17],
    'Salsa de tomates Carozzi' => [750, 18],
    'Super 8' => [500, 11],
];
foreach ($official as $name => [$price, $stock]) {
    $matches = array_values(array_filter($rows, static fn (array $row): bool => $row['name'] === $name));
    p0Assert(count($matches) === 1, 'Oferta oficial ausente: ' . $name);
    p0Assert((float) $matches[0]['price'] === (float) $price, 'Precio incorrecto: ' . $name);
    p0Assert((int) $matches[0]['stock'] === $stock, 'Stock incorrecto: ' . $name);
    p0Assert(! empty($matches[0]['image_url']), 'Imagen API ausente: ' . $name);
}

define('WP_USE_THEMES', false);
require dirname(__DIR__, 5) . '/wp-load.php';
global $wpdb;
$foreign = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->prefix}va_inventory i JOIN {$wpdb->prefix}va_stores s ON s.id=i.minimarket_id "
    . "WHERE i.id IN (" . implode(',', array_map(static fn (array $row): int => (int) $row['inventory_id'], $rows)) . ") "
    . "AND s.business_name <> 'Minimarket Los Vecinos'"
);
p0Assert($foreign === 0, 'REST expuso inventory de otra store.');
$orderResponse = p0Request(
    $base . '/wp-json/veciahorra/v1/minimarket/orders',
    $ssl,
    'GET',
    [],
    ['Cookie: ' . $cookie, 'X-WP-Nonce: ' . $nonce[1], 'Accept: application/json']
);
p0Assert(str_contains($orderResponse['headers'][0] ?? '', '200'), 'Orders REST no respondió 200.');
$orderPayload = json_decode($orderResponse['body'], true, 512, JSON_THROW_ON_ERROR);
$orderRows = $orderPayload['data'] ?? [];
$foreignOrders = 0;
foreach ($orderRows as $orderRow) {
    $storeName = (string) $wpdb->get_var($wpdb->prepare(
        "SELECT s.business_name FROM {$wpdb->prefix}va_orders o JOIN {$wpdb->prefix}va_stores s ON s.id=o.minimarket_id WHERE o.id=%d",
        (int) $orderRow['order_id']
    ));
    if ($storeName !== 'Minimarket Los Vecinos') $foreignOrders++;
}
p0Assert($foreignOrders === 0, 'REST expuso pedido de otra store.');
if ($orderRows !== []) {
    $detailResponse = p0Request(
        $base . '/wp-json/veciahorra/v1/minimarket/orders/' . (int) $orderRows[0]['order_id'],
        $ssl,
        'GET',
        [],
        ['Cookie: ' . $cookie, 'X-WP-Nonce: ' . $nonce[1], 'Accept: application/json']
    );
    p0Assert(str_contains($detailResponse['headers'][0] ?? '', '200'), 'Detalle de pedido propio no respondió 200.');
}

echo "PASS http_home=200 discoverability=yes store_login=302 panel=200 logout=yes\n";
echo "PASS store_inventory=20 official_offers=4 images_api=4 isolation=yes\n";
echo "PASS image_http_success={$imageHttpSuccess} image_http_failure=0\n";
echo 'PASS store_order_list_isolation=yes store_order_detail_isolation=' . ($orderRows === [] ? 'not_applicable' : 'yes') . ' order_rows=' . count($orderRows) . "\n";
