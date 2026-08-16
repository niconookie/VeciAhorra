<?php

declare(strict_types=1);

const VA_PHASE12_BASELINE = '6cbf3c24dd5eb3635cbd844242e9ae0e9fcbddd2';

function p12assert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function p12git(array $arguments, bool $trim = true): string
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
    p12assert(is_resource($process), 'Git no disponible.');
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    p12assert(proc_close($process) === 0, 'Git: ' . trim((string) $error));
    return $trim ? trim((string) $output) : (string) $output;
}

function p12gitExit(array $arguments): int
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
    p12assert(is_resource($process), 'Git no disponible.');
    stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    p12assert(in_array($exitCode, [0, 1], true), 'Git: ' . trim((string) $error));
    return $exitCode;
}

/** @return list<string> */
function p12effectiveDelta(): array
{
    $lines = array_values(array_filter(
        preg_split('/\R/', p12git(['diff', '--name-status', VA_PHASE12_BASELINE, '--'], false)) ?: [],
        static fn (string $line): bool => $line !== ''
    ));
    sort($lines, SORT_STRING);
    return $lines;
}

/** @return array{files:int,directories:int,bytes:int} */
function p12artifactMetrics(string $root): array
{
    $files = 0;
    $directories = 0;
    $bytes = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/artifacts', FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir()) {
            $directories++;
        } elseif ($entry->isFile()) {
            $files++;
            $bytes += $entry->getSize();
        }
    }
    return ['files' => $files, 'directories' => $directories, 'bytes' => $bytes];
}

function p12hasDeliveryAllowlist(string $validator, string $property): bool
{
    $field = 'detail\\.delivery\\.' . preg_quote($property, '/');
    $literal = '[\'\"][^\'\"]+[\'\"]';

    return preg_match('/' . $field . '\\s*(?:={2,3}|!={1,2})\\s*' . $literal . '/', $validator) === 1
        || preg_match('/' . $literal . '\\s*(?:={2,3}|!={1,2})\\s*' . $field . '/', $validator) === 1
        || preg_match('/\\bswitch\\s*\\(\\s*' . $field . '\\s*\\)/', $validator) === 1
        || preg_match('/(?:\\.includes|\\.indexOf)\\s*\\(\\s*' . $field . '\\s*\\)/', $validator) === 1
        || preg_match('/\\.test\\s*\\(\\s*' . $field . '\\s*\\)/', $validator) === 1;
}

function p12method(string $source, string $name): string
{
    $start = strpos($source, "    function {$name}(");
    p12assert($start !== false, "Función {$name} ausente.");
    $next = strpos($source, "\n    function ", $start + 1);
    return substr($source, $start, $next === false ? null : $next - $start);
}

function p12balancedObjectEnd(string $source, int $open): int
{
    p12assert(($source[$open] ?? '') === '{', 'Inicio de objeto deliveryStatus invalido.');
    $depth = 0;
    $quote = null;
    $lineComment = false;
    $blockComment = false;
    $escaped = false;
    $length = strlen($source);

    for ($index = $open; $index < $length; $index++) {
        $character = $source[$index];
        $next = $source[$index + 1] ?? '';
        if ($lineComment) {
            if ($character === "\n") {
                $lineComment = false;
            }
            continue;
        }
        if ($blockComment) {
            if ($character === '*' && $next === '/') {
                $blockComment = false;
                $index++;
            }
            continue;
        }
        if ($quote !== null) {
            if ($escaped) {
                $escaped = false;
            } elseif ($character === '\\') {
                $escaped = true;
            } elseif ($character === $quote) {
                $quote = null;
            }
            continue;
        }
        if ($character === '/' && $next === '/') {
            $lineComment = true;
            $index++;
            continue;
        }
        if ($character === '/' && $next === '*') {
            $blockComment = true;
            $index++;
            continue;
        }
        if (in_array($character, ["'", '"', '`'], true)) {
            $quote = $character;
            continue;
        }
        if ($character === '{') {
            $depth++;
        } elseif ($character === '}') {
            $depth--;
            if ($depth === 0) {
                return $index;
            }
        }
    }

    throw new RuntimeException('Objeto deliveryStatus sin cierre balanceado.');
}

