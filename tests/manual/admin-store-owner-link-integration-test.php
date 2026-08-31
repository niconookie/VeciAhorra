<?php

declare(strict_types=1);

const TEST_DATABASE = 'veciahorra_admin_store_owner_link_it_20260830';
const WORDPRESS_ROOT = 'C:/xampp/htdocs/Minimarket/';

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};

$config = (string) file_get_contents(WORDPRESS_ROOT . 'wp-config.php');
$constant = static function (string $name) use ($config): string {
    $pattern = '/define\s*\(\s*[\'\"]' . preg_quote($name, '/') . '[\'\"]\s*,\s*[\'\"]((?:\\\\.|[^\'\"])*)[\'\"]\s*\)\s*;/';
    if (preg_match($pattern, $config, $match) !== 1) throw new RuntimeException('missing_local_config_' . strtolower($name));
    return stripcslashes($match[1]);
};

$host = $constant('DB_HOST');
$assert(preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $host) === 1, 'La configuración no apunta a MySQL local.');
$hostParts = explode(':', $host, 2);
$adminDb = @new mysqli($hostParts[0], $constant('DB_USER'), $constant('DB_PASSWORD'), '', isset($hostParts[1]) ? (int) $hostParts[1] : 3306);
if ($adminDb->connect_errno !== 0) throw new RuntimeException('No fue posible conectar a MySQL local.');
$check = $adminDb->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME=?');
$databaseName = TEST_DATABASE;
$check->bind_param('s', $databaseName);
$check->execute();
$databaseExists = $check->get_result()->num_rows > 0;
$cleanupOnly = getenv('VA_OWNER_LINK_CLEANUP_ONLY') === '1';
if ($cleanupOnly) {
    $adminDb->query('DROP DATABASE IF EXISTS `' . TEST_DATABASE . '`');
    $verifyCleanup = $adminDb->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME=?');
    $verifyCleanup->bind_param('s', $databaseName);
    $verifyCleanup->execute();
    $removed = $verifyCleanup->get_result()->num_rows === 0;
    $adminDb->close();
    if (! $removed) throw new RuntimeException('No fue posible limpiar la base aislada.');
    echo 'PASS isolated-database-recovery-cleanup database=' . TEST_DATABASE . "\n";
    exit(0);
}
$assert(! $databaseExists, 'La base aislada ya existe; no se sobrescribirá.');
$assert($adminDb->query('CREATE DATABASE `' . TEST_DATABASE . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci') === true, 'No fue posible crear la base aislada.');

$cleaned = false;
$passed = false;
try {
    define('DB_NAME', TEST_DATABASE);
    foreach (['DB_USER','DB_PASSWORD','DB_HOST','DB_CHARSET','DB_COLLATE','AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT'] as $name) {
        if (! defined($name)) define($name, $constant($name));
    }
    define('WP_INSTALLING', true);
    define('WP_DEBUG', false);
    define('WP_DEBUG_DISPLAY', false);
    define('ABSPATH', WORDPRESS_ROOT);
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/';
    $table_prefix = 'wp_';
    require WORDPRESS_ROOT . 'wp-settings.php';
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_install('Owner Link Integration', 'integration_admin', 'integration-admin@example.test', false, '', 'A9!isolated-owner-link-password');
    update_option('blog_public', '0');

    $pluginRoot = dirname(__DIR__, 2) . '/';
    require_once $pluginRoot . 'vendor/autoload.php';
    define('VA_PLUGIN_PATH', $pluginRoot);
    define('VA_PLUGIN_URL', 'http://localhost/wp-content/plugins/veciahorra/');

    global $wpdb;
    $storesTable = $wpdb->prefix . \VeciAhorra\Core\Config::TABLE_PREFIX . 'stores';
    $builder = \VeciAhorra\Database\Builder\TableBuilder::make($storesTable);
    (new \VeciAhorra\Database\Tables\StoresTable())->define($builder);
    dbDelta($builder->build($wpdb->get_charset_collate()));
    $applicationsTable = $wpdb->prefix . \VeciAhorra\Core\Config::TABLE_PREFIX . 'store_onboarding_applications';
    $wpdb->query("CREATE TABLE {$applicationsTable} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,user_id BIGINT UNSIGNED NULL,PRIMARY KEY(id)) ENGINE=InnoDB");
    (new \VeciAhorra\Modules\Minimarket\Identity\MinimarketRole())->register();
    (new \VeciAhorra\Modules\Minimarket\Identity\PendingMinimarketRole())->register();
    wp_set_current_user((int) get_user_by('login', 'integration_admin')->ID);

    $mailObservations = [];
    $mailResult = true;
    add_filter('pre_wp_mail', static function ($return, array $attributes) use (&$mailObservations, &$mailResult, $storesTable, $wpdb) {
        $user = get_user_by('email', (string) ($attributes['to'] ?? ''));
        $confirmed = false;
        if ($user instanceof WP_User) {
            $confirmed = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$storesTable} WHERE owner_user_id=%d", $user->ID)) === 1;
        }
        $mailObservations[] = ['confirmed'=>$confirmed, 'user_id'=>$user instanceof WP_User ? (int) $user->ID : 0];
        return $mailResult;
    }, 10, 2);

    $service = new \VeciAhorra\Modules\Stores\Services\AdminStoreOwnerProvisioningService();
    $payload = static fn(string $suffix, string $email, string $rut = ''): array => [
        'business_name'=>'Store ' . $suffix, 'legal_name'=>'Legal ' . $suffix, 'owner_name'=>'Owner ' . $suffix,
        'rut'=>$rut, 'email'=>$email, 'phone'=>'221234567', 'mobile'=>'', 'address'=>'Calle 1',
        'commune'=>'Santiago', 'city'=>'Santiago', 'region'=>'Metropolitana',
        'created_at'=>current_time('mysql'), 'updated_at'=>current_time('mysql'),
    ];
    $expectFailure = static function (callable $operation, string $label) use ($assert): void {
        try { $operation(); } catch (InvalidArgumentException) { return; }
        $assert(false, $label);
    };

    // 1, 2, 11: usuario nuevo, lifecycle y orden de invitación.
    $newMailStart = count($mailObservations);
    $first = $service->create($payload('Nuevo', 'owner-new@example.test', '12.345.678-5'));
    $firstRow = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$storesTable} WHERE id=%d", $first['store_id']), ARRAY_A);
    $assert($first['user_created'] === true && (int) $firstRow['owner_user_id'] === $first['user_id'], 'Usuario nuevo no quedó vinculado.');
    $assert($firstRow['status'] === 'pending' && $firstRow['onboarding_status'] === 'draft' && $firstRow['approved_at'] === null, 'Lifecycle inicial incorrecto.');
    $assert($mailObservations !== [] && end($mailObservations)['confirmed'] === true, 'La invitación ocurrió antes de confirmar Store+owner.');
    $assert(count($mailObservations) === $newMailStart + 1, 'Usuario nuevo no recibió exactamente una invitación.');

    // 3: usuario existente compatible.
    $compatibleId = wp_insert_user(['user_login'=>'compatible_owner', 'user_email'=>'compatible@example.test', 'user_pass'=>'A9!compatible-password', 'role'=>\VeciAhorra\Modules\Minimarket\Identity\MinimarketRole::ROLE]);
    $compatibleHash = (string) get_userdata((int) $compatibleId)->user_pass;
    $compatibleMailStart = count($mailObservations);
    $compatible = $service->create($payload('Compatible', 'compatible@example.test'));
    $assert($compatible['user_created'] === false && $compatible['user_id'] === $compatibleId, 'No se reutilizó el usuario compatible.');
    $assert($compatible['invitation_sent'] === false, 'El resultado afirmó invitación para usuario existente.');
    $assert(count($mailObservations) === $compatibleMailStart, 'Usuario existente recibió una invitación automática.');
    $assert(hash_equals($compatibleHash, (string) get_userdata((int) $compatibleId)->user_pass), 'Cambió el password hash del usuario existente.');

    // 4: usuario privilegiado.
    $expectFailure(fn() => $service->create($payload('Privilegiado', 'integration-admin@example.test')), 'Se aceptó usuario privilegiado.');

    // 5 y 7: correo vinculado y replay/doble submit.
    $replay = $service->create($payload('Nuevo', 'owner-new@example.test', '12.345.678-5'));
    $assert($replay['store_id'] === $first['store_id'], 'Doble submit creó otra Store.');
    $assert((int) $wpdb->get_var("SELECT COUNT(*) FROM {$storesTable} WHERE email='owner-new@example.test'") === 1, 'Doble submit duplicó Store.');
    $assert(count(get_users(['search'=>'owner-new@example.test', 'search_columns'=>['user_email']])) === 1, 'Doble submit duplicó usuario.');
    $expectFailure(fn() => $service->create($payload('Otro', 'owner-new@example.test')), 'Se reutilizó correo ya vinculado con otro payload.');

    // 6: RUT vinculado; el usuario candidato nuevo debe compensarse.
    $expectFailure(fn() => $service->create($payload('Rut duplicado', 'rut-duplicate@example.test', '12.345.678-5')), 'Se aceptó RUT ya vinculado.');
    $assert(email_exists('rut-duplicate@example.test') === false, 'Quedó usuario residual tras RUT duplicado.');

    // 8: fallo Store conserva usuario y retry reutiliza la cuenta sin invitar.
    $wpdb->hide_errors();
    $wpdb->query("CREATE TRIGGER va_owner_link_fail BEFORE INSERT ON {$storesTable} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated_store_failure'");
    $failureMailStart = count($mailObservations);
    $expectFailure(fn() => $service->create($payload('Falla Store', 'store-failure@example.test')), 'No se propagó fallo Store.');
    $preservedUserId = email_exists('store-failure@example.test');
    $assert(is_int($preservedUserId) && $preservedUserId > 0, 'No se conservó usuario nuevo tras fallo Store.');
    $assert(count($mailObservations) === $failureMailStart, 'Se invitó antes de confirmar Store+owner.');
    $wpdb->query('DROP TRIGGER va_owner_link_fail');
    $retry = $service->create($payload('Falla Store', 'store-failure@example.test'));
    $assert($retry['user_id'] === $preservedUserId && $retry['user_created'] === false, 'Retry no reutilizó el usuario conservado.');
    $assert(count($mailObservations) === $failureMailStart, 'Retry de cuenta conservada envió invitación automática.');
    $assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$storesTable} WHERE owner_user_id=%d", $preservedUserId)) === 1, 'Retry duplicó Store para el usuario conservado.');

    // 9: usuario preexistente jamás se elimina.
    $wpdb->query("CREATE TRIGGER va_owner_link_fail BEFORE INSERT ON {$storesTable} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated_store_failure'");
    $preexistingId = wp_insert_user(['user_login'=>'preexisting_owner', 'user_email'=>'preexisting@example.test', 'user_pass'=>'A9!preexisting-password', 'role'=>\VeciAhorra\Modules\Minimarket\Identity\MinimarketRole::ROLE]);
    $expectFailure(fn() => $service->create($payload('Preexistente', 'preexisting@example.test')), 'No se propagó fallo Store para usuario preexistente.');
    $assert(get_userdata((int) $preexistingId) instanceof WP_User, 'Se eliminó usuario preexistente.');
    $wpdb->query('DROP TRIGGER va_owner_link_fail');
    $wpdb->show_errors();

    // 10: fallo correo conserva Store y usuario.
    $mailResult = false;
    $mailFailure = $service->create($payload('Mail falla', 'mail-failure@example.test'));
    $assert($mailFailure['invitation_sent'] === false, 'No se informó fallo de correo.');
    $assert(get_userdata($mailFailure['user_id']) instanceof WP_User && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$storesTable} WHERE id=%d AND owner_user_id=%d", $mailFailure['store_id'], $mailFailure['user_id'])) === 1, 'Fallo correo deshizo Store o usuario.');

    // 12 y 13: reenvío y guards administrativos reales.
    $mailResult = true;
    $beforeResend = count($mailObservations);
    $assert($service->resendInvitation($mailFailure['store_id']) === true && count($mailObservations) === $beforeResend + 1, 'Reenvío autorizado falló.');
    $nonce = wp_create_nonce('veciahorra_store_invitation_' . $mailFailure['store_id']);
    $assert(current_user_can('manage_options') && wp_verify_nonce($nonce, 'veciahorra_store_invitation_' . $mailFailure['store_id']) !== false, 'Guard autorizado no validó.');
    $subscriberId = wp_insert_user(['user_login'=>'integration_subscriber', 'user_email'=>'subscriber@example.test', 'user_pass'=>'A9!subscriber-password', 'role'=>'subscriber']);
    wp_set_current_user((int) $subscriberId);
    $assert(! current_user_can('manage_options'), 'Usuario sin autorización superó manage_options.');
    $assert(wp_verify_nonce('invalid', 'veciahorra_store_invitation_' . $mailFailure['store_id']) === false, 'Nonce inválido fue aceptado.');
    wp_set_current_user((int) get_user_by('login', 'integration_admin')->ID);

    // Payload administrativo cerrado y nonce real.
    $basePost = [
        'action'=>'veciahorra_store_create', '_wpnonce'=>wp_create_nonce('veciahorra_store'),
        '_wp_http_referer'=>'/wp-admin/admin.php?page=veciahorra-store-create',
        'business_name'=>'Payload', 'legal_name'=>'Payload Legal', 'rut'=>'', 'owner_name'=>'Payload Owner',
        'email'=>'payload@example.test', 'phone'=>'221111111', 'mobile'=>'', 'address'=>'Calle 2',
        'commune'=>'Santiago', 'city'=>'Santiago', 'region'=>'Metropolitana', 'submit'=>'Guardar Minimarket',
    ];
    $_POST = $basePost; $_REQUEST = $_POST;
    $assert((new \VeciAhorra\Modules\Stores\Requests\StoreRequest())->validatedForCreate()['email'] === 'payload@example.test', 'Payload exacto fue rechazado.');
    $_POST = $basePost + ['unexpected'=>'x']; $_REQUEST = $_POST;
    $expectFailure(fn() => (new \VeciAhorra\Modules\Stores\Requests\StoreRequest())->validatedForCreate(), 'Campo adicional fue aceptado.');
    $_POST = $basePost; $_POST['email'] = ['payload@example.test']; $_REQUEST = $_POST;
    $expectFailure(fn() => (new \VeciAhorra\Modules\Stores\Requests\StoreRequest())->validatedForCreate(), 'Array injection fue aceptado.');
    $_POST = $basePost; $_POST['action'] = 'veciahorra_store_update'; $_REQUEST = $_POST;
    $expectFailure(fn() => (new \VeciAhorra\Modules\Stores\Requests\StoreRequest())->validatedForCreate(), 'Action distinta fue aceptada.');
    $_POST = $basePost; $_POST['_wpnonce'] = 'invalid'; $_REQUEST = $_POST;
    $assert(wp_verify_nonce((string) $_POST['_wpnonce'], 'veciahorra_store') === false, 'Nonce inválido fue aceptado.');
    $_POST = []; $_REQUEST = [];

    // 14: owner único y proyección coherente.
    $ownership = new \VeciAhorra\Modules\Minimarket\Ownership\StoreOwnershipRepository();
    $assert($ownership->resolveStoreIdForOwnerUser($first['user_id']) === $first['store_id'], 'Owner autoritativo no es único.');
    $assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$storesTable} WHERE owner_user_id=%d", $first['user_id'])) === 1, 'Más de una Store referencia al owner.');

    // 15: listado, detalle y edición.
    $storeService = new \VeciAhorra\Modules\Stores\Services\StoreService();
    $listed = $storeService->paginateAdmin(1, 50, null, null, null, 'business_name', 'ASC');
    $assert(count($listed) >= 3, 'Regresión de listado administrativo.');
    $controller = new \VeciAhorra\Modules\Stores\Controllers\StoreAdminReadController(
        $storeService,
        new \VeciAhorra\Modules\Stores\Services\StoreTransitionService(new \VeciAhorra\Modules\Stores\Repositories\StoreRepository()),
        new \VeciAhorra\Modules\Stores\Domain\StoreLifecycleContract()
    );
    $detail = $controller->show($first['store_id']);
    $assert(($detail['success'] ?? false) === true && ($detail['data']['account_linked'] ?? false) === true, 'Regresión de detalle administrativo.');
    $storeService->update($first['store_id'], ['phone'=>'229999999', 'updated_at'=>current_time('mysql')]);
    $assert((string) $storeService->find($first['store_id'])->phone === '229999999', 'Regresión de edición comercial.');

    $passed = true;
} finally {
    if (isset($adminDb) && $adminDb instanceof mysqli) {
        $adminDb->query('DROP DATABASE IF EXISTS `' . TEST_DATABASE . '`');
        $verify = $adminDb->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME=?');
        $verify->bind_param('s', $databaseName);
        $verify->execute();
        $cleaned = $verify->get_result()->num_rows === 0;
        $adminDb->close();
    }
    if (! $cleaned) fwrite(STDERR, "CLEANUP_FAILED\n");
}
$assert($passed && $cleaned, 'La ejecución o el cleanup de la base aislada falló.');
echo 'PASS admin-store-owner-link-integration-test database=' . TEST_DATABASE . " cleanup=PASS\n";
