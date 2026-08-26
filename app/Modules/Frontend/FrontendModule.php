<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Frontend;

use VeciAhorra\Modules\Frontend\Assets\FrontendAssets;
use VeciAhorra\Modules\Frontend\Components\PublicRouteLink;
use VeciAhorra\Modules\Frontend\Components\HomepageProducts;
use VeciAhorra\Modules\Frontend\Controller\FrontendController;
use VeciAhorra\Modules\Frontend\Search\PublicSearchIsolation;
use VeciAhorra\Modules\Frontend\Search\PublicSearchIsolationPolicy;
use VeciAhorra\Modules\Frontend\Search\WooCommercePublicPageResolver;
use VeciAhorra\Modules\Frontend\Support\CartSession;
use VeciAhorra\Modules\Frontend\Support\PublicRouteResolver;

/**
 * Registers the public frontend infrastructure without business features.
 */
final class FrontendModule
{
    private bool $registered = false;

    public function __construct(
        private FrontendAssets $assets,
        private FrontendController $controller,
        private ?CartSession $cartSession = null,
        private ?PublicRouteLink $publicRouteLink = null,
        private ?PublicSearchIsolation $publicSearchIsolation = null,
        private ?HomepageProducts $homepageProducts = null
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
        add_action(
            'wp_enqueue_scripts',
            [$this, 'enqueueHomepageHeroAsset'],
            20
        );
        add_action(
            'wp_enqueue_scripts',
            [$this, 'enqueueHomepageHowItWorksAsset'],
            20
        );
        add_filter(
            'elementor/widget/render_content',
            [$this, 'replaceHomepageHeroLogo'],
            10,
            2
        );
        add_filter('body_class', [$this, 'catalogBodyClasses']);
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
        add_shortcode(
            PublicRouteLink::SHORTCODE,
            [
                $this->publicRouteLink ?? new PublicRouteLink(new PublicRouteResolver()),
                'render',
            ]
        );
        add_shortcode(
            HomepageProducts::SHORTCODE,
            [
                $this->homepageProducts ?? new HomepageProducts($this->assets),
                'render',
            ]
        );
        add_action(
            'wp',
            [($this->cartSession ?? new CartSession()), 'prepareForRequest']
        );
        ($this->publicSearchIsolation ?? new PublicSearchIsolation(
            new PublicSearchIsolationPolicy(),
            new WooCommercePublicPageResolver()
        ))->register();
    }

    /** @param list<string> $classes @return list<string> */
    public function catalogBodyClasses(array $classes): array
    {
        if (! is_singular()) {
            return $classes;
        }

        $post = get_queried_object();
        if (! $post instanceof \WP_Post || ! has_shortcode($post->post_content, FrontendController::SHORTCODE)) {
            return $classes;
        }

        $pattern = get_shortcode_regex([FrontendController::SHORTCODE]);
        if (! preg_match_all('/' . $pattern . '/s', $post->post_content, $matches)) {
            return $classes;
        }

        foreach ($matches[3] as $rawAttributes) {
            $attributes = shortcode_parse_atts($rawAttributes);
            if (absint(is_array($attributes) ? ($attributes['product_id'] ?? 0) : 0) === 0) {
                $classes[] = 'veciahorra-catalog-page';
                break;
            }
        }

        return array_values(array_unique($classes));
    }

    public function enqueueHomepageHeroAsset(): void
    {
        if (
            is_admin()
            || wp_doing_ajax()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || is_feed()
            || ! is_singular()
        ) {
            return;
        }

        $post = get_queried_object();
        if (! $post instanceof \WP_Post) {
            return;
        }

        $raw = get_post_meta($post->ID, '_elementor_data', true);
        if (! is_string($raw) || $raw === '') {
            return;
        }

        $elements = json_decode($raw, true);
        if (! is_array($elements) || json_last_error() !== JSON_ERROR_NONE) {
            return;
        }

        if ($this->containsHomepageHero($elements)) {
            $this->assets->enqueueHomepageHero();
        }
    }

