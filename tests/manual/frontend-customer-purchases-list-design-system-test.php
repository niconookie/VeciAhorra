<?php

declare(strict_types=1);

ob_start();

const VA_PHASE7_BASELINE = '95ed9d7266bc9f517931a794baac88d28285b98c';
const VA_PHASE7_PATHS = [
    'app/Modules/Frontend/Assets/FrontendAssets.php',
    'app/Modules/Frontend/Views/customer-panel.php',
    'assets/frontend/js/customer-panel.js',
    'tests/manual/frontend-customer-purchases-list-design-system-test.php',
    'tests/manual/customer-purchases-list-design-system-browser-test.py',
];

function phase7Assert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @param list<string> $arguments */
function phase7Git(array $arguments): string
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
    phase7Assert(is_resource($process), 'Git no pudo iniciarse.');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    phase7Assert($exit === 0, 'Git fallo: ' . substr(trim((string) $stderr), 0, 300));

    return rtrim(str_replace(["\r\n", "\r"], "\n", (string) $stdout), "\n");
}

/** @return list<string> */
function phase7Lines(string $value): array
{
    return $value === '' ? [] : array_values(array_filter(explode("\n", $value)));
}

function phase7Method(string $source, string $name): string
{
    phase7Assert(preg_match('/(?:public|private) function ' . preg_quote($name, '/') . '\s*\([^)]*\)\s*:\s*void\s*\{/', $source, $match, PREG_OFFSET_CAPTURE) === 1, "Metodo {$name} ausente.");
    $start = $match[0][1];
    $brace = strpos($source, '{', $start);
    $depth = 0;
    for ($index = $brace, $length = strlen($source); $index < $length; $index++) {
        if ($source[$index] === '{') {
            $depth++;
        } elseif ($source[$index] === '}' && --$depth === 0) {
            return substr($source, $start, $index - $start + 1);
        }
    }
    throw new RuntimeException("Metodo {$name} incompleto.");
}

function phase7JsFunction(string $source, string $name): string
{
    phase7Assert(preg_match('/function ' . preg_quote($name, '/') . '\s*\([^)]*\)\s*\{/', $source, $match, PREG_OFFSET_CAPTURE) === 1, "Funcion JS {$name} ausente.");
    $start = $match[0][1];
    $brace = strpos($source, '{', $start);
    $depth = 0;
    for ($index = $brace, $length = strlen($source); $index < $length; $index++) {
        if ($source[$index] === '{') {
            $depth++;
        } elseif ($source[$index] === '}' && --$depth === 0) {
            return substr($source, $start, $index - $start + 1);
        }
    }
    throw new RuntimeException("Funcion JS {$name} incompleta.");
}

