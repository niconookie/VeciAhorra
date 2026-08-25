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
    public const PAGE_SLUG = 'veciahorra-products';

    private const PARENT_SLUG = 'veciahorra';

    private ?string $pageHook = null;

    /**
     * Registra los hooks administrativos de la pantalla.
     */
    public function register(): void
    {
        (new ProductBulkImportPage())->register();
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

        $baseUrl = VA_PLUGIN_URL . 'assets/admin/products/';
        $screen = $this->screen();
        if ($screen !== 'detail') {
            wp_enqueue_media();
        }

        wp_enqueue_style(
            'veciahorra-products-admin',
            $baseUrl . 'products.css',
            [],
            Config::PLUGIN_VERSION
        );

        wp_enqueue_script_module(
            'veciahorra-products-admin',
            $baseUrl . ($screen === 'detail' ? 'detail-app.js' : 'app.js'),
            [],
            Config::PLUGIN_VERSION
        );
    }

    /**
     * Renderiza el shell inicial de la aplicación Products.
     */
    public function render(): void
    {
        $screen = $this->screen();
        $productId = $this->productId();
        $listUrl = esc_url_raw(add_query_arg(
            ['page' => self::PAGE_SLUG],
            admin_url('admin.php')
        ));
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
            'productsUrl' => $listUrl,
            'screen' => $screen,
            'productId' => $productId,
            'listUrl' => $this->listReturnUrl($listUrl),
            'editUrl' => $productId === null ? null : esc_url_raw(add_query_arg(
                [
                    'page' => self::PAGE_SLUG,
                    'action' => 'edit',
                    'product_id' => $productId,
                ],
                admin_url('admin.php')
            )),
            'version' => Config::PLUGIN_VERSION,
            'textDomain' => Config::TEXT_DOMAIN,
        ];

        require dirname(__DIR__) . '/Views/'
            . ($screen === 'detail' ? 'detail.php' : 'index.php');
    }

    private function screen(): string
    {
        $action = isset($_GET['action'])
            ? sanitize_key(wp_unslash((string) $_GET['action']))
            : '';

        if ($this->productId() !== null && $action === 'view') {
            return 'detail';
        }

        return $this->productId() !== null && $action === 'edit'
            ? 'edit'
            : 'list';
    }

    private function productId(): ?int
    {
        $raw = isset($_GET['product_id'])
            ? wp_unslash((string) $_GET['product_id'])
            : '';

        return preg_match('/^[1-9]\d*$/', $raw) === 1
            ? (int) $raw
            : null;
    }

    private function listReturnUrl(string $base): string
    {
        $allowed = [];
        $value = fn (string $key): string => isset($_GET[$key])
            && ! is_array($_GET[$key])
                ? sanitize_text_field(wp_unslash((string) $_GET[$key]))
                : '';
        $term = $value('term');
        if ($term !== '') $allowed['term'] = $term;
        $status = $value('status');
        if (in_array($status, ['draft', 'active', 'inactive'], true)) {
            $allowed['status'] = $status;
        }
        foreach (['category_id', 'brand_id', 'paged'] as $key) {
            $id = $value($key);
            if (preg_match('/^[1-9]\d*$/', $id) === 1) {
                $allowed[$key] = $id;
            }
        }
        $orderBy = $value('order_by');
        if (in_array($orderBy, [
            'id', 'name', 'sku', 'created_at', 'updated_at',
        ], true)) {
            $allowed['order_by'] = $orderBy;
        }
        $direction = strtoupper($value('direction'));
        if (in_array($direction, ['ASC', 'DESC'], true)) {
            $allowed['direction'] = $direction;
        }

        return esc_url_raw(add_query_arg($allowed, $base));
    }
}
