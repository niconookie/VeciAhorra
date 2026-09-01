<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\CustomerAccess\CustomerAccessModule;
use VeciAhorra\Modules\ServiceProviders\Admin\ServiceProviderAdminPage;
use VeciAhorra\Modules\ServiceProviders\Domain\ServicePlanCatalog;
use VeciAhorra\Modules\ServiceProviders\Service\ServiceProviderService;
use VeciAhorra\Modules\ServiceProviders\ServiceProviderModule;

if (! defined('VECIAHORRA_PUBLIC_REGISTRATION_ENABLED')) {
    define('VECIAHORRA_PUBLIC_REGISTRATION_ENABLED', true);
}

require_once dirname(__DIR__, 5) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$service = new ServiceProviderService();
$repository = $service->repository();
$module = new ServiceProviderModule(new CustomerAccessModule());
$created = [];
$createdUser = 0;
$previousGet = $_GET;
$previousMethod = $_SERVER['REQUEST_METHOD'] ?? null;
$nonce = bin2hex(random_bytes(6));
$commune = 'Plan Comunal ' . $nonce;

$payload = static fn (string $plan): array => [
    'full_name' => 'Prestador ' . $plan,
    'rut' => '12.345.678-9',
    'email' => $plan . '-' . $nonce . '@example.test',
    'phone' => '+56911111111',
    'plan' => $plan,
    'terms_accepted' => true,
    'photo_id' => 0,
    'business_name' => 'Servicio ' . $plan,
    'category_key' => 'veciarregla',
    'subcategory_key' => 'gasfiteria',
    'description' => 'Servicio de prueba para el plan comunal.',
    'commune' => $commune,
    'coverage' => [],
    'specialties' => ['Instalaciones'],
    'experience_years' => 4,
    'schedule' => 'Lunes a viernes',
    'emergency_service' => false,
    'whatsapp' => '+56911111111',
    'contact_email' => 'contacto-' . $plan . '-' . $nonce . '@example.test',
];

$insert = static function (string $plan, string $publishedAt) use (
    $service,
    $repository,
    $payload,
    &$created
): int {
    $data = ServicePlanCatalog::canonical($plan) === null
        ? [...$service->validate($payload('local')), 'plan' => $plan]
        : $service->validate($payload($plan));
    $id = $repository->create([
        ...$data,
        'status' => 'published',
        'admin_observation' => null,
        'submitted_at' => $publishedAt,
        'approved_at' => $publishedAt,
        'published_at' => $publishedAt,
        'created_at' => $publishedAt,
        'updated_at' => $publishedAt,
    ]);
    $created[] = $id;
    return $id;
};