function p12deliveryStatusBlock(string $render, bool $strictStart = false): string
{
    $strictMarker = "var deliveryStatus = element('p', 'va-customer-panel__delivery-status');";
    $startMarker = $strictStart ? $strictMarker : 'var deliveryStatus = element(';
    $appendMarker = 'deliveryStatus.append(renderStatusBadge({';
    p12assert(substr_count($render, $startMarker) === 1, 'Bloque deliveryStatus ausente o duplicado.');
    p12assert(! $strictStart || substr_count($render, $strictMarker) === 1, 'Precondicion P24: inicio deliveryStatus alterado.');
    p12assert(substr_count($render, $appendMarker) === 1, 'Badge deliveryStatus ausente o duplicado.');
    $start = strpos($render, $startMarker);
    $append = strpos($render, $appendMarker, $start + strlen($startMarker));
    p12assert($start !== false && $append !== false, 'Badge fuera del bloque deliveryStatus.');
    $open = strpos($render, '{', $append + strlen($appendMarker) - 1);
    p12assert($open !== false, 'Objeto deliveryStatus ausente.');
    $close = p12balancedObjectEnd($render, $open);
    p12assert(substr($render, $close + 1, 3) === '));', 'Cierre de badge deliveryStatus invalido.');

    return substr($render, $start, $close + 4 - $start);
}

function p12mutateDeliveryStatusLabel(string $js): string
{
    $render = p12method($js, 'renderDetail');
    $block = p12deliveryStatusBlock($render, true);
    $from = 'label: detail.delivery.label';
    $to = 'label: detail.fulfillment.label';
    p12assert(substr_count($block, $from) === 1, 'Precondicion P24: label Delivery ausente o duplicado.');
    $mutatedBlock = preg_replace('/' . preg_quote($from, '/') . '/', $to, $block, 1);
    p12assert(is_string($mutatedBlock) && substr_count($mutatedBlock, $to) === 1, 'Mutacion P24 no dirigida.');
    p12assert(substr_count($js, $block) === 1, 'Precondicion P24: bloque productivo no unico.');

    return str_replace($block, $mutatedBlock, $js);
}

function p12balancedCallEnd(string $source, int $start): int
{
    $open = strpos($source, '(', $start);
    p12assert($open !== false, 'Llamada Delivery sin parentesis inicial.');
    $stack = [];
    $pairs = [')' => '(', ']' => '[', '}' => '{'];
    $quote = null;
    $lineComment = false;
    $blockComment = false;
    $escaped = false;
    $length = strlen($source);

    for ($index = $open; $index < $length; $index++) {
        $character = $source[$index];
        $next = $source[$index + 1] ?? '';
        if ($lineComment) {
            if ($character === "\n") {
                $lineComment = false;
            }
            continue;
        }
        if ($blockComment) {
            if ($character === '*' && $next === '/') {
                $blockComment = false;
                $index++;
            }
            continue;
        }
        if ($quote !== null) {
            if ($escaped) {
                $escaped = false;
            } elseif ($character === '\\') {
                $escaped = true;
            } elseif ($character === $quote) {
                $quote = null;
            }
            continue;
        }
        if ($character === '/' && $next === '/') {
            $lineComment = true;
            $index++;
            continue;
        }
        if ($character === '/' && $next === '*') {
            $blockComment = true;
            $index++;
            continue;
        }
        if (in_array($character, ["'", '"', '`'], true)) {
            $quote = $character;
            continue;
        }
        if (in_array($character, ['(', '[', '{'], true)) {
            $stack[] = $character;
        } elseif (isset($pairs[$character])) {
            p12assert(array_pop($stack) === $pairs[$character], 'Delimitadores Delivery desbalanceados.');
            if ($stack === []) {
                p12assert(($source[$index + 1] ?? '') === ';', 'Llamada Delivery sin punto y coma.');
                return $index + 1;
            }
        }
    }

    throw new RuntimeException('Llamada Delivery sin cierre balanceado.');
}

function p12deliveryRegion(string $render, bool $strict = false): string
{
    $startMarker = 'var deliverySection = element(';
    $endMarker = 'deliverySection.append(';
    p12assert(substr_count($render, $startMarker) === 1, 'Inicio de region Delivery ausente o duplicado.');
    $start = strpos($render, $startMarker);
    p12assert($start !== false, 'Inicio de region Delivery invalido.');
    $candidates = [];
    $offset = $start + strlen($startMarker);
    while (($append = strpos($render, $endMarker, $offset)) !== false) {
        $candidateEnd = p12balancedCallEnd($render, $append);
        $call = substr($render, $append, $candidateEnd + 1 - $append);
        if (preg_match('/\bdeliveryStatus\b/', p12executableSource($call)) === 1) {
            $candidates[] = [$append, $candidateEnd];
        }
        $offset = $candidateEnd + 1;
    }
    p12assert(count($candidates) === 1, 'Cierre productivo de region Delivery ausente o duplicado.');
    [$append, $end] = $candidates[0];
    $region = substr($render, $start, $end + 1 - $start);
    if ($strict) {
        foreach ([
            'data-va-customer-panel-detail-delivery',
            "var deliveryStatus = element('p', 'va-customer-panel__delivery-status');",
            'deliveryStatus.append(renderStatusBadge({',
            'deliverySection.append(',
        ] as $required) {
            p12assert(str_contains($region, $required), 'Region Delivery incompleta: ' . $required);
        }
    }

    return $region;
}

