<?php

declare(strict_types=1);

use VeciAhorra\Core\Application;
use VeciAhorra\Modules\Frontend\FrontendModule;
use VeciAhorra\Modules\Frontend\Search\PublicSearchIsolation;
use VeciAhorra\Modules\Frontend\Search\PublicSearchIsolationPolicy;

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

$isolation = new PublicSearchIsolation($policy);
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
$wp_the_query = $productMainSearch;
$isolation->filterMainSearch($productMainSearch);
assertPublicSearchIsolation(
    $productMainSearch->get('post_type') === 'product',
    'La consulta tradicional product-only fue alterada.'
);

$secondarySearch = new WP_Query();
$secondarySearch->is_search = true;
$secondarySearch->set('post_type', ['page', 'product']);
$wp_the_query = $mainSearch;
$isolation->filterMainSearch($secondarySearch);
assertPublicSearchIsolation(
    $secondarySearch->get('post_type') === ['page', 'product'],
    'Una consulta secundaria fue alterada.'
);

$notSearch = new WP_Query();
$notSearch->set('post_type', ['page', 'product']);
$wp_the_query = $notSearch;
$isolation->filterMainSearch($notSearch);
assertPublicSearchIsolation(
    $notSearch->get('post_type') === ['page', 'product'],
    'Una consulta que no es busqueda fue alterada.'
);

$commercialArchive = new WP_Query();
$commercialArchive->is_search = true;
$commercialArchive->is_post_type_archive = true;
$commercialArchive->set('post_type', ['product', 'page']);
$wp_the_query = $commercialArchive;
$isolation->filterMainSearch($commercialArchive);
assertPublicSearchIsolation(
    $commercialArchive->get('post_type') === ['product', 'page'],
    'Un archivo comercial fue alterado.'
);

set_current_screen('edit-product');
$adminSearch = new WP_Query();
$adminSearch->is_search = true;
$adminSearch->set('post_type', ['page', 'product']);
$wp_the_query = $adminSearch;
$isolation->filterMainSearch($adminSearch);
assertPublicSearchIsolation(
    $adminSearch->get('post_type') === ['page', 'product'],
    'Una busqueda administrativa fue alterada.'
);
set_current_screen('front');
$wp_the_query = $originalMainQuery;

$request = new WP_REST_Request('GET', '/wp/v2/search');
$request->set_param('type', 'post');
$request->set_param('ct_live_search', 'true');
$mixed = $isolation->filterLiveSearch([
    'post_type' => ['post', 'page', 'product', 'event'],
], $request);
assertPublicSearchIsolation(
    $mixed['post_type'] === ['post', 'page', 'event'],
    'El live search no retiro product del conjunto mixto.'
);
$productOnly = $isolation->filterLiveSearch(['post_type' => 'product'], $request);
assertPublicSearchIsolation(
    $productOnly['post_type'] === 'product',
    'El live search altero una busqueda product-only.'
);
$request->set_param('ct_live_search', 'false');
$untouched = ['post_type' => ['page', 'product']];
assertPublicSearchIsolation(
    $isolation->filterLiveSearch($untouched, $request) === $untouched,
    'Una busqueda REST ajena fue modificada.'
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
