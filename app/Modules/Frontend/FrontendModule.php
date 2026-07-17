<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Frontend;

use VeciAhorra\Modules\Frontend\Assets\FrontendAssets;
use VeciAhorra\Modules\Frontend\Controller\FrontendController;
use VeciAhorra\Modules\Frontend\Support\CartSession;

/**
 * Registers the public frontend infrastructure without business features.
 */
final class FrontendModule
{
    private bool $registered = false;

    public function __construct(
        private FrontendAssets $assets,
        private FrontendController $controller,
        private ?CartSession $cartSession = null
    ) {
    }

    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;

        add_action(
            'wp_enqueue_scripts',
            [$this->assets, 'registerAssets']
        );
        add_shortcode(
            FrontendController::SHORTCODE,
            [$this->controller, 'renderPlaceholder']
        );
        add_shortcode(
            FrontendController::CART_SHORTCODE,
            [$this->controller, 'renderCart']
        );
        add_shortcode(
            FrontendController::CHECKOUT_SHORTCODE,
            [$this->controller, 'renderCheckout']
        );
        add_shortcode(
            FrontendController::CUSTOMER_PANEL_SHORTCODE,
            [$this->controller, 'renderCustomerPanel']
        );
        add_action(
            'wp',
            [($this->cartSession ?? new CartSession()), 'prepareForRequest']
        );
    }
}
