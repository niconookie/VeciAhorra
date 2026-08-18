<?php

declare(strict_types=1);

function offerPrivacyAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @param array<string,string> $sources */
function validateOfferPrivacy(array $sources): void
{
    offerPrivacyAssert(str_contains($sources['catalog'], "unset(\$offer['minimarket_id'], \$offer['minimarket']);"), 'PUBLIC_OFFER_API_IDENTITY_EXPOSED');
    offerPrivacyAssert(str_contains($sources['view'], 'Selecciona la oferta que más te convenga.'), 'PUBLIC_OFFER_COPY_PRIVATE');
    offerPrivacyAssert(! str_contains($sources['view'], 'Selecciona el minimarket donde deseas comprar.'), 'PUBLIC_OFFER_COPY_PRIVATE');
    offerPrivacyAssert(str_contains($sources['offers'], "normalized.offer_label = 'Oferta ' + (validOffers.length + 1);"), 'PUBLIC_OFFER_NUMBERING_REMOVED');
    offerPrivacyAssert(str_contains($sources['offers'], 'data-inventory-id') && str_contains($sources['offers'], 'inventory_id: selectedId'), 'PUBLIC_OFFER_INVENTORY_AUTHORITY_REMOVED');
    offerPrivacyAssert(! str_contains($sources['offers'], 'offer.minimarket') && ! str_contains($sources['offers'], 'offer.minimarket_id'), 'PUBLIC_OFFER_RENDERER_IDENTITY_EXPOSED');
    offerPrivacyAssert(str_contains($sources['cart_service'], "unset(\$item['minimarket_name']);"), 'PUBLIC_CART_IDENTITY_EXPOSED');
    offerPrivacyAssert(! str_contains($sources['cart_repository'], 'stores.business_name AS minimarket_name'), 'PUBLIC_CART_DTO_IDENTITY_EXPOSED');
    offerPrivacyAssert(! str_contains($sources['cart'], 'item.minimarket_name'), 'PUBLIC_CART_RENDERER_IDENTITY_EXPOSED');
    offerPrivacyAssert(! str_contains($sources['checkout'], 'item.minimarket_name') && ! str_contains($sources['checkout'], 'group.name'), 'PUBLIC_CHECKOUT_IDENTITY_EXPOSED');
    offerPrivacyAssert(str_contains($sources['checkout'], "group.label = 'Oferta ' + (index + 1);"), 'PUBLIC_CHECKOUT_NEUTRAL_LABEL_REMOVED');
}

$root = dirname(__DIR__, 2);
$paths = [
    'catalog' => 'app/Modules/Catalog/Service/CatalogService.php',
    'view' => 'app/Modules/Frontend/Views/product-detail.php',
    'offers' => 'assets/frontend/js/veciahorra-product-offers.js',
    'cart_service' => 'app/Modules/Cart/Service/CartService.php',
    'cart_repository' => 'app/Modules/Cart/Repository/CartRepository.php',
    'cart' => 'assets/frontend/js/veciahorra-cart.js',
    'checkout' => 'assets/frontend/js/veciahorra-checkout.js',
];
$sources = [];
foreach ($paths as $name => $path) {
    $sources[$name] = (string) file_get_contents($root . '/' . $path);
}
validateOfferPrivacy($sources);

$mutations = [
    ['catalog', "unset(\$offer['minimarket_id'], \$offer['minimarket']);", ''],
    ['view', 'Selecciona la oferta que más te convenga.', 'Selecciona el minimarket donde deseas comprar.'],
    ['offers', "normalized.offer_label = 'Oferta ' + (validOffers.length + 1);", "normalized.offer_label = String(normalized.inventory_id);"],
    ['offers', 'inventory_id: selectedId', 'inventory_id: anotherId'],
    ['offers', 'unit_price: price,', "minimarket: offer.minimarket,\n            unit_price: price,"],
    ['cart_service', "unset(\$item['minimarket_name']);", ''],
    ['cart_repository', 'products.image_id AS product_image_id', "products.image_id AS product_image_id,\n                stores.business_name AS minimarket_name"],
    ['cart', "labeledCell('Oferta', 'Oferta seleccionada')", "labeledCell('Oferta', item.minimarket_name)"],
    ['checkout', "group.label = 'Oferta ' + (index + 1);", 'group.label = group.name;'],
];
$accepted = 0;
foreach ($mutations as [$name, $search, $replacement]) {
    offerPrivacyAssert(substr_count($sources[$name], $search) === 1, "PUBLIC_OFFER_MUTATION_NOT_UNITARY:{$name}");
    $candidate = $sources;
    $candidate[$name] = str_replace($search, $replacement, $candidate[$name]);
    try {
        validateOfferPrivacy($candidate);
    } catch (RuntimeException) {
        $accepted++;
    }
}
offerPrivacyAssert($accepted === count($mutations), 'PUBLIC_OFFER_ADVERSARIAL_ACCEPTED');
echo 'PUBLIC_OFFER_STORE_PRIVACY=PASS before=inventory_id,minimarket_id,minimarket,price,stock after=inventory_id,price,stock adversarials=' . $accepted . '/' . count($mutations) . "\n";
