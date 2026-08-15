<?php

declare(strict_types=1);

const VA_PHASE10_BASELINE = 'e4f83dd60933e02acf862ac7917542cf0e92723a';
const VA_PHASE10_PATHS = [
    'assets/frontend/js/customer-panel.js',
    'tests/manual/frontend-customer-purchase-detail-order-header-design-system-test.php',
    'tests/manual/customer-purchase-detail-order-header-design-system-browser-test.py',
];

function phase10Assert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function phase10Method(string $source, string $name): string
{
    $start = strpos($source, "    function {$name}(");
    phase10Assert($start !== false, "Funcion {$name} ausente.");
    $next = strpos($source, "\n    function ", $start + 1);
    return substr($source, $start, $next === false ? null : $next - $start);
}

/** @return list<string> */
function phase10Git(array $arguments): array
{
    $command = ['git', '-C', dirname(__DIR__, 2), ...$arguments];
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    phase10Assert(is_resource($process), 'Git no pudo iniciarse.');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    phase10Assert(proc_close($process) === 0, 'Git fallo: ' . trim((string) $stderr));
    return array_values(array_filter(preg_split('/\R/', trim((string) $stdout)) ?: []));
}

function phase10GitText(array $arguments): string
{
    $command = ['git', '-C', dirname(__DIR__, 2), ...$arguments];
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    phase10Assert(is_resource($process), 'Git no pudo iniciarse.');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    phase10Assert(proc_close($process) === 0, 'Git fallo: ' . trim((string) $stderr));
    return rtrim(str_replace(["\r\n", "\r"], "\n", (string) $stdout), "\n");
}

