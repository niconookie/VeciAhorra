<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Frontend;

use VeciAhorra\Modules\Frontend\Assets\FrontendAssets;
use VeciAhorra\Modules\Frontend\Controller\FrontendController;

/**
 * Registers the public frontend infrastructure without business features.
 */
final class FrontendModule
{
    private bool $registered = false;

    public function __construct(
        private FrontendAssets $assets,
        private FrontendController $controller
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
    }
}
