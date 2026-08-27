<?php

declare(strict_types=1);

function detailLifecycleAssert(bool $condition, string $message): void
{
    if (! $condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__, 2);
$app = file_get_contents($root . '/assets/admin/js/modules/stores/detail-app.js');
$api = file_get_contents($root . '/assets/admin/js/modules/stores/detail-api.js');
$module = file_get_contents($root . '/assets/admin/js/modules/stores/detail-lifecycle.js');
$view = file_get_contents($root . '/assets/admin/js/modules/stores/detail-view.js');
$css = file_get_contents($root . '/assets/admin/css/stores-detail.css');
$controller = file_get_contents($root . '/app/Modules/Stores/Controllers/StoreAdminReadController.php');
$request = file_get_contents($root . '/app/Modules/Stores/Requests/StoreTransitionRequest.php');

$actions = ['submit_for_review', 'return_to_draft', 'approve', 'reject', 'observe', 'activate', 'deactivate'];
foreach ($actions as $action) {
    detailLifecycleAssert(str_contains($module, "{$action}: Object.freeze"), "Falta acción lifecycle {$action}.");
}
foreach (['ACTION_SUBMIT_FOR_REVIEW', 'ACTION_RETURN_TO_DRAFT', 'ACTION_APPROVE', 'ACTION_REJECT', 'ACTION_OBSERVE', 'ACTION_ACTIVATE', 'ACTION_DEACTIVATE'] as $constant) {
    detailLifecycleAssert(str_contains($controller, $constant) && str_contains($request, $constant), "Acción anunciada sin request/dispatcher: {$constant}.");
}
detailLifecycleAssert(str_contains($module, 'dto.allowed_actions.filter'), 'Las acciones no derivan de allowed_actions.');
detailLifecycleAssert(str_contains($module, 'observe') && str_contains($module, 'expected_updated_at') && str_contains($app, 'lifecycleReason'), 'Observe no posee UI, motivo o control de concurrencia.');
detailLifecycleAssert(! str_contains($module, "save:") && ! str_contains($module, 'delete_if_unreferenced'), 'Save o delete se trataron como lifecycle.');
foreach (["method: 'POST'", '`${config.detailUrl}/transitions`', "'X-WP-Nonce': config.nonce", "'Content-Type': 'application/json'", 'JSON.stringify(payload)'] as $transport) {
    detailLifecycleAssert(str_contains($api, $transport), "Transporte lifecycle sin {$transport}.");
}
detailLifecycleAssert(substr_count($api, 'fetch(') === 1, 'Se perdió el punto fetch único.');
foreach (['confirmLifecycle(action)', "role', 'region", 'Confirmar:', 'vaStoreConfirmLifecycle', 'vaStoreCancelLifecycle', 'Procesando acción lifecycle', 'aria-busy'] as $fragment) {
    detailLifecycleAssert(str_contains($view, $fragment), "Confirmación inline sin {$fragment}.");
}
foreach (['transitionSequence', 'transitionController', "mode = 'confirming'", "mode = 'transitioning'", 'isCurrentTransition', 'transitionController?.abort()', 'validateDetailPayload(result', 'authoritativeTransitionGet'] as $fragment) {
    detailLifecycleAssert(str_contains($app, $fragment), "Coordinación lifecycle sin {$fragment}.");
}
detailLifecycleAssert(str_contains($app, 'event.preventDefault()') && str_contains($app, "control.matches('[data-va-store-confirm-lifecycle]')"), 'Confirmar no usa delegación DOM segura.');
detailLifecycleAssert(str_contains($app, "mode !== 'reading'") && str_contains($app, '!dto.allowed_actions.includes(action)'), 'No se revalida modo o autoridad antes de confirmar.');
detailLifecycleAssert(str_contains($app, 'error?.status === 409') && str_contains($app, 'Se recargó la información vigente'), 'Conflicto CAS no recarga el DTO vigente.');
detailLifecycleAssert(str_contains($app, 'La acción fue procesada, pero no fue posible recargar'), 'POST exitoso + GET fallido no es honesto.');
detailLifecycleAssert(str_contains($app, 'isUncertainTransition') && str_contains($app, 'No fue posible confirmar el resultado'), 'Respuesta 2xx inválida o red incierta reactivan controles obsoletos.');
detailLifecycleAssert(str_contains($app, 'El estado cambió y no fue posible recargar'), 'Conflicto con GET fallido no queda en estado seguro.');
detailLifecycleAssert(str_contains($app, 'pendingAction = null') && str_contains($app, 'focusLifecycle'), 'Cancelación no restaura estado/foco.');
foreach (['innerHTML', 'insertAdjacentHTML', 'window.confirm', 'localStorage', 'sessionStorage', 'indexedDB', 'console.'] as $forbidden) {
    detailLifecycleAssert(! str_contains($app . $api . $module . $view, $forbidden), "Patrón prohibido: {$forbidden}.");
}
detailLifecycleAssert(! str_contains($module, "method: 'DELETE'"), 'El módulo lifecycle incorporó DELETE.');
detailLifecycleAssert(str_contains($css, '.va-store-detail__confirmation') && str_contains($css, '.va-store-detail__lifecycle-actions') && str_contains($css, '@media (max-width: 782px)'), 'CSS lifecycle no cubre confirmación/responsive.');
detailLifecycleAssert(! str_contains($css, '!important'), 'CSS lifecycle usa !important.');

echo "PASS store-admin-operational-detail-lifecycle-test\n";