function p12executableSource(string $source, bool $preserveStrings = false): string
{
    $code = '';
    $quote = null;
    $lineComment = false;
    $blockComment = false;
    $escaped = false;
    $length = strlen($source);
    for ($index = 0; $index < $length; $index++) {
        $character = $source[$index];
        $next = $source[$index + 1] ?? '';
        if ($lineComment) {
            if ($character === "\n") {
                $lineComment = false;
                $code .= "\n";
            } else {
                $code .= ' ';
            }
            continue;
        }
        if ($blockComment) {
            if ($character === '*' && $next === '/') {
                $blockComment = false;
                $code .= '  ';
                $index++;
            } else {
                $code .= $character === "\n" ? "\n" : ' ';
            }
            continue;
        }
        if ($quote !== null) {
            $code .= $preserveStrings ? $character : ($character === "\n" ? "\n" : ' ');
            if ($escaped) {
                $escaped = false;
            } elseif ($character === '\\') {
                $escaped = true;
            } elseif ($character === $quote) {
                $quote = null;
            }
            continue;
        }
        if ($character === '/' && $next === '/') {
            $lineComment = true;
            $code .= '  ';
            $index++;
        } elseif ($character === '/' && $next === '*') {
            $blockComment = true;
            $code .= '  ';
            $index++;
        } elseif (in_array($character, ["'", '"', '`'], true)) {
            $quote = $character;
            $code .= $preserveStrings ? $character : ' ';
        } else {
            $code .= $character;
        }
    }
    p12assert($quote === null && ! $blockComment, 'Region Delivery con literal o comentario sin cierre.');
    return $code;
}

function p12mutateDeliveryTimer(string $js): string
{
    $render = p12method($js, 'renderDetail');
    $region = p12deliveryRegion($render, true);
    $declaration = "var deliveryStatus = element('p', 'va-customer-panel__delivery-status');";
    p12assert(substr_count($region, $declaration) === 1, 'Precondicion P26: declaracion deliveryStatus ausente o duplicada.');
    $mutatedRegion = str_replace($declaration, $declaration . "\n        setInterval(function () {}, 1000);", $region);
    p12assert(substr_count(p12executableSource($mutatedRegion), 'setInterval') === 1, 'Mutacion P26 no dirigida.');
    p12assert(substr_count($js, $region) === 1, 'Precondicion P26: region Delivery no unica.');
    return str_replace($region, $mutatedRegion, $js);
}

function p12mutateDeliveryTracking(string $js): string
{
    $render = p12method($js, 'renderDetail');
    $region = p12deliveryRegion($render, true);
    $declaration = "var deliveryStatus = element('p', 'va-customer-panel__delivery-status');";
    p12assert(substr_count($region, $declaration) === 1, 'Precondicion P27: declaracion deliveryStatus ausente o duplicada.');
    $probe = "\n        var courierTracking = element(\n"
        . "            'p',\n"
        . "            'va-customer-panel__delivery-tracking',\n"
        . "            detail.delivery.tracking\n"
        . "        );\n"
        . "        deliverySection.append(courierTracking);";
    $mutatedRegion = str_replace($declaration, $declaration . $probe, $region);
    p12assert(substr_count($mutatedRegion, 'detail.delivery.tracking') === 1, 'Mutacion P27 no dirigida.');
    p12assert(substr_count($js, $region) === 1, 'Precondicion P27: region Delivery no unica.');
    return str_replace($region, $mutatedRegion, $js);
}

function p12phpMethod(string $source, string $signature): string
{
    p12assert(substr_count($source, $signature) === 1, 'Metodo PHP ausente o duplicado: ' . $signature);
    $start = strpos($source, $signature);
    $open = strpos($source, '{', $start + strlen($signature));
    p12assert($start !== false && $open !== false, 'Metodo PHP sin cuerpo: ' . $signature);
    $close = p12balancedObjectEnd($source, $open);
    return substr($source, $start, $close + 1 - $start);
}

function p12mutateOwnedCheckoutQuery(string $query): string
{
    $signature = 'public function findOwnedCheckout(string $publicId, int $userId): ?array';
    $method = p12phpMethod($query, $signature);
    $clause = "            . ' AND c.user_id = %%d'\n";
    p12assert(substr_count($method, $clause) === 1, 'Precondicion P30: clausula ownership ausente o duplicada.');
    $mutatedMethod = str_replace($clause, '', $method);
    p12assert($mutatedMethod !== $method && substr_count($query, $method) === 1, 'Mutacion P30 no dirigida.');
    return str_replace($method, $mutatedMethod, $query);
}