/** @return list<string> */
function phase10Validate(array $s, bool $worktree = true): array
{
    $errors = [];
    $need = static function (bool $ok, string $code) use (&$errors): void { if (! $ok) $errors[] = $code; };
    $js = $s['js']; $view = $s['view']; $css = $s['css']; $assets = $s['assets'];
    $routes = $s['routes']; $service = $s['service']; $query = $s['query'];
    $detail = $s['detail']; $orderDto = $s['orderDto']; $browser = $s['browser'];
    $order = phase10Method($js, 'renderDetailOrder');
    $render = phase10Method($js, 'renderDetail');
    $root = 'veciahorra-frontend va-design-system va-customer-panel__detail-order-header';
    $attr = 'data-va-customer-panel-detail-order-header';

    $need(substr_count($order, "'{$root}'") === 1 && substr_count($order, "orderHeader.setAttribute('{$attr}', '');") === 1, 'P01_ROOT_MISSING');
    $need(substr_count($js, $attr) === 1, 'P02_ROOT_DUPLICATED');
    $need(! str_contains($view, $attr), 'P03_ROOT_GLOBAL');
    $need(str_contains($order, "var orderHeader = element(\n            'div',") && ! preg_match('/(?:listItem|heading|subtotal|products)\.setAttribute\(\'' . $attr . '/', $order), 'P04_WRONG_NODE');
    $need(! str_contains(phase10Method($js, 'renderDetail'), "overview.setAttribute('{$attr}'"), 'P05_NESTED_PHASE8');
    $need(! str_contains(phase10Method($js, 'renderDetailItem'), $attr) && strpos($order, 'orderHeader.append') < strpos($order, 'listItem.append(orderHeader, productsHeading, products)'), 'P06_NESTED_PHASE9');
    $need(str_contains($render, "veciahorra-frontend va-design-system va-customer-panel__detail-overview va-customer-panel__detail-primary-card") && str_contains($render, "data-va-customer-panel-detail-overview"), 'P07_PHASE8_CHANGED');
    $item = phase10Method($js, 'renderDetailItem');
    $need(str_contains($item, 'veciahorra-frontend va-design-system va-customer-panel__detail-item') && str_contains($item, 'data-va-customer-panel-detail-item'), 'P08_PHASE9_CHANGED');
    $need(! str_contains(phase10Method($js, 'renderList'), $attr), 'P09_LIST_INVADED');
    $need(! preg_match('/paymentSection[^;]*' . $attr . '/', $render), 'P10_PAYMENT_INVADED');
    $need(! preg_match('/deliverySection[^;]*' . $attr . '/', $render), 'P11_DELIVERY_INVADED');
    $need(! str_contains(phase10Method($js, 'renderTimeline'), $attr), 'P12_TIMELINE_INVADED');
    $need(! str_contains(phase10Method($js, 'renderDetailLoading'), $attr), 'P13_LOADING_INVADED');
    $need(! str_contains(phase10Method($js, 'renderDetailNotFound'), $attr), 'P14_NOT_FOUND_INVADED');
    $need(! str_contains(phase10Method($js, 'renderDetailRecoverableError'), $attr), 'P15_ERROR_INVADED');
    $need(substr_count($order, 'orderHeader.setAttribute') === 1 && substr_count($order, 'orders.append(renderDetailOrder') === 0, 'P16_CARDINALITY_CHANGED');
    $need(str_contains($js, "var ENDPOINT = 'customer-panel/purchases';") && str_contains($routes, "private const PURCHASES = '/customer-panel/purchases';"), 'P17_ENDPOINT_CHANGED');
    $need(! preg_match('/\b(?:post|put|patch|delete)\s*\(/i', $js) && str_contains($routes, 'WP_REST_Server::READABLE'), 'P18_HTTP_METHOD_CHANGED');
    $need(str_contains($js, "url.searchParams.set('compra', publicId)") && str_contains($js, "getAll('compra')"), 'P19_COMPRA_QUERY_LOST');
    $need(str_contains($js, 'var PUBLIC_ID_PATTERN = /^chk_[A-Za-z0-9_-]{43}$/;') && str_contains($js, 'route.publicId'), 'P20_PUBLIC_ID_LOST');
    $need(str_contains($routes, 'get_current_user_id()') && str_contains($service, 'findOwnedCheckout($publicId, $userId)'), 'P21_OWNERSHIP_LOST');
    $need(str_contains($service, "(int) (\$order['customer_id'] ?? 0) !== \$userId")
        && substr_count($service, "(int) (\$payment['customer_id'] ?? 0) !== \$userId") >= 2
        && str_contains($service, "(int) (\$delivery['customer_id'] ?? 0) !== \$userId"), 'P22_FOREIGN_CUSTOMER_EXPOSED');
    $need(! preg_match('/get_(?:query|body|json)_params[^;]*(?:user_id|customer_id)/', $routes), 'P23_IDENTITY_OVERRIDE');
    $need(! preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE)\b/i', $js . $routes . $service), 'P24_MUTATION_ADDED');
    $need(str_contains($orderDto, "'minimarket' => ['name' => \$this->minimarketName, 'historical' => false]") && str_contains($orderDto, "'subtotal' => \$this->subtotal") && str_contains($detail, "'orders' => array_map"), 'P25_DTO_CHANGED');
    $need(! preg_match('/[\'\"](?:order_id|store_id|minimarket_id|customer_id|user_id|payment_id|delivery_id)[\'\"]\s*[:=]/', $js), 'P26_INTERNAL_DATA_EXPOSED');
    $need(str_contains($order, 'order.minimarket.name') && ! str_contains($order, 'order.minimarket.historical'), 'P27_HISTORICAL_STATE_CHANGED');
    $headerOrder = strpos($order, 'orderHeader.append(heading, subtotal)');
    $cardOrder = strpos($order, 'listItem.append(orderHeader, productsHeading, products)');
    $need($headerOrder !== false && $cardOrder !== false && $headerOrder < $cardOrder && str_contains($query, 'ORDER BY co.checkout_id ASC, o.id ASC'), 'P28_ORDER_CHANGED');
    $need(str_contains($order, "'Subtotal: ' + formatTotal({amount: order.subtotal, currency: currency}, config)") && str_contains(phase10Method($js, 'formatTotal'), "currency === 'CLP'"), 'P29_MONEY_FORMAT_CHANGED');
    $need(str_contains($order, "visualHeading('h4', order.minimarket.name, 'store')") && str_contains(phase10Method($js, 'decorativeIcon'), "aria-hidden") && str_contains($browser, '44'), 'P30_ACCESSIBILITY_LOST');
    $need($css === $s['baselineCss'] && $assets === $s['baselineAssets'], 'P31_ASSET_OR_CSS_CHANGED');
    $need(str_contains($s['schema'], "SCHEMA_VERSION = '0.28.0'"), 'P32_ALLOWLIST_OR_BASELINE_DRIFT');
    if ($worktree) {
        $changed = array_values(array_unique([...phase10Git(['diff', '--name-only', VA_PHASE10_BASELINE]), ...phase10Git(['diff', '--cached', '--name-only']), ...array_filter(phase10Git(['ls-files', '--others', '--exclude-standard']), static fn (string $p): bool => in_array($p, VA_PHASE10_PATHS, true))]));
        sort($changed); $allowed = VA_PHASE10_PATHS; sort($allowed);
        $need($changed === [] || $changed === $allowed, 'P32_ALLOWLIST_OR_BASELINE_DRIFT');
    }
    return $errors;
}

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$sources = [
    'js' => $read('assets/frontend/js/customer-panel.js'), 'view' => $read('app/Modules/Frontend/Views/customer-panel.php'),
    'css' => $read('assets/frontend/css/customer-panel.css'), 'assets' => $read('app/Modules/Frontend/Assets/FrontendAssets.php'),
    'routes' => $read('app/Modules/CustomerPanel/Routes/CustomerPanelRoutes.php'), 'service' => $read('app/Modules/CustomerPanel/Service/CustomerPanelService.php'),
    'query' => $read('app/Modules/CustomerPanel/Query/CustomerPurchaseQuery.php'), 'detail' => $read('app/Modules/CustomerPanel/DTO/CustomerPurchaseDetail.php'),
    'orderDto' => $read('app/Modules/CustomerPanel/DTO/CustomerPurchaseOrder.php'), 'schema' => $read('app/Core/Config.php'),
    'browser' => $read('tests/manual/customer-purchase-detail-order-header-design-system-browser-test.py'),
    'baselineCss' => phase10GitText(['show', VA_PHASE10_BASELINE . ':assets/frontend/css/customer-panel.css']),
    'baselineAssets' => phase10GitText(['show', VA_PHASE10_BASELINE . ':app/Modules/Frontend/Assets/FrontendAssets.php']),
];
$sources['css'] = rtrim(str_replace(["\r\n", "\r"], "\n", $sources['css']), "\n");
$sources['assets'] = rtrim(str_replace(["\r\n", "\r"], "\n", $sources['assets']), "\n");
$errors = phase10Validate($sources);
phase10Assert($errors === [], 'Validacion base: ' . implode(',', $errors));

