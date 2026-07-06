<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Inventory\Admin;

use VeciAhorra\Core\Config;

/**
 * Registra y renderiza la lista administrativa de Inventory.
 */
final class InventoryPage
{
    private const PAGE_SLUG = 'veciahorra-inventory';

    private const PARENT_SLUG = 'veciahorra';

    private ?string $pageHook = null;

    public function register(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function registerMenu(): void
    {
        $pageHook = add_submenu_page(
            self::PARENT_SLUG,
            'Inventario',
            'Inventario',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render']
        );

        $this->pageHook = is_string($pageHook) ? $pageHook : null;
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        if ($this->pageHook === null || $hookSuffix !== $this->pageHook) {
            return;
        }

        wp_enqueue_style(
            'veciahorra-inventory-admin',
            VA_PLUGIN_URL . 'assets/admin/css/inventory.css',
            [],
            Config::PLUGIN_VERSION
        );

        wp_enqueue_script_module(
            'veciahorra-inventory-admin',
            VA_PLUGIN_URL . 'assets/admin/js/modules/inventory/app.js',
            [],
            Config::PLUGIN_VERSION
        );
    }

    public function render(): void
    {
        $config = [
            'restUrl' => esc_url_raw(rest_url('veciahorra/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'screenSlug' => self::PAGE_SLUG,
            'version' => Config::PLUGIN_VERSION,
            'textDomain' => Config::TEXT_DOMAIN,
        ];

        require dirname(__DIR__) . '/Views/index.php';
    }
}