/** @param array<string,string> $sources @return list<string> */
function phase7Validate(array $sources, bool $scopeOk = true): array
{
    $view = $sources['view'];
    $js = $sources['js'];
    $assets = $sources['assets'];
    $css = $sources['css'];
    $schema = $sources['schema'];
    $customer = phase7Method($assets, 'enqueueCustomerPanel');
    $errors = [];
    $need = static function (bool $condition, string $code) use (&$errors): void {
        if (! $condition) {
            $errors[] = $code;
        }
    };

    $need(str_contains($view, 'class="veciahorra-frontend va-design-system va-customer-panel__status va-loader"'), 'P01_ROOT_MISSING');
    $need(! str_contains($view, 'class="veciahorra-frontend va-design-system va-customer-panel"'), 'P02_ROOT_ON_PANEL');
    $need(! str_contains($view, 'va-design-system va-customer-panel__content'), 'P03_ROOT_ON_SHARED_CONTENT');
    $need(! preg_match('/<header[^>]*va-design-system/', $view), 'P04_ROOT_ON_HEADER');
    $need(! str_contains(phase7JsFunction($js, 'renderDetailLoading'), 'va-design-system'), 'P05_ROOT_IN_DETAIL_LOADING');
    $need(! str_contains(phase7JsFunction($js, 'renderDetail'), 'listSurface('), 'P06_ROOT_IN_DETAIL_SUCCESS');
    $need(! str_contains(phase7JsFunction($js, 'renderDetailRecoverableError'), 'listSurface('), 'P07_ROOT_IN_DETAIL_ERROR');
    $need(! str_contains(phase7JsFunction($js, 'renderDetailNotFound'), 'listSurface('), 'P08_ROOT_IN_DETAIL_NOT_FOUND');
    $need(str_contains(phase7JsFunction($js, 'renderList'), 'surface.append(heading, list);') && str_contains(phase7JsFunction($js, 'renderList'), 'replaceChildren(surface)'), 'P09_LIST_SURFACE_MISSING_RESULTS');
    $need(str_contains(phase7JsFunction($js, 'renderEmpty'), 'surface.append(empty);') && str_contains(phase7JsFunction($js, 'renderEmpty'), 'replaceChildren(surface)'), 'P10_LIST_SURFACE_MISSING_EMPTY');
    $need(str_contains(phase7JsFunction($js, 'renderError'), 'surface.append(element(') && str_contains(phase7JsFunction($js, 'renderError'), 'replaceChildren(surface)'), 'P11_LIST_SURFACE_MISSING_ERROR');
    $need(str_contains($js, 'state.root.content.replaceChildren.apply(state.root.content, state.listSnapshot)'), 'P12_LIST_SURFACE_MISSING_SNAPSHOT');
    $need(substr_count($js, "element('section', 'veciahorra-frontend va-design-system va-customer-panel__list-surface')") === 1, 'P13_LIST_SURFACE_DUPLICATED');
    $need(substr_count($view, 'veciahorra-frontend va-design-system va-customer-panel__status va-loader') === 1, 'P14_LOADER_ROOT_MISSING');
    $need(str_contains($js, "element('article', 'va-customer-panel__purchase va-card')"), 'P15_CARD_CLASS_REMOVED');
    $need(str_contains($js, "element('a', 'va-customer-panel__purchase-link')"), 'P16_LINK_CONVERTED_TO_BUTTON');
    $need(str_contains($js, "element('span', 'va-customer-panel__purchase-action')") && ! str_contains($js, "va-customer-panel__purchase-action va-button"), 'P17_ACTION_SPAN_CONVERTED_TO_CONTROL');
    $need(! preg_match('/checkout_public_id[^;\n]*(?:slice|substring|substr)\s*\(/', $js), 'P18_PUBLIC_ID_TRUNCATED');
    $need(str_contains($js, "url.searchParams.set('compra', publicId)"), 'P19_DETAIL_QUERY_NOT_COMPRA');
    $need(! preg_match('/["\'](?:order_id|checkout_id|payment_id)["\']\s*[:=]/', $js), 'P20_INTERNAL_ID_EXPOSED');
    $need(! preg_match('/api\.(?:post|put|patch|delete)\s*\(/i', $js), 'P21_MUTATION_METHOD_ADDED');
    $need(! preg_match('/(?:user_id|customer_id)\s*[:=]/', $js), 'P22_OWNERSHIP_OVERRIDE_ACCEPTED');
    $need(! preg_match('/(?:provider|prestador|rut|contact_email)/i', $js), 'P23_PROVIDER_IDENTITY_EXPOSED');
    $need(str_contains($js, "var ENDPOINT = 'customer-panel/purchases';") && str_contains($js, 'var DETAIL_ENDPOINT = ENDPOINT + \'/\';'), 'P24_REST_ROUTE_CHANGED');
    $need(str_contains($assets, "CUSTOMER_PANEL_STYLE_HANDLE = 'veciahorra-customer-panel'") && str_contains($assets, "CUSTOMER_PANEL_SCRIPT_HANDLE = 'veciahorra-customer-panel'"), 'P25_ASSET_HANDLE_CHANGED');
    $need(str_contains($assets, '[self::STYLE_HANDLE],') && str_contains($assets, '[self::SCRIPT_HANDLE],'), 'P26_ASSET_DEPENDENCY_CHANGED');
    $need(substr_count($customer, '$this->enqueueDesignSystem();') === 1 && strpos($customer, '$this->enqueueDesignSystem();') < strpos($customer, 'if ($authenticated)'), 'P27_DESIGN_SYSTEM_NOT_ENQUEUED');
    $need(substr_count($assets, '$this->enqueueDesignSystem();') === 5, 'P28_DESIGN_SYSTEM_ENQUEUED_GLOBALLY');
    $need($sources['old_consumers'] === $sources['baseline_consumers'], 'P29_EXISTING_CONSUMER_CHANGED');
    $need($css === $sources['baseline_css'] && $scopeOk, 'P30_CSS_BRIDGE_ADDED');
    $need(str_contains($schema, "SCHEMA_VERSION = '0.28.0'") && ! preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE)\b/i', $view . $js), 'P31_NEW_SCHEMA_OR_DATA_WRITE');
    $need(str_contains($js, 'function isCurrentRequest(state, request)') && substr_count($js, 'isCurrentRequest(state, request)') >= 3, 'P32_STALE_RESPONSE_RENDERED');
    $need(str_contains($js, 'state.activeController.abort()'), 'P33_ABORT_GUARD_REMOVED');
    $need(str_contains($js, 'state.originLink.focus()') && str_contains($js, 'window.scrollTo(0, state.scrollPosition)'), 'P34_FOCUS_RESTORE_REMOVED');
    $need(str_contains($view, 'data-va-customer-panel-announcer') && str_contains($view, 'aria-live="polite"'), 'P35_LIVE_REGION_REMOVED');
    $need(str_contains($view, "<?php echo \$loggedIn ? ' data-va-customer-panel-mount' : ''; ?>"), 'P36_VISITOR_PRIVATE_MOUNT');
    $need(! str_contains($sources['browser'], 'DETAIL_ENDPOINT_INTERCEPT_ALLOWED'), 'P37_DETAIL_ENDPOINT_INTERCEPTED');
    $need(! str_contains($sources['browser'], 'NON_GET_INTERCEPT_ALLOWED'), 'P38_NON_GET_INTERCEPTED');
    $need(! str_contains($sources['browser'], 'ASSET_INTERCEPT_ALLOWED'), 'P39_DOCUMENT_OR_ASSET_INTERCEPTED');
    $need(str_contains($sources['browser'], 'def delete_user(user):') && substr_count($sources['browser'], 'delete_user(user)') >= 2 && str_contains($sources['browser'], 'auth_profile.cleanup()'), 'P40_CLEANUP_RESIDUE');

    return array_values(array_unique($errors));
}

