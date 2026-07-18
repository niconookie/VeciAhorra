<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Frontend\Support;

use WP_Post;

/**
 * Autoridad unica para las URLs publicas del marketplace.
 *
 * Cada resolucion de WordPress se memoiza durante la request. Los filtros se
 * aplican en cada lectura para conservar su comportamiento dinamico. Si no
 * existe una portada estatica publicada, home() usa la URL base del sitio.
 * Las demas rutas devuelven una cadena vacia cuando no existe una pagina
 * publicada con su shortcode oficial; sus filtros pueden proporcionar una
 * URL alternativa.
 */
final class PublicRouteResolver
{
    private const ROUTE_SHORTCODES = [
        'catalog' => 'veciahorra_frontend',
        'cart' => 'veciahorra_cart',
        'checkout' => 'veciahorra_checkout',
        'customer_purchases' => 'veciahorra_customer_panel',
    ];

    /** @var array<string, string> */
    private array $resolved = [];

    /** @var list<WP_Post>|null */
    private ?array $publishedPages = null;

    public function home(): string
    {
        if (array_key_exists('home', $this->resolved)) {
            return $this->resolved['home'];
        }

        $frontPageId = (int) get_option('page_on_front');
        $url = $frontPageId > 0 && get_post_status($frontPageId) === 'publish'
            ? get_permalink($frontPageId)
            : false;

        return $this->resolved['home'] = is_string($url) && $url !== ''
            ? $url
            : home_url('/');
    }

    public function catalog(): string
    {
        return $this->resolve('catalog');
    }

    public function cart(): string
    {
        return (string) apply_filters(
            'veciahorra_frontend_cart_url',
            $this->resolve('cart')
        );
    }

    public function checkout(): string
    {
        return (string) apply_filters(
            'veciahorra_frontend_checkout_url',
            $this->resolve('checkout')
        );
    }

    public function customerPurchases(): string
    {
        return (string) apply_filters(
            'veciahorra_frontend_customer_purchases_url',
            $this->resolve('customer_purchases')
        );
    }

    private function resolve(string $route): string
    {
        if (array_key_exists($route, $this->resolved)) {
            return $this->resolved[$route];
        }

        return $this->resolved[$route] = $this->findPageUrl($route);
    }

    private function findPageUrl(string $route): string
    {
        $shortcode = self::ROUTE_SHORTCODES[$route] ?? '';
        if ($shortcode === '') {
            return '';
        }

        foreach ($this->publishedPages() as $page) {
            if (! $page instanceof WP_Post
                || ! has_shortcode($page->post_content, $shortcode)
                || ($route === 'catalog' && ! $this->isCatalogPage($page))
            ) {
                continue;
            }

            $url = get_permalink($page);
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return '';
    }

    /** @return list<WP_Post> */
    private function publishedPages(): array
    {
        if ($this->publishedPages !== null) {
            return $this->publishedPages;
        }

        $pages = get_posts([
            'post_type' => 'page',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        return $this->publishedPages = array_values(array_filter(
            $pages,
            static fn (mixed $page): bool => $page instanceof WP_Post
        ));
    }

    private function isCatalogPage(WP_Post $page): bool
    {
        $pattern = get_shortcode_regex([self::ROUTE_SHORTCODES['catalog']]);
        if (! preg_match_all('/' . $pattern . '/s', $page->post_content, $matches)) {
            return false;
        }

        foreach ($matches[3] as $rawAttributes) {
            $attributes = shortcode_parse_atts($rawAttributes);
            if (absint(is_array($attributes) ? ($attributes['product_id'] ?? 0) : 0) === 0) {
                return true;
            }
        }

        return false;
    }
}
