<?php

declare(strict_types=1);

function phase9Assert(bool $condition, string $message): void
{
    if (! $condition) throw new RuntimeException($message);
}

function phase9Method(string $source, string $name): string
{
    $start = strpos($source, "    function {$name}(");
    phase9Assert($start !== false, "Funcion {$name} ausente.");
    $next = strpos($source, "\n    function ", $start + 1);
    return substr($source, $start, $next === false ? null : $next - $start);
}

/** @return list<string> */
function phase9Validate(array $s, bool $worktree = true): array
{
    $errors = [];
    $need = static function (bool $ok, string $code) use (&$errors): void { if (! $ok) $errors[] = $code; };
    $js = $s['js']; $view = $s['view']; $css = $s['css']; $assets = $s['assets'];
    $routes = $s['routes']; $service = $s['service']; $query = $s['query'];
    $detail = $s['detail']; $orderDto = $s['orderDto']; $itemDto = $s['itemDto']; $browser = $s['browser'];
    $item = phase9Method($js, 'renderDetailItem'); $order = phase9Method($js, 'renderDetailOrder');
    $render = phase9Method($js, 'renderDetail'); $image = phase9Method($js, 'renderProductImage');
    $placeholder = phase9Method($js, 'productImagePlaceholder');
    $root = "veciahorra-frontend va-design-system va-customer-panel__detail-item";

    $need(substr_count($item, "element(\n            'li',\n            '{$root}'\n        )") === 1
        && substr_count($item, "listItem.setAttribute('data-va-customer-panel-detail-item', '');") === 1, 'P01_ROOT_MISSING');
    $need(substr_count($js, 'data-va-customer-panel-detail-item') === 1, 'P02_ROOT_DUPLICATED');
    $need(! str_contains($view, 'data-va-customer-panel-detail-item'), 'P03_ROOT_GLOBAL');
    $need(! str_contains($render, "overview.setAttribute('data-va-customer-panel-detail-item'"), 'P04_ROOT_ON_OVERVIEW');
    $need(! preg_match('/paymentSection[^;]*data-va-customer-panel-detail-item/', $render), 'P05_ROOT_ON_PAYMENT');
    $need(! preg_match('/deliverySection[^;]*data-va-customer-panel-detail-item/', $render), 'P06_ROOT_ON_DELIVERY');
    $need(! str_contains(phase9Method($js, 'renderTimeline'), 'data-va-customer-panel-detail-item'), 'P07_ROOT_ON_TIMELINE');
    $need(! str_contains(phase9Method($js, 'renderList'), 'data-va-customer-panel-detail-item'), 'P08_ROOT_ON_LIST');
    $need(! str_contains(phase9Method($js, 'renderDetailLoading'), 'data-va-customer-panel-detail-item'), 'P09_ROOT_ON_LOADING');
    $need(! str_contains(phase9Method($js, 'renderDetailNotFound'), 'data-va-customer-panel-detail-item'), 'P10_ROOT_ON_NOT_FOUND');
    $need(! str_contains(phase9Method($js, 'renderDetailRecoverableError'), 'data-va-customer-panel-detail-item'), 'P11_ROOT_ON_ERROR');
    $need(str_contains($item, "var listItem = element(\n            'li',")
        && ! preg_match('/(?:content|values)\.setAttribute\(\'data-va-customer-panel-detail-item\'/', $item), 'P12_WRONG_NODE');
    $need(! str_contains($order, 'data-va-customer-panel-detail-item')
        && ! str_contains($render, "overview.setAttribute('data-va-customer-panel-detail-item'")
        && substr_count($item, 'data-va-customer-panel-detail-item') === 1, 'P13_UNAUTHORIZED_NESTING');
    $need(str_contains($order, 'order.items.forEach(function (item)')
        && str_contains($order, 'products.append(renderDetailItem(item, currency, config))'), 'P14_CARDINALITY_CHANGED');
    $need(str_contains($js, "var ENDPOINT = 'customer-panel/purchases';") && str_contains($js, "var DETAIL_ENDPOINT = ENDPOINT + '/';"), 'P15_ENDPOINT_CHANGED');
    $need(! preg_match('/api\.(?:post|put|patch|delete)\s*\(/i', $js), 'P16_HTTP_METHOD_CHANGED');
    $need(str_contains($js, "searchParams.set('compra', publicId)") && str_contains($js, "searchParams.getAll('compra')"), 'P17_COMPRA_QUERY_LOST');
    $need(str_contains($js, 'checkout_public_id') && ! preg_match('/checkout_public_id[^;\n]*(?:slice|substring|substr)\s*\(/', $js), 'P18_PUBLIC_ID_LOST');
    $need(str_contains($routes, 'get_current_user_id()') && str_contains($service, 'findOwnedCheckout($publicId, $userId)'), 'P19_OWNERSHIP_LOST');
    $need(str_contains($query, 'c.owner_type = %%s') && str_contains($query, 'c.user_id = %%d')
        && str_contains($service, "(int) (\$order['customer_id'] ?? 0) !== \$userId"), 'P20_FOREIGN_CUSTOMER_EXPOSED');
    $need(! preg_match('/get_param\s*\(\s*[\'\"](?:user_id|customer_id)/', $routes), 'P21_IDENTITY_OVERRIDE');
    $need(! preg_match('/api\.(?:post|put|patch|delete)\s*\(/i', $js), 'P22_MUTATION_ADDED');
    $need(str_contains($detail, "'orders' => array_map") && str_contains($orderDto, "'minimarket' =>")
        && str_contains($orderDto, "'items' => array_map") && str_contains($itemDto, "'unit_price'"), 'P23_DTO_CHANGED');
    $need(! preg_match('/[\'\"](?:order_id|store_id|minimarket_id|product_id|inventory_id|customer_id|user_id|payment_id|delivery_id)[\'\"]\s*[:=]/', $js), 'P24_INTERNAL_ID_EXPOSED');
    $need(str_contains($orderDto, "'historical' => false") && str_contains($itemDto, "'name_historical' => false")
        && str_contains($itemDto, "'image_historical' => false") && str_contains($js, "typeof item.name_historical === 'boolean'")
        && str_contains($js, "typeof item.image_historical === 'boolean'"), 'P25_HISTORICAL_FLAGS_LOST');
    $need(str_contains($image, 'item.image === null') && str_contains($image, 'safeImageUrl(item.image)')
        && str_contains($image, 'image.onerror = function') && str_contains($image, 'productImagePlaceholder()')
        && str_contains($placeholder, "setAttribute('aria-hidden', 'true')"), 'P26_IMAGE_FALLBACK_LOST');
    $need(str_contains($item, "detailValue('Cantidad', String(item.quantity))")
        && str_contains($item, "detailValue('Precio unitario', formatTotal({amount: item.unit_price")
        && str_contains($item, "detailValue('Subtotal', formatTotal({amount: item.subtotal"), 'P27_VALUES_CHANGED');
    $need(str_contains($query, 'ORDER BY co.checkout_id ASC, o.id ASC')
        && str_contains($query, 'ORDER BY oi.order_id ASC, oi.id ASC')
        && strpos($item, "detailValue('Producto'") < strpos($item, "detailValue('Cantidad'")
        && strpos($item, "detailValue('Cantidad'") < strpos($item, "detailValue('Precio unitario'")
        && strpos($item, "detailValue('Precio unitario'") < strpos($item, "detailValue('Subtotal'"), 'P28_ORDER_CHANGED');
    $need(str_contains($item, "element('li'") || str_contains($item, "'li',")
        && str_contains($item, "element('dl'") && str_contains($js, "element('dt'") && str_contains($js, "element('dd'")
        && str_contains($image, "image.alt = ''") && str_contains($placeholder, "aria-hidden', 'true"), 'P29_ACCESSIBILITY_LOST');
    $customer = substr($assets, strpos($assets, 'public function enqueueCustomerPanel'), strpos($assets, 'public function enqueue()', strpos($assets, 'public function enqueueCustomerPanel')) - strpos($assets, 'public function enqueueCustomerPanel'));
    $need(substr_count($customer, '$this->enqueueDesignSystem();') === 1 && substr_count($assets, '$this->enqueueDesignSystem();') === 5, 'P30_ASSETS_CHANGED');
    $need(str_contains($render, "element('div', 'veciahorra-frontend va-design-system va-customer-panel__detail-overview va-customer-panel__detail-primary-card')")
        && str_contains($render, "overview.setAttribute('data-va-customer-panel-detail-overview', '');"), 'P31_PHASE8_CHANGED');
    $need(! str_contains($css, 'data-va-customer-panel-detail-item'), 'P32_UNAUTHORIZED_CSS');
    $need(str_contains($browser, 'LIST_PATH') && str_contains($browser, 'DETAIL_PATH') && str_contains($browser, 'INTERCEPT_GET_ONLY'), 'P33_ALLOWLIST_BREACH');
    $need(str_contains($s['schema'], "SCHEMA_VERSION = '0.28.0'") && str_contains($browser, 'def fingerprint()'), 'P34_BASELINE_DRIFT');

    if ($worktree) {
        $allowed = ['assets/frontend/js/customer-panel.js','tests/manual/customer-purchase-detail-items-design-system-browser-test.py','tests/manual/frontend-customer-purchase-detail-items-design-system-test.php'];
        $changed = array_values(array_unique(array_filter(array_merge(
            preg_split('/\R/', trim((string) shell_exec('git diff --name-only HEAD'))) ?: [],
            preg_split('/\R/', trim((string) shell_exec('git diff --cached --name-only'))) ?: []
        ))));
        if ($changed !== []) { sort($changed); sort($allowed); $need($changed === $allowed, 'P33_ALLOWLIST_BREACH'); }
        $env = array_values(array_filter(preg_split('/\R/', trim((string) shell_exec('git ls-files --others --exclude-standard'))) ?: []));
        $need(count($env) === 516, 'P34_BASELINE_DRIFT');
    }
    return array_values(array_unique($errors));
}

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string { $v = file_get_contents($root . '/' . $path); phase9Assert(is_string($v), "Lectura fallida {$path}"); return $v; };
$sources = [
    'js'=>$read('assets/frontend/js/customer-panel.js'),'view'=>$read('app/Modules/Frontend/Views/customer-panel.php'),
    'css'=>$read('assets/frontend/css/customer-panel.css'),'assets'=>$read('app/Modules/Frontend/Assets/FrontendAssets.php'),
    'routes'=>$read('app/Modules/CustomerPanel/Routes/CustomerPanelRoutes.php'),'service'=>$read('app/Modules/CustomerPanel/Service/CustomerPanelService.php'),
    'query'=>$read('app/Modules/CustomerPanel/Query/CustomerPurchaseQuery.php'),'detail'=>$read('app/Modules/CustomerPanel/DTO/CustomerPurchaseDetail.php'),
    'orderDto'=>$read('app/Modules/CustomerPanel/DTO/CustomerPurchaseOrder.php'),'itemDto'=>$read('app/Modules/CustomerPanel/DTO/CustomerPurchaseItem.php'),
    'browser'=>$read('tests/manual/customer-purchase-detail-items-design-system-browser-test.py'),'schema'=>$read('app/Core/Config.php'),
];
phase9Assert(phase9Validate($sources) === [], 'Primaria: ' . json_encode(phase9Validate($sources)));

