<?php

declare(strict_types=1);

use VeciAhorra\Modules\Frontend\Search\WooCommercePublicPageResolver;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertWooCommercePageResolver(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$posts = [
    101 => new WP_Post((object) ['ID' => 101, 'post_type' => 'page']),
    102 => new WP_Post((object) ['ID' => 102, 'post_type' => 'page']),
    103 => new WP_Post((object) ['ID' => 103, 'post_type' => 'page']),
    104 => new WP_Post((object) ['ID' => 104, 'post_type' => 'page']),
    105 => new WP_Post((object) ['ID' => 105, 'post_type' => 'post']),
];
$providerCalls = 0;
$resolver = new WooCommercePublicPageResolver(
    static function (string $type) use (&$providerCalls): mixed {
        $providerCalls++;
        return match ($type) {
            'shop' => 101,
            'cart' => '102',
            'checkout' => 103,
            'myaccount' => 104,
        };
    },
    static fn (int $id): mixed => $posts[$id] ?? null
);
assertWooCommercePageResolver(
    $resolver->pageIds() === [101, 102, 103, 104],
    'No se resolvieron las cuatro autoridades oficiales en orden estable.'
);
assertWooCommercePageResolver(
    $resolver->pageIds() === [101, 102, 103, 104] && $providerCalls === 4,
    'La resolucion no fue memoizada durante la request.'
);

$edgeResolver = new WooCommercePublicPageResolver(
    static fn (string $type): mixed => match ($type) {
        'shop' => 101,
        'cart' => 101,
        'checkout' => 0,
        'myaccount' => 999,
    },
    static fn (int $id): mixed => $posts[$id] ?? null
);
assertWooCommercePageResolver(
    $edgeResolver->pageIds() === [101],
    'No se ignoraron duplicados, cero o paginas eliminadas.'
);

$invalidResolver = new WooCommercePublicPageResolver(
    static fn (string $type): mixed => match ($type) {
        'shop' => -1,
        'cart' => 'invalid',
        'checkout' => 105,
        'myaccount' => null,
    },
    static fn (int $id): mixed => $posts[$id] ?? null
);
assertWooCommercePageResolver(
    $invalidResolver->pageIds() === [],
    'Valores ausentes, invalidos o de otro post_type no fueron ignorados.'
);

// The injected provider models the official-option fallback used when the
// WooCommerce API is unavailable (for example, while the plugin is inactive).
$fallbackResolver = new WooCommercePublicPageResolver(
    static fn (string $type): mixed => match ($type) {
        'shop' => '101',
        'cart' => '102',
        'checkout' => '',
        'myaccount' => '0',
    },
    static fn (int $id): mixed => $posts[$id] ?? null
);
assertWooCommercePageResolver(
    $fallbackResolver->pageIds() === [101, 102],
    'El fallback de opciones oficiales no tolero APIs no disponibles.'
);

$official = (new WooCommercePublicPageResolver())->pageIds();
assertWooCommercePageResolver(
    $official === array_values(array_unique($official)),
    'La autoridad real no produjo una lista determinista y unica.'
);
foreach ($official as $id) {
    assertWooCommercePageResolver(
        get_post_type($id) === 'page',
        'La autoridad real incluyo un objeto que no es pagina.'
    );
}

echo "PASS woocommerce-public-page-resolver-test\n";
