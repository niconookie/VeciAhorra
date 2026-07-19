<?php

declare(strict_types=1);

use VeciAhorra\Core\Application;
use VeciAhorra\Modules\Frontend\FrontendModule;
use VeciAhorra\Modules\Frontend\Search\PublicSearchIsolation;
use VeciAhorra\Modules\Frontend\Search\PublicSearchIsolationPolicy;
use VeciAhorra\Modules\Frontend\Search\WooCommercePublicPageResolver;

require_once dirname(__DIR__, 5) . '/wp-load.php';

if (! function_exists('set_current_screen')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
    require_once ABSPATH . 'wp-admin/includes/screen.php';
}

function assertPublicSearchIsolation(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$policy = new PublicSearchIsolationPolicy();

global $wp_filter;
$countClassCallbacks = static function (string $hook, string $method): int {
    global $wp_filter;
    $count = 0;
    foreach (($wp_filter[$hook]->callbacks ?? []) as $callbacks) {
        foreach ($callbacks as $callback) {
            $registered = $callback['function'] ?? null;
            if (is_array($registered)
                && ($registered[0] ?? null) instanceof PublicSearchIsolation
                && ($registered[1] ?? null) === $method
            ) {
                $count++;
            }
        }
    }
    return $count;
};
assertPublicSearchIsolation(
    $countClassCallbacks('pre_get_posts', 'filterMainSearch') === 1,
    'FrontendModule no registro una unica instancia productiva en pre_get_posts.'
);
assertPublicSearchIsolation(
    $countClassCallbacks('rest_post_search_query', 'filterLiveSearch') === 1,
    'FrontendModule no registro una unica instancia productiva en REST.'
);

$transformations = [
    [['post', 'page', 'product'], ['post', 'page']],
    [['product', 'page'], ['page']],
    ['product', 'product'],
    [['product'], ['product']],
    [['post', 'page'], ['post', 'page']],
    [['post', 'product', 'event'], ['post', 'event']],
    [['post', 'product', 'post', 'product'], ['post', 'post']],
    ['', ''],
    [null, null],
    [42, 42],
    [['product', 42], ['product', 42]],
    [['product', ''], ['product', '']],
    [new stdClass(), null],
];

foreach ($transformations as [$input, $expected]) {
    $actual = $policy->excludesProduct($input);
    if ($input instanceof stdClass) {
        assertPublicSearchIsolation(
            $actual === $input,
            'Un valor inesperado fue transformado.'
        );
        continue;
    }

    assertPublicSearchIsolation(
        $actual === $expected,
        'La transformacion de post_type no fue determinista.'
    );
}

$mergeCases = [
    [[], [11, 12], [11, 12]],
    [[7], [11, 12], [7, 11, 12]],
    [[7, 11, 7], [11, 12], [7, 11, 12]],
    [7, [11, 12], [7, 11, 12]],
    ['invalid', [0, 11, -2, 12], [11, 12]],
    [null, [], []],
];
foreach ($mergeCases as [$existing, $additional, $expected]) {
    assertPublicSearchIsolation(
        $policy->mergesExcludedPostIds($existing, $additional) === $expected,
        'La combinacion de post__not_in no fue segura o determinista.'
    );
}

$allowedContext = [
    'is_admin' => false,
    'is_ajax' => false,
    'is_rest' => false,
    'is_cron' => false,
    'is_cli' => false,
    'is_action_scheduler' => false,
    'is_secondary' => false,
    'is_not_search' => false,
    'is_commercial_archive' => false,
];
assertPublicSearchIsolation(
    $policy->allowsTraditionalSearch($allowedContext),
    'La politica rechazo una busqueda publica general.'
);

foreach (array_keys($allowedContext) as $negativeCondition) {
    $context = $allowedContext;
    $context[$negativeCondition] = true;
    assertPublicSearchIsolation(
        ! $policy->allowsTraditionalSearch($context),
        "La condicion negativa {$negativeCondition} no aislo la consulta."
    );
}

$liveContext = [
    'route' => '/wp/v2/search',
    'type' => 'post',
    'ct_live_search' => 'true',
];
assertPublicSearchIsolation(
    $policy->allowsLiveSearch($liveContext),
    'La politica rechazo el live search comprobado.'
);
foreach (
    [
        ['route' => '/wc/store/v1/products'],
        ['type' => 'term'],
        ['ct_live_search' => 'false'],
        ['ct_live_search' => null],
    ] as $change
) {
    assertPublicSearchIsolation(
        ! $policy->allowsLiveSearch(array_replace($liveContext, $change)),
        'La politica REST admitio un contexto ajeno.'
    );
}

$fakeWooPosts = [
    91001 => new WP_Post((object) ['ID' => 91001, 'post_type' => 'page']),
    91002 => new WP_Post((object) ['ID' => 91002, 'post_type' => 'page']),
];
$fakeWooPages = new WooCommercePublicPageResolver(
    static fn (string $type): int => match ($type) {
        'shop' => 91001,
        'cart' => 91002,
        default => 0,
    },
    static fn (int $id): mixed => $fakeWooPosts[$id] ?? null
);
$isolation = new PublicSearchIsolation($policy, $fakeWooPages);
$isolation->register();
$preGetPostsPriority = has_action(
    'pre_get_posts',
    [$isolation, 'filterMainSearch']
);
$restPriority = has_filter(
    'rest_post_search_query',
    [$isolation, 'filterLiveSearch']
);
assertPublicSearchIsolation(
    $preGetPostsPriority === PublicSearchIsolation::MAIN_SEARCH_PRIORITY,
    'pre_get_posts no fue registrado con la prioridad aprobada.'
);
assertPublicSearchIsolation(
    $restPriority === PublicSearchIsolation::LIVE_SEARCH_PRIORITY,
    'rest_post_search_query no fue registrado con la prioridad aprobada.'
);
assertPublicSearchIsolation(
    PublicSearchIsolation::LIVE_SEARCH_PRIORITY > 999,
    'La prioridad REST no prevalece sobre Blocksy.'
);
$isolation->register();
$preCallbacks = $wp_filter['pre_get_posts']->callbacks[$preGetPostsPriority] ?? [];
$restCallbacks = $wp_filter['rest_post_search_query']->callbacks[$restPriority] ?? [];
$countCallback = static function (array $callbacks, object $object, string $method): int {
    $count = 0;
    foreach ($callbacks as $callback) {
        $registered = $callback['function'] ?? null;
        if (is_array($registered)
            && ($registered[0] ?? null) === $object
            && ($registered[1] ?? null) === $method
        ) {
            $count++;
        }
    }
    return $count;
};
assertPublicSearchIsolation(
    $countCallback($preCallbacks, $isolation, 'filterMainSearch') === 1,
    'pre_get_posts fue registrado mas de una vez.'
);
assertPublicSearchIsolation(
    $countCallback($restCallbacks, $isolation, 'filterLiveSearch') === 1,
    'rest_post_search_query fue registrado mas de una vez.'
);

set_current_screen('front');
global $wp_the_query;
$originalMainQuery = $wp_the_query;
$mainSearch = new WP_Query();
$mainSearch->is_search = true;
$mainSearch->set('post_type', ['post', 'page', 'product', 'event']);
$wp_the_query = $mainSearch;
$isolation->filterMainSearch($mainSearch);
assertPublicSearchIsolation(
    $mainSearch->get('post_type') === ['post', 'page', 'event'],
    'La consulta principal publica no fue aislada.'
);
assertPublicSearchIsolation(
    $mainSearch->get('post__not_in') === [91001, 91002],
    'La consulta principal no excluyo las paginas comerciales oficiales.'
);

$expectedSearchableTypes = array_values(array_filter(
    get_post_types([
        'public' => true,
        'exclude_from_search' => false,
    ], 'names'),
    static fn (string $postType): bool => $postType !== 'product'
));
foreach (['', 'any', null] as $implicitPostType) {
    $implicitMainSearch = new WP_Query();
    $implicitMainSearch->is_search = true;
    $implicitMainSearch->set('post_type', $implicitPostType);
    $wp_the_query = $implicitMainSearch;
    $isolation->filterMainSearch($implicitMainSearch);
    assertPublicSearchIsolation(
        $implicitMainSearch->get('post_type') === $expectedSearchableTypes,
        'La expansion implicita incluyo tipos no publicos o no buscables.'
    );
}

$productMainSearch = new WP_Query();
$productMainSearch->is_search = true;
$productMainSearch->set('post_type', 'product');
$productMainSearch->set('post__not_in', [66]);
$wp_the_query = $productMainSearch;
$isolation->filterMainSearch($productMainSearch);
assertPublicSearchIsolation(
    $productMainSearch->get('post_type') === 'product',
    'La consulta tradicional product-only fue alterada.'
);
assertPublicSearchIsolation(
    $productMainSearch->get('post__not_in') === [66],
    'La consulta tradicional product-only recibio exclusiones de paginas.'
);

$duplicateProductSearch = new WP_Query();
$duplicateProductSearch->is_search = true;
$duplicateProductSearch->set('post_type', ['product', 'product']);
$wp_the_query = $duplicateProductSearch;
$isolation->filterMainSearch($duplicateProductSearch);
assertPublicSearchIsolation(
    $duplicateProductSearch->get('post_type') === ['product', 'product']
        && $duplicateProductSearch->get('post__not_in') === '',
    'Una consulta product-only duplicada fue alterada.'
);

$secondarySearch = new WP_Query();
$secondarySearch->is_search = true;
$secondarySearch->set('post_type', ['page', 'product']);
$secondarySearch->set('post__not_in', [88]);
$wp_the_query = $mainSearch;
$isolation->filterMainSearch($secondarySearch);
assertPublicSearchIsolation(
    $secondarySearch->get('post_type') === ['page', 'product'],
    'Una consulta secundaria fue alterada.'
);
assertPublicSearchIsolation($secondarySearch->get('post__not_in') === [88], 'Consulta secundaria perdio exclusiones.');

$notSearch = new WP_Query();
$notSearch->set('post_type', ['page', 'product']);
$notSearch->set('post__not_in', [88]);
$wp_the_query = $notSearch;
$isolation->filterMainSearch($notSearch);
assertPublicSearchIsolation(
    $notSearch->get('post_type') === ['page', 'product'],
    'Una consulta que no es busqueda fue alterada.'
);
assertPublicSearchIsolation($notSearch->get('post__not_in') === [88], 'Consulta no-search perdio exclusiones.');

$commercialArchive = new WP_Query();
$commercialArchive->is_search = true;
$commercialArchive->is_post_type_archive = true;
$commercialArchive->set('post_type', ['product', 'page']);
$commercialArchive->set('post__not_in', [88]);
$wp_the_query = $commercialArchive;
$isolation->filterMainSearch($commercialArchive);
assertPublicSearchIsolation(
    $commercialArchive->get('post_type') === ['product', 'page'],
    'Un archivo comercial fue alterado.'
);
assertPublicSearchIsolation($commercialArchive->get('post__not_in') === [88], 'Archivo comercial perdio exclusiones.');

set_current_screen('edit-product');
$adminSearch = new WP_Query();
$adminSearch->is_search = true;
$adminSearch->set('post_type', ['page', 'product']);
$adminSearch->set('post__not_in', [88]);
$wp_the_query = $adminSearch;
$isolation->filterMainSearch($adminSearch);
assertPublicSearchIsolation(
    $adminSearch->get('post_type') === ['page', 'product'],
    'Una busqueda administrativa fue alterada.'
);
assertPublicSearchIsolation($adminSearch->get('post__not_in') === [88], 'Busqueda admin perdio exclusiones.');
set_current_screen('front');
$wp_the_query = $originalMainQuery;

$request = new WP_REST_Request('GET', '/wp/v2/search');
$request->set_param('type', 'post');
$request->set_param('ct_live_search', 'true');
$mixed = $isolation->filterLiveSearch([
    'post_type' => ['post', 'page', 'product', 'event'],
    'post__not_in' => [77, 91001, 77],
], $request);
assertPublicSearchIsolation(
    $mixed['post_type'] === ['post', 'page', 'event'],
    'El live search no retiro product del conjunto mixto.'
);
assertPublicSearchIsolation(
    $mixed['post__not_in'] === [77, 91001, 91002],
    'El live search no preservo exclusiones previas.'
);
$productOnly = $isolation->filterLiveSearch([
    'post_type' => 'product',
    'post__not_in' => [66],
], $request);
assertPublicSearchIsolation(
    $productOnly['post_type'] === 'product'
        && $productOnly['post__not_in'] === [66],
    'El live search altero una busqueda product-only.'
);
$request->set_param('ct_live_search', 'false');
$untouched = ['post_type' => ['page', 'product']];
assertPublicSearchIsolation(
    $isolation->filterLiveSearch($untouched, $request) === $untouched,
    'Una busqueda REST ajena fue modificada.'
);
$otherRequest = new WP_REST_Request('GET', '/wc/store/v1/products');
$otherRequest->set_param('type', 'post');
$otherRequest->set_param('ct_live_search', 'true');
assertPublicSearchIsolation(
    $isolation->filterLiveSearch($untouched, $otherRequest) === $untouched,
    'Otro endpoint REST fue modificado.'
);

$application = new Application();
$module = $application->container()->make(FrontendModule::class);
$module->register();
assertPublicSearchIsolation(
    has_action('pre_get_posts') !== false
        && has_filter('rest_post_search_query') !== false,
    'FrontendModule no integro el aislamiento de busqueda.'
);

$moduleSource = file_get_contents(
    dirname(__DIR__, 2) . '/app/Modules/Frontend/FrontendModule.php'
);
assertPublicSearchIsolation(
    substr_count($moduleSource, 'PublicSearchIsolation') >= 2,
    'FrontendModule no contiene un unico punto de composicion.'
);

echo "PASS public-search-isolation-test\n";
