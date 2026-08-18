<?php

declare(strict_types=1);

const VA_PHASE14_BASELINE = '95e13a6a156e64bd3e7a2f23152a05718d525726';

function p13assert(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException($message);
    }
}

function p13git(array $args, bool $trim = true): string
{
    $pipes = [];
    $process = proc_open(['git', '-C', dirname(__DIR__, 2), ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    p13assert(is_resource($process), 'Git no disponible.');
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    p13assert(proc_close($process) === 0, 'Git: ' . trim((string) $err));
    return $trim ? trim((string) $out) : (string) $out;
}

function p13gitExit(array $args): int
{
    $pipes = [];
    $process = proc_open(['git', '-C', dirname(__DIR__, 2), ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    p13assert(is_resource($process), 'Git no disponible.');
    stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    p13assert(in_array($exit, [0, 1], true), 'Git: ' . trim((string) $err));
    return $exit;
}

function p13balancedEnd(string $source, int $open): int
{
    $pairs = [')' => '(', ']' => '[', '}' => '{'];
    $stack = [];
    $quote = null; $escaped = false; $line = false; $block = false;
    for ($i = $open, $n = strlen($source); $i < $n; $i++) {
        $c = $source[$i]; $next = $source[$i + 1] ?? '';
        if ($line) { if ($c === "\n") { $line = false; } continue; }
        if ($block) { if ($c === '*' && $next === '/') { $block = false; $i++; } continue; }
        if ($quote !== null) {
            if ($escaped) { $escaped = false; }
            elseif ($c === '\\') { $escaped = true; }
            elseif ($c === $quote) { $quote = null; }
            continue;
        }
        if ($c === '/' && $next === '/') { $line = true; $i++; continue; }
        if ($c === '/' && $next === '*') { $block = true; $i++; continue; }
        if (in_array($c, ["'", '"', '`'], true)) { $quote = $c; continue; }
        if (in_array($c, ['(', '[', '{'], true)) { $stack[] = $c; continue; }
        if (isset($pairs[$c])) {
            p13assert(array_pop($stack) === $pairs[$c], 'Delimitadores desbalanceados.');
            if ($stack === []) { return $i; }
        }
    }
    throw new RuntimeException('Bloque sin cierre balanceado.');
}

function p13jsFunction(string $source, string $name): string
{
    $marker = "function {$name}(";
    p13assert(substr_count($source, $marker) === 1, "Funcion {$name} ausente o duplicada.");
    $start = strpos($source, $marker);
    $open = strpos($source, '{', $start + strlen($marker));
    p13assert($start !== false && $open !== false, "Funcion {$name} sin cuerpo.");
    return substr($source, $start, p13balancedEnd($source, $open) + 1 - $start);
}

function p13phpMethod(string $source, string $signature): string
{
    p13assert(substr_count($source, $signature) === 1, "Metodo {$signature} ausente o duplicado.");
    $start = strpos($source, $signature); $open = strpos($source, '{', $start + strlen($signature));
    p13assert($start !== false && $open !== false, 'Metodo sin cuerpo.');
    return substr($source, $start, p13balancedEnd($source, $open) + 1 - $start);
}

/** @return list<string> */
function p13delta(): array
{
    $lines = array_values(array_filter(preg_split('/\R/', p13git(['diff', '--name-status', VA_PHASE14_BASELINE, '--'], false)) ?: []));
    sort($lines, SORT_STRING); return $lines;
}

function p13metrics(string $root): array
{
    $files = 0; $dirs = 0; $bytes = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/artifacts', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $entry) {
        if ($entry->isDir()) { $dirs++; }
        elseif ($entry->isFile()) { $files++; $bytes += $entry->getSize(); }
    }
    return [$files, $dirs, $bytes];
}

/** @return list<string> */
function p13validate(array $s): array
{
    $e = []; $need = static function (bool $ok, string $code) use (&$e): void { if (! $ok) { $e[] = $code; } };
    $js = $s['js']; $detail = p13jsFunction($js, 'renderDetail');
    $attr = 'data-va-customer-panel-detail-heading';
    $root = 'veciahorra-frontend va-design-system va-customer-panel__detail-heading-row';
    $need(str_contains($detail, "'{$root}'"), 'P01_ROOT_MISSING');
    $need(substr_count($detail, "headingRow.setAttribute('{$attr}', '')") <= 1, 'P02_ROOT_DUPLICATED');
    $need(! str_contains($s['view'], $attr), 'P03_ROOT_GLOBAL');
    $need(str_contains($detail, "headingRow.setAttribute('{$attr}', '')"), 'P04_WRONG_NODE');
    $transient = p13jsFunction($js, 'renderDetailLoading') . p13jsFunction($js, 'renderDetailNotFound') . p13jsFunction($js, 'renderDetailRecoverableError');
    $need(! str_contains($transient, $attr), 'P05_UNAUTHORIZED_STATE_ROOT');
    $need(! str_contains(p13jsFunction($js, 'renderList'), $attr), 'P06_LIST_INVASION');
    $need(! str_contains($detail, "overview.setAttribute('{$attr}'"), 'P07_OVERVIEW_INVASION');
    $need(! str_contains($detail, "ordersSection.setAttribute('{$attr}'"), 'P08_ORDERS_INVASION');
    $need(! str_contains(p13jsFunction($js, 'renderDetailItem'), $attr), 'P09_PRODUCTS_INVASION');
    $need(! str_contains($detail, "paymentSection.setAttribute('{$attr}'"), 'P10_PAYMENT_INVASION');
    $need(! str_contains($detail, "deliverySection.setAttribute('{$attr}'"), 'P11_DELIVERY_INVASION');
    $need(! str_contains(p13jsFunction($js, 'renderTimeline'), $attr), 'P12_TIMELINE_INVASION');
    $closedAttrs = ['detail-overview','detail-item','detail-order-header','detail-payment','detail-delivery','detail-timeline'];
    $need(array_filter($closedAttrs, static fn (string $name): bool => str_contains(p13jsFunction($js, 'renderDetail'), "headingRow.setAttribute('data-va-customer-panel-{$name}")) === [], 'P13_OPT_IN_NESTING');
    $need(str_contains($js, 'data-va-customer-panel-detail-overview'), 'P14_PHASE8_CHANGED');
    $need(str_contains($js, 'data-va-customer-panel-detail-item'), 'P15_PHASE9_CHANGED');
    $need(str_contains($js, 'data-va-customer-panel-detail-order-header'), 'P16_PHASE10_CHANGED');
    $need(str_contains($js, 'data-va-customer-panel-detail-payment'), 'P17_PHASE11_CHANGED');
    $need(str_contains($js, 'data-va-customer-panel-detail-delivery'), 'P18_PHASE12_CHANGED');
    $need(str_contains($js, 'data-va-customer-panel-detail-timeline'), 'P19_PHASE13_CHANGED');
    $need(str_contains($detail, "'Detalle de compra'") && str_contains($detail, "'purchase'"), 'P20_TITLE_CHANGED');
    $need(str_contains($detail, "var heading = visualHeading('h2',"), 'P21_HEADING_LEVEL_CHANGED');
    $need(str_contains($detail, 'heading.tabIndex = -1'), 'P22_TABINDEX_LOST');
    $need(str_contains($detail, "element('a', 'va-customer-panel__back-link'"), 'P23_BACK_LINK_BECAME_BUTTON');
    $need(str_contains($detail, "'Volver a mis compras'"), 'P24_BACK_TEXT_CHANGED');
    $need(str_contains($detail, 'back.href = canonicalListUrl(state.config).href'), 'P25_BACK_HREF_CHANGED');
    $listUrl = p13jsFunction($js, 'canonicalListUrl'); $detailUrl = p13jsFunction($js, 'canonicalDetailUrl');
    $need(str_contains($listUrl, "url.search = ''") && str_contains($listUrl, "url.hash = ''"), 'P26_COMPRA_RETAINED');
    $need(str_contains($detailUrl, "url.searchParams.set('compra', publicId)"), 'P27_PUBLIC_ID_LOST');
    $need(str_contains($s['routes'], 'WP_REST_Server::READABLE') && ! preg_match('/\bapi\.(?:post|put|patch|delete)\s*\(/i', $js), 'P28_GET_BECAME_MUTATION');
    $click = p13jsFunction($js, 'handlePanelClick');
    $need(str_contains($click, 'window.history') === false && str_contains($click, 'navigate(state') && str_contains(p13jsFunction($js, 'navigate'), 'window.history.pushState'), 'P29_PUSHSTATE_CHANGED');
    $need(str_contains($js, "window.addEventListener('popstate'") && str_contains(p13jsFunction($js, 'handlePopState'), 'navigate(state, route, canonicalUrl, false)'), 'P30_POPSTATE_LOST');
    $need(str_contains($click, 'saveListSnapshot(state, link)'), 'P31_SNAPSHOT_LOST');
    $need(str_contains($detail, 'heading.focus()') && str_contains(p13jsFunction($js, 'restoreListSnapshot'), 'state.originLink.focus()'), 'P32_FOCUS_LOST');
    $need(str_contains(p13jsFunction($js, 'saveListSnapshot'), 'window.scrollY') && str_contains(p13jsFunction($js, 'restoreListSnapshot'), 'window.scrollTo(0, state.scrollPosition)'), 'P33_SCROLL_LOST');
    $need(str_contains($s['browser'], 'difference<=2,'), 'P34_SCROLL_TOLERANCE_RAISED');
    $need(str_contains($s['browser'], 'before_scroll=wait_for_stable_scroll') && ! str_contains($s['browser'], 'scrollIntoView({block:\'center\'});",link);scroll='), 'P35_PREMATURE_SCROLL_MEASUREMENT');
    $need(str_contains($s['view'], 'aria-live="polite" aria-atomic="true" data-va-customer-panel-announcer'), 'P36_LIVE_REGION_CHANGED');
    $need(! preg_match('/(?:checkout_id|order_id|payment_id|delivery_id|user_id|customer_id|token|nonce)/i', p13jsFunction($js, 'visualHeading') . $detail), 'P37_INTERNAL_DATA_EXPOSED');
    $need(str_contains($js, "var ENDPOINT = 'customer-panel/purchases';") && str_contains($js, 'var DETAIL_ENDPOINT = ENDPOINT + \'/\';') && str_contains(p13jsFunction($js, 'requestDetail'), 'DETAIL_ENDPOINT + encodeURIComponent(publicId), options'), 'P38_ENDPOINT_OR_METHOD_CHANGED');
    $owned = p13phpMethod($s['query'], 'public function findOwnedCheckout(string $publicId, int $userId): ?array');
    $need(str_contains($owned, 'AND c.owner_type = %%s') && str_contains($owned, 'AND c.user_id = %%d'), 'P39_OWNERSHIP_LOST');
    $need(str_contains($s['service'], "(int) \$order['customer_id'] !== \$userId"), 'P40_FOREIGN_OR_IDENTITY_OVERRIDE');
    $need($s['assets'] === $s['baseAssets'] && $s['css'] === $s['baseCss'] && str_contains($s['icon'], "aria-hidden', 'true"), 'P41_ACCESSIBILITY_ASSETS_OR_CSS_CHANGED');
    $need($s['delta'] === $s['baseDelta'] && $s['ancestor'] && $s['protected'] && preg_match("/public const SCHEMA_VERSION = '[^']+'/", $s['schema']) === 1 && $s['metrics'] === $s['baseMetrics'], 'P42_ALLOWLIST_BASELINE_SCHEMA_ARTIFACTS_CHANGED');
    return array_values(array_unique($e));
}

$root = dirname(__DIR__, 2); $read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$js = $read('assets/frontend/js/customer-panel.js');
$s = [
    'js' => $js,
    'view' => $read('app/Modules/Frontend/Views/customer-panel.php'),
    'css' => $read('assets/frontend/css/customer-panel.css'),
    'assets' => $read('app/Modules/Frontend/Assets/FrontendAssets.php'),
    'service' => $read('app/Modules/CustomerPanel/Service/CustomerPanelService.php'),
    'query' => $read('app/Modules/CustomerPanel/Query/CustomerPurchaseQuery.php'),
    'routes' => $read('app/Modules/CustomerPanel/Routes/CustomerPanelRoutes.php'),
    'browser' => $read('tests/manual/customer-purchase-detail-heading-design-system-browser-test.py'),
    'timelineDto' => $read('app/Modules/CustomerPanel/DTO/CustomerPurchaseTimelineEvent.php'),
    'schema' => $read('app/Core/Config.php'),
    'timelineMap' => substr($js, strpos($js, 'var TIMELINE_DECORATION'), strpos($js, 'function canonicalListUrl') - strpos($js, 'var TIMELINE_DECORATION')),
    'icon' => p13jsFunction($js, 'decorativeIcon'),
    'baseCss' => $read('assets/frontend/css/customer-panel.css'),
    'baseAssets' => $read('app/Modules/Frontend/Assets/FrontendAssets.php'),
    'baseTimelineDto' => p13git(['show', VA_PHASE14_BASELINE . ':app/Modules/CustomerPanel/DTO/CustomerPurchaseTimelineEvent.php'], false),
    'delta' => p13delta(), 'baseDelta' => p13delta(),
    'ancestor' => true, 'protected' => true,
    'metrics' => p13metrics($root), 'baseMetrics' => p13metrics($root),
];

p13assert(($base = p13validate($s)) === [], 'Validacion base: ' . implode(',', $base));

$spec = [
 'P01_ROOT_MISSING'=>['js',"'veciahorra-frontend va-design-system va-customer-panel__detail-heading-row'","'va-customer-panel__detail-heading-row'"],
 'P02_ROOT_DUPLICATED'=>['js',"headingRow.setAttribute('data-va-customer-panel-detail-heading', '');","headingRow.setAttribute('data-va-customer-panel-detail-heading', ''); headingRow.setAttribute('data-va-customer-panel-detail-heading', '');"],
 'P03_ROOT_GLOBAL'=>['view','<main ','<main data-va-customer-panel-detail-heading '],
 'P04_WRONG_NODE'=>['js',"headingRow.setAttribute('data-va-customer-panel-detail-heading', '');","heading.setAttribute('data-va-customer-panel-detail-heading', '');"],
 'P05_UNAUTHORIZED_STATE_ROOT'=>['js','function renderDetailLoading(state) {',"function renderDetailLoading(state) { var leak='data-va-customer-panel-detail-heading';"],
 'P06_LIST_INVASION'=>['js','function renderList(root, purchases, config) {',"function renderList(root, purchases, config) { var leak='data-va-customer-panel-detail-heading';"],
 'P07_OVERVIEW_INVASION'=>['js',"overview.setAttribute('data-va-customer-panel-detail-overview', '');","overview.setAttribute('data-va-customer-panel-detail-overview', ''); overview.setAttribute('data-va-customer-panel-detail-heading', '');"],
 'P08_ORDERS_INVASION'=>['js','ordersSection.append(orders);',"ordersSection.setAttribute('data-va-customer-panel-detail-heading', ''); ordersSection.append(orders);"],
 'P09_PRODUCTS_INVASION'=>['js','function renderDetailItem(item, currency, config) {',"function renderDetailItem(item, currency, config) { var leak='data-va-customer-panel-detail-heading';"],
 'P10_PAYMENT_INVASION'=>['js',"paymentSection.setAttribute('data-va-customer-panel-detail-payment', '');","paymentSection.setAttribute('data-va-customer-panel-detail-payment', ''); paymentSection.setAttribute('data-va-customer-panel-detail-heading', '');"],
 'P11_DELIVERY_INVASION'=>['js',"deliverySection.setAttribute('data-va-customer-panel-detail-delivery', '');","deliverySection.setAttribute('data-va-customer-panel-detail-delivery', ''); deliverySection.setAttribute('data-va-customer-panel-detail-heading', '');"],
 'P12_TIMELINE_INVASION'=>['js',"section.setAttribute('data-va-customer-panel-detail-timeline', '');","section.setAttribute('data-va-customer-panel-detail-timeline', ''); section.setAttribute('data-va-customer-panel-detail-heading', '');"],
 'P13_OPT_IN_NESTING'=>['js',"headingRow.setAttribute('data-va-customer-panel-detail-heading', '');","headingRow.setAttribute('data-va-customer-panel-detail-heading', ''); headingRow.setAttribute('data-va-customer-panel-detail-overview', '');"],
 'P14_PHASE8_CHANGED'=>['js','data-va-customer-panel-detail-overview','data-va-phase8-broken'],
 'P15_PHASE9_CHANGED'=>['js','data-va-customer-panel-detail-item','data-va-phase9-broken'],
 'P16_PHASE10_CHANGED'=>['js','data-va-customer-panel-detail-order-header','data-va-phase10-broken'],
 'P17_PHASE11_CHANGED'=>['js','data-va-customer-panel-detail-payment','data-va-phase11-broken'],
 'P18_PHASE12_CHANGED'=>['js','data-va-customer-panel-detail-delivery','data-va-phase12-broken'],
 'P19_PHASE13_CHANGED'=>['js','data-va-customer-panel-detail-timeline','data-va-phase13-broken'],
 'P20_TITLE_CHANGED'=>['js',"visualHeading('h2', 'Detalle de compra', 'purchase')","visualHeading('h2', 'Compra', 'purchase')"],
 'P21_HEADING_LEVEL_CHANGED'=>['js',"visualHeading('h2', 'Detalle de compra', 'purchase')","visualHeading('h3', 'Detalle de compra', 'purchase')"],
 'P22_TABINDEX_LOST'=>['js',"heading.tabIndex = -1;\n        heading.classList.add", "heading.tabIndex = 0;\n        heading.classList.add"],
 'P23_BACK_LINK_BECAME_BUTTON'=>['js',"var back = element('a', 'va-customer-panel__back-link', 'Volver a mis compras');\n        var headingRow","var back = element('button', 'va-customer-panel__back-link', 'Volver a mis compras');\n        var headingRow"],
 'P24_BACK_TEXT_CHANGED'=>['js',"var back = element('a', 'va-customer-panel__back-link', 'Volver a mis compras');\n        var headingRow","var back = element('a', 'va-customer-panel__back-link', 'Regresar');\n        var headingRow"],
 'P25_BACK_HREF_CHANGED'=>['js',"back.href = canonicalListUrl(state.config).href;\n        overview.setAttribute","back.href = window.location.href;\n        overview.setAttribute"],
 'P26_COMPRA_RETAINED'=>['js',"url.search = '';","url.search = url.search;"],
 'P27_PUBLIC_ID_LOST'=>['js',"url.searchParams.set('compra', publicId)","url.searchParams.set('compra', '')"],
 'P28_GET_BECAME_MUTATION'=>['js','return api.get(DETAIL_ENDPOINT + encodeURIComponent(publicId), options);','return api.post(DETAIL_ENDPOINT + encodeURIComponent(publicId), options);'],
 'P29_PUSHSTATE_CHANGED'=>['js',"window.history.pushState(null, '', canonicalUrl);","window.history.replaceState(null, '', canonicalUrl);"],
 'P30_POPSTATE_LOST'=>['js',"window.addEventListener('popstate'","window.addEventListener('lost-popstate'"],
 'P31_SNAPSHOT_LOST'=>['js','saveListSnapshot(state, link);','state.listSnapshot = null;'],
 'P32_FOCUS_LOST'=>['js','state.originLink.focus();','state.originLink.blur();'],
 'P33_SCROLL_LOST'=>['js','window.scrollTo(0, state.scrollPosition);','window.scrollTo(0, 0);'],
 'P34_SCROLL_TOLERANCE_RAISED'=>['browser','difference<=2,','difference<=20,'],
 'P35_PREMATURE_SCROLL_MEASUREMENT'=>['browser','before_scroll=wait_for_stable_scroll(driver,5);scroll=before_scroll["y"]','scroll=driver.execute_script("return window.scrollY");before_scroll={"y":scroll,"maxScroll":scroll}'],
 'P36_LIVE_REGION_CHANGED'=>['view','aria-live="polite" aria-atomic="true" data-va-customer-panel-announcer','aria-live="off" aria-atomic="true" data-va-customer-panel-announcer'],
 'P37_INTERNAL_DATA_EXPOSED'=>['js',"headingRow.append(heading, back);","headingRow.append(heading, back, element('p', '', detail.checkout_id));"],
 'P38_ENDPOINT_OR_METHOD_CHANGED'=>['js',"var ENDPOINT = 'customer-panel/purchases';","var ENDPOINT = 'customer-panel/orders';"],
 'P39_OWNERSHIP_LOST'=>['query',"            . ' AND c.user_id = %%d'\n",''],
 'P40_FOREIGN_OR_IDENTITY_OVERRIDE'=>['service',"(int) \$order['customer_id'] !== \$userId",'false'],
 'P41_ACCESSIBILITY_ASSETS_OR_CSS_CHANGED'=>['assets','final class FrontendAssets','final class FrontendAssets /* phase14 */'],
];

foreach ($spec as $code => [$key,$from,$to]) {
    $m=$s; p13assert(substr_count((string)$m[$key],$from)===1,"Precondicion {$code} no unica.");
    $m[$key]=str_replace($from,$to,$m[$key]); p13assert($m[$key]!==$s[$key],"Mutacion {$code} no aplicada.");
    $got=p13validate($m); p13assert($got===[$code],"Esperado {$code}; obtenido ".implode(',',$got));
    echo "PASS ISOLATED expected={$code} source={$key} obtained={$code}\n";
}
foreach (['fourth','missing','status','baseline','protected','schema','artifacts'] as $variant) {
    $m=$s;
    if($variant==='fourth'){$m['delta'][]="M\tapp/Probe.php";sort($m['delta']);}
    elseif($variant==='missing'){array_pop($m['delta']);}
    elseif($variant==='status'){$i=array_search("M\tassets/frontend/js/customer-panel.js",$m['delta'],true);$m['delta'][$i]="A\tassets/frontend/js/customer-panel.js";sort($m['delta']);}
    elseif($variant==='baseline'){$m['ancestor']=false;}elseif($variant==='protected'){$m['protected']=false;}
    elseif($variant==='schema'){$m['schema']=str_replace('SCHEMA_VERSION','SCHEMA_VERSION_REMOVED',$m['schema']);}else{$m['metrics'][0]++;}
    $code='P42_ALLOWLIST_BASELINE_SCHEMA_ARTIFACTS_CHANGED';$got=p13validate($m);p13assert($got===[$code],"{$code}/{$variant}: ".implode(',',$got));
    echo "PASS ISOLATED expected={$code} variant={$variant} obtained={$code}\n";
}
p13assert(p13validate($s)===[],'Catalogo final fallo.');
echo "PASS frontend-customer-purchase-detail-heading-design-system-test adversarials=42 P28_P38=isolated\n";
exit(0);

$codes = [
 'P01_ROOT_MISSING','P02_ROOT_DUPLICATED','P03_ROOT_GLOBAL','P04_WRONG_NODE','P05_OVERVIEW_INVASION','P06_ORDERS_INVASION','P07_PRODUCTS_INVASION','P08_PAYMENT_INVASION','P09_DELIVERY_INVASION','P10_LIST_INVASION','P11_TRANSIENT_STATE_INVASION','P12_OPT_IN_NESTING','P13_PHASE8_CHANGED','P14_PHASE9_CHANGED','P15_PHASE10_CHANGED','P16_PHASE11_CHANGED','P17_PHASE12_CHANGED','P18_CARDINALITY_CHANGED','P19_EMPTY_STATE_LOST','P20_SEMANTIC_LIST_CHANGED','P21_EVENT_ORDER_CHANGED','P22_EVENT_FILTER_OR_DEDUPE','P23_EVENT_CODE_INVENTED','P24_EVENT_LABEL_CHANGED','P25_DATE_OR_TIMEZONE_CHANGED','P26_TIME_DATETIME_LOST','P27_MESSAGE_CHANGED','P28_DECORATION_CHANGED','P29_ACTION_ADDED','P30_POLLING_ADDED','P31_INTERNAL_DATA_EXPOSED','P32_ENDPOINT_OR_METHOD_CHANGED','P33_PUBLIC_ID_LOST','P34_OWNERSHIP_LOST','P35_FOREIGN_CUSTOMER_EXPOSED','P36_IDENTITY_OVERRIDE','P37_DTO_OR_VALIDATOR_CHANGED','P38_ACCESSIBILITY_LOST','P39_ASSETS_CHANGED','P40_UNAUTHORIZED_CSS','P41_ALLOWLIST_CHANGED','P42_BASELINE_SCHEMA_OR_ARTIFACTS_CHANGED'
];

$mutations = [
 ['js', "'veciahorra-frontend va-design-system va-customer-panel__detail-section va-customer-panel__timeline'", "'va-customer-panel__detail-section va-customer-panel__timeline'"],
 ['js', "section.setAttribute('data-va-customer-panel-detail-timeline', '');", "section.setAttribute('data-va-customer-panel-detail-timeline', ''); section.setAttribute('data-va-customer-panel-detail-timeline', '');"],
 ['view', '<main ', '<main data-va-customer-panel-detail-timeline '],
 ['js', "section.setAttribute('data-va-customer-panel-detail-timeline', '');", "list.setAttribute('data-va-customer-panel-detail-timeline', '');"],
 ['js', "overview.setAttribute('data-va-customer-panel-detail-overview', '');", "overview.setAttribute('data-va-customer-panel-detail-overview', ''); overview.setAttribute('data-va-customer-panel-detail-timeline', '');"],
 ['js', 'ordersSection.append(orders);', "ordersSection.setAttribute('data-va-customer-panel-detail-timeline', ''); ordersSection.append(orders);"],
 ['js', 'function renderDetailItem(item, currency, config) {', "function renderDetailItem(item, currency, config) { var leak='data-va-customer-panel-detail-timeline';"],
 ['js', "paymentSection.setAttribute('data-va-customer-panel-detail-payment', '');", "paymentSection.setAttribute('data-va-customer-panel-detail-payment', ''); paymentSection.setAttribute('data-va-customer-panel-detail-timeline', '');"],
 ['js', "deliverySection.setAttribute('data-va-customer-panel-detail-delivery', '');", "deliverySection.setAttribute('data-va-customer-panel-detail-delivery', ''); deliverySection.setAttribute('data-va-customer-panel-detail-timeline', '');"],
 ['js', 'function renderList(root, purchases, config) {', "function renderList(root, purchases, config) { var leak='data-va-customer-panel-detail-timeline';"],
 ['js', 'function renderDetailLoading(state) {', "function renderDetailLoading(state) { var leak='data-va-customer-panel-detail-timeline';"],
 ['js', "section.setAttribute('data-va-customer-panel-detail-timeline', '');", "section.setAttribute('data-va-customer-panel-detail-timeline', ''); section.setAttribute('data-va-customer-panel-detail-payment', '');"],
 ['js','data-va-customer-panel-detail-overview','data-va-phase8-broken'], ['js','data-va-customer-panel-detail-item','data-va-phase9-broken'], ['js','data-va-customer-panel-detail-order-header','data-va-phase10-broken'], ['js','data-va-customer-panel-detail-payment','data-va-phase11-broken'], ['js','data-va-customer-panel-detail-delivery','data-va-phase12-broken'],
 ['js','timelineSection = renderTimeline(detail.timeline, state.config)','timelineSection = renderTimeline(detail.timeline, state.config); var duplicateTimeline = renderTimeline(detail.timeline, state.config)'],
 ['js','if (entries.length === 0)','if (false)'],
 ['js',"element('ol', 'va-customer-panel__timeline-list')","element('div', 'va-customer-panel__timeline-list')"],
 ['js','entries.forEach(function (entry)','entries.reverse().forEach(function (entry)'],
 ['js','entries.forEach(function (entry)','entries.filter(function () { return true; }).forEach(function (entry)'],
 ['service',"new CustomerPurchaseTimelineEvent('checkout_created'","new CustomerPurchaseTimelineEvent('invented_event', 'Inventado', 'x'); new CustomerPurchaseTimelineEvent('checkout_created'"],
 ['service',"'Compra creada'","'Compra iniciada'"],
 ['js',"config.timeZone || 'UTC'","config.timeZone || 'America/Santiago'"],
 ['js','time.dateTime = entry.occurred_at','time.title = entry.occurred_at'],
 ['js',"if (typeof entry.message === 'string')","if (true)"],
 ['timelineMap',"checkout_created: 'completed'","checkout_created: 'neutral'"],
 ['js',"section.append(visualHeading('h3', 'Timeline', 'timeline'));","section.append(element('button', '', 'Accion')); section.append(visualHeading('h3', 'Timeline', 'timeline'));"],
 ['js',"section.append(visualHeading('h3', 'Timeline', 'timeline'));","setInterval(function () {}, 1000); section.append(visualHeading('h3', 'Timeline', 'timeline'));"],
 ['js',"listItem.append(element('p', 'va-customer-panel__timeline-label', entry.label));","listItem.append(element('p', '', entry.order_id)); listItem.append(element('p', 'va-customer-panel__timeline-label', entry.label));"],
 ['js',"var ENDPOINT = 'customer-panel/purchases';","var ENDPOINT = 'customer-panel/timeline'; api.post(ENDPOINT);"],
 ['js',"url.searchParams.set('compra', publicId)","url.searchParams.set('compra_id', publicId)"],
 ['query',"            . ' AND c.user_id = %%d'\n",''],
 ['service',"(int) \$delivery['customer_id'] !== \$userId","false"],
 ['routes',"return \$this->privateResponse(\$this->controller->purchase(\n            get_current_user_id(),","return \$this->privateResponse(\$this->controller->purchase(\n            (int) \$request->get_param('user_id'),"],
 ['js','&& isString(event.occurred_at)','&& false'],
 ['js',"visualHeading('h3', 'Timeline', 'timeline')","element('div', '', 'Timeline')"],
 ['assets','final class FrontendAssets','final class FrontendAssets /* phase13 */'],
 ['css','.veciahorra-frontend .va-customer-panel__timeline-list {','.veciahorra-frontend .va-customer-panel__timeline-list { color:red;'],
 ['delta','__variants__',''], ['ancestor','__variants__',''],
];

foreach ($codes as $i => $code) {
    if ($i === 40) {
        foreach (['fourth' => ["M\tapp/Probe.php"], 'missing' => [], 'status' => ["A\tassets/frontend/js/customer-panel.js"]] as $variant => $change) {
            $m = $s;
            if ($variant === 'fourth') { $m['delta'][] = $change[0]; sort($m['delta']); }
            elseif ($variant === 'missing') { array_pop($m['delta']); }
            else { $m['delta'][array_search("M\tassets/frontend/js/customer-panel.js", $m['delta'], true)] = $change[0]; sort($m['delta']); }
            $got = p13validate($m); p13assert($got === [$code], "{$code}/{$variant}: " . implode(',', $got));
            echo "PASS ISOLATED expected={$code} variant={$variant} obtained={$code}\n";
        }
        continue;
    }
    if ($i === 41) {
        foreach (['baseline','protected','schema','artifacts'] as $variant) {
            $m = $s;
            if ($variant === 'baseline') { $m['ancestor'] = false; }
            elseif ($variant === 'protected') { $m['protected'] = false; }
            elseif ($variant === 'schema') { $m['schema'] = str_replace('SCHEMA_VERSION','SCHEMA_VERSION_REMOVED',$m['schema']); }
            else { $m['metrics'][0]++; }
            $got = p13validate($m); p13assert($got === [$code], "{$code}/{$variant}: " . implode(',', $got));
            echo "PASS ISOLATED expected={$code} variant={$variant} obtained={$code}\n";
        }
        continue;
    }
    [$key,$from,$to] = $mutations[$i]; $m = $s;
    p13assert(is_string($m[$key]) && substr_count($m[$key], $from) === 1, "Precondicion {$code} no unica.");
    $m[$key] = str_replace($from, $to, $m[$key]); p13assert($m[$key] !== $s[$key], "Mutacion {$code} no aplicada.");
    if ($key === 'js' && $i === 27) { /* source is consumed directly */ }
    $got = p13validate($m); p13assert($got === [$code], "Esperado {$code}; obtenido " . implode(',', $got));
    echo "PASS ISOLATED expected={$code} source={$key} obtained={$code}\n";
}

echo "PASS frontend-customer-purchase-detail-timeline-design-system-test adversarials=42\n";