function phase7GitGuard(): void
{
    $head = phase7Git(['rev-parse', 'HEAD']);
    $tracked = phase7Lines(phase7Git(['status', '--short', '--untracked-files=no']));
    $paths = array_map(static fn (string $line): string => substr($line, 3), $tracked);
    $staged = phase7Lines(phase7Git(['diff', '--cached', '--name-only']));
    sort($paths);
    sort($staged);
    $expected = VA_PHASE7_PATHS;
    sort($expected);
    if ($head === VA_PHASE7_BASELINE) {
        phase7Assert($paths === $expected, 'P40_ALLOWLIST: ' . json_encode($paths));
        phase7Assert($staged === [
            'tests/manual/customer-purchases-list-design-system-browser-test.py',
            'tests/manual/frontend-customer-purchases-list-design-system-test.php',
        ], 'Staging precommit incorrecto: ' . json_encode($staged));
        return;
    }
    phase7Assert(phase7Git(['rev-parse', 'HEAD^']) === VA_PHASE7_BASELINE, 'Parent postcommit incorrecto.');
    phase7Assert($tracked === [] && $staged === [], 'Postcommit no limpio.');
    $commit = phase7Lines(phase7Git(['diff-tree', '--no-commit-id', '--name-only', '-r', 'HEAD']));
    sort($commit);
    phase7Assert($commit === $expected, 'Allowlist postcommit incorrecta.');
}

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$assets = $read('app/Modules/Frontend/Assets/FrontendAssets.php');
$consumerNames = ['enqueueProductOffers', 'enqueueCatalog', 'enqueueCart', 'enqueueCheckout', 'enqueueDesignSystem', 'registerAssets'];
$consumers = '';
$baselineConsumers = '';
foreach ($consumerNames as $name) {
    $consumers .= phase7Method($assets, $name);
    $baselineConsumers .= phase7Method(phase7Git(['show', VA_PHASE7_BASELINE . ':app/Modules/Frontend/Assets/FrontendAssets.php']), $name);
}
$sources = [
    'assets' => $assets,
    'view' => $read('app/Modules/Frontend/Views/customer-panel.php'),
    'js' => $read('assets/frontend/js/customer-panel.js'),
    'css' => $read('assets/frontend/css/veciahorra-design-system.css'),
    'baseline_css' => $read('assets/frontend/css/veciahorra-design-system.css'),
    'schema' => $read('app/Core/Config.php'),
    'browser' => $read('tests/manual/customer-purchases-list-design-system-browser-test.py'),
    'old_consumers' => $consumers,
    'baseline_consumers' => $baselineConsumers,
];

phase7GitGuard();
$actual = phase7Validate($sources);
phase7Assert($actual === [], 'Contrato invalido: ' . json_encode($actual));

