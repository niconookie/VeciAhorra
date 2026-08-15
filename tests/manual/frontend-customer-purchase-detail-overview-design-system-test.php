<?php

declare(strict_types=1);

function phase8Assert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function phase8Method(string $source, string $name): string
{
    $needle = preg_match('/\\b(?:public|private|protected) function ' . preg_quote($name, '/') . '\\s*\\(/', $source, $match, PREG_OFFSET_CAPTURE)
        ? $match[0][1]
        : strpos($source, "    function {$name}(");
    $start = $needle;
    phase8Assert($start !== false, "Funcion {$name} ausente.");
    $next = strpos($source, "\n    function ", $start + 1);
    if ($next === false) {
        $nextPublic = strpos($source, "\n    public function ", $start + 1);
        $nextPrivate = strpos($source, "\n    private function ", $start + 1);
        $candidates = array_values(array_filter([$nextPublic, $nextPrivate], static fn ($value): bool => $value !== false));
        $next = $candidates === [] ? false : min($candidates);
    }
    return substr($source, $start, $next === false ? null : $next - $start);
}

/** @return list<string> */
function phase8Validate(array $sources, bool $checkWorktree = true): array
{
    $errors = [];
    $need = static function (bool $condition, string $code) use (&$errors): void {
        if (! $condition) {
            $errors[] = $code;
        }
    };
    $js = $sources['js'];
    $view = $sources['view'];
    $assets = $sources['assets'];
    $css = $sources['css'];
    $routes = $sources['routes'];
    $service = $sources['service'];
    $query = $sources['query'];
    $browser = $sources['browser'];
    $render = phase8Method($js, 'renderDetail');
    $loading = phase8Method($js, 'renderDetailLoading');
    $missing = phase8Method($js, 'renderDetailNotFound');
    $failure = phase8Method($js, 'renderDetailRecoverableError');
    $list = phase8Method($js, 'listSurface') . phase8Method($js, 'renderList');
    $root = "veciahorra-frontend va-design-system va-customer-panel__detail-overview va-customer-panel__detail-primary-card";

    $need(substr_count($render, "element('div', '{$root}')") === 1
        && substr_count($render, "overview.setAttribute('data-va-customer-panel-detail-overview', '');") === 1, 'P01_ROOT_MISSING');
    $need(substr_count($js, 'data-va-customer-panel-detail-overview') === 1, 'P02_ROOT_DUPLICATED');
    $need(! str_contains($view, 'data-va-customer-panel-detail-overview'), 'P03_ROOT_GLOBAL_PANEL');
    $need(! str_contains($view, 'va-design-system va-customer-panel__detail-overview'), 'P04_ROOT_ON_STATIC_VIEW');
    $need(! str_contains($list, 'data-va-customer-panel-detail-overview'), 'P05_ROOT_ON_LIST');
    $need(strpos($render, 'overview =') < strpos($render, 'ordersSection =')
        && preg_match('/replaceChildren\(\s*headingRow,\s*overview,\s*ordersSection,\s*services,\s*timelineSection/', $render) === 1, 'P06_ROOT_ON_DETAIL_HEADING');
    $need(! str_contains(phase8Method($js, 'renderDetailOrder'), 'data-va-customer-panel-detail-overview'), 'P07_ROOT_ON_ORDERS');
    $need(! str_contains(phase8Method($js, 'renderDetailItem'), 'data-va-customer-panel-detail-overview'), 'P08_ROOT_ON_PRODUCTS');
    $need(! str_contains(phase8Method($js, 'renderTimeline'), 'data-va-customer-panel-detail-overview'), 'P09_ROOT_ON_TIMELINE');
    $need(! preg_match('/paymentSection[^;]*data-va-customer-panel-detail-overview/', $render), 'P10_ROOT_ON_PAYMENT');
    $need(! preg_match('/deliverySection[^;]*data-va-customer-panel-detail-overview/', $render), 'P11_ROOT_ON_DELIVERY');
    $need(! str_contains($loading, 'data-va-customer-panel-detail-overview'), 'P12_ROOT_ON_LOADING');
    $need(! str_contains($missing, 'data-va-customer-panel-detail-overview'), 'P13_ROOT_ON_NOT_FOUND');
    $need(! str_contains($failure, 'data-va-customer-panel-detail-overview'), 'P14_ROOT_ON_ERROR');
    $need(! str_contains($view, 'data-va-customer-panel-detail-overview'), 'P15_ROOT_ON_VISITOR');
    $need(str_contains($js, "var ENDPOINT = 'customer-panel/purchases';")
        && str_contains($js, "var DETAIL_ENDPOINT = ENDPOINT + '/';"), 'P16_ENDPOINT_CHANGED');
    $need(! preg_match('/api\.(?:post|put|patch|delete)\s*\(/i', $js), 'P17_HTTP_METHOD_CHANGED');
    $need(str_contains($js, "url.searchParams.set('compra', publicId);")
        && str_contains($js, "url.searchParams.getAll('compra')"), 'P18_QUERY_PARAMETER_CHANGED');
    $need(str_contains($js, 'checkout_public_id') && ! preg_match('/checkout_public_id[^;\n]*(?:slice|substring|substr)\s*\(/', $js), 'P19_PUBLIC_ID_LOST');
    $need(str_contains($routes, 'get_current_user_id()')
        && str_contains($service, 'findOwnedCheckout($publicId, $userId)'), 'P20_OWNERSHIP_LOST');
    $need(str_contains($query, "c.owner_type = %%s") && str_contains($query, "c.user_id = %%d")
        && str_contains($service, "(int) (\$order['customer_id'] ?? 0) !== \$userId"), 'P21_FOREIGN_CUSTOMER_EXPOSED');
    $need(! preg_match('/get_param\s*\(\s*[\'\"](?:user_id|customer_id)/', $routes), 'P22_OWNERSHIP_OVERRIDE');
    $need(str_contains($js, "var link = element('a', 'va-customer-panel__purchase-link');")
        && str_contains($js, 'link.href = canonicalDetailUrl('), 'P23_NAVIGATION_MUTATION');
    $need(str_contains($loading, "setAttribute('aria-busy', 'true')") && str_contains($loading, "setAttribute('aria-live', 'polite')"), 'P24_LOADING_LOST');
    $need(substr_count($missing, 'La compra no est') === 2 && str_contains($missing, 'heading.focus()'), 'P25_NOT_FOUND_LOST');
    $need(substr_count($failure, 'No pudimos cargar tus compras') === 2 && str_contains($failure, 'heading.focus()'), 'P26_ERROR_LOST');
    $need(str_contains($js, 'window.history.pushState') && str_contains($js, 'window.addEventListener(\'popstate\'')
        && str_contains($js, 'window.scrollTo(0, state.scrollPosition)') && str_contains($js, 'state.originLink.focus()'), 'P27_NAVIGATION_STATE_LOST');
    $need(str_contains($view, 'data-va-customer-panel-announcer')
        && str_contains($render, 'state.root.announcer.textContent'), 'P28_LIVE_REGION_LOST');
    $need(str_contains(phase8Method($assets, 'enqueueCustomerPanel'), '$this->enqueueDesignSystem();')
        && substr_count($assets, '$this->enqueueDesignSystem();') === 5, 'P29_ASSET_SCOPE_CHANGED');
    $need(! str_contains($css, 'data-va-customer-panel-detail-overview')
        && str_contains($browser, 'LIST_PATH') && str_contains($browser, 'DETAIL_PATH')
        && str_contains($browser, 'INTERCEPT_GET_ONLY'), 'P30_SCOPE_OR_ALLOWLIST_BREACH');

    if ($checkWorktree) {
        $allowed = [
            'assets/frontend/js/customer-panel.js',
            'tests/manual/customer-purchase-detail-overview-design-system-browser-test.py',
            'tests/manual/frontend-customer-purchase-detail-overview-design-system-test.php',
        ];
        $changed = array_values(array_unique(array_filter(array_merge(
            preg_split('/\R/', trim((string) shell_exec('git diff --name-only HEAD'))) ?: [],
            preg_split('/\R/', trim((string) shell_exec('git diff --cached --name-only'))) ?: []
        ))));
        if ($changed !== []) {
            sort($changed);
            $expected = $allowed;
            sort($expected);
            $need($changed === $expected, 'P30_SCOPE_OR_ALLOWLIST_BREACH');
        }
        $environmental = array_values(array_filter(preg_split('/\R/', trim((string) shell_exec('git ls-files --others --exclude-standard'))) ?: []));
        $need(count($environmental) === 516, 'P30_SCOPE_OR_ALLOWLIST_BREACH');
    }
    return array_values(array_unique($errors));
}

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $value = file_get_contents($root . '/' . $path);
    phase8Assert(is_string($value), "No se pudo leer {$path}.");
    return $value;
};
$sources = [
    'js' => $read('assets/frontend/js/customer-panel.js'),
    'view' => $read('app/Modules/Frontend/Views/customer-panel.php'),
    'assets' => $read('app/Modules/Frontend/Assets/FrontendAssets.php'),
    'css' => $read('assets/frontend/css/customer-panel.css'),
    'routes' => $read('app/Modules/CustomerPanel/Routes/CustomerPanelRoutes.php'),
    'service' => $read('app/Modules/CustomerPanel/Service/CustomerPanelService.php'),
    'query' => $read('app/Modules/CustomerPanel/Query/CustomerPurchaseQuery.php'),
    'browser' => $read('tests/manual/customer-purchase-detail-overview-design-system-browser-test.py'),
];
phase8Assert(phase8Validate($sources) === [], 'Validacion primaria: ' . json_encode(phase8Validate($sources)));

