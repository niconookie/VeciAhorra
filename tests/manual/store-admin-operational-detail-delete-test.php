<?php

declare(strict_types=1);

function detailDeleteAssert(bool $condition, string $message): void
{
    if (! $condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__, 2);
$app = file_get_contents($root . '/assets/admin/js/modules/stores/detail-app.js');
$api = file_get_contents($root . '/assets/admin/js/modules/stores/detail-api.js');
$module = file_get_contents($root . '/assets/admin/js/modules/stores/detail-delete.js');
$view = file_get_contents($root . '/assets/admin/js/modules/stores/detail-view.js');
$css = file_get_contents($root . '/assets/admin/css/stores-detail.css');

detailDeleteAssert(str_contains($module, "deleteAction = 'delete_if_unreferenced'"), 'Falta autoridad delete_if_unreferenced.');
detailDeleteAssert(str_contains($module, 'dto.allowed_actions.includes(deleteAction)'), 'Delete no deriva exclusivamente de allowed_actions.');
detailDeleteAssert(str_contains($module, 'value === businessName'), 'Confirmación no usa igualdad literal exacta.');
foreach (["method: 'DELETE'", 'config.detailUrl', "credentials: 'same-origin'", "Accept: 'application/json'", "'X-WP-Nonce': config.nonce", "'store_delete_failed', true"] as $transport) {
    detailDeleteAssert(str_contains($api, $transport), "DELETE sin {$transport}.");
}
detailDeleteAssert(substr_count($api, 'fetch(') === 1, 'Se perdió el punto fetch único.');
detailDeleteAssert(! preg_match('/deleteStore\(signal\).*?body:/s', $api), 'DELETE contiene body.');
detailDeleteAssert(! preg_match('/deleteStore\(signal\).*?Content-Type/s', $api), 'DELETE contiene Content-Type.');
foreach (['confirmDelete(item)', 'Eliminar minimarket', 'vaStoreDeleteName', 'aria-describedby', "role', 'region", 'vaStoreConfirmDelete', 'vaStoreCancelDelete', 'Eliminando minimarket'] as $fragment) {
    detailDeleteAssert(str_contains($view, $fragment), "Vista delete sin {$fragment}.");
}
foreach (['deleteSequence', 'deleteController', "mode = 'confirming_delete'", "mode = 'deleting'", "mode = 'uncertain'", "mode = 'navigating'", 'active = false', 'isCurrentDelete', 'deleteController?.abort()', 'navigate(config.returnUrl)', 'error?.status === 404'] as $fragment) {
    detailDeleteAssert(str_contains($app, $fragment), "Coordinación delete sin {$fragment}.");
}
detailDeleteAssert(str_contains($app, "code === 'store_referenced'") && str_contains($app, 'existen registros que lo referencian'), 'Referencias no se clasifican localmente.');
detailDeleteAssert(str_contains($app, "'concurrent_modification', 'action_not_allowed'") && str_contains($app, 'authoritativeDeleteGet'), 'Conflicto delete no recarga autoridad.');
detailDeleteAssert(str_contains($app, 'No fue posible confirmar el resultado de la eliminación'), 'Resultado incierto no retira controles.');
detailDeleteAssert(str_contains($app, "!['loading', 'reading', 'error'].includes(mode)"), 'Uncertain puede volver a reading mediante load programático.');
detailDeleteAssert(! str_contains($app . $api . $module . $view, 'StoreReferenceInspector') && ! str_contains($app . $api . $module . $view, '/inventory'), 'Frontend inspecciona referencias.');
foreach (['innerHTML', 'insertAdjacentHTML', 'window.confirm', 'localStorage', 'sessionStorage', 'indexedDB', 'console.'] as $forbidden) {
    detailDeleteAssert(! str_contains($app . $api . $module . $view, $forbidden), "Patrón prohibido: {$forbidden}.");
}
detailDeleteAssert(str_contains($css, '.va-store-detail__delete-confirmation') && str_contains($css, '.va-store-detail__delete-button') && str_contains($css, '@media (max-width: 782px)'), 'CSS delete incompleto.');
detailDeleteAssert(! str_contains($css, '!important'), 'CSS delete usa !important.');

echo "PASS store-admin-operational-detail-delete-test\n";
