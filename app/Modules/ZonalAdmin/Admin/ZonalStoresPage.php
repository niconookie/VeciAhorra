<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\ZonalAdmin\Admin;

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\ZonalAdmin\Identity\ZonalAdminRole;

final class ZonalStoresPage
{
    public const PAGE_SLUG = 'veciahorra-zonal-stores';
    private ?string $pageHook = null;

    public function register(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function registerMenu(): void
    {
        $hook = add_submenu_page('veciahorra', 'Administración zonal', 'Minimarkets de mi zona', ZonalAdminRole::CAPABILITY_READ, self::PAGE_SLUG, [$this, 'render']);
        $this->pageHook = is_string($hook) ? $hook : null;
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        if ($this->pageHook === null || $hookSuffix !== $this->pageHook || ! $this->authorized()) return;
        wp_enqueue_style('veciahorra-zonal-stores-admin', VA_PLUGIN_URL . 'assets/admin/css/zonal-stores.css', [], Config::PLUGIN_VERSION);
        wp_enqueue_script('veciahorra-zonal-stores-admin', VA_PLUGIN_URL . 'assets/admin/js/modules/zonal-stores/app.js', [], Config::PLUGIN_VERSION, true);
        wp_add_inline_script('veciahorra-zonal-stores-admin', 'window.VeciAhorraZonalStores=' . wp_json_encode([
            'restUrl' => esc_url_raw(rest_url('veciahorra/v1/zonal/stores')),
            'nonce' => wp_create_nonce('wp_rest'),
        ]) . ';', 'before');
    }

    public function render(): void
    {
        if (! $this->authorized()) wp_die(esc_html__('No autorizado.', 'veciahorra'), '', ['response' => 403]);
        require dirname(__DIR__) . '/Views/zonal-stores.php';
    }

    private function authorized(): bool
    {
        return current_user_can('manage_options') || current_user_can(ZonalAdminRole::CAPABILITY_READ);
    }
}
