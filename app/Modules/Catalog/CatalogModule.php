<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Catalog;

use VeciAhorra\Modules\Catalog\Routes\CatalogRoutes;

final class CatalogModule
{
    private bool $registered = false;

    public function __construct(private CatalogRoutes $routes)
    {
    }

    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;
        add_action('rest_api_init', [$this->routes, 'register']);
    }
}
