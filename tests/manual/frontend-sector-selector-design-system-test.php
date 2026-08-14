<?php

declare(strict_types=1);

ob_start();

const VA_PHASE6_BASELINE = '846fe4ddf71a15bb5df1c36c6bda9ff6959e5f1b';
const VA_PHASE6_PATHS = [
    'app/Modules/Frontend/Views/layout.php',
    'tests/manual/frontend-sector-selector-design-system-test.php',
    'tests/manual/sector-selector-design-system-browser-test.py',
];

function phase6Assert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @param list<string> $arguments */
function phase6Git(array $arguments): string
{
    $pipes = [];
    $process = proc_open(
        ['git', '-C', dirname(__DIR__, 2), ...$arguments],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );
    phase6Assert(is_resource($process), 'Git no pudo iniciarse.');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    phase6Assert($exit === 0, 'Git fallo (' . $exit . '): ' . substr(trim((string) $stderr), 0, 300));

    return rtrim(str_replace(["\r\n", "\r"], "\n", (string) $stdout), "\n");
}

/** @return list<string> */
function phase6Lines(string $value): array
{
    return $value === '' ? [] : array_values(array_filter(explode("\n", $value), static fn (string $line): bool => $line !== ''));
}

function phase6GitGuard(): void
{
    $head = phase6Git(['rev-parse', 'HEAD']);
    $staged = phase6Lines(phase6Git(['diff', '--cached', '--name-only']));
    sort($staged);
    $tracked = phase6Lines(phase6Git(['status', '--short', '--untracked-files=no']));
    $expected = VA_PHASE6_PATHS;
    sort($expected);

    if ($head === VA_PHASE6_BASELINE) {
        $paths = array_map(static fn (string $line): string => substr($line, 3), $tracked);
        sort($paths);
        phase6Assert($paths === $expected, 'S24_ALLOWLIST_EXACT: ' . json_encode($paths));
        phase6Assert($staged === [
            'tests/manual/frontend-sector-selector-design-system-test.php',
            'tests/manual/sector-selector-design-system-browser-test.py',
        ], 'Staging precommit incorrecto.');
        return;
    }

    phase6Assert(phase6Git(['rev-parse', 'HEAD^']) === VA_PHASE6_BASELINE, 'Parent postcommit incorrecto.');
    phase6Assert($tracked === [] && $staged === [], 'Postcommit no esta limpio.');
    $commitPaths = phase6Lines(phase6Git(['diff-tree', '--no-commit-id', '--name-only', '-r', 'HEAD']));
    sort($commitPaths);
    phase6Assert($commitPaths === $expected, 'S24_ALLOWLIST_EXACT postcommit.');
}

