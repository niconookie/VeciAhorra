<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\ProductCatalogs\Repositories;

use VeciAhorra\Exceptions\CatalogUnavailableException;
use WP_Term;

/**
 * Repositorio base de catálogos respaldados por taxonomías.
 */
abstract class TaxonomyCatalogRepository
{
    /**
     * Devuelve los elementos utilizables ordenados por nombre.
     *
     * @return list<array{id: int, name: string}>
     */
    final public function all(): array
    {
        $taxonomy = $this->taxonomy();

        if (! taxonomy_exists($taxonomy)) {
            throw new CatalogUnavailableException(
                'La taxonomía del catálogo no está registrada.'
            );
        }

        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);

        if (is_wp_error($terms)) {
            throw new CatalogUnavailableException(
                'No fue posible consultar la taxonomía del catálogo.'
            );
        }

        $items = [];

        foreach ($terms as $term) {
            if (! $term instanceof WP_Term) {
                continue;
            }

            $id = (int) $term->term_id;
            $name = trim($term->name);

            if ($id <= 0 || $name === '') {
                continue;
            }

            $items[] = [
                'id' => $id,
                'name' => $name,
            ];
        }

        return $items;
    }

    abstract protected function taxonomy(): string;
}