$mutations = [
 ['js', "'veciahorra-frontend va-design-system va-customer-panel__detail-order-header'", "'va-customer-panel__detail-order-header'"],
 ['js', "orderHeader.setAttribute('data-va-customer-panel-detail-order-header', '');", "orderHeader.setAttribute('data-va-customer-panel-detail-order-header', '');\n        orderHeader.setAttribute('data-va-customer-panel-detail-order-header', '');"],
 ['view', '<main ', '<div data-va-customer-panel-detail-order-header></div><main '],
 ['js', "orderHeader.setAttribute('data-va-customer-panel-detail-order-header', '');", "listItem.setAttribute('data-va-customer-panel-detail-order-header', '');"],
 ['js', "overview.setAttribute('data-va-customer-panel-detail-overview', '');", "overview.setAttribute('data-va-customer-panel-detail-overview', ''); overview.setAttribute('data-va-customer-panel-detail-order-header', '');"],
 ['js', "listItem.setAttribute('data-va-customer-panel-detail-item', '');", "listItem.setAttribute('data-va-customer-panel-detail-item', ''); listItem.setAttribute('data-va-customer-panel-detail-order-header', '');"],
 ['js', 'data-va-customer-panel-detail-overview', 'data-va-phase8-broken'],
 ['js', 'data-va-customer-panel-detail-item', 'data-va-phase9-broken'],
 ['js', 'function renderList(root, purchases, config) {', "function renderList(root, purchases, config) { var leak='data-va-customer-panel-detail-order-header';"],
 ['js', "var paymentSection = element('section', 'va-customer-panel__detail-section va-customer-panel__detail-payment');", "var paymentSection = element('section', 'va-customer-panel__detail-section va-customer-panel__detail-payment data-va-customer-panel-detail-order-header');"],
 ['js', "var deliverySection = element('section', 'va-customer-panel__detail-section va-customer-panel__detail-delivery');", "var deliverySection = element('section', 'va-customer-panel__detail-section va-customer-panel__detail-delivery data-va-customer-panel-detail-order-header');"],
 ['js', 'function renderTimeline(entries, config) {', "function renderTimeline(entries, config) { var leak='data-va-customer-panel-detail-order-header';"],
 ['js', 'function renderDetailLoading(state) {', "function renderDetailLoading(state) { var leak='data-va-customer-panel-detail-order-header';"],
 ['js', 'function renderDetailNotFound(state) {', "function renderDetailNotFound(state) { var leak='data-va-customer-panel-detail-order-header';"],
 ['js', 'function renderDetailRecoverableError(state) {', "function renderDetailRecoverableError(state) { var leak='data-va-customer-panel-detail-order-header';"],
 ['js', "orderHeader.setAttribute('data-va-customer-panel-detail-order-header', '');", "orderHeader.setAttribute('data-va-customer-panel-detail-order-header', ''); orderHeader.setAttribute('data-va-customer-panel-detail-order-header', '');"],
 ['js', 'customer-panel/purchases', 'customer-panel/orders'],
 ['js', 'initialize();', "api.post('customer-panel/purchases'); initialize();"],
 ['js', "url.searchParams.set('compra', publicId)", "url.searchParams.set('pedido', publicId)"],
 ['js', 'PUBLIC_ID_PATTERN', 'PUBLIC_REFERENCE_BROKEN'],
 ['service', 'findOwnedCheckout($publicId, $userId)', 'findOwnedCheckout($publicId, 0)'],
 ['service', "(int) (\$payment['customer_id'] ?? 0) !== \$userId", "(int) (\$payment['customer_id'] ?? 0) === \$userId"],
 ['routes', '(string) ($request->get_url_params()', "(string) (\$request->get_query_params()['user_id'] ?? '') . (string) (\$request->get_url_params()"],
 ['js', 'initialize();', "api.delete('customer-panel/purchases'); initialize();"],
 ['orderDto', "'subtotal' => \$this->subtotal", "'total' => \$this->subtotal"],
 ['js', "var ENDPOINT =", "var leaked={'order_id':1}; var ENDPOINT ="],
 ['js', "visualHeading('h4', order.minimarket.name, 'store')", "visualHeading('h4', order.minimarket.name + (order.minimarket.historical ? ' histórico' : ''), 'store')"],
 ['js', 'orderHeader.append(heading, subtotal)', 'orderHeader.append(subtotal, heading)'],
 ['js', "currency === 'CLP'", "currency === 'USD'"],
 ['js', "visualHeading('h4', order.minimarket.name, 'store')", "element('div', '', order.minimarket.name)"],
 ['css', '.veciahorra-frontend.va-customer-panel {', '.veciahorra-frontend.va-customer-panel { color:red;'],
 ['schema', "SCHEMA_VERSION = '0.28.0'", "SCHEMA_VERSION = '0.29.0'"],
];

