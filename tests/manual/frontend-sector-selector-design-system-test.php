<?php

declare(strict_types=1);

ob_start();

function phase6Assert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @param array<string, string> $sources @return list<string> */
function phase6Validate(array $sources): array
{
    $layout = $sources['layout'];
    $script = $sources['script'];
    $assets = $sources['assets'];
    $controller = $sources['controller'];
    $sector = $sources['sector'];
    $sectorModule = $sources['sectorModule'];
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
    $exact = str_replace(["\r\n", "\r"], "\n", $exact);

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
    $require(! str_contains($layout, '<style'), 'S19_NO_PRODUCTION_CSS');
    $require(! str_contains($layout, '<script') && str_contains($script, 'function mountSectorSelector()'), 'S20_NO_PRODUCTION_JS');
    preg_match_all(
        '/public function enqueue(?:ProductOffers|Catalog|Cart|Checkout)\(\): void\s*\{(?:(?!public function).)*\$this->enqueueDesignSystem\(\);/s',
        $assets,
        $sectorSurfaceEnqueues
    );
    $require(count($sectorSurfaceEnqueues[0]) === 4, 'S21_DESIGN_ASSET_ALREADY_ENQUEUED');
    $require(substr_count($controller, '$this->views->render(\'layout\'') === 3 && substr_count($layout, 'data-va-sector-selector') === 1, 'S22_SINGLE_SELECTOR_PER_RENDER');
    $require(str_contains($config, "public const SCHEMA_VERSION = '0.28.0'") && preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE)\b/i', $layout) !== 1, 'S23_NO_SCHEMA_OR_DATA_WRITE');
    $require(str_contains($sector, '$this->zones->findActive($id)??throw new \\InvalidArgumentException'), 'S24_INVALID_OR_INACTIVE_SECTOR_REJECTED');
    $require(str_contains($sectorModule, "'error'=>['code'=>'invalid_sector'")
        && str_contains($sectorModule, ']],422)'), 'S25_INVALID_SECTOR_RESPONSE_PRESERVED');
    $require(str_contains($script, 'select.disabled=true')
        && str_contains($script, 'select.disabled=false')
        && str_contains($script, 'No fue posible cambiar el sector.'), 'S26_LOADING_ERROR_STATES_PRESERVED');
    $require(! str_contains($layout, 'data-va-catalog')
        && ! str_contains($layout, 'va-design-system va-catalog'), 'S27_CATALOG_INDEPENDENCE');

    return array_values(array_unique($errors));
}

$root = dirname(__DIR__, 2);
$paths = [
    'layout' => 'app/Modules/Frontend/Views/layout.php',
    'script' => 'assets/frontend/js/veciahorra-frontend.js',
    'assets' => 'app/Modules/Frontend/Assets/FrontendAssets.php',
    'controller' => 'app/Modules/Frontend/Controller/FrontendController.php',
    'sector' => 'app/Modules/Sectorization/CurrentSector.php',
    'sectorModule' => 'app/Modules/Sectorization/SectorizationModule.php',
    'config' => 'app/Core/Config.php',
];
$sources = [];
foreach ($paths as $key => $path) {
    $sources[$key] = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($root . '/' . $path));
}
$sources['closed'] = implode("\n", array_map(
    static fn (string $path): string => str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($root . '/' . $path)),
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
    'S24_INVALID_OR_INACTIVE_SECTOR_REJECTED' => ['sector', '$this->zones->findActive($id)??throw new \\InvalidArgumentException', '$this->zones->find($id)'],
    'S25_INVALID_SECTOR_RESPONSE_PRESERVED' => ['sectorModule', "'error'=>['code'=>'invalid_sector'", "'error'=>['code'=>'changed'"],
    'S26_LOADING_ERROR_STATES_PRESERVED' => ['script', 'select.disabled=true', 'select.disabled=false'],
    'S27_CATALOG_INDEPENDENCE' => ['layout', 'data-va-sector-selector', 'data-va-sector-selector data-va-catalog'],
];
foreach ($mutants as $expected => [$target, $from, $to]) {
    $mutated = $sources;
    $mutated[$target] = preg_replace('/' . preg_quote($from, '/') . '/', addcslashes($to, '\\$'), $sources[$target], 1) ?? '';
    $obtained = phase6Validate($mutated);
    phase6Assert(in_array($expected, $obtained, true), "Adversarial {$expected} no rechazado: " . json_encode($obtained));
    echo "PASS ADVERSARIAL expected={$expected} obtained={$expected}\n";
}

$requiredMutants = [
    'SELECTOR_ABSENT' => ['layout', 'data-va-sector-selector', 'data-va-sector-selector-missing', 'S01_ROOT_EXACT'],
    'SELECTOR_DUPLICATED' => ['layout', 'data-va-sector-selector', 'data-va-sector-selector data-va-sector-selector', 'S22_SINGLE_SELECTOR_PER_RENDER'],
    'SELECTOR_ATTRIBUTE_REMOVED' => ['layout', 'data-va-sector-selector', '', 'S01_ROOT_EXACT'],
    'DESIGN_SYSTEM_REMOVED' => ['layout', 'veciahorra-frontend va-design-system', 'veciahorra-frontend', 'S01_ROOT_EXACT'],
    'SELECTOR_AS_CATALOG_ROOT' => ['layout', 'veciahorra-frontend va-design-system', 'veciahorra-frontend va-design-system va-catalog', 'S27_CATALOG_INDEPENDENCE'],
    'SELECTOR_WITH_CATALOG_ATTRIBUTE' => ['layout', 'data-va-sector-selector', 'data-va-sector-selector data-va-catalog', 'S27_CATALOG_INDEPENDENCE'],
    'LIVE_REGION_REMOVED' => ['layout', ' aria-live="polite"', '', 'S10_LIVE_REGION_PRESERVED'],
    'ACCESSIBLE_CONTROL_REMOVED' => ['layout', 'data-va-sector-select', 'data-va-sector-control-missing', 'S07_SELECT_HOOK_PRESERVED'],
    'SECTOR_ENDPOINT_REPLACED' => ['script', "request('POST','sector/current/'", "request('POST','sector/changed/'", 'S15_ENDPOINTS_UNCHANGED'],
    'INVALID_SECTOR_VALIDATION_REMOVED' => ['sector', '$this->zones->findActive($id)??throw new \\InvalidArgumentException', '$this->zones->find($id)', 'S24_INVALID_OR_INACTIVE_SECTOR_REJECTED'],
];
foreach ($requiredMutants as $name => [$target, $from, $to, $expected]) {
    $mutated = $sources;
    $mutated[$target] = preg_replace('/' . preg_quote($from, '/') . '/', addcslashes($to, '\\$'), $sources[$target], 1) ?? '';
    $obtained = phase6Validate($mutated);
    phase6Assert(in_array($expected, $obtained, true), "Adversarial {$name} no rechazado: " . json_encode($obtained));
    echo "PASS ADVERSARIAL required={$name} obtained={$expected}\n";
}

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

echo 'PASS frontend-sector-selector-design-system-test adversarials=37' . PHP_EOL;
ob_end_flush();
