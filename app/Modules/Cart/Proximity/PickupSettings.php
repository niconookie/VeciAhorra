<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Cart\Proximity;

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Checkout\Repository\CheckoutRepository;

final class PickupSettings
{
    private const OPTION = 'veciahorra_google_maps_browser_key';

    public function browserKey(): string
    {
        $key = get_option(self::OPTION, '');
        return is_string($key) && preg_match('/^[A-Za-z0-9_-]{20,200}$/D', $key) === 1 ? $key : '';
    }

    public function saveKey(string $key, bool $remove): void
    {
        if (!current_user_can('manage_options')) throw new \DomainException('pickup_admin_required');
        if ($remove) { delete_option(self::OPTION); return; }
        if ($key === '') return;
        if (preg_match('/^[A-Za-z0-9_-]{20,200}$/D', $key) !== 1) throw new \InvalidArgumentException('La clave de navegador no tiene un formato válido.');
        update_option(self::OPTION, $key, false);
    }

    public function saveCoordinates(int $storeId, mixed $latitude, mixed $longitude, bool $clear): void
    {
        if (!current_user_can('manage_options')) throw new \DomainException('pickup_admin_required');
        $point = $clear ? null : GeoPoint::validate($latitude, $longitude);
        (new CheckoutRepository())->transaction(function () use ($storeId, $point): void {
            global $wpdb;
            $table = $wpdb->prefix . Config::TABLE_PREFIX . 'stores';
            if (!$wpdb->get_row($wpdb->prepare("SELECT id FROM {$table} WHERE id=%d FOR UPDATE", $storeId))) throw new \InvalidArgumentException('El minimarket no existe.');
            if ($wpdb->update($table, ['pickup_latitude' => $point['latitude'] ?? null, 'pickup_longitude' => $point['longitude'] ?? null], ['id' => $storeId]) === false) throw new \DomainException('pickup_coordinates_write_failed');
        });
    }

    public function page(): void
    {
        if (!current_user_can('manage_options')) wp_die('No autorizado.');
        $message = '';
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            check_admin_referer('va_pickup_settings');
            try {
                if (($_POST['operation'] ?? '') === 'key') $this->saveKey(is_string($_POST['browser_key'] ?? null) ? wp_unslash($_POST['browser_key']) : '', isset($_POST['remove_key']));
                else $this->saveCoordinates((int)($_POST['store_id'] ?? 0), $_POST['latitude'] ?? null, $_POST['longitude'] ?? null, isset($_POST['clear_point']));
                $message = 'Configuración guardada.';
            } catch (\Throwable) { $message = 'No se pudo guardar. Revisa los campos y sus rangos.'; }
        }
        global $wpdb;
        $p = $wpdb->prefix . Config::TABLE_PREFIX;
        $stores = $wpdb->get_results("SELECT s.id,s.business_name,s.pickup_latitude,s.pickup_longitude,GROUP_CONCAT(z.name ORDER BY z.id SEPARATOR ', ') AS zones FROM {$p}stores s LEFT JOIN {$p}store_service_zones sz ON sz.store_id=s.id LEFT JOIN {$p}service_zones z ON z.id=sz.zone_id GROUP BY s.id,s.business_name,s.pickup_latitude,s.pickup_longitude ORDER BY s.business_name,s.id", ARRAY_A);
        require __DIR__ . '/Views/settings.php';
    }
}
