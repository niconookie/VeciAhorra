<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Frontend\Components;

use VeciAhorra\Modules\Frontend\Support\PublicRouteResolver;

/**
 * Renders accessible links to an explicitly approved public route.
 */
final class PublicRouteLink
{
    public const SHORTCODE = 'veciahorra_public_route_link';

    public function __construct(
        private PublicRouteResolver $routes
    ) {
    }

    /** @param array<string, mixed>|string $attributes */
    public function render(
        array|string $attributes = [],
        ?string $content = null,
        string $tag = ''
    ): string {
        $attributes = shortcode_atts(
            [
                'route' => '',
                'label' => '',
            ],
            is_array($attributes) ? $attributes : [],
            self::SHORTCODE
        );

        $url = $this->resolve(sanitize_key((string) $attributes['route']));
        if ($url === '') {
            return '';
        }

        $safeUrl = esc_url($url);
        if ($safeUrl === '') {
            return '';
        }

        $label = wp_strip_all_tags((string) $attributes['label'], true);
        if (trim($label) === '') {
            return '';
        }

        return sprintf(
            '<a class="va-public-route-link elementor-button elementor-button-link elementor-size-sm" href="%s">%s</a>',
            $safeUrl,
            esc_html($label)
        );
    }

    private function resolve(string $route): string
    {
        return match ($route) {
            'catalog' => $this->routes->catalog(),
            default => '',
        };
    }
}