$mutations = [
    ['js', "veciahorra-frontend va-design-system va-customer-panel__detail-overview va-customer-panel__detail-primary-card", 'va-customer-panel__detail-overview va-customer-panel__detail-primary-card'],
    ['js', "overview.setAttribute('data-va-customer-panel-detail-overview', '');", "overview.setAttribute('data-va-customer-panel-detail-overview', '');\n        overview.setAttribute('data-va-customer-panel-detail-overview', '');"],
    ['view', 'data-va-customer-panel', 'data-va-customer-panel data-va-customer-panel-detail-overview'],
    ['view', 'va-customer-panel__main', 'va-design-system va-customer-panel__detail-overview va-customer-panel__main'],
    ['js', "surface.setAttribute('data-va-customer-panel-list-surface', '');", "surface.setAttribute('data-va-customer-panel-detail-overview', '');"],
    ['js', 'headingRow, overview, ordersSection, services, timelineSection', 'overview, headingRow, ordersSection, services, timelineSection'],
    ['js', "var listItem = element('li', 'va-customer-panel__detail-order va-card');", "var listItem = element('li', 'va-customer-panel__detail-order va-card data-va-customer-panel-detail-overview');"],
    ['js', "var listItem = element('li', 'va-customer-panel__detail-item');", "var listItem = element('li', 'va-customer-panel__detail-item data-va-customer-panel-detail-overview');"],
    ['js', "var section = element('section', 'va-customer-panel__detail-section va-customer-panel__timeline');", "var section = element('section', 'va-customer-panel__detail-section va-customer-panel__timeline data-va-customer-panel-detail-overview');"],
    ['js', "var paymentSection = element('section', 'va-customer-panel__detail-section va-customer-panel__detail-payment');", "var paymentSection = element('section', 'data-va-customer-panel-detail-overview');"],
    ['js', "var deliverySection = element('section', 'va-customer-panel__detail-section va-customer-panel__detail-delivery');", "var deliverySection = element('section', 'data-va-customer-panel-detail-overview');"],
    ['js', "function renderDetailLoading(state) {", "function renderDetailLoading(state) {\n        var leak = 'data-va-customer-panel-detail-overview';"],
    ['js', "function renderDetailNotFound(state) {", "function renderDetailNotFound(state) {\n        var leak = 'data-va-customer-panel-detail-overview';"],
    ['js', "function renderDetailRecoverableError(state) {", "function renderDetailRecoverableError(state) {\n        var leak = 'data-va-customer-panel-detail-overview';"],
    ['view', 'data-va-customer-panel-announcer', 'data-va-customer-panel-detail-overview data-va-customer-panel-announcer'],
    ['js', 'customer-panel/purchases', 'customer-panel/orders'],
    ['js', 'initialize();', "api.post('customer-panel/purchases');\n    initialize();"],
    ['js', "searchParams.set('compra'", "searchParams.set('pedido'"],
    ['js', 'item.checkout_public_id)', 'item.checkout_public_id.slice(0, 12))'],
    ['service', 'findOwnedCheckout($publicId, $userId)', 'findOwnedCheckout($publicId, 1)'],
    ['service', "(int) (\$order['customer_id'] ?? 0) !== \$userId", 'false'],
    ['routes', 'public function purchase(WP_REST_Request $request): WP_REST_Response', 'public function purchase(WP_REST_Request $request): WP_REST_Response' . "\n    {\n        \$request->get_param('user_id');\n    }\n\n    public function purchaseBroken(WP_REST_Request \$request): WP_REST_Response"],
    ['js', "var link = element('a', 'va-customer-panel__purchase-link');", "var link = element('button', 'va-customer-panel__purchase-link');"],
    ['js', "loader.setAttribute('aria-live', 'polite');", "loader.setAttribute('aria-live', 'off');"],
    ['js', 'La compra no est', 'Compra desconocida'],
    ['js', 'No pudimos cargar tus compras', 'Fallo'],
    ['js', 'window.history.pushState', 'window.history.disabledPushState'],
    ['view', 'data-va-customer-panel-announcer', 'data-va-disabled-announcer'],
    ['assets', '$this->enqueueDesignSystem();', '$this->enqueue();'],
    ['css', '.veciahorra-frontend .va-customer-panel__detail-overview', '[data-va-customer-panel-detail-overview]'],
];