$codes = [];
for ($number = 1; $number <= 40; $number++) {
    $prefix = 'P' . str_pad((string) $number, 2, '0', STR_PAD_LEFT) . '_';
    $code = array_values(array_filter([
        'P01_ROOT_MISSING','P02_ROOT_ON_PANEL','P03_ROOT_ON_SHARED_CONTENT','P04_ROOT_ON_HEADER','P05_ROOT_IN_DETAIL_LOADING','P06_ROOT_IN_DETAIL_SUCCESS','P07_ROOT_IN_DETAIL_ERROR','P08_ROOT_IN_DETAIL_NOT_FOUND','P09_LIST_SURFACE_MISSING_RESULTS','P10_LIST_SURFACE_MISSING_EMPTY','P11_LIST_SURFACE_MISSING_ERROR','P12_LIST_SURFACE_MISSING_SNAPSHOT','P13_LIST_SURFACE_DUPLICATED','P14_LOADER_ROOT_MISSING','P15_CARD_CLASS_REMOVED','P16_LINK_CONVERTED_TO_BUTTON','P17_ACTION_SPAN_CONVERTED_TO_CONTROL','P18_PUBLIC_ID_TRUNCATED','P19_DETAIL_QUERY_NOT_COMPRA','P20_INTERNAL_ID_EXPOSED','P21_MUTATION_METHOD_ADDED','P22_OWNERSHIP_OVERRIDE_ACCEPTED','P23_PROVIDER_IDENTITY_EXPOSED','P24_REST_ROUTE_CHANGED','P25_ASSET_HANDLE_CHANGED','P26_ASSET_DEPENDENCY_CHANGED','P27_DESIGN_SYSTEM_NOT_ENQUEUED','P28_DESIGN_SYSTEM_ENQUEUED_GLOBALLY','P29_EXISTING_CONSUMER_CHANGED','P30_CSS_BRIDGE_ADDED','P31_NEW_SCHEMA_OR_DATA_WRITE','P32_STALE_RESPONSE_RENDERED','P33_ABORT_GUARD_REMOVED','P34_FOCUS_RESTORE_REMOVED','P35_LIVE_REGION_REMOVED','P36_VISITOR_PRIVATE_MOUNT','P37_DETAIL_ENDPOINT_INTERCEPTED','P38_NON_GET_INTERCEPTED','P39_DOCUMENT_OR_ASSET_INTERCEPTED','P40_CLEANUP_RESIDUE',
    ], static fn (string $candidate): bool => str_starts_with($candidate, $prefix)))[0];
    $codes[] = $code;
}

$mutations = [
    ['view', 'veciahorra-frontend va-design-system va-customer-panel__status va-loader', 'va-customer-panel__status va-loader'],
    ['view', 'veciahorra-frontend va-customer-panel', 'veciahorra-frontend va-design-system va-customer-panel'],
    ['view', 'va-customer-panel__content', 'va-design-system va-customer-panel__content'],
    ['view', '<header class="va-customer-panel__header">', '<header class="va-customer-panel__header va-design-system">'],
    ['js', 'function renderDetailLoading(state) {', "function renderDetailLoading(state) {\n        var bad = 'va-design-system';"],
    ['js', 'function renderDetail(state, detail) {', "function renderDetail(state, detail) {\n        listSurface();"],
    ['js', 'function renderDetailRecoverableError(state) {', "function renderDetailRecoverableError(state) {\n        listSurface();"],
    ['js', 'function renderDetailNotFound(state) {', "function renderDetailNotFound(state) {\n        listSurface();"],
    ['js', 'surface.append(heading, list);', 'surface.append(list);'],
    ['js', 'surface.append(empty);', 'surface.append();'],
    ['js', 'surface.append(element(', 'surface.appendNode(element('],
    ['js', 'state.root.content.replaceChildren.apply(state.root.content, state.listSnapshot)', 'state.root.content.replaceChildren()'],
    ['js', "element('section', 'veciahorra-frontend va-design-system va-customer-panel__list-surface')", "element('section', 'veciahorra-frontend va-design-system va-customer-panel__list-surface') + element('section', 'veciahorra-frontend va-design-system va-customer-panel__list-surface')"],
    ['view', 'veciahorra-frontend va-design-system va-customer-panel__status va-loader', 'va-loader'],
    ['js', 'va-customer-panel__purchase va-card', 'va-customer-panel__purchase'],
    ['js', "element('a', 'va-customer-panel__purchase-link')", "element('button', 'va-customer-panel__purchase-link')"],
    ['js', 'va-customer-panel__purchase-action', 'va-customer-panel__purchase-action va-button'],
    ['js', 'item.checkout_public_id)', 'item.checkout_public_id.slice(0, 8))'],
    ['js', "url.searchParams.set('compra', publicId)", "url.searchParams.set('order_id', publicId)"],
    ['js', "var ENDPOINT = 'customer-panel/purchases';", "var leak = {\"order_id\": 1};\n    var ENDPOINT = 'customer-panel/purchases';"],
    ['js', 'initialize();', "api.post('customer-panel/purchases');\n    initialize();"],
    ['js', 'initialize();', "var user_id = 2;\n    initialize();"],
    ['js', 'initialize();', "var provider = 'prestador';\n    initialize();"],
    ['js', "customer-panel/purchases", 'customer-panel/orders'],
    ['assets', "CUSTOMER_PANEL_STYLE_HANDLE = 'veciahorra-customer-panel'", "CUSTOMER_PANEL_STYLE_HANDLE = 'changed'"],
    ['assets', '[self::STYLE_HANDLE],', '[],'],
    ['assets', '$this->enqueueDesignSystem();', '$this->enqueue();'],
    ['assets', 'public function enqueueCustomerPanel', '$this->enqueueDesignSystem(); public function enqueueCustomerPanel'],
    ['old_consumers', '$this->enqueueDesignSystem();', '$this->enqueue();'],
    ['css', '.veciahorra-frontend.va-design-system {', '.phase7-bridge {}\n.veciahorra-frontend.va-design-system {'],
    ['schema', "SCHEMA_VERSION = '0.28.0'", "SCHEMA_VERSION = '0.29.0'"],
    ['js', 'isCurrentRequest(state, request)', 'isAnyRequest(state, request)'],
    ['js', 'state.activeController.abort()', 'void state.activeController'],
    ['js', 'state.originLink.focus()', 'void state.originLink'],
    ['view', 'data-va-customer-panel-announcer', 'data-va-announcer-removed'],
    ['view', '<?php echo $loggedIn ? \' data-va-customer-panel-mount\' : \'\'; ?>', ' data-va-customer-panel-mount'],
    ['browser', 'INTERCEPT_LIST_ONLY', 'DETAIL_ENDPOINT_INTERCEPT_ALLOWED'],
    ['browser', 'INTERCEPT_LIST_ONLY', 'NON_GET_INTERCEPT_ALLOWED'],
    ['browser', 'INTERCEPT_LIST_ONLY', 'ASSET_INTERCEPT_ALLOWED'],
    ['browser', 'delete_user(user)', 'delete_user_disabled(user)'],
];

