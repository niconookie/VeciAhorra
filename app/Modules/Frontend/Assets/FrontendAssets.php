<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Frontend\Assets;

use VeciAhorra\Modules\Checkout\Service\FulfillmentPolicy;

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Frontend\Support\CartSession;

/**
 * Registers and enqueues assets only for a rendered VeciAhorra mount point.
 */
final class FrontendAssets
{
    public const STYLE_HANDLE = 'veciahorra-frontend';
    public const SCRIPT_HANDLE = 'veciahorra-frontend';
    public const OFFER_SCRIPT_HANDLE = 'veciahorra-product-offers';
    public const CATALOG_SCRIPT_HANDLE = 'veciahorra-catalog';
    public const CART_SCRIPT_HANDLE = 'veciahorra-cart';
    public const CHECKOUT_SCRIPT_HANDLE = 'veciahorra-checkout';
    public const CUSTOMER_PANEL_STYLE_HANDLE = 'veciahorra-customer-panel';
    public const CUSTOMER_PANEL_SCRIPT_HANDLE = 'veciahorra-customer-panel';
    public const REST_NAMESPACE = 'veciahorra/v1';

    private bool $registered = false;
    private bool $enqueued = false;
    private bool $customerPanelConfigured = false;

    public function __construct(private ?CartSession $cartSession = null)
    {
    }

    public function registerAssets(): void
    {
        if ($this->registered || is_admin()) {
            return;
        }

        $this->registered = true;
        $baseUrl = VA_PLUGIN_URL . 'assets/frontend/';

        wp_register_style(
            self::STYLE_HANDLE,
            $baseUrl . 'css/veciahorra-frontend.css',
            [],
            Config::PLUGIN_VERSION
        );
        wp_register_script(
            self::SCRIPT_HANDLE,
            $baseUrl . 'js/veciahorra-frontend.js',
            [],
            Config::PLUGIN_VERSION,
            true
        );
        wp_register_script(
            self::CATALOG_SCRIPT_HANDLE,
            $baseUrl . 'js/veciahorra-catalog.js',
            [self::SCRIPT_HANDLE],
            Config::PLUGIN_VERSION,
            true
        );
        wp_register_script(
            self::OFFER_SCRIPT_HANDLE,
            $baseUrl . 'js/veciahorra-product-offers.js',
            [self::SCRIPT_HANDLE],
            Config::PLUGIN_VERSION,
            true
        );
        wp_register_script(
            self::CART_SCRIPT_HANDLE,
            $baseUrl . 'js/veciahorra-cart.js',
            [self::SCRIPT_HANDLE],
            Config::PLUGIN_VERSION,
            true
        );
        wp_register_script(
            self::CHECKOUT_SCRIPT_HANDLE,
            $baseUrl . 'js/veciahorra-checkout.js',
            [self::SCRIPT_HANDLE],
            Config::PLUGIN_VERSION,
            true
        );
        wp_register_style(
            self::CUSTOMER_PANEL_STYLE_HANDLE,
            $baseUrl . 'css/customer-panel.css',
            [self::STYLE_HANDLE],
            Config::PLUGIN_VERSION
        );
        wp_register_script(
            self::CUSTOMER_PANEL_SCRIPT_HANDLE,
            $baseUrl . 'js/customer-panel.js',
            [self::SCRIPT_HANDLE],
            Config::PLUGIN_VERSION,
            true
        );
    }

    public function enqueueProductOffers(): void
    {
        if (is_admin()) {
            return;
        }

        $this->enqueue();
        wp_enqueue_script(self::OFFER_SCRIPT_HANDLE);
    }

    public function enqueueCatalog(): void
    {
        if (is_admin()) {
            return;
        }

        $this->enqueue();
        wp_enqueue_script(self::CATALOG_SCRIPT_HANDLE);
    }

    public function enqueueCart(): void
    {
        if (is_admin()) {
            return;
        }

        $this->enqueue();
        wp_enqueue_script(self::CART_SCRIPT_HANDLE);
    }

    public function enqueueCheckout(): void
    {
        if (is_admin()) {
            return;
        }

        $this->enqueue();
        wp_enqueue_script(self::CHECKOUT_SCRIPT_HANDLE);
    }

    public function enqueueCustomerPanel(): void
    {
        if (is_admin()) {
            return;
        }

        $this->registerAssets();
        wp_enqueue_style(self::STYLE_HANDLE);
        wp_enqueue_style(self::CUSTOMER_PANEL_STYLE_HANDLE);
        wp_enqueue_script(self::CUSTOMER_PANEL_SCRIPT_HANDLE);

        if ($this->customerPanelConfigured) {
            return;
        }

        $this->customerPanelConfigured = true;
        $configuration = wp_json_encode(
            ['enabled' => true],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        if (is_string($configuration)) {
            wp_add_inline_script(
                self::CUSTOMER_PANEL_SCRIPT_HANDLE,
                'window.VeciAhorra = window.VeciAhorra || {};'
                    . 'window.VeciAhorra.customerPanel = Object.assign('
                    . 'window.VeciAhorra.customerPanel || {}, '
                    . $configuration . ');',
                'before'
            );
        }
    }

    public function enqueue(): void
    {
        if ($this->enqueued || is_admin()) {
            return;
        }

        $this->registerAssets();
        $this->enqueued = true;

        wp_enqueue_style(self::STYLE_HANDLE);
        wp_enqueue_script(self::SCRIPT_HANDLE);
        $configuration = wp_json_encode(
            $this->configuration(),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        if (is_string($configuration)) {
            wp_add_inline_script(
                self::SCRIPT_HANDLE,
                'window.VeciAhorra = Object.assign(window.VeciAhorra || {}, ' . $configuration . ');',
                'before'
            );
        }
    }

    /** @return array<string, mixed> */
    public function configuration(): array
    {
        $userId = get_current_user_id();
        $minimumDeliveryAmount = (new FulfillmentPolicy())->minimumDeliveryAmount();

        return [
            'restUrl' => esc_url_raw(rest_url(self::REST_NAMESPACE . '/')),
            'restNamespace' => self::REST_NAMESPACE,
            'nonce' => $userId > 0 ? wp_create_nonce('wp_rest') : '',
            'currentUser' => [
                'id' => $userId > 0 ? $userId : 0,
                'loggedIn' => $userId > 0,
            ],
            'locale' => str_replace('_', '-', sanitize_text_field(determine_locale())),
            'currency' => 'CLP',
            'pages' => [
                'cart' => esc_url_raw((string) apply_filters(
                    'veciahorra_frontend_cart_url',
                    home_url('/carrito-veciahorra/')
                )),
                'checkout' => esc_url_raw($this->checkoutUrl()),
                'orders' => esc_url_raw(home_url('/mis-pedidos/')),
            ],
            'checkout' => [
                'minimumDeliveryAmount' => max(0, $minimumDeliveryAmount),
            ],
            'cart' => [
                'sessionHeader' => 'X-Veciahorra-Cart-Session',
                'sessionId' => $userId > 0
                    ? ''
                    : ($this->cartSession ?? new CartSession())->identifier(),
            ],
        ];
    }

    public function checkoutUrl(): string
    {
        $page = get_page_by_path('checkout');
        $default = $page instanceof \WP_Post
            && has_shortcode($page->post_content, 'veciahorra_checkout')
            ? (string) get_permalink($page)
            : '';
        $url = apply_filters('veciahorra_frontend_checkout_url', $default);

        return is_string($url) ? $url : '';
    }

}