try {
    $assert(array_keys(ServicePlanCatalog::PLANS) === ['local', 'featured', 'communal'], 'Catalogo PHP no es cerrado o estable.');
    foreach (['local', 'featured', 'communal'] as $plan) {
        $assert($service->validate($payload($plan))['plan'] === $plan, "Backend rechazo {$plan}.");
    }
    foreach (['', 'premium', 'COMMUNAL', ' communal '] as $invalid) {
        try {
            $service->validate($payload($invalid));
            $assert(false, "Backend acepto plan invalido: {$invalid}");
        } catch (InvalidArgumentException) {
            $assert(true, "Backend rechazo plan invalido: {$invalid}");
        }
    }

    $createdUser = wp_create_user(
        'sp_communal_' . $nonce,
        wp_generate_password(24),
        'sp-user-' . $nonce . '@example.test'
    );
    $assert(is_int($createdUser), 'No se pudo crear usuario para persistencia por servicio.');
    $saved = $service->save($createdUser, $payload('communal'));
    $created[] = (int) $saved['id'];
    $assert($saved['plan'] === 'communal', 'ServiceProviderService::save no persistio communal.');

    wp_set_current_user(0);
    $_GET = [];
    $landing = $module->landing();
    preg_match_all('/data-va-choose-plan="([^"]+)"/', $landing, $landingPlans);
    sort($landingPlans[1]);
    $assert($landingPlans[1] === ['communal', 'featured', 'local'], 'Landing no contiene exactamente los tres planes.');
    foreach (['local', 'featured', 'communal'] as $plan) {
        $pattern = '/href="([^"]+)"[^>]*data-va-choose-plan="' . $plan . '"/';
        $assert(preg_match($pattern, $landing, $match) === 1, "Landing sin enlace para {$plan}.");
        $url = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $registrationQuery);
        $returnUrl = (string) ($registrationQuery['redirect_to'] ?? $url);
        parse_str((string) parse_url($returnUrl, PHP_URL_QUERY), $returnQuery);
        $assert(($returnQuery['plan'] ?? null) === $plan, "Landing no conserva preseleccion unica para {$plan}.");
    }

    $customers = get_users(['role' => 'customer', 'number' => 1]);
    $assert($customers !== [], 'Falta usuario cliente para renderizar panel.');
    wp_set_current_user((int) $customers[0]->ID);
    $_GET = ['plan' => 'communal'];
    $panel = $module->panel();
    preg_match_all('/name="plan" value="([^"]+)" required(?: checked)?/', $panel, $formPlans);
    sort($formPlans[1]);
    $assert($formPlans[1] === ['communal', 'featured', 'local'], 'Formulario no contiene exactamente tres radios requeridos.');
    $assert(str_contains($panel, 'value="communal" required checked'), 'Query communal no preselecciono Plan Comunal.');
    $assert(str_contains($panel, 'Plan Comunal') && str_contains($panel, '$3.000'), 'Formulario no muestra resumen comercial comunal.');
    $_GET = ['plan' => 'premium'];
    $invalidPanel = $module->panel();
    $assert(! preg_match('/name="plan"[^>]*checked/', $invalidPanel), 'Query invalida se convirtio en un plan valido.');

    $communalOld = $insert('communal', '2026-08-01 10:00:00');
    $communalTieLow = $insert('communal', '2026-08-02 10:00:00');
    $communalTieHigh = $insert('communal', '2026-08-02 10:00:00');
    $featured = $insert('featured', '2026-08-03 10:00:00');
    $local = $insert('local', '2026-08-04 10:00:00');
    $unknown = $insert('legacy', '2026-08-05 10:00:00');

    $stored = $repository->find($communalOld);
    $assert(($stored['plan'] ?? null) === 'communal', 'Persistencia no conservo communal.');
    $communalProjection = $service->public($stored);
    $featuredProjection = $service->public($repository->find($featured));
    $localProjection = $service->public($repository->find($local));
    $assert($communalProjection['plan'] === 'communal' && $communalProjection['featured'] === true, 'Proyeccion comunal incorrecta.');
    $assert($featuredProjection['plan'] === 'featured' && $featuredProjection['featured'] === true, 'Proyeccion destacada incorrecta.');
    $assert($localProjection['plan'] === 'local' && $localProjection['featured'] === false, 'Proyeccion local incorrecta.');

    $ordered = $repository->published(['commune' => $commune]);
    $orderedIds = array_map(static fn (array $row): int => (int) $row['id'], $ordered);
    $assert(
        $orderedIds === [$communalTieHigh, $communalTieLow, $communalOld, $featured, $local, $unknown],
        'Prioridad o desempates historicos del catalogo son incorrectos.'
    );

    $admins = get_users(['role' => 'administrator', 'number' => 1]);
    $assert($admins !== [], 'Falta administrador para probar filtro.');
    wp_set_current_user((int) $admins[0]->ID);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['plan' => 'communal'];
    ob_start();
    (new ServiceProviderAdminPage($service))->render();
    $admin = (string) ob_get_clean();
    $assert(str_contains($admin, '>Local<') && str_contains($admin, '>Destacado<') && str_contains($admin, '>Comunal<'), 'Admin no ofrece los tres filtros canonicos.');
    $assert(str_contains($admin, 'Plan Comunal') && ! str_contains($admin, '>communal<'), 'Admin no etiqueta o expone raw el plan comunal.');
    $assert(! str_contains($admin, 'Servicio featured') && ! str_contains($admin, 'Servicio local'), 'Filtro admin communal no fue efectivo.');
    $_GET = ['plan' => 'COMMUNAL'];
    ob_start();
    (new ServiceProviderAdminPage($service))->render();
    $invalidAdmin = (string) ob_get_clean();
    $assert(! str_contains($invalidAdmin, 'value="communal" selected'), 'Admin normalizo una query de plan no canonica.');
    $assert(str_contains($invalidAdmin, 'Servicio featured') && str_contains($invalidAdmin, 'Plan no reconocido'), 'Admin no ignoro query invalida o expuso plan desconocido raw.');

    echo "PASS service-provider-communal-plan-test assertions={$assertions}\n";
    echo "ORDER=communal,communal,communal,featured,local,legacy\n";
    echo "PAYMENTS=0 SUBSCRIPTIONS=0 EXTERNAL_CALLS=0\n";
} finally {
    global $wpdb;
    $table = $wpdb->prefix . Config::TABLE_PREFIX . 'service_providers';
    foreach (array_reverse($created) as $id) {
        $wpdb->delete($table, ['id' => $id]);
    }
    if (is_int($createdUser) && $createdUser > 0) {
        wp_delete_user($createdUser);
    }
    $_GET = $previousGet;
    if ($previousMethod === null) {
        unset($_SERVER['REQUEST_METHOD']);
    } else {
        $_SERVER['REQUEST_METHOD'] = $previousMethod;
    }
    wp_set_current_user(0);
}
