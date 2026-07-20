<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Products\Admin;

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Inventory\Admin\InventoryPage;

/**
 * Registra y renderiza la pantalla administrativa de Products.
 */
final class ProductsPage
{
    private const PAGE_SLUG = 'veciahorra-products';

    private const PARENT_SLUG = 'veciahorra';

    private ?string $pageHook = null;

    /**
     * Registra los hooks administrativos de la pantalla.
     */
    public function register(): void
    {
        add_action(
            'admin_menu',
            [$this, 'registerMenu']
        );

        add_action(
            'admin_enqueue_scripts',
            [$this, 'enqueueAssets']
        );
    }

    /**
     * Registra el submenú de Productos.
     */
    public function registerMenu(): void
    {
        $pageHook = add_submenu_page(
            self::PARENT_SLUG,
            'Productos',
            'Productos',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render']
        );

        $this->pageHook = is_string($pageHook)
            ? $pageHook
            : null;
    }

    /**
     * Carga los assets exclusivamente en la pantalla de Productos.
     */
    public function enqueueAssets(string $hookSuffix): void
    {
        if (
            $this->pageHook === null
            || $hookSuffix !== $this->pageHook
        ) {
            return;
        }

        wp_enqueue_media();

        $baseUrl = VA_PLUGIN_URL . 'assets/admin/products/';

        wp_enqueue_style(
            'veciahorra-products-admin',
            $baseUrl . 'products.css',
            [],
            Config::PLUGIN_VERSION
        );

        wp_enqueue_script_module(
            'veciahorra-products-admin',
            $baseUrl . 'app.js',
            [],
            Config::PLUGIN_VERSION
        );
    }

    /**
     * Renderiza el shell inicial de la aplicación Products.
     */
    public function render(): void
    {
        $config = [
            'restUrl' => esc_url_raw(
                rest_url('veciahorra/v1')
            ),
            'nonce' => wp_create_nonce('wp_rest'),
            'screenSlug' => self::PAGE_SLUG,
            'inventoryUrl' => esc_url_raw(add_query_arg(
                ['page' => InventoryPage::PAGE_SLUG],
                admin_url('admin.php')
            )),
            'version' => Config::PLUGIN_VERSION,
            'textDomain' => Config::TEXT_DOMAIN,
        ];

        require dirname(__DIR__) . '/Views/index.php';
    }
}
