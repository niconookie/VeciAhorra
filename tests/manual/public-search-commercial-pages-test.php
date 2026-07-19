<?php

declare(strict_types=1);

use VeciAhorra\Modules\Frontend\Search\WooCommercePublicPageResolver;
use VeciAhorra\Modules\Frontend\Support\PublicRouteResolver;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertCommercialPageSearch(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @return array{status: int, body: string, location: string} */
function commercialPageGet(string $url, int $redirection = 0): array
{
    $response = wp_remote_get($url, [
        'sslverify' => false,
        'redirection' => $redirection,
        'timeout' => 20,
    ]);
    assertCommercialPageSearch(! is_wp_error($response), "Fallo HTTP para {$url}.");

    return [
        'status' => wp_remote_retrieve_response_code($response),
        'body' => (string) wp_remote_retrieve_body($response),
        'location' => (string) wp_remote_retrieve_header($response, 'location'),
    ];
}

function bodyLinksTo(string $body, string $url): bool
{
    return str_contains($body, 'href="' . esc_url($url) . '"');
}

do_action('rest_api_init');
$commercialIds = (new WooCommercePublicPageResolver())->pageIds();
assertCommercialPageSearch(count($commercialIds) === 4, 'Faltan autoridades WooCommerce reales para la prueba.');

foreach ($commercialIds as $commercialId) {
    $title = get_the_title($commercialId);
    $permalink = get_permalink($commercialId);
    assertCommercialPageSearch(is_string($title) && $title !== '', 'Pagina comercial sin titulo de prueba.');
    assertCommercialPageSearch(is_string($permalink) && $permalink !== '', 'Pagina comercial sin permalink.');

    $traditional = commercialPageGet(add_query_arg('s', $title, home_url('/')));
    assertCommercialPageSearch($traditional['status'] === 200, 'La busqueda tradicional no respondio 200.');
    assertCommercialPageSearch(
        ! bodyLinksTo($traditional['body'], $permalink),
        "La busqueda tradicional expuso la pagina comercial {$commercialId}."
    );

    $request = new WP_REST_Request('GET', '/wp/v2/search');
    $request->set_param('type', 'post');
    $request->set_param('subtype', ['post', 'page', 'product']);
    $request->set_param('ct_live_search', 'true');
    $request->set_param('search', $title);
    $live = rest_do_request($request);
    assertCommercialPageSearch($live->get_status() === 200, 'El live search no respondio 200.');
    $liveIds = array_map(static fn (array $item): int => (int) $item['id'], $live->get_data());
    assertCommercialPageSearch(
        ! in_array($commercialId, $liveIds, true),
        "El live search expuso la pagina comercial {$commercialId}."
    );

    $direct = commercialPageGet($permalink);
    assertCommercialPageSearch(
        $direct['status'] >= 200 && $direct['status'] < 400,
        "El acceso directo WooCommerce dejo de operar para {$commercialId}."
    );
}

$cartUrl = (new PublicRouteResolver())->cart();
$cartId = url_to_postid($cartUrl);
$cartTitle = get_the_title($cartId);
assertCommercialPageSearch($cartId > 0 && $cartTitle !== '', 'No existe carrito canonico VeciAhorra.');
$cartSearch = commercialPageGet(add_query_arg('s', $cartTitle, home_url('/')));
assertCommercialPageSearch(
    bodyLinksTo($cartSearch['body'], $cartUrl),
    'La busqueda tradicional retiro el carrito canonico VeciAhorra.'
);

$cartLiveRequest = new WP_REST_Request('GET', '/wp/v2/search');
$cartLiveRequest->set_param('type', 'post');
$cartLiveRequest->set_param('subtype', ['post', 'page', 'product']);
$cartLiveRequest->set_param('ct_live_search', 'true');
$cartLiveRequest->set_param('search', $cartTitle);
$cartLive = rest_do_request($cartLiveRequest);
$cartLiveIds = array_map(static fn (array $item): int => (int) $item['id'], $cartLive->get_data());
assertCommercialPageSearch(
    in_array($cartId, $cartLiveIds, true),
    'El live search retiro el carrito canonico VeciAhorra.'
);

$unmarked = new WP_REST_Request('GET', '/wp/v2/search');
$unmarked->set_param('type', 'post');
$unmarked->set_param('subtype', ['page']);
$unmarked->set_param('search', get_the_title($commercialIds[0]));
$unmarkedIds = array_map(
    static fn (array $item): int => (int) $item['id'],
    rest_do_request($unmarked)->get_data()
);
assertCommercialPageSearch(
    in_array($commercialIds[0], $unmarkedIds, true),
    'REST sin marcador fue alterado.'
);

$storeApi = rest_do_request(new WP_REST_Request('GET', '/wc/store/v1/products'));
assertCommercialPageSearch($storeApi->get_status() === 200, 'Store API fue alterada.');

$routes = new PublicRouteResolver();
foreach ([$routes->home(), $routes->catalog(), $routes->cart(), $routes->checkout(), $routes->customerPurchases()] as $url) {
    assertCommercialPageSearch($url !== '', 'Falta una ruta canonica VeciAhorra.');
    $response = commercialPageGet($url);
    assertCommercialPageSearch($response['status'] === 200, "Ruta canonica no disponible: {$url}");
    $id = url_to_postid($url);
    $search = commercialPageGet(add_query_arg('s', get_the_title($id), home_url('/')));
    assertCommercialPageSearch(
        $id > 0 && bodyLinksTo($search['body'], $url),
        "La busqueda retiro una pagina canonica VeciAhorra: {$url}"
    );
}

echo "PASS public-search-commercial-pages-test\n";