function p12mutatePurchaseIdentity(string $routes): string
{
    $signature = 'public function purchase(WP_REST_Request $request): WP_REST_Response';
    $method = p12phpMethod($routes, $signature);
    p12assert(substr_count($method, 'get_current_user_id()') === 1, 'Precondicion P32: identidad servidor ausente o duplicada.');
    $mutatedMethod = str_replace('get_current_user_id()', "(int) \$request->get_param('user_id')", $method);
    p12assert($mutatedMethod !== $method && substr_count($routes, $method) === 1, 'Mutacion P32 no dirigida.');
    return str_replace($method, $mutatedMethod, $routes);
}

/** @param array<string,string> $s @return list<string> */
function p12validate(array $s, bool $repository = true): array
{
    $errors = [];
    $need = static function (bool $condition, string $code) use (&$errors): void {
        if (! $condition) {
            $errors[] = $code;
        }
    };
    $js = $s['js'];
    $render = p12method($js, 'renderDetail');
    $root = 'veciahorra-frontend va-design-system va-customer-panel__detail-section va-customer-panel__detail-delivery';
    $attr = 'data-va-customer-panel-detail-delivery';

    $need(str_contains($render, "'{$root}'") && str_contains($render, "deliverySection.setAttribute('{$attr}', '');"), 'P01_ROOT_MISSING');
    $need(substr_count($render, "setAttribute('{$attr}'") === 1, 'P02_ROOT_DUPLICATED');
    $need(! str_contains($s['view'], $attr), 'P03_ROOT_GLOBAL');
    $need(preg_match("/var deliverySection = element\\(\\s*'section',[\\s\\S]*?'{$root}'[\\s\\S]*?\\);\\s*deliverySection\\.setAttribute\\('{$attr}', ''\\);/", $render) === 1, 'P04_WRONG_NODE');
    $need(! preg_match("/services\\.setAttribute\\('{$attr}'|detail-services[^'\"]*{$attr}/", $render), 'P05_ROOT_ON_SERVICES');
    $need(! preg_match("/paymentSection\\.setAttribute\\('{$attr}'|detail-payment[^'\"]*{$attr}/", $render), 'P06_PAYMENT_INVASION');
    $need(! preg_match("/overview\\.setAttribute\\('{$attr}'|detail-overview[^'\"]*{$attr}/", $render), 'P07_OVERVIEW_INVASION');
    $need(! preg_match("/ordersSection\\.setAttribute\\('{$attr}'|detail-orders-section[^'\"]*{$attr}/", $render), 'P08_ORDERS_INVASION');
    $need(! str_contains(p12method($js, 'renderDetailItem'), $attr), 'P09_PRODUCTS_INVASION');
    $need(! str_contains(p12method($js, 'renderTimeline'), $attr), 'P10_TIMELINE_INVASION');
    $need(! str_contains(p12method($js, 'renderList'), $attr), 'P11_LIST_INVASION');
    $need(! str_contains(p12method($js, 'renderDetailLoading'), $attr), 'P12_LOADING_INVASION');
    $need(! str_contains(p12method($js, 'renderDetailNotFound'), $attr), 'P13_NOT_FOUND_INVASION');
    $need(! str_contains(p12method($js, 'renderDetailRecoverableError'), $attr), 'P14_ERROR_INVASION');
    $need(! preg_match('/detail-(?:overview|item|order-header|payment)[^\n]*' . $attr . '|' . $attr . '[^\n]*detail-(?:overview|item|order-header|payment)/', $js), 'P15_OPT_IN_NESTING');
    $need(str_contains($js, "veciahorra-frontend va-design-system va-customer-panel__detail-overview va-customer-panel__detail-primary-card") && str_contains($js, 'data-va-customer-panel-detail-overview'), 'P16_PHASE8_CHANGED');
    $need(str_contains($js, "veciahorra-frontend va-design-system va-customer-panel__detail-item") && str_contains($js, 'data-va-customer-panel-detail-item'), 'P17_PHASE9_CHANGED');
    $need(str_contains($js, "veciahorra-frontend va-design-system va-customer-panel__detail-order-header") && str_contains($js, 'data-va-customer-panel-detail-order-header'), 'P18_PHASE10_CHANGED');
    $need(str_contains($js, "veciahorra-frontend va-design-system va-customer-panel__detail-section va-customer-panel__detail-payment") && str_contains($js, 'data-va-customer-panel-detail-payment'), 'P19_PHASE11_CHANGED');
    $need(substr_count($render, "var deliverySection = element(") === 1 && substr_count($render, 'services.append(paymentSection, deliverySection)') === 1, 'P20_CARDINALITY_CHANGED');
    $need(str_contains($s['service'], "! in_array(\$checkout['fulfillment_method'] ?? null, ['pickup', 'delivery', null], true)"), 'P21_DELIVERY_METHOD_CHANGED');
    $need(str_contains($s['service'], "['preparing_delivery', 'out_for_delivery', 'delivered', 'cancelled', 'under_review']") && str_contains($s['service'], "'not_applicable' : 'not_available'"), 'P22_DELIVERY_STATUS_INVENTED');
    $need(str_contains($s['service'], "\$method === 'pickup' ? 'Retiro' : (\$method === 'delivery' ? 'Despacho' : 'Por confirmar')"), 'P23_DELIVERY_LABEL_CHANGED');
    $deliveryStatus = p12deliveryStatusBlock($render);
    $need(
        str_contains($render, "detailValue('Entrega', detail.fulfillment.label, 'fulfillment')")
        && str_contains($deliveryStatus, 'code: detail.delivery.status')
        && str_contains($deliveryStatus, 'label: detail.delivery.label')
        && ! str_contains($deliveryStatus, 'detail.fulfillment'),
        'P24_FULFILLMENT_DELIVERY_MIXED'
    );
    $need(! preg_match('/(?:button|form)|Seguir entrega|Contactar repartidor|Confirmar recepción/i', substr($render, strpos($render, 'var deliveryStatus'))), 'P25_ACTION_ADDED');
    $deliveryExecutable = p12executableSource(p12deliveryRegion($render));
    $need(preg_match('/\bset(?:Interval|Timeout)\s*\(/', $deliveryExecutable) !== 1, 'P26_POLLING_ADDED');
    $deliveryRuntime = p12executableSource(p12deliveryRegion($render), true);
    $need(! preg_match('/courier|tracking|dispatch[_-]?token|ubicaci[oó]n|coordenad|\bruta\b|direcci[oó]n|tel[eé]fono/i', $deliveryRuntime), 'P27_TRACKING_OR_COURIER_EXPOSED');
    $need(str_contains($js, "var ENDPOINT = 'customer-panel/purchases';") && str_contains($s['routes'], "private const PURCHASES = '/customer-panel/purchases';") && ! preg_match('/\bapi\.(?:post|put|patch|delete)\s*\(/i', $js), 'P28_ENDPOINT_OR_METHOD_CHANGED');
    $need(str_contains($s['query'], 'findOwnedCheckout(string $publicId, int $userId)') && str_contains($s['dto'], "'checkout_public_id' => \$this->publicId"), 'P29_PUBLIC_ID_LOST');
    $ownedCheckout = p12phpMethod($s['query'], 'public function findOwnedCheckout(string $publicId, int $userId): ?array');
    $need(str_contains($ownedCheckout, "AND c.user_id = %%d") && str_contains($ownedCheckout, "\$publicId, 'user', \$userId"), 'P30_OWNERSHIP_LOST');
    $need(str_contains($s['service'], "(int) \$delivery['customer_id'] !== \$userId") && str_contains($s['service'], "(int) \$order['customer_id'] !== \$userId"), 'P31_FOREIGN_CUSTOMER_EXPOSED');
    $need(! preg_match('/get_(?:query|body|json)_params[^;]*(?:user_id|customer_id)|get_param\s*\(\s*[\'\"](?:user_id|customer_id)/', $s['routes']), 'P32_IDENTITY_OVERRIDE');
    $validator = p12method($js, 'validateDetailEnvelope');
    $need(
        str_contains($validator, 'isObject(detail.delivery)')
        && str_contains($validator, 'isNullableString(detail.delivery.method)')
        && str_contains($validator, 'isString(detail.delivery.status)')
        && str_contains($validator, 'isString(detail.delivery.label)')
        && ! p12hasDeliveryAllowlist($validator, 'method')
        && ! p12hasDeliveryAllowlist($validator, 'status')
        && ! p12hasDeliveryAllowlist($validator, 'label'),
        'P33_DTO_OR_VALIDATOR_CHANGED'
    );
    $need(! preg_match('/[\'\"](?:delivery_id|courier_id|order_id|checkout_id|customer_id|user_id|store_id|dispatch_token)[\'\"]\s*[:=]/', $js), 'P34_INTERNAL_ID_EXPOSED');
    $need(str_contains($render, "visualHeading('h3', 'Entrega', 'delivery')") && str_contains($s['view'], 'aria-live="polite"'), 'P35_ACCESSIBILITY_LOST');
    $need($s['assets'] === $s['baseAssets'], 'P36_ASSETS_CHANGED');
    $need($s['css'] === $s['baseCss'], 'P37_UNAUTHORIZED_CSS');
    $expectedDelta = [
        "A\ttests/manual/customer-purchase-detail-delivery-design-system-browser-test.py",
        "A\ttests/manual/frontend-customer-purchase-detail-delivery-design-system-test.php",
        "M\tassets/frontend/js/customer-panel.js",
    ];
    sort($expectedDelta, SORT_STRING);
    $need(
        $s['effectiveDelta'] === $expectedDelta
        && $s['baselineAncestor'] === true
        && $s['protectedViewOid'] === $s['expectedViewOid']
        && $s['protectedViewUnchanged'] === true
        && $s['view'] === $s['protectedView']
        && str_contains($s['schema'], "SCHEMA_VERSION = '0.28.0'")
        && $s['artifactMetrics'] === ['files' => 513, 'directories' => 309, 'bytes' => 28537157],
        'P38_BASELINE_OR_ALLOWLIST_CHANGED'
    );
    return array_values(array_unique($errors));
}

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$s = [
    'js' => $read('assets/frontend/js/customer-panel.js'),
    'view' => $read('app/Modules/Frontend/Views/customer-panel.php'),
    'css' => $read('assets/frontend/css/customer-panel.css'),
    'assets' => $read('app/Modules/Frontend/Assets/FrontendAssets.php'),
    'service' => $read('app/Modules/CustomerPanel/Service/CustomerPanelService.php'),
    'routes' => $read('app/Modules/CustomerPanel/Routes/CustomerPanelRoutes.php'),
    'query' => $read('app/Modules/CustomerPanel/Query/CustomerPurchaseQuery.php'),
    'dto' => $read('app/Modules/CustomerPanel/DTO/CustomerPurchaseDetail.php'),
    'schema' => $read('app/Core/Config.php'),
    'baseCss' => p12git(['show', VA_PHASE12_BASELINE . ':assets/frontend/css/customer-panel.css'], false),
    'baseAssets' => p12git(['show', VA_PHASE12_BASELINE . ':app/Modules/Frontend/Assets/FrontendAssets.php'], false),
    'protectedView' => $read('app/Modules/Frontend/Views/customer-panel.php'),
    'effectiveDelta' => p12effectiveDelta(),
    'baselineAncestor' => p12gitExit(['merge-base', '--is-ancestor', VA_PHASE12_BASELINE, 'HEAD']) === 0,
    'expectedViewOid' => p12git(['rev-parse', VA_PHASE12_BASELINE . ':app/Modules/Frontend/Views/customer-panel.php']),
    'protectedViewOid' => p12git(['hash-object', '--path=app/Modules/Frontend/Views/customer-panel.php', 'app/Modules/Frontend/Views/customer-panel.php']),
    'protectedViewUnchanged' => p12gitExit(['diff', '--quiet', VA_PHASE12_BASELINE, '--', 'app/Modules/Frontend/Views/customer-panel.php']) === 0,
    'artifactMetrics' => p12artifactMetrics($root),
];

