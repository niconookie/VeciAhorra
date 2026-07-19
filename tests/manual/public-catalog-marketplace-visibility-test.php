<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertMarketplaceVisibility(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__, 2);
$service = (string) file_get_contents($root . '/app/Modules/Catalog/Service/CatalogService.php');
$javascript = (string) file_get_contents($root . '/assets/frontend/js/veciahorra-catalog.js');
$css = (string) file_get_contents($root . '/assets/frontend/css/veciahorra-frontend.css');

foreach ([
    "var price = product.min_price",
    "var minimarkets = Number(product.available_minimarkets)",
    "'va-catalog-card__price-prefix', 'Desde'",
    "'Disponible en ' + minimarkets",
    "minimarkets === 1 ? ' minimarket' : ' minimarkets'",
    "'Ver producto'",
] as $contract) {
    assertMarketplaceVisibility(
        str_contains($javascript, $contract),
        "Falta contrato visual del marketplace: {$contract}."
    );
}

assertMarketplaceVisibility(
    ! str_contains($javascript, "config.api.get('/catalog/products/'"),
    'El listado conserva solicitudes de detalle por producto.'
);
assertMarketplaceVisibility(
    ! str_contains($javascript, 'Promise.all(items.map'),
    'El listado conserva el patron N+1 anterior.'
);
assertMarketplaceVisibility(
    ! str_contains($javascript, 'detail.offers')
        && ! str_contains($javascript, 'offer.minimarket')
        && ! str_contains($javascript, 'offer.stock'),
    'La tarjeta deriva datos desde ofertas parciales.'
);
assertMarketplaceVisibility(
    ! str_contains($javascript, 'Disponible en 0 minimarkets'),
    'La interfaz contiene un estado imposible de cero minimarkets.'
);

foreach ([
    '.va-catalog-card__price-prefix',
    '.va-catalog-card__price-value',
    '.va-catalog-card__availability',
] as $selector) {
    assertMarketplaceVisibility(
        str_contains($css, $selector),
        "Falta estilo acotado para {$selector}."
    );
}

assertMarketplaceVisibility(
    str_contains($service, "'available_minimarkets' => count(\$summary['minimarkets'])"),
    'El contrato no cuenta minimarkets distintos desde el resumen publico.'
);
assertMarketplaceVisibility(
    str_contains($service, "\$minimarkets[\$offer['minimarket_id']] = true"),
    'El conteo no deduplica Stores por minimarket_id.'
);
assertMarketplaceVisibility(
    str_contains($service, "'min_price' => (string) reset(\$prices)"),
    'El precio minimo no procede del mismo universo publico.'
);
assertMarketplaceVisibility(
    str_contains($service, 'array_chunk($ids, self::READ_BATCH_SIZE)')
        && str_contains($service, '$this->stores->findActiveByIds($batch)'),
    'Los Stores no se resuelven mediante lectura agregada por lotes.'
);

echo "PASS public-catalog-marketplace-visibility-test\n";
