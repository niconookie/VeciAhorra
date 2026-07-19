<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertCategoryFrontend(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__, 2);
$view = (string) file_get_contents($root . '/app/Modules/Frontend/Views/catalog.php');
$javascript = (string) file_get_contents($root . '/assets/frontend/js/veciahorra-catalog.js');
$css = (string) file_get_contents($root . '/assets/frontend/css/veciahorra-frontend.css');

foreach ([
    'data-va-catalog-filters', 'data-va-catalog-search', 'data-va-catalog-category',
    'data-va-catalog-category-status', 'data-va-catalog-order', 'data-va-catalog-reset',
    'Todas las categorías',
] as $contract) {
    assertCategoryFrontend(str_contains($view, $contract), "Falta contrato de vista: {$contract}.");
}

assertCategoryFrontend(substr_count($javascript, "config.api.get('/catalog/categories')") === 1, 'Las categorías no se cargan exactamente una vez por mount.');
assertCategoryFrontend(str_contains($javascript, "params.set('category', category)"), 'No se envía category válido.');
assertCategoryFrontend(str_contains($javascript, "if (category)"), 'No se omite category al limpiar.');
assertCategoryFrontend(str_contains($javascript, "params.set('search', search)"), 'No se conserva búsqueda.');
assertCategoryFrontend(str_contains($javascript, "params.set('brand', brand)"), 'No se conserva marca.');
assertCategoryFrontend(str_contains($javascript, "params.set('order_by', order)"), 'No se conserva orden.');
assertCategoryFrontend(str_contains($javascript, "filters.page = 1"), 'No se reinicia paginación al cambiar filtros.');
assertCategoryFrontend(str_contains($javascript, 'category.disabled = true') && str_contains($javascript, 'Puedes seguir usando el catálogo'), 'No existe degradación neutral de categorías.');
assertCategoryFrontend(substr_count($javascript, "category.addEventListener('change'") === 1, 'El selector no tiene un único listener de cambio.');
assertCategoryFrontend(str_contains($css, '.va-catalog__filters select:focus-visible'), 'No hay foco visible para el selector.');

echo "PASS public-catalog-category-frontend-test\n";