foreach ($mutations as $index => [$target, $from, $to]) {
    $mutant = $sources;
    $mutant[$target] = preg_replace('/' . preg_quote($from, '/') . '/', addcslashes($to, '\\$'), $mutant[$target], 1) ?? '';
    $code = sprintf('P%02d_%s', $index + 1, explode('_', [
        'ROOT_MISSING','ROOT_DUPLICATED','ROOT_GLOBAL_PANEL','ROOT_ON_STATIC_VIEW','ROOT_ON_LIST','ROOT_ON_DETAIL_HEADING','ROOT_ON_ORDERS','ROOT_ON_PRODUCTS','ROOT_ON_TIMELINE','ROOT_ON_PAYMENT','ROOT_ON_DELIVERY','ROOT_ON_LOADING','ROOT_ON_NOT_FOUND','ROOT_ON_ERROR','ROOT_ON_VISITOR','ENDPOINT_CHANGED','HTTP_METHOD_CHANGED','QUERY_PARAMETER_CHANGED','PUBLIC_ID_LOST','OWNERSHIP_LOST','FOREIGN_CUSTOMER_EXPOSED','OWNERSHIP_OVERRIDE','NAVIGATION_MUTATION','LOADING_LOST','NOT_FOUND_LOST','ERROR_LOST','NAVIGATION_STATE_LOST','LIVE_REGION_LOST','ASSET_SCOPE_CHANGED','SCOPE_OR_ALLOWLIST_BREACH',
    ][$index], 2)[1] ?? '');
    $expected = [
        'P01_ROOT_MISSING','P02_ROOT_DUPLICATED','P03_ROOT_GLOBAL_PANEL','P04_ROOT_ON_STATIC_VIEW','P05_ROOT_ON_LIST','P06_ROOT_ON_DETAIL_HEADING','P07_ROOT_ON_ORDERS','P08_ROOT_ON_PRODUCTS','P09_ROOT_ON_TIMELINE','P10_ROOT_ON_PAYMENT','P11_ROOT_ON_DELIVERY','P12_ROOT_ON_LOADING','P13_ROOT_ON_NOT_FOUND','P14_ROOT_ON_ERROR','P15_ROOT_ON_VISITOR','P16_ENDPOINT_CHANGED','P17_HTTP_METHOD_CHANGED','P18_QUERY_PARAMETER_CHANGED','P19_PUBLIC_ID_LOST','P20_OWNERSHIP_LOST','P21_FOREIGN_CUSTOMER_EXPOSED','P22_OWNERSHIP_OVERRIDE','P23_NAVIGATION_MUTATION','P24_LOADING_LOST','P25_NOT_FOUND_LOST','P26_ERROR_LOST','P27_NAVIGATION_STATE_LOST','P28_LIVE_REGION_LOST','P29_ASSET_SCOPE_CHANGED','P30_SCOPE_OR_ALLOWLIST_BREACH',
    ][$index];
    $obtained = phase8Validate($mutant, false);
    phase8Assert(in_array($expected, $obtained, true), "Adversarial {$expected} no rechazado: " . json_encode($obtained));
    echo "PASS ADVERSARIAL expected={$expected} obtained={$expected}\n";
}

echo "PASS frontend-customer-purchase-detail-overview-design-system-test adversarials=30\n";