    public function enqueueHomepageHowItWorksAsset(): void
    {
        if (
            is_admin()
            || wp_doing_ajax()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || is_feed()
            || ! is_singular()
        ) {
            return;
        }

        $post = get_queried_object();
        if (! $post instanceof \WP_Post) {
            return;
        }

        $raw = get_post_meta($post->ID, '_elementor_data', true);
        if (! is_string($raw) || $raw === '') {
            return;
        }

        try {
            $elements = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }

        if (is_array($elements) && $this->containsHomepageHowItWorks($elements)) {
            $this->assets->enqueueHomepageHowItWorks();
        }
    }

    public function replaceHomepageHeroLogo(string $content, mixed $widget): string
    {
        if (
            ! is_front_page()
            || ! is_object($widget)
            || ! method_exists($widget, 'get_name')
            || $widget->get_name() !== 'image'
            || ! str_contains($content, content_url('/uploads/2026/06/Logo-Veciahorra.jpg'))
        ) {
            return $content;
        }

        return str_replace(
            content_url('/uploads/2026/06/Logo-Veciahorra.jpg'),
            VA_PLUGIN_URL . 'assets/frontend/images/veciahorra-logo-oficial.png',
            $content
        );
    }

    /** @param array<mixed> $elements */
    private function containsHomepageHowItWorks(array $elements): bool
    {
        foreach ($elements as $element) {
            if ($this->isHomepageHowItWorksRoot($element)) {
                return true;
            }
        }

        return false;
    }

    private function isHomepageHowItWorksRoot(mixed $element): bool
    {
        if (
            ! is_array($element)
            || ! is_string($element['id'] ?? null)
            || ($element['id'] ?? '') === ''
            || ($element['elType'] ?? null) !== 'e-flexbox'
            || ! is_array($element['settings'] ?? null)
            || ! is_array($element['elements'] ?? null)
        ) {
            return false;
        }

        $classes = $element['settings']['classes'] ?? null;
        if (
            ! is_array($classes)
            || ($classes['$$type'] ?? null) !== 'classes'
            || ! is_array($classes['value'] ?? null)
            || ! array_is_list($classes['value'])
        ) {
            return false;
        }

        foreach ($classes['value'] as $class) {
            if (! is_string($class)) {
                return false;
            }
        }

        return count(array_keys($classes['value'], 'va-home-how-it-works', true)) === 1;
    }

    /** @param array<mixed> $elements */
    private function containsHomepageHero(array $elements): bool
    {
        return $this->countHomepageHeroRoots($elements) === 1;
    }

    /** @param array<mixed> $elements */
    private function countHomepageHeroRoots(array $elements): ?int
    {
        $matches = 0;

        foreach ($elements as $element) {
            if (! is_array($element)) {
                return null;
            }

            if ($this->isHomepageHeroRoot($element)) {
                $matches++;
            }

            $children = $element['elements'] ?? [];
            if (! is_array($children)) {
                return null;
            }

            $childMatches = $this->countHomepageHeroRoots($children);
            if ($childMatches === null) {
                return null;
            }
            $matches += $childMatches;

            if ($matches > 1) {
                return null;
            }
        }

        return $matches;
    }

    /** @param array<string, mixed> $element */
    private function isHomepageHeroRoot(array $element): bool
    {
        $settings = $element['settings'] ?? null;
        if (! is_array($settings)) {
            return false;
        }

        $classes = $settings['classes']['value'] ?? null;
        if (! is_array($classes) || ! in_array('va-home-hero', $classes, true)) {
            return false;
        }

        $children = $element['elements'] ?? null;
        return is_array($children) && $this->hasExactCatalogShortcode($children);
    }

    /** @param array<mixed> $elements */
    private function hasExactCatalogShortcode(array $elements): bool
    {
        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }

            if (
                ($element['elType'] ?? null) === 'widget'
                && ($element['widgetType'] ?? null) === 'shortcode'
                && ($element['settings']['shortcode'] ?? null)
                    === '[veciahorra_public_route_link route="catalog" label="Explorar catálogo"]'
            ) {
                return true;
            }

            $children = $element['elements'] ?? [];
            if (is_array($children) && $this->hasExactCatalogShortcode($children)) {
                return true;
            }
        }

        return false;
    }
}