foreach ($mutations as $index => [$target, $from, $to]) {
    $mutant = $sources;
    if ($index === 26) {
        $originalMethod = phase7Method($mutant['assets'], 'enqueueCustomerPanel');
        $changedMethod = str_replace('$this->enqueueDesignSystem();', '$this->enqueue();', $originalMethod);
        $mutant['assets'] = str_replace($originalMethod, $changedMethod, $mutant['assets']);
    } else {
        $mutant[$target] = preg_replace('/' . preg_quote($from, '/') . '/', addcslashes($to, '\\$'), $mutant[$target], 1) ?? '';
    }
    $obtained = phase7Validate($mutant, $index !== 29);
    phase7Assert(in_array($codes[$index], $obtained, true), "Adversarial {$codes[$index]} no rechazado: " . json_encode($obtained));
    echo "PASS ADVERSARIAL expected={$codes[$index]} obtained={$codes[$index]}\n";
}

require_once dirname(__DIR__, 5) . '/wp-load.php';
wp_set_current_user(0);
$container = new VeciAhorra\Core\Container();
$renderer = $container->make(VeciAhorra\Modules\Frontend\Support\ViewRenderer::class);
$anonymousController = new VeciAhorra\Modules\Frontend\Controller\FrontendController(
    new VeciAhorra\Modules\Frontend\Assets\FrontendAssets(),
    $renderer
);
$anonymous = $anonymousController->renderCustomerPanel();
phase7Assert(! str_contains($anonymous, 'va-design-system'), 'Visitante recibio raiz visual.');
phase7Assert(wp_style_is('veciahorra-design-system', 'enqueued'), 'Design system no encolado para el panel.');
$users = get_users(['number' => 1, 'fields' => 'ids']);
phase7Assert($users !== [], 'Se requiere un usuario WordPress existente para render in-memory.');
wp_set_current_user((int) $users[0]);
$authenticatedController = new VeciAhorra\Modules\Frontend\Controller\FrontendController(
    new VeciAhorra\Modules\Frontend\Assets\FrontendAssets(),
    $renderer
);
$authenticated = $authenticatedController->renderCustomerPanel();
phase7Assert(substr_count($authenticated, 'veciahorra-frontend va-design-system va-customer-panel__status va-loader') === 1, 'Loader runtime incorrecto.');
wp_set_current_user(0);

echo "PASS frontend-customer-purchases-list-design-system-test adversarials=40\n";
ob_end_flush();
