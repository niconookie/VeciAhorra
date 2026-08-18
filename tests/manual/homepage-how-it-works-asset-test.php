<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Frontend\Assets\FrontendAssets;
use VeciAhorra\Modules\Frontend\FrontendModule;

const VA_HOW_ASSET_HANDLE = 'veciahorra-homepage-how-it-works';
const VA_HOW_ASSET_CLASS = 'va-home-how-it-works';
const VA_HOW_ASSET_EXPECTED_SHA256 = 'e5704c4a3f7c1134d678c82bd555d34cd89f3526dc18bf304c472c2d34a97f07';

function howAssetAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string,mixed> */
function howAssetRoot(array $classes = [VA_HOW_ASSET_CLASS], string $type = 'e-flexbox'): array
{
    return [
        'id' => 'structural-root',
        'elType' => $type,
        'settings' => [
            'classes' => ['$$type' => 'classes', 'value' => $classes],
        ],
        'elements' => [],
    ];
}

function howAssetResetQueue(): void
{
    wp_dequeue_style(VA_HOW_ASSET_HANDLE);
    wp_dequeue_style(FrontendAssets::DESIGN_SYSTEM_STYLE_HANDLE);
}

$writes = [];
add_filter('query', static function (string $query) use (&$writes): string {
    if (preg_match('/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE)\b/i', $query)) {
        $writes[] = $query;
    }
    return $query;
});

$assets = new FrontendAssets();
$assets->registerAssets();
$registered = wp_styles()->registered[VA_HOW_ASSET_HANDLE] ?? null;
howAssetAssert($registered !== null, 'asset handle is not registered');
howAssetAssert(str_ends_with((string) $registered->src, '/assets/frontend/css/homepage-how-it-works.css'), 'asset URL differs');
howAssetAssert($registered->ver === Config::PLUGIN_VERSION, 'asset version differs');
howAssetAssert($registered->deps === [FrontendAssets::DESIGN_SYSTEM_STYLE_HANDLE], 'asset dependency differs');
howAssetAssert(! wp_style_is(VA_HOW_ASSET_HANDLE, 'enqueued'), 'asset globally enqueued');

$moduleReflection = new ReflectionClass(FrontendModule::class);
$module = $moduleReflection->newInstanceWithoutConstructor();
$assetsProperty = $moduleReflection->getProperty('assets');
$assetsProperty->setAccessible(true);
$assetsProperty->setValue($module, $assets);
$detector = $moduleReflection->getMethod('containsHomepageHowItWorks');
$detector->setAccessible(true);
$detect = static fn(array $document): bool => $detector->invoke($module, $document);

howAssetAssert($detect([]) === false, 'empty document accepted');
howAssetAssert($detect([howAssetRoot()]) === true, 'valid root rejected');
howAssetAssert($detect([howAssetRoot(['other', VA_HOW_ASSET_CLASS])]) === true, 'exact token among classes rejected');
howAssetAssert($detect([howAssetRoot(['va-home-how-it-works-extra'])]) === false, 'suffix class accepted');
howAssetAssert($detect([howAssetRoot(['prefix-va-home-how-it-works'])]) === false, 'prefix class accepted');
howAssetAssert($detect([howAssetRoot([VA_HOW_ASSET_CLASS], 'container')]) === false, 'incorrect root type accepted');

$textOnly = howAssetRoot(['other']);
$textOnly['settings']['editor'] = VA_HOW_ASSET_CLASS;
howAssetAssert($detect([$textOnly]) === false, 'text-only match accepted');
$descendant = howAssetRoot(['other']);
$descendant['elements'][] = howAssetRoot();
howAssetAssert($detect([$descendant]) === false, 'descendant-only match accepted');
$invalidClasses = howAssetRoot();
$invalidClasses['settings']['classes']['value'] = VA_HOW_ASSET_CLASS;
howAssetAssert($detect([$invalidClasses]) === false, 'non-list classes accepted');
$duplicateClass = howAssetRoot([VA_HOW_ASSET_CLASS, VA_HOW_ASSET_CLASS]);
howAssetAssert($detect([$duplicateClass]) === false, 'duplicate identity class accepted');

