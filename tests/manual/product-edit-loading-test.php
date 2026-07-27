<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$api = file_get_contents($root . '/assets/admin/products/api.js');
$app = file_get_contents($root . '/assets/admin/products/app.js');
$view = file_get_contents($root . '/assets/admin/products/view.js');
$css = file_get_contents($root . '/assets/admin/products/products.css');
$html = file_get_contents(__DIR__ . '/product-edit-loading-test.html');

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$assert(str_contains($app, 'if (config.initialEditId !== null)')
    && str_contains($app, 'store.openEditForm(config.initialEditId)')
    && str_contains($app, 'store.loadQuery(config.initialQuery)'), 'Edit y listado no tienen ramas exclusivas.');
$assert(substr_count($app, 'store.openEditForm(config.initialEditId)') === 1, 'Edit se inicializa más de una vez.');
$assert(! preg_match('/function isProductDetail\\(product\\)\\s*\\{\\s*return isProduct\\(product\\)/', $api), 'Detalle reutiliza el DTO enriquecido del listado.');
$getProduct = strstr($api, 'async function getProduct(id)');
$getProduct = is_string($getProduct)
    ? strstr($getProduct, 'async function createProduct', true)
    : false;
$assert(is_string($getProduct)
    && str_contains($getProduct, 'data: normalizeProductDetail(response.payload.data)')
    && str_contains($api, 'id: Number(product.id)'), 'IDs editables no se normalizan en getProduct.');
$assert(str_contains($view, 'nodes.toolbar.hidden = true'), 'Formulario no oculta toolbar.');
$assert(str_contains($css, '.veciahorra-products-admin__toolbar[hidden]')
    && str_contains($css, 'display: none'), 'CSS puede anular hidden.');
$assert(str_contains($html, 'initial.length===4')
    && str_contains($html, 'detailCalls.length===1')
    && str_contains($html, 'listCalls.length===0')
    && str_contains($html, '.click()'), 'Harness no cubre routing, transporte y DOM.');

echo "PASS product-edit-loading-test\n";