p12assert(p12git(['merge-base', '--is-ancestor', VA_PHASE12_BASELINE, 'HEAD']) === '', 'Baseline no es ancestro.');
p12assert(($baseErrors = p12validate($s)) === [], 'Validación base: ' . implode(',', $baseErrors));

$mutations = [
    ['js', "deliverySection.setAttribute('data-va-customer-panel-detail-delivery', '');", ''],
    ['js', "deliverySection.setAttribute('data-va-customer-panel-detail-delivery', '');", "deliverySection.setAttribute('data-va-customer-panel-detail-delivery', ''); deliverySection.setAttribute('data-va-customer-panel-detail-delivery', '');"],
    ['view', '<main ', '<i data-va-customer-panel-detail-delivery></i><main '],
    ['js', "deliverySection.setAttribute('data-va-customer-panel-detail-delivery', '');", "deliveryStatus.setAttribute('data-va-customer-panel-detail-delivery', '');"],
    ['js', "var services = element('div', 'va-customer-panel__detail-services');", "var services = element('div', 'va-customer-panel__detail-services data-va-customer-panel-detail-delivery');"],
    ['js', "paymentSection.setAttribute('data-va-customer-panel-detail-payment', '');", "paymentSection.setAttribute('data-va-customer-panel-detail-payment', ''); paymentSection.setAttribute('data-va-customer-panel-detail-delivery', '');"],
    ['js', "overview.setAttribute('data-va-customer-panel-detail-overview', '');", "overview.setAttribute('data-va-customer-panel-detail-overview', ''); overview.setAttribute('data-va-customer-panel-detail-delivery', '');"],
    ['js', "ordersSection.append(orders);", "ordersSection.setAttribute('data-va-customer-panel-detail-delivery', ''); ordersSection.append(orders);"],
    ['js', 'function renderDetailItem(item, currency, config) {', "function renderDetailItem(item, currency, config) { var leak='data-va-customer-panel-detail-delivery';"],
    ['js', 'function renderTimeline(entries, config) {', "function renderTimeline(entries, config) { var leak='data-va-customer-panel-detail-delivery';"],
    ['js', 'function renderList(root, purchases, config) {', "function renderList(root, purchases, config) { var leak='data-va-customer-panel-detail-delivery';"],
    ['js', 'function renderDetailLoading(state) {', "function renderDetailLoading(state) { var leak='data-va-customer-panel-detail-delivery';"],
    ['js', 'function renderDetailNotFound(state) {', "function renderDetailNotFound(state) { var leak='data-va-customer-panel-detail-delivery';"],
    ['js', 'function renderDetailRecoverableError(state) {', "function renderDetailRecoverableError(state) { var leak='data-va-customer-panel-detail-delivery';"],
    ['js', 'data-va-customer-panel-detail-payment', 'data-va-customer-panel-detail-payment data-va-customer-panel-detail-delivery'],
    ['js', 'data-va-customer-panel-detail-overview', 'data-va-phase8-broken'],
    ['js', 'data-va-customer-panel-detail-item', 'data-va-phase9-broken'],
    ['js', 'data-va-customer-panel-detail-order-header', 'data-va-phase10-broken'],
    ['js', 'data-va-customer-panel-detail-payment', 'data-va-phase11-broken'],
    ['js', 'services.append(paymentSection, deliverySection)', 'services.append(paymentSection, deliverySection, deliverySection)'],
    ['service', "['pickup', 'delivery', null]", "['pickup', 'delivery', 'drone', null]"],
    ['service', "['preparing_delivery', 'out_for_delivery', 'delivered', 'cancelled', 'under_review']", "['preparing_delivery', 'out_for_delivery', 'delivered', 'cancelled', 'under_review', 'tracking']"],
    ['service', "'Retiro' : (\$method === 'delivery' ? 'Despacho' : 'Por confirmar')", "'Recogida' : (\$method === 'delivery' ? 'Envío' : 'Pendiente')"],
    ['js', '__P24_DIRECTED_DELIVERY_STATUS_MUTATION__', ''],
    ['js', "var deliveryStatus = element('p', 'va-customer-panel__delivery-status');", "var deliveryStatus = element('button', 'va-customer-panel__delivery-status');"],
    ['js', '__P26_DIRECTED_DELIVERY_TIMER_MUTATION__', ''],
    ['js', '__P27_DIRECTED_DELIVERY_TRACKING_MUTATION__', ''],
    ['js', "var ENDPOINT = 'customer-panel/purchases';", "var ENDPOINT = 'customer-panel/deliveries'; api.post(ENDPOINT);"],
    ['dto', "'checkout_public_id' => \$this->publicId", "'checkout_reference' => \$this->publicId"],
    ['query', '__P30_DIRECTED_OWNED_CHECKOUT_MUTATION__', ''],
    ['service', "(int) \$delivery['customer_id'] !== \$userId", 'false'],
    ['routes', '__P32_DIRECTED_PURCHASE_IDENTITY_MUTATION__', ''],
    ['js', '|| !isString(detail.delivery.status)', '|| false'],
    ['js', "var ENDPOINT = 'customer-panel/purchases';", "var leaked={'delivery_id':1}; var ENDPOINT = 'customer-panel/purchases';"],
    ['js', "visualHeading('h3', 'Entrega', 'delivery')", "element('div', '', 'Entrega')"],
    ['assets', 'final class FrontendAssets', 'final class FrontendAssets /* phase12 */'],
    ['css', '.veciahorra-frontend.va-customer-panel {', '.veciahorra-frontend.va-customer-panel { color:red;'],
    ['effectiveDelta', '__P38_EFFECTIVE_SNAPSHOT_VARIANTS__', ''],
];

