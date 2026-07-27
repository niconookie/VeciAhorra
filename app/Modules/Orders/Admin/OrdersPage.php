<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Admin;

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Orders\Requests\OrderAdminPageRequest;

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
        $request = OrderAdminPageRequest::fromGlobals();
        if ($request->isValidDetail()) {
            wp_enqueue_script_module(
                'veciahorra-orders-detail-app',
                VA_PLUGIN_URL . 'assets/admin/js/modules/orders/detail-app.js',
                [],
                Config::PLUGIN_VERSION
            );
            return;
        }
        if (! $request->isList()) {
            return;
        }
        wp_enqueue_script_module(
            'veciahorra-orders-admin',
            VA_PLUGIN_URL . 'assets/admin/js/modules/orders/app.js',
            [],
            Config::PLUGIN_VERSION
        );
    }

    public function render(): void
    {
        $request = OrderAdminPageRequest::fromGlobals();
        if ($request->isValidDetail()) {
            $returnUrl = $request->returnUrl();
            $detailConfig = [
                'enabled' => true,
                'orderId' => $request->orderId(),
                'restUrl' => esc_url_raw(rest_url('veciahorra/v1/orders')),
                'nonce' => wp_create_nonce('wp_rest'),
            ];
            require dirname(__DIR__) . '/Views/admin-detail.php';
            return;
        }
        if (! $request->isList()) {
            $returnUrl = $request->returnUrl();
            $errorMessage = $request->screen() === OrderAdminPageRequest::SCREEN_INVALID_DETAIL
                ? 'La Order solicitada no es válida.'
                : 'La acción administrativa solicitada no es válida.';
            require dirname(__DIR__) . '/Views/admin-route-error.php';
            return;
        }

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
