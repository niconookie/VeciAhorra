<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Admin;

use VeciAhorra\Core\Config;

final class OrdersPage
{
    public const PAGE_SLUG = 'veciahorra-orders';

    private ?string $pageHook = null;

    public function register(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function registerMenu(): void
    {
        $hook = add_submenu_page(
            'veciahorra',
            'Pedidos',
            'Pedidos',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render']
        );
        $this->pageHook = is_string($hook) ? $hook : null;
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        if ($this->pageHook === null || $hookSuffix !== $this->pageHook) {
            return;
        }
        wp_enqueue_style(
            'veciahorra-orders-admin',
            VA_PLUGIN_URL . 'assets/admin/css/orders.css',
            [],
            Config::PLUGIN_VERSION
        );
        wp_enqueue_script_module(
            'veciahorra-orders-admin',
            VA_PLUGIN_URL . 'assets/admin/js/modules/orders/app.js',
            [],
            Config::PLUGIN_VERSION
        );
    }

    public function render(): void
    {
        $config = [
            'restUrl' => esc_url_raw(rest_url('veciahorra/v1/orders/admin')),
            'nonce' => wp_create_nonce('wp_rest'),
            'adminUrl' => esc_url_raw(add_query_arg(
                ['page' => self::PAGE_SLUG],
                admin_url('admin.php')
            )),
        ];
        require dirname(__DIR__) . '/Views/admin-list.php';
    }
}