$codes = [
    'P01_ROOT_MISSING','P02_ROOT_DUPLICATED','P03_ROOT_GLOBAL','P04_WRONG_NODE','P05_ROOT_ON_SERVICES','P06_PAYMENT_INVASION','P07_OVERVIEW_INVASION','P08_ORDERS_INVASION','P09_PRODUCTS_INVASION','P10_TIMELINE_INVASION','P11_LIST_INVASION','P12_LOADING_INVASION','P13_NOT_FOUND_INVASION','P14_ERROR_INVASION','P15_OPT_IN_NESTING','P16_PHASE8_CHANGED','P17_PHASE9_CHANGED','P18_PHASE10_CHANGED','P19_PHASE11_CHANGED','P20_CARDINALITY_CHANGED','P21_DELIVERY_METHOD_CHANGED','P22_DELIVERY_STATUS_INVENTED','P23_DELIVERY_LABEL_CHANGED','P24_FULFILLMENT_DELIVERY_MIXED','P25_ACTION_ADDED','P26_POLLING_ADDED','P27_TRACKING_OR_COURIER_EXPOSED','P28_ENDPOINT_OR_METHOD_CHANGED','P29_PUBLIC_ID_LOST','P30_OWNERSHIP_LOST','P31_FOREIGN_CUSTOMER_EXPOSED','P32_IDENTITY_OVERRIDE','P33_DTO_OR_VALIDATOR_CHANGED','P34_INTERNAL_ID_EXPOSED','P35_ACCESSIBILITY_LOST','P36_ASSETS_CHANGED','P37_UNAUTHORIZED_CSS','P38_BASELINE_OR_ALLOWLIST_CHANGED',
];

