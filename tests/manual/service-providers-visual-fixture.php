<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\ServiceProviders\Identity\ServiceProviderRole;
use VeciAhorra\Modules\ServiceProviders\Service\ServiceProviderService;

require_once dirname(__DIR__, 5) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

global $wpdb;
$mode = (string) ($argv[1] ?? '');
$table = $wpdb->prefix . Config::TABLE_PREFIX . 'service_providers';

if ($mode === 'cleanup') {
    $providerId = (int) ($argv[2] ?? 0);
    $userId = (int) ($argv[3] ?? 0);
    if ($providerId > 0) {
        $wpdb->delete($table, ['id' => $providerId]);
    }
    if ($userId > 0) {
        wp_delete_user($userId);
    }
    echo "CLEANUP=PASS\n";
    exit;
}

$suffix = bin2hex(random_bytes(5));
$userId = wp_create_user('va_visual_jose_' . $suffix, wp_generate_password(24), 'jose.' . $suffix . '@example.test');
if (! is_int($userId)) {
    throw new RuntimeException('No fue posible crear la fixture visual.');
}

ServiceProviderRole::register();
$user = new WP_User($userId);
$user->add_role(ServiceProviderRole::ROLE);
$user->add_cap(ServiceProviderRole::CAPABILITY);
$service = new ServiceProviderService();
$profile = $service->save($userId, [
    'full_name' => 'José Martínez',
    'rut' => '12.345.678-9',
    'email' => 'jose.' . $suffix . '@example.test',
    'phone' => '+56912345678',
    'plan' => 'featured',
    'terms_accepted' => true,
    'photo_id' => 0,
    'business_name' => 'Gasfitería y reparaciones',
    'category_key' => 'veciarregla',
    'subcategory_key' => 'gasfiteria',
    'description' => 'Instalaciones, mantenciones y emergencias domiciliarias con atención cercana y profesional.',
    'commune' => 'San Miguel',
    'coverage' => ['La Cisterna', 'San Joaquín'],
    'specialties' => ['Urgencias', 'Grifería', 'Filtraciones'],
    'experience_years' => 8,
    'schedule' => 'Lunes a sábado, 08:00 a 19:00',
    'emergency_service' => true,
    'whatsapp' => '+56912345678',
    'contact_email' => 'contacto.' . $suffix . '@example.test',
]);
$providerId = (int) $profile['id'];
$service->submit($providerId);
$service->adminTransition($providerId, 'approved');
$service->adminTransition($providerId, 'published');
echo wp_json_encode(['provider_id' => $providerId, 'user_id' => $userId]) . "\n";