$codes = ['P01_ROOT_MISSING','P02_ROOT_DUPLICATED','P03_ROOT_GLOBAL','P04_ROOT_ON_OVERVIEW','P05_ROOT_ON_PAYMENT','P06_ROOT_ON_DELIVERY','P07_ROOT_ON_TIMELINE','P08_ROOT_ON_LIST','P09_ROOT_ON_LOADING','P10_ROOT_ON_NOT_FOUND','P11_ROOT_ON_ERROR','P12_WRONG_NODE','P13_UNAUTHORIZED_NESTING','P14_CARDINALITY_CHANGED','P15_ENDPOINT_CHANGED','P16_HTTP_METHOD_CHANGED','P17_COMPRA_QUERY_LOST','P18_PUBLIC_ID_LOST','P19_OWNERSHIP_LOST','P20_FOREIGN_CUSTOMER_EXPOSED','P21_IDENTITY_OVERRIDE','P22_MUTATION_ADDED','P23_DTO_CHANGED','P24_INTERNAL_ID_EXPOSED','P25_HISTORICAL_FLAGS_LOST','P26_IMAGE_FALLBACK_LOST','P27_VALUES_CHANGED','P28_ORDER_CHANGED','P29_ACCESSIBILITY_LOST','P30_ASSETS_CHANGED','P31_PHASE8_CHANGED','P32_UNAUTHORIZED_CSS','P33_ALLOWLIST_BREACH','P34_BASELINE_DRIFT'];
$mutations = [
 ['js',"veciahorra-frontend va-design-system va-customer-panel__detail-item",'va-customer-panel__detail-item'],
 ['js',"listItem.setAttribute('data-va-customer-panel-detail-item', '');","listItem.setAttribute('data-va-customer-panel-detail-item', '');\n        listItem.setAttribute('data-va-customer-panel-detail-item', '');"],
 ['view','data-va-customer-panel','data-va-customer-panel data-va-customer-panel-detail-item'],
 ['js',"overview.setAttribute('data-va-customer-panel-detail-overview', '');","overview.setAttribute('data-va-customer-panel-detail-item', '');"],
 ['js',"var paymentSection = element('section',","var paymentSection = element('section', 'data-va-customer-panel-detail-item');\n        var ignored = element('section',"],
 ['js',"var deliverySection = element('section',","var deliverySection = element('section', 'data-va-customer-panel-detail-item');\n        var ignored = element('section',"],
 ['js',"function renderTimeline(entries, config) {","function renderTimeline(entries, config) {\n        var leak='data-va-customer-panel-detail-item';"],
 ['js',"function renderList(root, purchases, config) {","function renderList(root, purchases, config) {\n        var leak='data-va-customer-panel-detail-item';"],
 ['js',"function renderDetailLoading(state) {","function renderDetailLoading(state) {\n        var leak='data-va-customer-panel-detail-item';"],
 ['js',"function renderDetailNotFound(state) {","function renderDetailNotFound(state) {\n        var leak='data-va-customer-panel-detail-item';"],
 ['js',"function renderDetailRecoverableError(state) {","function renderDetailRecoverableError(state) {\n        var leak='data-va-customer-panel-detail-item';"],
 ['js',"listItem.setAttribute('data-va-customer-panel-detail-item', '');","content.setAttribute('data-va-customer-panel-detail-item', '');"],
 ['js',"function renderDetailOrder(order, currency, config) {","function renderDetailOrder(order, currency, config) {\n        var leak='data-va-customer-panel-detail-item';"],
 ['js','products.append(renderDetailItem(item, currency, config))','products.append(renderDetailItem(item, currency, config), renderDetailItem(item, currency, config))'],
 ['js','customer-panel/purchases','customer-panel/orders'],
 ['js','initialize();',"api.post('customer-panel/purchases');\n    initialize();"],
 ['js',"searchParams.set('compra'","searchParams.set('pedido'"],
 ['js','item.checkout_public_id)','item.checkout_public_id.slice(0,12))'],
 ['service','findOwnedCheckout($publicId, $userId)','findOwnedCheckout($publicId, 1)'],
 ['service',"(int) (\$order['customer_id'] ?? 0) !== \$userId",'false'],
 ['routes','public function purchase(WP_REST_Request $request): WP_REST_Response','public function purchase(WP_REST_Request $request): WP_REST_Response' . "\n    {\n        \$request->get_param('user_id');\n    }\n    public function purchaseBroken(WP_REST_Request \$request): WP_REST_Response"],
 ['js','initialize();',"api.delete('customer-panel/purchases');\n    initialize();"],
 ['itemDto',"'unit_price' => \$this->unitPrice", "'price' => \$this->unitPrice"],
 ['js',"var ENDPOINT = 'customer-panel/purchases';", "var leaked={'order_id':1};\n    var ENDPOINT = 'customer-panel/purchases';"],
 ['itemDto',"'name_historical' => false", "'legacy_name' => false"],
 ['js','image.onerror = function','image.disabledOnerror = function'],
 ['js',"detailValue('Cantidad', String(item.quantity))","detailValue('Unidades', String(item.quantity))"],
 ['query','ORDER BY oi.order_id ASC, oi.id ASC','ORDER BY oi.id DESC'],
 ['js',"image.alt = ''","image.alt = item.name"],
 ['assets','$this->enqueueDesignSystem();','$this->enqueue();'],
 ['js',"data-va-customer-panel-detail-overview', ''","data-va-customer-panel-detail-overview-disabled', ''"],
 ['css','.veciahorra-frontend .va-customer-panel__detail-item','[data-va-customer-panel-detail-item]'],
 ['browser','INTERCEPT_GET_ONLY','INTERCEPT_ANY_ROUTE'],
 ['schema',"SCHEMA_VERSION = '0.28.0'","SCHEMA_VERSION = '0.29.0'"],
];
foreach ($mutations as $i => [$target,$from,$to]) {
    $m=$sources; $m[$target]=preg_replace('/'.preg_quote($from,'/').'/',addcslashes($to,'\\$'),$m[$target],1)??'';
    $obtained=phase9Validate($m,false); phase9Assert(in_array($codes[$i],$obtained,true),"No rechazo {$codes[$i]}: ".json_encode($obtained));
    echo "PASS ADVERSARIAL expected={$codes[$i]} obtained={$codes[$i]}\n";
}
echo "PASS frontend-customer-purchase-detail-items-design-system-test adversarials=34\n";