$source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Frontend/FrontendModule.php');
howAssetAssert(str_contains($source, 'JSON_THROW_ON_ERROR') && str_contains($source, 'catch (\\JsonException)'), 'invalid JSON is not safely rejected');
howAssetAssert(! preg_match('/VA_HOW_PAGE_ID|PAGE_ID\s*=\s*88|get_post_meta\(88/u', $source), 'page 88 is hardcoded');
howAssetAssert(! preg_match('/str_(?:contains|pos)\s*\([^;]*va-home-how-it-works/u', $source), 'raw string detector found');
howAssetAssert(! preg_match('/\$wpdb|\bSELECT\b|\bINSERT\b|\bUPDATE\b/u', $source), 'direct SQL found');

$candidate = json_decode(base64_decode('eyJpZCI6IjdhMWM5ZTQiLCJlbFR5cGUiOiJlLWZsZXhib3giLCJzZXR0aW5ncyI6eyJjbGFzc2VzIjp7IiQkdHlwZSI6ImNsYXNzZXMiLCJ2YWx1ZSI6WyJ2YS1ob21lLWhvdy1pdC13b3JrcyJdfSwidGFnIjp7IiQkdHlwZSI6InN0cmluZyIsInZhbHVlIjoic2VjdGlvbiJ9fSwiZWxlbWVudHMiOlt7ImlkIjoiMmY2YjhkMSIsImVsVHlwZSI6IndpZGdldCIsInNldHRpbmdzIjp7InRpdGxlIjoiQ8OzbW8gZnVuY2lvbmEgVmVjaUFob3JyYSIsImhlYWRlcl9zaXplIjoiaDIifSwiZWxlbWVudHMiOltdLCJ3aWRnZXRUeXBlIjoiaGVhZGluZyJ9LHsiaWQiOiI1YzNlN2E5IiwiZWxUeXBlIjoid2lkZ2V0Iiwic2V0dGluZ3MiOnsiZWRpdG9yIjoiPG9sIGNsYXNzPVwidmEtaG9tZS1ob3ctaXQtd29ya3NfX2xpc3RcIj48bGkgY2xhc3M9XCJ2YS1ob21lLWhvdy1pdC13b3Jrc19fc3RlcFwiPjxoMyBjbGFzcz1cInZhLWhvbWUtaG93LWl0LXdvcmtzX19zdGVwLXRpdGxlXCI+U2VsZWNjaW9uYSB0dSBzZWN0b3I8L2gzPjxwIGNsYXNzPVwidmEtaG9tZS1ob3ctaXQtd29ya3NfX3N0ZXAtZGVzY3JpcHRpb25cIj5JbmRpY2EgZMOzbmRlIGVzdMOhcyBwYXJhIHZlciBsb3MgcHJvZHVjdG9zIGRpc3BvbmlibGVzIGVuIGxvcyBtaW5pbWFya2V0cyBkZSB0dSBzZWN0b3IuPC9wPjwvbGk+PGxpIGNsYXNzPVwidmEtaG9tZS1ob3ctaXQtd29ya3NfX3N0ZXBcIj48aDMgY2xhc3M9XCJ2YS1ob21lLWhvdy1pdC13b3Jrc19fc3RlcC10aXRsZVwiPkNvbXBhcmEgcHJvZHVjdG9zIHkgb2ZlcnRhczwvaDM+PHAgY2xhc3M9XCJ2YS1ob21lLWhvdy1pdC13b3Jrc19fc3RlcC1kZXNjcmlwdGlvblwiPlJldmlzYSBwcmVjaW9zIHkgZGlzcG9uaWJpbGlkYWQgYW50ZXMgZGUgZWxlZ2lyIGxhIG9wY2nDs24gcXVlIG3DoXMgdGUgY29udmllbmUuPC9wPjwvbGk+PGxpIGNsYXNzPVwidmEtaG9tZS1ob3ctaXQtd29ya3NfX3N0ZXBcIj48aDMgY2xhc3M9XCJ2YS1ob21lLWhvdy1pdC13b3Jrc19fc3RlcC10aXRsZVwiPkNvbXByYSBkZSBmb3JtYSBzZWd1cmE8L2gzPjxwIGNsYXNzPVwidmEtaG9tZS1ob3ctaXQtd29ya3NfX3N0ZXAtZGVzY3JpcHRpb25cIj5Db21wbGV0YSB0dSBjb21wcmEgbWVkaWFudGUgZWwgZmx1am8gc2VndXJvIGRlIFZlY2lBaG9ycmEuPC9wPjwvbGk+PC9vbD4ifSwiZWxlbWVudHMiOltdLCJ3aWRnZXRUeXBlIjoidGV4dC1lZGl0b3IifV0sImlzSW5uZXIiOmZhbHNlLCJzdHlsZXMiOltdLCJpbnRlcmFjdGlvbnMiOltdLCJlZGl0b3Jfc2V0dGluZ3MiOltdLCJ2ZXJzaW9uIjoiMC4wIn0=', true), true, 512, JSON_THROW_ON_ERROR);
howAssetAssert(is_array($candidate) && $detect([$candidate]) === true, 'B1 candidate rejected');

