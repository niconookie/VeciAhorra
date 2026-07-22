<?php

declare(strict_types=1);

function detailReadAssert(bool $condition, string $message): void
{
    if (! $condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__, 2);
$app = file_get_contents($root . '/assets/admin/js/modules/stores/detail-app.js');
$api = file_get_contents($root . '/assets/admin/js/modules/stores/detail-api.js');
$contract = file_get_contents($root . '/assets/admin/js/modules/stores/detail-contract.js');
$view = file_get_contents($root . '/assets/admin/js/modules/stores/detail-view.js');
$css = file_get_contents($root . '/assets/admin/css/stores-detail.css');
$controller = file_get_contents($root . '/app/Modules/Stores/Controllers/StoreAdminReadController.php');

detailReadAssert(substr_count($api, 'fetch(') === 1, 'La lectura debe tener un único punto fetch.');
foreach (["method: 'GET'", "credentials: 'same-origin'", "Accept: 'application/json'", "'X-WP-Nonce': config.nonce", 'config.detailUrl', 'signal'] as $fragment) {
    detailReadAssert(str_contains($api, $fragment), 'Cliente GET sin ' . $fragment . '.');
}
foreach (['PUT', 'PATCH', 'setInterval', 'setTimeout', 'localStorage', 'sessionStorage', 'console.log'] as $forbidden) {
    detailReadAssert(! str_contains($app . $api . $contract . $view, $forbidden), 'Lectura contiene patrón prohibido: ' . $forbidden);
}
foreach (['AbortController', 'readSequence', 'requestId !== readSequence', "addEventListener('pagehide'", 'readController?.abort()', 'rootNode.isConnected', "error?.name === 'AbortError'", 'vaStoreDetailInitialized', 'createStoreDetailCoordinator'] as $fragment) {
    detailReadAssert(str_contains($app, $fragment), 'Coordinación sin ' . $fragment . '.');
}
foreach (['validateDetailPayload', 'Number.isSafeInteger', 'item.id !== expectedId', 'isPlainObject', 'invalid_detail_actions', "item.lifecycle_state === 'invalid'", 'new Set(item.allowed_actions)', 'isMysqlDate'] as $fragment) {
    detailReadAssert(str_contains($contract, $fragment), 'Contrato DTO sin ' . $fragment . '.');
}
foreach (['draft', 'in_review', 'rejected', 'approved_inactive', 'active', 'invalid', 'save', 'submit_for_review', 'return_to_draft', 'approve', 'reject', 'activate', 'deactivate', 'delete_if_unreferenced'] as $value) {
    detailReadAssert(str_contains($contract, "'{$value}'") || str_contains($contract, "{$value}:"), 'Allowlist sin ' . $value . '.');
}
foreach (['document.createElement', 'textContent', 'append', 'replaceChildren', 'createDocumentFragment', 'Cargando minimarket', 'No hay acciones lifecycle disponibles', 'Estado operativo', 'Estado de incorporación'] as $fragment) {
    detailReadAssert(str_contains($view, $fragment), 'Render seguro sin ' . $fragment . '.');
}
foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'eval(', 'new Function', 'mailto:', 'tel:'] as $forbidden) {
    detailReadAssert(! str_contains($app . $api . $contract . $view, $forbidden), 'Render contiene sumidero/URL no permitida: ' . $forbidden);
}
detailReadAssert(str_contains($api, "headers?.get?.('content-type')") && str_contains($api, "includes('application/json')"), 'Cliente no valida Content-Type JSON.');
detailReadAssert(strpos($api, 'if (!response.ok)') < strpos($api, 'response.json()'), 'Errores HTTP dependen de parsear JSON.');
foreach ([' 0:', ' 400:', ' 401:', ' 403:', ' 404:', ' 409:', ' 422:'] as $status) {
    detailReadAssert(str_contains($view, $status), 'Manejo HTTP sin ' . trim($status, ' :') . '.');
}
detailReadAssert(str_contains($view, "role', 'alert") && str_contains($view, 'focus({ preventScroll: true })'), 'Error sin alerta o foco.');
detailReadAssert(str_contains($view, 'va-store-detail__invalid') && str_contains($view, 'No se realizó ninguna corrección'), 'Invalid no es conservador.');
detailReadAssert(str_contains($controller, 'STATE_INVALID') && str_contains($controller, '? []') && str_contains($controller, 'classify('), 'Read model invalid no entrega acciones vacías.');
detailReadAssert(! str_contains($controller, "'inventory'") && ! str_contains($controller, "'orders'"), 'DTO agregó relaciones.');
detailReadAssert(str_contains($css, '.va-store-detail__definitions') && str_contains($css, 'overflow-wrap: anywhere') && str_contains($css, '@media (max-width: 782px)'), 'CSS de lectura no cubre contenido/responsive.');
detailReadAssert(! str_contains($css, '!important') && preg_match('/^\s*height\s*:/m', $css) !== 1, 'CSS fuera de alcance.');

echo "PASS store-admin-operational-detail-read-test\n";
