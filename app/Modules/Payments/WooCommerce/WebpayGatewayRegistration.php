<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\WooCommerce;

final class WebpayGatewayRegistration
{
    private bool $registered = false;

    public function register(): void
    {
        if (function_exists('did_action') && did_action('plugins_loaded') > 0) {
            $this->registerWhenWooCommerceIsReady();

            return;
        }

        add_action('plugins_loaded', [$this, 'registerWhenWooCommerceIsReady'], 20);
    }

    public function registerWhenWooCommerceIsReady(): void
    {
        if ($this->registered || ! class_exists('WC_Payment_Gateway')) {
            return;
        }

        $this->registered = true;
        add_filter('woocommerce_payment_gateways', [$this, 'addGateway']);
    }

    public function addGateway(array $gateways): array
    {
        $gateways[] = WebpayPlusGateway::class;

        return $gateways;
    }
}