/** @param array<string, string> $sources @return list<string> */
function phase6Validate(array $sources, bool $scopeOk = true): array
{
    $layout = $sources['layout'];
    $script = $sources['script'];
    $assets = $sources['assets'];
    $controller = $sources['controller'];
    $sector = $sources['sector'];
    $config = $sources['config'];
    $closed = $sources['closed'];
    $errors = [];
    $require = static function (bool $condition, string $code) use (&$errors): void {
        if (! $condition) {
            $errors[] = $code;
        }
    };
    $exact = <<<'HTML'
    <section
        class="veciahorra-frontend va-design-system"
        data-va-sector-selector
        aria-label="Sector de compra">
        <div class="va-sector-selector va-card va-field-group">
            <label class="va-field">Estás comprando en:
                <select data-va-sector-select>
                    <option value="">Selecciona un sector</option>
                </select>
            </label>
            <span data-va-sector-message aria-live="polite"></span>
        </div>
    </section>
HTML;

    $require(str_contains($layout, $exact), 'S01_ROOT_EXACT');
    $require(substr_count($layout, 'va-design-system') === 1, 'S02_OPT_IN_ONCE');
    $require(str_contains($layout, 'class="va-sector-selector va-card va-field-group"'), 'S03_SELECTOR_WRAPPER');
    $require(str_contains($layout, 'va-sector-selector va-card'), 'S04_CARD_REQUIRED');
    $require(str_contains($layout, 'va-card va-field-group'), 'S05_FIELD_GROUP_REQUIRED');
    $require(str_contains($layout, '<label class="va-field">Estás comprando en:'), 'S06_LABEL_FIELD_REQUIRED');
    $require(substr_count($layout, 'data-va-sector-select') === 2, 'S07_SELECT_HOOK_PRESERVED');
    $require(substr_count($layout, 'data-va-sector-message') === 1, 'S08_MESSAGE_HOOK_PRESERVED');
    $require(str_contains($layout, "data-va-sector-selector\n        aria-label=\"Sector de compra\""), 'S09_SECTION_ARIA_PRESERVED');
    $require(str_contains($layout, 'data-va-sector-message aria-live="polite"'), 'S10_LIVE_REGION_PRESERVED');
    $require(str_contains($layout, '<option value="">Selecciona un sector</option>'), 'S11_INITIAL_OPTION_PRESERVED');
    $require(substr_count($layout, 'Estás comprando en:') === 1, 'S12_TEXT_PRESERVED');
    $require(str_contains($layout, 'class="veciahorra-frontend" data-va-frontend') && ! str_contains($layout, 'class="veciahorra-frontend va-design-system" data-va-frontend'), 'S13_NO_OUTER_LAYOUT_OPT_IN');
    $require(str_contains($closed, 'veciahorra-frontend va-design-system va-catalog')
        && str_contains($closed, 'veciahorra-frontend va-design-system va-product-detail')
        && str_contains($closed, 'veciahorra-frontend va-design-system va-public-cart')
        && str_contains($closed, 'veciahorra-frontend va-design-system va-checkout'), 'S14_CLOSED_ROOTS_UNCHANGED');
    $require(str_contains($script, "request('GET','sectors')")
        && str_contains($script, "request('GET','sector/current')")
        && str_contains($script, "request('POST','sector/current/'"), 'S15_ENDPOINTS_UNCHANGED');
    $require(str_contains($script, 'window.location.reload()'), 'S16_POST_RELOAD_UNCHANGED');
    $require(str_contains($sector, "private const SESSION='veciahorra_service_zone_id'") && str_contains($sector, 'Session::put(self::SESSION,$id)'), 'S17_SESSION_PERSISTENCE_UNCHANGED');
    $require(str_contains($sector, "private const META='_veciahorra_service_zone_id'") && str_contains($sector, 'update_user_meta(get_current_user_id(),self::META,$id)'), 'S18_USER_META_UNCHANGED');
    $require(! str_contains($layout, '<style') && $scopeOk, 'S19_NO_PRODUCTION_CSS');
    $require(! str_contains($layout, '<script') && str_contains($script, 'function mountSectorSelector()'), 'S20_NO_PRODUCTION_JS');
    $require(substr_count($assets, '$this->enqueueDesignSystem();') === 4, 'S21_DESIGN_ASSET_ALREADY_ENQUEUED');
    $require(substr_count($controller, '$this->views->render(\'layout\'') === 3 && substr_count($layout, 'data-va-sector-selector') === 1, 'S22_SINGLE_SELECTOR_PER_RENDER');
    $require(str_contains($config, "public const SCHEMA_VERSION = '0.28.0'") && preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE)\b/i', $layout) !== 1, 'S23_NO_SCHEMA_OR_DATA_WRITE');
    $require($scopeOk, 'S24_ALLOWLIST_EXACT');

    return array_values(array_unique($errors));
}

phase6GitGuard();
$root = dirname(__DIR__, 2);
$paths = [
    'layout' => 'app/Modules/Frontend/Views/layout.php',
    'script' => 'assets/frontend/js/veciahorra-frontend.js',
    'assets' => 'app/Modules/Frontend/Assets/FrontendAssets.php',
    'controller' => 'app/Modules/Frontend/Controller/FrontendController.php',
    'sector' => 'app/Modules/Sectorization/CurrentSector.php',
    'config' => 'app/Core/Config.php',
];
$sources = [];
foreach ($paths as $key => $path) {
    $sources[$key] = (string) file_get_contents($root . '/' . $path);
}
$sources['closed'] = implode("\n", array_map(
    static fn (string $path): string => (string) file_get_contents($root . '/' . $path),
    [
        'app/Modules/Frontend/Views/catalog.php',
        'app/Modules/Frontend/Views/product-detail.php',
        'app/Modules/Frontend/Views/cart.php',
        'app/Modules/Frontend/Views/checkout.php',
    ]
));
phase6Assert(phase6Validate($sources) === [], 'Contrato productivo invalido: ' . json_encode(phase6Validate($sources)));