$codes = array_map(static fn (int $i): string => 'P' . str_pad((string) $i, 2, '0', STR_PAD_LEFT) . '_' . [
 'ROOT_MISSING','ROOT_DUPLICATED','ROOT_GLOBAL','WRONG_NODE','NESTED_PHASE8','NESTED_PHASE9','PHASE8_CHANGED','PHASE9_CHANGED','LIST_INVADED','PAYMENT_INVADED','DELIVERY_INVADED','TIMELINE_INVADED','LOADING_INVADED','NOT_FOUND_INVADED','ERROR_INVADED','CARDINALITY_CHANGED','ENDPOINT_CHANGED','HTTP_METHOD_CHANGED','COMPRA_QUERY_LOST','PUBLIC_ID_LOST','OWNERSHIP_LOST','FOREIGN_CUSTOMER_EXPOSED','IDENTITY_OVERRIDE','MUTATION_ADDED','DTO_CHANGED','INTERNAL_DATA_EXPOSED','HISTORICAL_STATE_CHANGED','ORDER_CHANGED','MONEY_FORMAT_CHANGED','ACCESSIBILITY_LOST','ASSET_OR_CSS_CHANGED','ALLOWLIST_OR_BASELINE_DRIFT'][$i-1], range(1, 32));
foreach ($mutations as $i => [$key, $from, $to]) {
    $mutated = $sources; phase10Assert(str_contains($mutated[$key], $from), "Fixture {$codes[$i]} ausente.");
    $mutated[$key] = preg_replace('/' . preg_quote($from, '/') . '/', addcslashes($to, '\\$'), $mutated[$key], 1) ?? $mutated[$key];
    $obtained = phase10Validate($mutated, false);
    phase10Assert(in_array($codes[$i], $obtained, true), "Esperado {$codes[$i]}; obtenido " . implode(',', $obtained));
    echo "PASS ADVERSARIAL expected={$codes[$i]} obtained={$codes[$i]}\n";
}
echo "PASS frontend-customer-purchase-detail-order-header-design-system-test adversarials=32\n";