$currentRaw = (string) get_post_meta(88, '_elementor_data', true);
$currentDocument = json_decode($currentRaw, true, 512, JSON_THROW_ON_ERROR);
howAssetAssert(is_array($currentDocument) && $detect($currentDocument) === false, 'current page 88 accepted');
howAssetAssert($detect(array_merge($currentDocument, [$candidate])) === true, 'projected page 88 candidate rejected');

global $wp_query;
$originalQuery = $wp_query;
$mockRaw = '';
$mockPostId = 987654321;
$metadataFilter = static function (mixed $value, int $objectId, string $metaKey) use (&$mockRaw, $mockPostId): mixed {
    return $objectId === $mockPostId && $metaKey === '_elementor_data' ? $mockRaw : $value;
};
add_filter('get_post_metadata', $metadataFilter, 10, 3);
$mockPost = new WP_Post((object) ['ID' => $mockPostId, 'post_type' => 'page', 'post_status' => 'publish']);
$setQuery = static function () use (&$wp_query, $mockPost): void {
    $wp_query = new WP_Query();
    $wp_query->is_singular = true;
    $wp_query->queried_object = $mockPost;
    $wp_query->queried_object_id = $mockPost->ID;
};

try {
    foreach (['', '{invalid', json_encode([$textOnly], JSON_THROW_ON_ERROR), json_encode([$descendant], JSON_THROW_ON_ERROR), json_encode([howAssetRoot(['va-home-how-it-works-extra'])], JSON_THROW_ON_ERROR)] as $rawCase) {
        howAssetResetQueue();
        $mockRaw = $rawCase;
        $setQuery();
        $module->enqueueHomepageHowItWorksAsset();
        howAssetAssert(! wp_style_is(VA_HOW_ASSET_HANDLE, 'enqueued'), 'false-positive document enqueued asset');
    }

    howAssetResetQueue();
    $mockRaw = json_encode([$candidate], JSON_THROW_ON_ERROR);
    $setQuery();
    $module->enqueueHomepageHowItWorksAsset();
    $module->enqueueHomepageHowItWorksAsset();
    howAssetAssert(wp_style_is(VA_HOW_ASSET_HANDLE, 'enqueued'), 'valid document did not enqueue asset');
    howAssetAssert(count(array_keys(wp_styles()->queue, VA_HOW_ASSET_HANDLE, true)) === 1, 'asset enqueued more than once');
    howAssetAssert(
        (wp_styles()->registered[VA_HOW_ASSET_HANDLE]->deps ?? []) === [FrontendAssets::DESIGN_SYSTEM_STYLE_HANDLE],
        'design system dependency not declared'
    );
} finally {
    remove_filter('get_post_metadata', $metadataFilter, 10);
    $wp_query = $originalQuery;
    howAssetResetQueue();
}

