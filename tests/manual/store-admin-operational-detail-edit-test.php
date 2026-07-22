<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Modules/Stores/Exceptions/StoreValidationException.php';

function detailEditAssert(bool $condition, string $message): void
{
    if (! $condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__, 2);
$app = file_get_contents($root . '/assets/admin/js/modules/stores/detail-app.js');
$api = file_get_contents($root . '/assets/admin/js/modules/stores/detail-api.js');
$edit = file_get_contents($root . '/assets/admin/js/modules/stores/detail-edit.js');
$view = file_get_contents($root . '/assets/admin/js/modules/stores/detail-view.js');
$css = file_get_contents($root . '/assets/admin/css/stores-detail.css');
$controller = file_get_contents($root . '/app/Modules/Stores/Controllers/StoresController.php');
$request = file_get_contents($root . '/app/Modules/Stores/Requests/StoreRequest.php');
$service = file_get_contents($root . '/app/Modules/Stores/Services/StoreService.php');

$validation = new VeciAhorra\Modules\Stores\Exceptions\StoreValidationException(['email' => 'invalid_email']);
detailEditAssert($validation->errors() === ['email' => 'invalid_email'], 'La excepción no encapsula el mapa válido.');
detailEditAssert($validation->getMessage() === 'El correo electrónico no es válido.', 'La excepción rompió el mensaje heredado.');
try {
    new VeciAhorra\Modules\Stores\Exceptions\StoreValidationException(['status' => 'required']);
    throw new RuntimeException('La excepción aceptó un campo de autoridad.');
} catch (LogicException $exception) {
    detailEditAssert(! str_contains($exception->getMessage(), 'status'), 'La excepción filtró datos internos.');
}

$fields = ['business_name', 'legal_name', 'owner_name', 'rut', 'email', 'phone', 'mobile', 'address', 'commune', 'city', 'region'];
foreach ($fields as $field) {
    detailEditAssert(str_contains($edit, "name: '{$field}'"), "Falta campo editable {$field}.");
    detailEditAssert(str_contains($request, "'{$field}' =>"), "StoreRequest no acepta {$field}.");
}
foreach (['status', 'onboarding_status', 'approved_at', 'lifecycle_state', 'allowed_actions', 'created_at', 'updated_at'] as $authority) {
    detailEditAssert(! str_contains($edit, "name: '{$authority}'"), "El formulario permite editar {$authority}.");
}
detailEditAssert(str_contains($app, "allowed_actions.includes('save')"), 'Save no es la autoridad exclusiva de edición.');
detailEditAssert(str_contains($view, "edit.type = 'button'") && str_contains($view, 'Editar información'), 'Falta botón real de edición.');
detailEditAssert(str_contains($view, "cancel.type = 'button'") && str_contains($view, 'Guardar cambios'), 'Acciones del formulario incorrectas.');
foreach (['createElement', 'textContent', 'replaceChildren', '.value = snapshot', 'aria-describedby', 'aria-invalid', 'aria-busy'] as $safe) {
    detailEditAssert(str_contains($view . $app, $safe), "Edición segura/accesible sin {$safe}.");
}
foreach (['innerHTML', 'insertAdjacentHTML', 'localStorage', 'sessionStorage', 'indexedDB', 'console.'] as $forbidden) {
    detailEditAssert(! str_contains($app . $api . $edit . $view, $forbidden), "Patrón prohibido: {$forbidden}.");
}
foreach (["method: 'POST'", "credentials: 'same-origin'", "Accept: 'application/json'", "'X-Veciahorra-Store-Detail': 'commercial-update'", "body.set('action', 'veciahorra_store_update')", "body.set('id', String(config.storeId))", "body.set('_wpnonce', config.updateNonce)"] as $transport) {
    detailEditAssert(str_contains($api, $transport), "Transporte heredado sin {$transport}.");
}
detailEditAssert(substr_count($api, 'fetch(') === 1, 'El transporte debe mantener un solo punto fetch compartido.');
detailEditAssert(str_contains($app, 'mode !== \'editing\'') && str_contains($app, "mode = 'saving'"), 'No se bloquea el doble submit.');
detailEditAssert(str_contains($app, 'saveSequence') && str_contains($app, 'saveController') && str_contains($app, 'baseDto'), 'Guardado sin concurrencia separada.');
detailEditAssert(str_contains($app, 'api.update(payload') && str_contains($app, 'api.get(readController.signal)'), 'El flujo no realiza mutación y GET autoritativo.');
detailEditAssert(str_contains($app, 'safeFieldErrors') && str_contains($view, 'editErrors('), 'Errores por campo no están controlados.');
detailEditAssert(str_contains($app, 'persisted = true') && str_contains($app, 'view.persistedRefreshError'), 'Falta distinguir POST exitoso y GET fallido.');
detailEditAssert(str_contains($controller, "wp_verify_nonce(\$nonce, 'veciahorra_store')") && str_contains($controller, "wp_send_json_success(['updated' => true]"), 'Endpoint heredado sin respuesta JSON segura.');
detailEditAssert(str_contains($controller, 'HTTP_X_VECIAHORRA_STORE_DETAIL') && str_contains($controller, "=== 'commercial-update'"), 'Negociación JSON no usa una señal cerrada.');
detailEditAssert(str_contains($controller, "current_user_can('manage_options')") && str_contains($controller, 'StoreValidationException'), 'Guardado sin permiso o errores estructurados.');
detailEditAssert(str_contains($service, "unset(\$data['status'], \$data['onboarding_status'], \$data['approved_at'])"), 'StoreService no preserva autoridades lifecycle.');
detailEditAssert(! str_contains($edit, '/transitions') && ! str_contains($edit, "method: 'DELETE'"), 'El módulo comercial incorporó lifecycle o eliminación.');
detailEditAssert(str_contains($css, '.va-store-detail__form-grid') && str_contains($css, '@media (max-width: 782px)') && ! str_contains($css, '!important'), 'CSS de edición fuera de alcance.');

echo "PASS store-admin-operational-detail-edit-test\n";