$mutants = [
    'S01_ROOT_EXACT' => ['layout', 'class="veciahorra-frontend va-design-system"', 'class="va-design-system"'],
    'S02_OPT_IN_ONCE' => ['layout', 'data-va-sector-selector', 'va-design-system data-va-sector-selector'],
    'S03_SELECTOR_WRAPPER' => ['layout', 'va-sector-selector va-card va-field-group', 'va-sector-selector'],
    'S04_CARD_REQUIRED' => ['layout', 'va-sector-selector va-card', 'va-sector-selector no-card'],
    'S05_FIELD_GROUP_REQUIRED' => ['layout', 'va-card va-field-group', 'va-card'],
    'S06_LABEL_FIELD_REQUIRED' => ['layout', '<label class="va-field">', '<label>'],
    'S07_SELECT_HOOK_PRESERVED' => ['layout', 'data-va-sector-select>', 'data-va-zone-select>'],
    'S08_MESSAGE_HOOK_PRESERVED' => ['layout', 'data-va-sector-message', 'data-va-zone-message'],
    'S09_SECTION_ARIA_PRESERVED' => ['layout', 'aria-label="Sector de compra"', 'aria-label="Zona"'],
    'S10_LIVE_REGION_PRESERVED' => ['layout', 'aria-live="polite"', 'aria-live="off"'],
    'S11_INITIAL_OPTION_PRESERVED' => ['layout', 'Selecciona un sector', 'Elige'],
    'S12_TEXT_PRESERVED' => ['layout', 'Estás comprando en:', 'Compra en:'],
    'S13_NO_OUTER_LAYOUT_OPT_IN' => ['layout', 'class="veciahorra-frontend" data-va-frontend', 'class="veciahorra-frontend va-design-system" data-va-frontend'],
    'S14_CLOSED_ROOTS_UNCHANGED' => ['closed', 'va-design-system va-public-cart', 'va-public-cart'],
    'S15_ENDPOINTS_UNCHANGED' => ['script', "request('GET','sectors')", "request('GET','zones')"],
    'S16_POST_RELOAD_UNCHANGED' => ['script', 'window.location.reload()', 'window.location.assign(window.location.href)'],
    'S17_SESSION_PERSISTENCE_UNCHANGED' => ['sector', "private const SESSION='veciahorra_service_zone_id'", "private const SESSION='changed'"],
    'S18_USER_META_UNCHANGED' => ['sector', "private const META='_veciahorra_service_zone_id'", "private const META='_changed'"],
    'S19_NO_PRODUCTION_CSS' => ['layout', '</section>', '<style>.x{}</style></section>'],
    'S20_NO_PRODUCTION_JS' => ['script', 'function mountSectorSelector()', 'function changedSelector()'],
    'S21_DESIGN_ASSET_ALREADY_ENQUEUED' => ['assets', '$this->enqueueDesignSystem();', '$this->enqueue();'],
    'S22_SINGLE_SELECTOR_PER_RENDER' => ['layout', 'data-va-sector-selector', 'data-va-sector-selector data-va-sector-selector-copy'],
    'S23_NO_SCHEMA_OR_DATA_WRITE' => ['config', "SCHEMA_VERSION = '0.28.0'", "SCHEMA_VERSION = '0.29.0'"],
];
foreach ($mutants as $expected => [$target, $from, $to]) {
    $mutated = $sources;
    $mutated[$target] = preg_replace('/' . preg_quote($from, '/') . '/', addcslashes($to, '\\$'), $sources[$target], 1) ?? '';
    $obtained = phase6Validate($mutated);
    phase6Assert(in_array($expected, $obtained, true), "Adversarial {$expected} no rechazado: " . json_encode($obtained));
    echo "PASS ADVERSARIAL expected={$expected} obtained={$expected}\n";
}
$obtained = phase6Validate($sources, false);
phase6Assert(in_array('S24_ALLOWLIST_EXACT', $obtained, true), 'Adversarial S24_ALLOWLIST_EXACT no rechazado.');
echo "PASS ADVERSARIAL expected=S24_ALLOWLIST_EXACT obtained=S24_ALLOWLIST_EXACT\n";

session_save_path(sys_get_temp_dir());
require_once dirname(__DIR__, 5) . '/wp-load.php';
try {
    foreach (['[veciahorra_frontend]', '[veciahorra_frontend product_id="1"]', '[veciahorra_cart]', '[veciahorra_checkout]'] as $shortcode) {
        $html = do_shortcode($shortcode);
        phase6Assert(substr_count($html, 'data-va-sector-selector') === 1, 'Render sin selector unico: ' . $shortcode);
        phase6Assert(substr_count($html, 'class="veciahorra-frontend va-design-system"') >= 1, 'Render sin opt-in sectorial: ' . $shortcode);
    }
    phase6Assert(wp_style_is('veciahorra-design-system', 'enqueued'), 'Design system no encolado en runtime.');
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        unset($_SESSION['veciahorra_service_zone_id'], $_SESSION['veciahorra_cart_session']);
        $_SESSION = [];
        session_destroy();
    }
}

echo 'PASS frontend-sector-selector-design-system-test adversarials=24' . PHP_EOL;
ob_end_flush();
