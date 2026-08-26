<?php

declare(strict_types=1);

use VeciAhorra\Modules\Frontend\FrontendModule;

ini_set('session.save_path', sys_get_temp_dir());
require_once dirname(__DIR__, 5) . '/wp-load.php';

function portableCatalogAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__, 2);
$css = file_get_contents($root . '/assets/frontend/css/veciahorra-frontend.css');
$moduleSource = file_get_contents($root . '/app/Modules/Frontend/FrontendModule.php');
portableCatalogAssert(is_string($css) && is_string($moduleSource), 'PORTABLE_SOURCE_MISSING');
portableCatalogAssert(! preg_match('/page-id-\d+/', $css), 'ABSOLUTE_PAGE_ID_REMAINS');
portableCatalogAssert(substr_count($css, 'body.veciahorra-catalog-page') === 9, 'PORTABLE_SELECTOR_COUNT');
portableCatalogAssert(str_contains($moduleSource, "add_filter('body_class', [\$this, 'catalogBodyClasses'])"), 'BODY_CLASS_FILTER_MISSING');
portableCatalogAssert(! str_contains($moduleSource, 'df4acd5'), 'ABSOLUTE_ELEMENTOR_WIDGET_ID_REMAINS');

$module = new ReflectionClass(FrontendModule::class);
$instance = $module->newInstanceWithoutConstructor();
$originalQuery = $GLOBALS['wp_query'] ?? null;
$catalog = new WP_Post((object) ['ID' => 1702, 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '[veciahorra_frontend]']);
$product = new WP_Post((object) ['ID' => 2702, 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '[veciahorra_frontend product_id="1"]']);

foreach ([[$catalog, true], [$product, false]] as [$post, $expected]) {
    $GLOBALS['wp_query'] = new WP_Query();
    $GLOBALS['wp_query']->is_singular = true;
    $GLOBALS['wp_query']->queried_object = $post;
    $classes = $instance->catalogBodyClasses(['page-id-' . $post->ID]);
    portableCatalogAssert(in_array('veciahorra-catalog-page', $classes, true) === $expected, 'PORTABLE_IDENTITY_SCOPE');
}

$GLOBALS['wp_query'] = $originalQuery;
echo "PASS catalog-portable-page-identity-test alternate_id=1702 product_id=2702 selector=veciahorra-catalog-page\n";
