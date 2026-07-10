<?php

declare(strict_types=1);

use VeciAhorra\Core\Container;
use VeciAhorra\Modules\Frontend\Assets\FrontendAssets;
use VeciAhorra\Modules\Frontend\Controller\FrontendController;
use VeciAhorra\Modules\Frontend\FrontendModule;
use VeciAhorra\Modules\Frontend\Support\ViewRenderer;

require_once dirname(__DIR__, 5) . '/wp-load.php';

if (! function_exists('set_current_screen')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
    require_once ABSPATH . 'wp-admin/includes/screen.php';
}

function assertFrontendFoundation(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertFrontendFoundationSame(mixed $expected, mixed $actual): void
{
    assertFrontendFoundation(
        $expected === $actual,
        sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        )
    );
}

$container = new Container();
$module = $container->make(FrontendModule::class);
assertFrontendFoundation(
    $module instanceof FrontendModule,
    'Container no pudo instanciar FrontendModule.'
);

$assets = $container->make(FrontendAssets::class);
$controller = $container->make(FrontendController::class);
$testModule = new FrontendModule($assets, $controller);
$hookBefore = has_action('wp_enqueue_scripts', [$assets, 'registerAssets']);
$testModule->register();
$hookAfterFirst = has_action('wp_enqueue_scripts', [$assets, 'registerAssets']);
$testModule->register();
$hookAfterSecond = has_action('wp_enqueue_scripts', [$assets, 'registerAssets']);
assertFrontendFoundationSame(false, $hookBefore);
assertFrontendFoundation($hookAfterFirst !== false, 'No registro hook de assets.');
assertFrontendFoundationSame($hookAfterFirst, $hookAfterSecond);
assertFrontendFoundation(
    shortcode_exists(FrontendController::SHORTCODE),
    'No registro el shortcode tecnico.'
);
$shortcodeCallback = $GLOBALS['shortcode_tags'][FrontendController::SHORTCODE]
    ?? null;
$testModule->register();
assertFrontendFoundationSame(
    $shortcodeCallback,
    $GLOBALS['shortcode_tags'][FrontendController::SHORTCODE] ?? null
);

set_current_screen('dashboard');
assertFrontendFoundation(is_admin(), 'No se activo contexto administrativo.');
$adminAssets = new FrontendAssets();
$adminAssets->registerAssets();
assertFrontendFoundation(
    ! wp_style_is(FrontendAssets::STYLE_HANDLE, 'registered'),
    'CSS fue registrado en administracion.'
);
assertFrontendFoundation(
    ! wp_script_is(FrontendAssets::SCRIPT_HANDLE, 'registered'),
    'JavaScript fue registrado en administracion.'
);
assertFrontendFoundationSame(
    '',
    $controller->renderPlaceholder()
);
set_current_screen('front');
assertFrontendFoundation(! is_admin(), 'No se restauro contexto frontend.');

$assets->registerAssets();
assertFrontendFoundation(
    wp_style_is(FrontendAssets::STYLE_HANDLE, 'registered'),
    'No registro el CSS frontend.'
);
assertFrontendFoundation(
    wp_script_is(FrontendAssets::SCRIPT_HANDLE, 'registered'),
    'No registro el JavaScript frontend.'
);
assertFrontendFoundationSame(
    FrontendAssets::STYLE_HANDLE,
    FrontendAssets::SCRIPT_HANDLE
);

wp_set_current_user(0);
$config = $assets->configuration();
foreach ([
    'restUrl', 'restNamespace', 'nonce', 'currentUser',
    'locale', 'currency', 'pages',
] as $key) {
    assertFrontendFoundation(
        array_key_exists($key, $config),
        "Falta configuracion {$key}."
    );
}
assertFrontendFoundationSame(0, $config['currentUser']['id']);
assertFrontendFoundationSame(false, $config['currentUser']['loggedIn']);
assertFrontendFoundationSame('', $config['nonce']);

$renderer = new ViewRenderer();
$unsafeRejected = false;

try {
    $renderer->render('../../veciahorra.php');
} catch (InvalidArgumentException) {
    $unsafeRejected = true;
}

assertFrontendFoundation($unsafeRejected, 'Renderer permite path traversal.');

$unsafe = '<script>alert(1)</script>';
$card = $renderer->render('card', [
    'title' => $unsafe,
    'text' => $unsafe,
]);
assertFrontendFoundation(
    ! str_contains($card, '<script>'),
    'Card no escapa contenido.'
);

ob_start();
$html = $controller->renderPlaceholder();
$directOutput = (string) ob_get_clean();
assertFrontendFoundationSame('', $directOutput);
assertFrontendFoundation(
    str_contains($html, 'class="veciahorra-frontend"'),
    'Placeholder no contiene raiz frontend.'
);
foreach (['va-card', 'va-alert', 'va-empty-state', 'va-loader', 'va-button'] as $class) {
    assertFrontendFoundation(
        str_contains($html, $class),
        "No se renderizo componente {$class}."
    );
}
$secondHtml = $controller->renderPlaceholder();
assertFrontendFoundation(
    str_contains($html, 'id="va-frontend-1"')
        && str_contains($secondHtml, 'id="va-frontend-2"'),
    'Instancias del shortcode duplican IDs.'
);
assertFrontendFoundation(
    ! str_contains($secondHtml, 'id="va-frontend-1"'),
    'Segunda instancia reutilizo IDs ARIA.'
);
assertFrontendFoundation(
    wp_style_is(FrontendAssets::STYLE_HANDLE, 'enqueued'),
    'Placeholder no encolo CSS.'
);
assertFrontendFoundation(
    wp_script_is(FrontendAssets::SCRIPT_HANDLE, 'enqueued'),
    'Placeholder no encolo JavaScript.'
);
global $wp_scripts;
$inlineConfiguration = $wp_scripts->get_data(
    FrontendAssets::SCRIPT_HANDLE,
    'before'
);
assertFrontendFoundationSame(
    1,
    substr_count(
        implode("\n", (array) $inlineConfiguration),
        'window.VeciAhorra ='
    )
);

$frontendRoot = dirname(__DIR__, 2) . '/app/Modules/Frontend';
$moduleFiles = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($frontendRoot)
);
$phpSource = '';

foreach ($moduleFiles as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $phpSource .= (string) file_get_contents($file->getPathname());
    }
}

assertFrontendFoundation(
    ! str_contains($phpSource, 'register_rest_route'),
    'Frontend registro un endpoint REST.'
);
assertFrontendFoundation(
    ! preg_match('/\b(CREATE TABLE|dbDelta)\b/i', $phpSource),
    'Frontend contiene creacion de tablas.'
);

$javascript = (string) file_get_contents(
    dirname(__DIR__, 2) . '/assets/frontend/js/veciahorra-frontend.js'
);
assertFrontendFoundationSame(1, substr_count($javascript, 'window.fetch('));
assertFrontendFoundation(
    ! preg_match('/(^|[;{}]\s*)request\s*\(/m', $javascript),
    'JavaScript ejecuta request automaticamente.'
);
assertFrontendFoundation(
    ! str_contains($javascript, 'localStorage'),
    'JavaScript usa localStorage.'
);

$application = (string) file_get_contents(
    dirname(__DIR__, 2) . '/app/Core/Application.php'
);
assertFrontendFoundationSame(
    1,
    substr_count($application, 'FrontendModule::class')
);
assertFrontendFoundationSame(
    1,
    substr_count($application, '$frontendModule->register()')
);

echo "PASS frontend-foundation-test\n";