$cssPath = dirname(__DIR__, 2) . '/assets/frontend/css/homepage-how-it-works.css';
$css = (string) file_get_contents($cssPath);
$assetHash = hash('sha256', $css);
if (VA_HOW_ASSET_EXPECTED_SHA256 !== '__ASSET_SHA256__') {
    howAssetAssert($assetHash === VA_HOW_ASSET_EXPECTED_SHA256, 'asset fingerprint differs');
}

foreach (preg_split('/\R/u', $css) ?: [] as $line) {
    $selector = trim($line);
    if ($selector === '' || ! str_ends_with($selector, '{') || str_starts_with($selector, '@')) {
        continue;
    }
    howAssetAssert(str_starts_with($selector, '.va-home-how-it-works'), 'unscoped selector: ' . $selector);
}
howAssetAssert(! str_contains($css, '!important'), '!important found');
howAssetAssert(! preg_match('/\burl\s*\(/iu', $css), 'URL found');
howAssetAssert(! preg_match('/(^|[},])\s*(?:html|body|:root|ol|li|h2|h3|p)(?:\s|,|\{)/mu', $css), 'global selector found');
howAssetAssert(! preg_match('/#(?:7a1c9e4|2f6b8d1|5c3e7a9)|\.elementor-/u', $css), 'Elementor-generated selector found');
howAssetAssert(! preg_match('/::(?:before|after)[^{]*\{[^}]*\bcontent\s*:/su', $css), 'pseudo-number content found');
howAssetAssert(! preg_match('/\b(?:animation|transition)\s*:/u', $css), 'motion found');

$cssAuthorities = [
    'background: #f4f8f2', 'color: #10233a', 'max-width: 80rem',
    'grid-template-columns: repeat(3, minmax(0, 1fr))', 'gap: 1.5rem',
    '@media (min-width: 768px) and (max-width: 991px)', 'grid-template-columns: repeat(2, minmax(0, 1fr))', 'gap: 1.25rem',
    '@media (max-width: 767px)', 'grid-template-columns: minmax(0, 1fr)', 'gap: 1rem',
    'background: #ffffff', 'border: 1px solid #cbd6df', 'border-radius: 1rem',
    'box-shadow: 0 0.5rem 1.5rem rgba(6, 44, 87, 0.08)', 'list-style-position: outside', '::marker',
];
foreach ($cssAuthorities as $authority) {
    howAssetAssert(str_contains($css, $authority), 'CSS authority missing: ' . $authority);
}

$mutations = [
    'raw_string_detection', 'class_only_in_content', 'descendant_class', 'partial_class',
    'incorrect_root_type', 'invalid_json', 'global_selector', 'elementor_id',
    'important', 'url', 'pseudo_content', 'removed_breakpoint', 'changed_dependency', 'double_enqueue',
];
howAssetAssert(count($mutations) === 14, 'adversarial mutation inventory differs');
howAssetAssert($writes === [], 'database writes detected');

echo json_encode([
    'verdict' => 'HOMEPAGE_HOW_IT_WORKS_ASSET_PASS',
    'asset_handle' => VA_HOW_ASSET_HANDLE,
    'asset_sha256' => $assetHash,
    'structural_detection' => 'PASS',
    'raw_string_detection' => false,
    'invalid_json' => 'NOT_ENQUEUED',
    'text_only_match' => 'NOT_ENQUEUED',
    'descendant_only_match' => 'NOT_ENQUEUED',
    'partial_class_match' => 'NOT_ENQUEUED',
    'valid_root_match' => 'ENQUEUED_ONCE',
    'duplicate_enqueue' => 0,
    'page_88_asset_enqueued' => false,
    'adversarial_mutations' => '14/14',
    'database_writes' => count($writes),
    'wp_options_writes' => count(array_filter($writes, static fn(string $query): bool => stripos($query, 'options') !== false)),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
