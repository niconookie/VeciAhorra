<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Frontend\Controller;

use VeciAhorra\Modules\Frontend\Assets\FrontendAssets;
use VeciAhorra\Modules\Frontend\Support\ViewRenderer;

/**
 * Renders the technical mount point used by future customer pages.
 */
final class FrontendController
{
    public const SHORTCODE = 'veciahorra_frontend';
    public const CART_SHORTCODE = 'veciahorra_cart';
    public const CHECKOUT_SHORTCODE = 'veciahorra_checkout';

    private int $instance = 0;

    public function __construct(
        private FrontendAssets $assets,
        private ViewRenderer $views
    ) {
    }

    /** @param array<string, mixed> $attributes */
    public function renderPlaceholder(
        array|string $attributes = [],
        ?string $content = null,
        string $tag = ''
    ): string {
        if (is_admin()) {
            return '';
        }

        $this->assets->enqueue();
        $this->instance++;
        $instanceId = 'va-frontend-' . $this->instance;
        $attributes = shortcode_atts(
            ['product_id' => 0],
            is_array($attributes) ? $attributes : [],
            self::SHORTCODE
        );
        $productId = absint($attributes['product_id']);

        if ($productId > 0) {
            $this->assets->enqueueProductOffers();
            $page = $this->views->render('product-detail', [
                'instanceId' => $instanceId,
                'productId' => $productId,
                'cartUrl' => (string) apply_filters(
                    'veciahorra_frontend_cart_url',
                    home_url('/carrito-veciahorra/')
                ),
            ]);
        } else {
            $this->assets->enqueueCatalog();
            $page = $this->views->render('catalog', [
                'instanceId' => $instanceId,
                'productUrls' => $this->productUrls(),
            ]);
        }

        return $this->views->render('layout', [
            'content' => $page,
            'instanceId' => $instanceId,
        ]);
    }

    /** @return array<int, string> */
    private function productUrls(): array
    {
        $urls = [];
        $pages = get_posts([
            'post_type' => 'page',
            'post_status' => 'publish',
            'numberposts' => -1,
        ]);
        $pattern = get_shortcode_regex([self::SHORTCODE]);

        foreach ($pages as $page) {
            if (! $page instanceof \WP_Post
                || ! preg_match_all('/' . $pattern . '/s', $page->post_content, $matches)
            ) {
                continue;
            }

            foreach ($matches[3] as $rawAttributes) {
                $attributes = shortcode_parse_atts($rawAttributes);
                $productId = absint(is_array($attributes) ? ($attributes['product_id'] ?? 0) : 0);

                if ($productId > 0) {
                    $urls[$productId] = (string) get_permalink($page);
                }
            }
        }

        return $urls;
    }

    /** @param array<string, mixed>|string $attributes */
    public function renderCart(
        array|string $attributes = [],
        ?string $content = null,
        string $tag = ''
    ): string {
        if (is_admin()) {
            return '';
        }

        $this->assets->enqueueCart();
        $this->instance++;
        $instanceId = 'va-cart-' . $this->instance;
        $page = $this->views->render('cart', [
            'instanceId' => $instanceId,
            'checkoutUrl' => $this->assets->checkoutUrl(),
        ]);

        return $this->views->render('layout', [
            'content' => $page,
            'instanceId' => $instanceId,
        ]);
    }

    /** @param array<string, mixed>|string $attributes */
    public function renderCheckout(
        array|string $attributes = [],
        ?string $content = null,
        string $tag = ''
    ): string {
        if (is_admin()) {
            return '';
        }

        $this->assets->enqueueCheckout();
        $this->instance++;
        $instanceId = 'va-checkout-' . $this->instance;
        $page = $this->views->render('checkout', [
            'instanceId' => $instanceId,
        ]);

        return $this->views->render('layout', [
            'content' => $page,
            'instanceId' => $instanceId,
        ]);
    }
}
