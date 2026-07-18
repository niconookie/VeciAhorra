<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\ProductCatalogs;

/**
 * Guarantees the taxonomy used as the unit catalog authority is available.
 */
final class UnitTaxonomy
{
    public const NAME = 'pa_unidad';

    public function register(): void
    {
        if (taxonomy_exists(self::NAME)) {
            return;
        }

        register_taxonomy(self::NAME, ['product'], [
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => false,
            'show_in_menu' => false,
            'show_in_rest' => false,
            'hierarchical' => false,
            'rewrite' => false,
            'query_var' => false,
        ]);
    }
}
