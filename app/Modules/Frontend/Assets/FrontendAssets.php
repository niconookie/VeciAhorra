<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Frontend\Assets;

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
    public const REST_NAMESPACE = 'veciahorra/v1';

    private bool $registered = false;
    private bool $enqueued = false;

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
            self::OFFER_SCRIPT_HANDLE,
            $baseUrl . 'js/veciahorra-product-offers.js',
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
                'window.VeciAhorra = ' . $configuration . ';',
                'before'
            );
        }
    }

    /** @return array<string, mixed> */
    public function configuration(): array
    {
        $userId = get_current_user_id();

        return [
            'restUrl' => esc_url_raw(rest_url(self::REST_NAMESPACE . '/')),
            'restNamespace' => self::REST_NAMESPACE,
            'nonce' => $userId > 0 ? wp_create_nonce('wp_rest') : '',
            'currentUser' => [
                'id' => $userId > 0 ? $userId : 0,
                'loggedIn' => $userId > 0,
            ],
            'locale' => sanitize_text_field(determine_locale()),
            'currency' => 'CLP',
            'pages' => [],
            'cart' => [
                'sessionHeader' => 'X-Veciahorra-Cart-Session',
                'sessionId' => $userId > 0
                    ? ''
                    : ($this->cartSession ?? new CartSession())->identifier(),
            ],
        ];
    }
}
