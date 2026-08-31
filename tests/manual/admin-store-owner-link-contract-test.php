<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};

$service = $read('app/Modules/Stores/Services/AdminStoreOwnerProvisioningService.php');
$controller = $read('app/Modules/Stores/Controllers/StoresController.php');
$menu = $read('app/Admin/Menu.php');
$detail = $read('app/Modules/Stores/Views/detail.php');
$table = $read('app/Database/Tables/StoresTable.php');
$request = $read('app/Modules/Stores/Requests/StoreRequest.php');

foreach ([
    "\$commercial['owner_user_id'] = \$userId",
    "\$row['status'] !== 'pending'",
    "\$row['onboarding_status'] !== 'draft'",
    "'approved_at'] !== null",
    'wp_generate_password(32, true, true)',
    "'role' => MinimarketRole::ROLE",
    'retrieve_password($user->user_login)',
    'resolveStoreIdForOwnerUser($userId)',
    'COUNT(*) FROM {$table} WHERE owner_user_id=%d',
    "user_can(\$user, 'manage_options')",
    "user_can(\$user, 'edit_others_posts')",
    'GET_LOCK(%s,1)',
] as $needle) {
    $assert(str_contains($service, $needle), 'Falta contrato de provisioning: ' . $needle);
}

$storePosition = strpos($service, '$this->assertConfirmed($storeId, $userId)');
$mailPosition = strpos($service, '$this->sendPasswordSetup($userId)');
$assert(is_int($storePosition) && is_int($mailPosition) && $storePosition < $mailPosition, 'La invitación debe ocurrir después de confirmar Store+owner.');
$assert(str_contains($table, "unique('owner_user_id', 'stores_owner_user_unique')"), 'Falta unicidad física del owner.');
$assert(str_contains($controller, "current_user_can('manage_options')"), 'El formulario no exige manage_options.');
$assert(str_contains($controller, 'check_admin_referer'), 'El reenvío no exige nonce.');
$assert(str_contains($menu, 'admin_post_veciahorra_store_resend_invitation'), 'El reenvío no está registrado.');
$assert(str_contains($detail, 'Cuenta vinculada:') && str_contains($detail, 'Reenviar invitaci'), 'El detalle no expone cuenta/reenvío.');
$assert(! preg_match('/user_login|password|reset[_ -]?key|hash/i', strip_tags($detail)), 'El detalle expone credenciales internas.');
$assert(! str_contains($service, 'wp_delete_user'), 'La política conservadora prohíbe eliminar usuarios.');
$assert(str_contains($service, '$sent = $userCreated && $this->sendPasswordSetup($userId);'), 'La invitación automática no está limitada al usuario nuevo.');
$assert(str_contains($service, 'suppress_errors(true)'), 'El fallo Store podría imprimir PII desde SQL.');
$assert(str_contains($controller, "['action', 'store_id', '_wpnonce', '_wp_http_referer', 'submit']"), 'El reenvío no tiene payload cerrado.');
foreach (["'action'", "'_wpnonce'", "'_wp_http_referer'", "'submit'", 'array_diff(array_keys($_POST), $allowed)', "!== 'veciahorra_store_create'"] as $needle) {
    $assert(str_contains($request, $needle), 'Falta contrato de payload cerrado: ' . $needle);
}

echo "PASS admin-store-owner-link-contract-test\n";
