<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Frontend\Components;

use VeciAhorra\Modules\Frontend\Assets\FrontendAssets;
use VeciAhorra\Modules\Frontend\Support\PublicRouteResolver;
use VeciAhorra\Modules\Sectorization\CurrentSector;

final class HomepageProducts
{
    public const SHORTCODE = 'veciahorra_homepage_products';
    private const LIMIT = 6;

    private int $instance = 0;

    public function __construct(
        private FrontendAssets $assets,
        private ?CurrentSector $currentSector = null,
        private ?PublicRouteResolver $routes = null
    ) {
    }

    /** @param array<string, mixed>|string $attributes */
    public function render(
        array|string $attributes = [],
        ?string $content = null,
        string $tag = ''
    ): string {
        if (is_admin()) {
            return '';
        }

        shortcode_atts(
            ['limit' => self::LIMIT],
            is_array($attributes) ? $attributes : [],
            self::SHORTCODE
        );

        $this->assets->enqueueHomepageProducts();
        $this->instance++;
        $titleId = $this->instance === 1
            ? 'va-home-products-title'
            : 'va-home-products-title-' . $this->instance;
        $catalogUrl = esc_url_raw($this->routeResolver()->catalog());
        $view = dirname(__DIR__) . '/Views/homepage-products.php';
        $data = [
            'titleId' => $titleId,
            'hasEffectiveSector' => $this->sector()->id() > 0,
            'catalogUrl' => is_string($catalogUrl) ? $catalogUrl : '',
            'limit' => self::LIMIT,
        ];

        extract($data, EXTR_SKIP);
        ob_start();

        try {
            require $view;
            return (string) ob_get_clean();
        } catch (\Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }
    }

    private function sector(): CurrentSector
    {
        return $this->currentSector ??= new CurrentSector();
    }

    private function routeResolver(): PublicRouteResolver
    {
        return $this->routes ??= new PublicRouteResolver();
    }
}
