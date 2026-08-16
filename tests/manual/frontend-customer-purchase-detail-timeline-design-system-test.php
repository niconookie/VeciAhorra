<?php

declare(strict_types=1);

const VA_PHASE13_BASELINE = 'bde6898ff920baf361e4c0399b09ef119b5fadce';

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
    $lines = array_values(array_filter(preg_split('/\R/', p13git(['diff', '--name-status', VA_PHASE13_BASELINE, '--'], false)) ?: []));
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
    $js = $s['js']; $timeline = p13jsFunction($js, 'renderTimeline'); $entry = p13jsFunction($js, 'renderTimelineEntry');
    $detail = p13jsFunction($js, 'renderDetail'); $validator = p13jsFunction($js, 'validTimelineEvent'); $date = p13jsFunction($js, 'formatDate');
    $attr = 'data-va-customer-panel-detail-timeline';
    $root = 'veciahorra-frontend va-design-system va-customer-panel__detail-section va-customer-panel__timeline';
    $need(str_contains($timeline, "'{$root}'"), 'P01_ROOT_MISSING');
    $need(substr_count($timeline, $attr) === 1, 'P02_ROOT_DUPLICATED');
    $need(! str_contains($s['view'], $attr), 'P03_ROOT_GLOBAL');
    $need(str_contains($timeline, "section.setAttribute('{$attr}', '')"), 'P04_WRONG_NODE');
    $need(! str_contains($detail, "overview.setAttribute('{$attr}'"), 'P05_OVERVIEW_INVASION');
    $need(! str_contains($detail, "ordersSection.setAttribute('{$attr}'"), 'P06_ORDERS_INVASION');
    $need(! str_contains(p13jsFunction($js, 'renderDetailItem'), $attr), 'P07_PRODUCTS_INVASION');
    $need(! str_contains($detail, "paymentSection.setAttribute('{$attr}'"), 'P08_PAYMENT_INVASION');
    $need(! str_contains($detail, "deliverySection.setAttribute('{$attr}'"), 'P09_DELIVERY_INVASION');
    $need(! str_contains(p13jsFunction($js, 'renderList'), $attr), 'P10_LIST_INVASION');
    $transient = p13jsFunction($js, 'renderDetailLoading') . p13jsFunction($js, 'renderDetailNotFound') . p13jsFunction($js, 'renderDetailRecoverableError');
    $need(! str_contains($transient, $attr), 'P11_TRANSIENT_STATE_INVASION');
    $closedAttrs = ['detail-overview','detail-item','detail-order-header','detail-payment','detail-delivery'];
    $need(array_filter($closedAttrs, static fn (string $name): bool => str_contains($timeline, 'data-va-customer-panel-' . $name)) === [], 'P12_OPT_IN_NESTING');
    $need(str_contains($js, 'data-va-customer-panel-detail-overview'), 'P13_PHASE8_CHANGED');
    $need(str_contains($js, 'data-va-customer-panel-detail-item'), 'P14_PHASE9_CHANGED');
    $need(str_contains($js, 'data-va-customer-panel-detail-order-header'), 'P15_PHASE10_CHANGED');
    $need(str_contains($js, 'data-va-customer-panel-detail-payment'), 'P16_PHASE11_CHANGED');
    $need(str_contains($js, 'data-va-customer-panel-detail-delivery'), 'P17_PHASE12_CHANGED');
    $need(substr_count($detail, 'renderTimeline(detail.timeline, state.config)') === 1, 'P18_CARDINALITY_CHANGED');
    $need(str_contains($timeline, 'if (entries.length === 0)') && str_contains($timeline, "'No hay eventos para mostrar.'") && str_contains($timeline, 'return section;'), 'P19_EMPTY_STATE_LOST');
    $need(str_contains($timeline, "element('ol', 'va-customer-panel__timeline-list')") && str_contains($entry, "'li',\n            'va-customer-panel__timeline-entry"), 'P20_SEMANTIC_LIST_CHANGED');
    $need(str_contains($timeline, 'forEach(function (entry)') && ! preg_match('/entries\.(?:sort|reverse)\s*\(/', $timeline), 'P21_EVENT_ORDER_CHANGED');
    $need(! preg_match('/entries\.(?:filter|reduce)\s*\(|new Set\s*\(/', $timeline), 'P22_EVENT_FILTER_OR_DEDUPE');
    $catalog = ['checkout_created','payment_confirmed','payment_reconciled','orders_materialized','delivery_created'];
    $need(substr_count($s['service'], 'new CustomerPurchaseTimelineEvent(') === 5 && array_reduce($catalog, static fn (bool $ok, string $code): bool => $ok && substr_count($s['service'], "new CustomerPurchaseTimelineEvent('{$code}'") === 1, true), 'P23_EVENT_CODE_INVENTED');
    $labels = ['Compra creada','Pago confirmado','Pago conciliado','Pedidos preparados en el sistema','Despacho creado'];
    $need(array_reduce($labels, static fn (bool $ok, string $label): bool => $ok && substr_count($s['service'], "'{$label}'") === 1, true), 'P24_EVENT_LABEL_CHANGED');
    $need(str_contains($date, "config.locale || 'es-CL'") && str_contains($date, "config.timeZone || 'UTC'") && str_contains($date, "return 'Fecha no disponible'") && str_contains($s['service'], "'Y-m-d\\TH:i:s\\Z'"), 'P25_DATE_OR_TIMEZONE_CHANGED');
    $need(str_contains($entry, 'time.dateTime = entry.occurred_at') && str_contains($entry, "element('time', 'va-customer-panel__timeline-time'"), 'P26_TIME_DATETIME_LOST');
    $need(str_contains($entry, "if (typeof entry.message === 'string')") && str_contains($entry, 'entry.message'), 'P27_MESSAGE_CHANGED');
    $need(array_reduce($catalog, static fn (bool $ok, string $code): bool => $ok && str_contains($s['timelineMap'], "{$code}: 'completed'"), true) && str_contains($entry, "TIMELINE_DECORATION[entry.code] || 'neutral'"), 'P28_DECORATION_CHANGED');
    $need(! preg_match('/\b(?:button|form)\b|element\(\s*[\'"]a[\'"]/', $timeline . $entry), 'P29_ACTION_ADDED');
    $need(! preg_match('/\bset(?:Interval|Timeout)\s*\(/', $timeline . $entry), 'P30_POLLING_ADDED');
    $need(! preg_match('/(?:order_id|payment_id|delivery_id|courier_id|customer_id|user_id|token|webpay)/i', $timeline . $entry . $s['timelineDto']), 'P31_INTERNAL_DATA_EXPOSED');
    $need(str_contains($js, "var ENDPOINT = 'customer-panel/purchases';") && ! preg_match('/\bapi\.(?:post|put|patch|delete)\s*\(/i', $js), 'P32_ENDPOINT_OR_METHOD_CHANGED');
    $need(str_contains($js, 'detail.checkout_public_id') && str_contains($js, "url.searchParams.set('compra', publicId)"), 'P33_PUBLIC_ID_LOST');
    $owned = p13phpMethod($s['query'], 'public function findOwnedCheckout(string $publicId, int $userId): ?array');
    $need(str_contains($owned, 'AND c.owner_type = %%s') && str_contains($owned, 'AND c.user_id = %%d') && str_contains($owned, "\$publicId, 'user', \$userId"), 'P34_OWNERSHIP_LOST');
    $need(str_contains($s['service'], "(int) (\$payment['customer_id'] ?? 0) !== \$userId") && str_contains($s['service'], "(int) \$delivery['customer_id'] !== \$userId") && str_contains($s['service'], "(int) \$order['customer_id'] !== \$userId"), 'P35_FOREIGN_CUSTOMER_EXPOSED');
    $need(! preg_match('/get_(?:query|body|json)_params[^;]*(?:user_id|customer_id)|get_param\s*\(\s*[\'"](?:user_id|customer_id)/', $s['routes']), 'P36_IDENTITY_OVERRIDE');
    $need(str_contains($validator, 'isString(event.code)') && str_contains($validator, 'isString(event.label)') && str_contains($validator, 'isString(event.occurred_at)') && ! preg_match('/checkout_created|payment_confirmed|delivery_created/', $validator) && $s['timelineDto'] === $s['baseTimelineDto'], 'P37_DTO_OR_VALIDATOR_CHANGED');
    $need(str_contains($timeline, "visualHeading('h3', 'Timeline', 'timeline')") && str_contains($s['icon'], "aria-hidden', 'true") && str_contains($s['icon'], "focusable', 'false"), 'P38_ACCESSIBILITY_LOST');
    $need($s['assets'] === $s['baseAssets'], 'P39_ASSETS_CHANGED');
    $need($s['css'] === $s['baseCss'], 'P40_UNAUTHORIZED_CSS');
    $expected = ["A\ttests/manual/customer-purchase-detail-timeline-design-system-browser-test.py", "A\ttests/manual/frontend-customer-purchase-detail-timeline-design-system-test.php", "M\tassets/frontend/js/customer-panel.js"];
    sort($expected, SORT_STRING);
    $need($s['delta'] === $expected, 'P41_ALLOWLIST_CHANGED');
    $need($s['ancestor'] && $s['protected'] && str_contains($s['schema'], "SCHEMA_VERSION = '0.28.0'") && $s['metrics'] === [513,309,28537157], 'P42_BASELINE_SCHEMA_OR_ARTIFACTS_CHANGED');
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
    'timelineDto' => $read('app/Modules/CustomerPanel/DTO/CustomerPurchaseTimelineEvent.php'),
    'schema' => $read('app/Core/Config.php'),
    'timelineMap' => substr($js, strpos($js, 'var TIMELINE_DECORATION'), strpos($js, 'function canonicalListUrl') - strpos($js, 'var TIMELINE_DECORATION')),
    'icon' => p13jsFunction($js, 'decorativeIcon'),
    'baseCss' => p13git(['show', VA_PHASE13_BASELINE . ':assets/frontend/css/customer-panel.css'], false),
    'baseAssets' => p13git(['show', VA_PHASE13_BASELINE . ':app/Modules/Frontend/Assets/FrontendAssets.php'], false),
    'baseTimelineDto' => p13git(['show', VA_PHASE13_BASELINE . ':app/Modules/CustomerPanel/DTO/CustomerPurchaseTimelineEvent.php'], false),
    'delta' => p13delta(),
    'ancestor' => p13gitExit(['merge-base','--is-ancestor',VA_PHASE13_BASELINE,'HEAD']) === 0,
    'protected' => p13gitExit(['diff','--quiet',VA_PHASE13_BASELINE,'--','assets/frontend/css/customer-panel.css','app/Modules/Frontend/Views/customer-panel.php','app/Modules/Frontend/Assets/FrontendAssets.php','app/Modules/CustomerPanel','app/Core','app/Database']) === 0,
    'metrics' => p13metrics($root),
];

p13assert(($base = p13validate($s)) === [], 'Validacion base: ' . implode(',', $base));

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
            elseif ($variant === 'schema') { $m['schema'] = str_replace('0.28.0','0.29.0',$m['schema']); }
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
