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
            ]);
        } else {
            $page = $this->views->render('page-placeholder', [
                'instanceId' => $instanceId,
                'title' => __('VeciAhorra', 'veciahorra'),
                'message' => __(
                    'La experiencia de clientes estará disponible próximamente.',
                    'veciahorra'
                ),
            ]);
        }

        return $this->views->render('layout', [
            'content' => $page,
            'instanceId' => $instanceId,
        ]);
    }
}