foreach ($mutations as $index => [$key, $from, $to]) {
    $mutated = $s;
    if ($codes[$index] === 'P24_FULFILLMENT_DELIVERY_MIXED') {
        $mutated['js'] = p12mutateDeliveryStatusLabel($mutated['js']);
    } elseif ($codes[$index] === 'P26_POLLING_ADDED') {
        $mutated['js'] = p12mutateDeliveryTimer($mutated['js']);
    } elseif ($codes[$index] === 'P27_TRACKING_OR_COURIER_EXPOSED') {
        $mutated['js'] = p12mutateDeliveryTracking($mutated['js']);
    } elseif ($codes[$index] === 'P30_OWNERSHIP_LOST') {
        $mutated['query'] = p12mutateOwnedCheckoutQuery($mutated['query']);
    } elseif ($codes[$index] === 'P32_IDENTITY_OVERRIDE') {
        $mutated['routes'] = p12mutatePurchaseIdentity($mutated['routes']);
    } elseif ($codes[$index] === 'P38_BASELINE_OR_ALLOWLIST_CHANGED') {
        $variants = [
            'fourth_route' => static function (array $candidate): array {
                $candidate['effectiveDelta'][] = "M\tapp/Probe.php";
                sort($candidate['effectiveDelta'], SORT_STRING);
                return $candidate;
            },
            'protected_oid' => static function (array $candidate): array {
                $candidate['protectedViewOid'] = str_repeat('0', 40);
                return $candidate;
            },
            'incorrect_baseline' => static function (array $candidate): array {
                $candidate['baselineAncestor'] = false;
                return $candidate;
            },
            'schema' => static function (array $candidate): array {
                $candidate['schema'] = str_replace("0.28.0", "0.29.0", $candidate['schema']);
                return $candidate;
            },
            'artifacts' => static function (array $candidate): array {
                $candidate['artifactMetrics']['files']++;
                return $candidate;
            },
        ];
        foreach ($variants as $variant => $mutate) {
            $obtained = p12validate($mutate($mutated), false);
            p12assert($obtained === [$codes[$index]], "Esperado exacto {$codes[$index]} variante={$variant}; obtenido " . implode(',', $obtained));
            echo "PASS ADVERSARIAL variant={$variant} expected={$codes[$index]} obtained={$codes[$index]}\n";
        }
        continue;
    } else {
        $matches = substr_count($mutated[$key], $from);
        p12assert($matches >= 1, "Fixture {$codes[$index]} ausente.");
        p12assert($index < 27 || $matches === 1, "Fixture {$codes[$index]} ambigua: {$matches} coincidencias.");
        $mutated[$key] = preg_replace('/' . preg_quote($from, '/') . '/', addcslashes($to, '\\$'), $mutated[$key], 1) ?? $mutated[$key];
    }
    $obtained = p12validate($mutated, false);
    p12assert(
        $index < 23 || $obtained === [$codes[$index]],
        "Esperado exacto {$codes[$index]}; obtenido " . implode(',', $obtained)
    );
    p12assert(in_array($codes[$index], $obtained, true), "Esperado {$codes[$index]}; obtenido " . implode(',', $obtained));
    echo "PASS ADVERSARIAL expected={$codes[$index]} obtained={$codes[$index]}\n";
}

echo "PASS frontend-customer-purchase-detail-delivery-design-system-test adversarials=38\n";
