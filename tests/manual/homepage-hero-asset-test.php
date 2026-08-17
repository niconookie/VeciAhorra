<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';

use VeciAhorra\Modules\Frontend\Assets\FrontendAssets;
use VeciAhorra\Modules\Frontend\FrontendModule;

function heroAssetAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function heroRoot(bool $class = true, string $shortcode = '[veciahorra_public_route_link route="catalog" label="Explorar catálogo"]'): array
{
    $settings = [
        'classes' => ['$$type' => 'classes', 'value' => $class ? ['e-local', 'va-home-hero'] : ['e-local']],
    ];

    return [
        'id' => 'root',
        'elType' => 'e-flexbox',
        'settings' => $settings,
        'elements' => [[
            'id' => 'shortcode',
            'elType' => 'widget',
            'widgetType' => 'shortcode',
            'settings' => ['shortcode' => $shortcode],
            'elements' => [],
        ]],
    ];
}

$module = (new ReflectionClass(FrontendModule::class))->newInstanceWithoutConstructor();
$detector = new ReflectionMethod(FrontendModule::class, 'containsHomepageHero');
$detector->setAccessible(true);

heroAssetAssert($detector->invoke($module, [heroRoot()]) === true, 'positive detection failed');
heroAssetAssert($detector->invoke($module, [heroRoot(false)]) === false, 'root without class accepted');
heroAssetAssert($detector->invoke($module, [heroRoot(true, '[veciahorra_public_route_link route="catalog" label="Other"]')]) === false, 'different shortcode accepted');

$outside = heroRoot();
$outside['elements'] = [];
heroAssetAssert($detector->invoke($module, [$outside, heroRoot(false)]) === false, 'shortcode outside root accepted');
heroAssetAssert($detector->invoke($module, [heroRoot(), heroRoot()]) === false, 'ambiguous roots accepted');
heroAssetAssert($detector->invoke($module, [['invalid']]) === false, 'invalid structure accepted');

$assets = new FrontendAssets();
$assets->registerAssets();
$registered = wp_styles()->registered[FrontendAssets::HOMEPAGE_HERO_STYLE_HANDLE] ?? null;
heroAssetAssert($registered !== null, 'hero handle not registered');
heroAssetAssert(str_ends_with((string) $registered->src, '/assets/frontend/css/homepage-hero.css'), 'wrong hero stylesheet');
heroAssetAssert(! wp_style_is(FrontendAssets::HOMEPAGE_HERO_STYLE_HANDLE, 'enqueued'), 'hero globally enqueued');
heroAssetAssert(! wp_style_is(FrontendAssets::STYLE_HANDLE, 'enqueued'), 'general stylesheet globally enqueued');

$css = file_get_contents(dirname(__DIR__, 2) . '/assets/frontend/css/homepage-hero.css');
heroAssetAssert(is_string($css), 'hero stylesheet unreadable');
heroAssetAssert(substr_count($css, '.va-home-hero .va-public-route-link') === 3, 'selector count differs');
heroAssetAssert((bool) preg_match('/@media\s*\(max-width:\s*767px\)\s*\{\s*\.va-home-hero\s*\{\s*box-shadow:\s*none\s*!important;\s*\}\s*\}/u', $css), 'mobile shadow override missing');
heroAssetAssert(! preg_match('/\.va-home-hero\s*\[/u', $css), 'revoked combined hero selector found');
heroAssetAssert(substr_count($css, '!important') === 1, 'unexpected important count');
heroAssetAssert(! preg_match('/(^|})\s*\.va-public-route-link/u', $css), 'global selector found');

echo 'HOMEPAGE_HERO_ASSET=PASS SHA256=' . hash('sha256', $css) . PHP_EOL;
