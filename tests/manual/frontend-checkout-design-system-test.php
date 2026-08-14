<?php

declare(strict_types=1);

const VA_PHASE5_BASELINE = 'f214056ebd5ad65ef2b658204f0e6919c9775107';
const VA_PHASE5_PATHS = [
    'app/Modules/Frontend/Views/checkout.php',
    'assets/frontend/js/veciahorra-checkout.js',
    'tests/manual/frontend-checkout-design-system-test.php',
    'tests/manual/checkout-design-system-browser-test.py',
];

function phase5Assert(bool $condition, string $message): void
{
    if (! $condition) throw new RuntimeException($message);
}

/** @param list<string> $arguments */
function phase5Git(array $arguments): string
{
    $pipes = [];
    $process = proc_open(['git', '-C', dirname(__DIR__, 2), ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    phase5Assert(is_resource($process), 'Git no pudo iniciarse.');
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
    phase5Assert($exit === 0, 'Git fallo (' . $exit . '): ' . substr(trim((string)$stderr), 0, 300));
    return rtrim(str_replace(["\r\n", "\r"], "\n", (string)$stdout), "\n");
}

/** @return list<string> */
function phase5Lines(string $value): array
{
    return $value === '' ? [] : array_values(array_filter(explode("\n", $value), static fn(string $line): bool => $line !== ''));
}

function phase5GitGuard(): void
{
    $head = phase5Git(['rev-parse', 'HEAD']);
    $staged = phase5Lines(phase5Git(['diff', '--cached', '--name-only'])); sort($staged);
    $tracked = phase5Lines(phase5Git(['status', '--short', '--untracked-files=no']));
    $expected = VA_PHASE5_PATHS; sort($expected);
    if ($head === VA_PHASE5_BASELINE) {
        $paths = array_map(static fn(string $line): string => substr($line, 3), $tracked); sort($paths);
        phase5Assert($paths === $expected, 'Precommit fuera de allowlist: ' . json_encode($paths));
        phase5Assert($staged === ['tests/manual/checkout-design-system-browser-test.py', 'tests/manual/frontend-checkout-design-system-test.php'], 'Staging precommit incorrecto.');
        return;
    }
    phase5Assert(phase5Git(['rev-parse', 'HEAD^']) === VA_PHASE5_BASELINE, 'Parent postcommit incorrecto.');
    phase5Assert($tracked === [] && $staged === [], 'Postcommit no esta limpio.');
    $commitPaths = phase5Lines(phase5Git(['diff-tree', '--no-commit-id', '--name-only', '-r', 'HEAD'])); sort($commitPaths);
    phase5Assert($commitPaths === $expected, 'Commit fuera de allowlist.');
}

/** @return list<string> */
function phase5Validate(string $view, string $js): array
{
    $errors = [];
    $require = static function(bool $condition, string $code) use (&$errors): void { if (!$condition) $errors[] = $code; };
    $require(str_contains($view, 'class="veciahorra-frontend va-design-system va-checkout" data-va-checkout aria-labelledby="<?php echo esc_attr($titleId); ?>"'), 'P01_ROOT');
    $require(substr_count($view, 'va-design-system') === 1, 'P02_OPT_IN_ONCE');
    $require(str_contains($view, '<header class="va-section-heading">'), 'P03_HEADER');
    $require(str_contains($view, 'class="va-product-detail__eyebrow va-eyebrow"'), 'P04_EYEBROW');
    $require(str_contains($view, 'class="va-checkout__summary va-card"'), 'P05_SUMMARY');
    $require(str_contains($view, 'class="va-checkout-form va-card va-field-group" data-va-checkout-form novalidate'), 'P06_FORM');
    $require(str_contains($view, 'class="va-button va-button--primary" type="button" data-va-checkout-retry'), 'P07_RETRY');
    $require(str_contains($view, 'class="va-button va-button--primary" data-va-payment-status-action hidden'), 'P08_PAYMENT_ACTION');
    $require(str_contains($view, 'class="va-button va-button--secondary" type="button" data-va-payment-status-refresh hidden'), 'P09_PAYMENT_REFRESH');
    $require(str_contains($view, 'class="va-button va-button--primary va-checkout-form__submit" type="submit" data-va-checkout-submit disabled'), 'P10_SUBMIT');
    $require(str_contains($js, "element('section', 'va-checkout-group va-card')"), 'P11_DYNAMIC_CARD');
    $require(str_contains($js, "paymentAction.className = action === 'view_order'\n                ? 'va-button va-button--secondary'\n                : 'va-button va-button--primary';"), 'P12_DYNAMIC_PAYMENT');
    $require(str_contains($js, "element('label', 'va-delivery-option')"), 'P13_DELIVERY_LABEL');
    $require(!str_contains($js, "va-delivery-option va-") && !preg_match('/va-delivery-option[^\']*va-card/', $js), 'P14_NO_DELIVERY_CLASS');
    $require(str_contains($js, "radio.type = 'radio';") && str_contains($js, "radio.name = 'delivery_method';"), 'P15_NATIVE_RADIO');
    $require(substr_count($view, 'va-field-group') === 1, 'P16_NO_NESTED_FIELD_GROUP');
    $require(str_contains($view, '<section aria-labelledby="<?php echo esc_attr($instanceId . \'-customer-title\'); ?>">'), 'P17_BUYER_SECTION');
    $require(str_contains($view, '<fieldset class="va-checkout-delivery"'), 'P18_DELIVERY_FIELDSET');
    $require(str_contains($view, '<section class="va-checkout-address"'), 'P19_ADDRESS_SECTION');
    $require(str_contains($view, 'data-va-checkout-buyer-name') && str_contains($view, 'value="<?php echo esc_attr($buyerName); ?>"'), 'P20_BUYER_RECIPIENT');
    $require(str_contains($js, "var minimumUnits = typeof minimum === 'number'") && str_contains($js, ': 8000;'), 'P21_MINIMUM');
    foreach (["config.api.get('/cart'", "'/checkout/validate'", "config.api.post('/checkout'", "config.api.post('/payments/session'", "'/payment-status'"] as $needle) $require(str_contains($js, $needle), 'P22_ENDPOINTS');
    $require(str_contains($js, "'Idempotency-Key': checkoutIdempotencyKey") && str_contains($js, "'Idempotency-Key': paymentIdempotencyKey"), 'P23_IDEMPOTENCY');
    $require(str_contains($js, 'if (!validated || creating || created || ambiguousAttempt)'), 'P24_DOUBLE_SUBMIT');
    $require(str_contains($js, "'webpay3gint.transbank.cl'") && str_contains($js, "'webpay3g.transbank.cl'"), 'P25_WEBPAY_HOSTS');
    $require(str_contains($js, "headers[cart.sessionHeader] = cart.sessionId") && str_contains($js, '!(config.currentUser && config.currentUser.loggedIn)'), 'P26_GUEST_SESSION');
    $require(substr_count($js, 'if (!authenticated)') === 2 && substr_count($js, 'para crear el pedido.') === 2, 'P27_GUEST_BLOCK');
    $require(substr_count($view, 'data-va-checkout') >= 15 && str_contains($view, 'data-va-checkout-loading'), 'P28_DATA_HOOKS');
    $require(str_contains($view, 'aria-live="assertive"') && str_contains($view, 'aria-live="polite"'), 'P29_ARIA');
    $require(!str_contains($view, '<style') && !str_contains($js, 'insertRule('), 'P30_NO_CSS');
    return array_values(array_unique($errors));
}

phase5GitGuard();
$root = dirname(__DIR__, 2);
$view = (string)file_get_contents($root . '/app/Modules/Frontend/Views/checkout.php');
$js = (string)file_get_contents($root . '/assets/frontend/js/veciahorra-checkout.js');
phase5Assert(phase5Validate($view, $js) === [], 'Contrato productivo invalido: ' . json_encode(phase5Validate($view, $js)));

$mutants = [
 'P01_ROOT'=>['v','veciahorra-frontend va-design-system va-checkout','va-checkout'], 'P02_OPT_IN_ONCE'=>['v','data-va-checkout','va-design-system data-va-checkout'],
 'P03_HEADER'=>['v','header class="va-section-heading"','header'], 'P04_EYEBROW'=>['v',' va-eyebrow',''], 'P05_SUMMARY'=>['v','va-checkout__summary va-card','va-checkout__summary'],
 'P06_FORM'=>['v','va-checkout-form va-card va-field-group','va-checkout-form'], 'P07_RETRY'=>['v','va-button va-button--primary" type="button" data-va-checkout-retry','va-button" type="button" data-va-checkout-retry'],
 'P08_PAYMENT_ACTION'=>['v','va-button va-button--primary" data-va-payment-status-action','va-button" data-va-payment-status-action'], 'P09_PAYMENT_REFRESH'=>['v','va-button va-button--secondary" type="button" data-va-payment-status-refresh','va-button" type="button" data-va-payment-status-refresh'],
 'P10_SUBMIT'=>['v','va-button va-button--primary va-checkout-form__submit','va-button va-checkout-form__submit'], 'P11_DYNAMIC_CARD'=>['j','va-checkout-group va-card','va-checkout-group'],
 'P12_DYNAMIC_PAYMENT'=>['j',"action === 'view_order'","action === 'retry_payment'"], 'P13_DELIVERY_LABEL'=>['j',"element('label', 'va-delivery-option')","element('label', 'delivery-option')"],
 'P14_NO_DELIVERY_CLASS'=>['j','va-delivery-option','va-delivery-option va-card'], 'P15_NATIVE_RADIO'=>['j',"radio.type = 'radio';","radio.type = 'button';"],
 'P16_NO_NESTED_FIELD_GROUP'=>['v','class="va-checkout-delivery"','class="va-checkout-delivery va-field-group"'], 'P17_BUYER_SECTION'=>['v','<section aria-labelledby="<?php echo esc_attr($instanceId . \'-customer-title\'); ?>">','<section class="va-card" aria-labelledby="<?php echo esc_attr($instanceId . \'-customer-title\'); ?>">'],
 'P18_DELIVERY_FIELDSET'=>['v','class="va-checkout-delivery"','class="va-checkout-delivery va-card"'], 'P19_ADDRESS_SECTION'=>['v','class="va-checkout-address"','class="va-checkout-address va-card"'],
 'P20_BUYER_RECIPIENT'=>['v','data-va-checkout-buyer-name','data-va-buyer'], 'P21_MINIMUM'=>['j',': 8000;',': 7000;'], 'P22_ENDPOINTS'=>['j',"'/checkout/validate'","'/checkout/check'"],
 'P23_IDEMPOTENCY'=>['j',"'Idempotency-Key': checkoutIdempotencyKey","'X-Key': checkoutIdempotencyKey"], 'P24_DOUBLE_SUBMIT'=>['j','creating || created','created'],
 'P25_WEBPAY_HOSTS'=>['j',"'webpay3gint.transbank.cl'","'example.test'"], 'P26_GUEST_SESSION'=>['j','headers[cart.sessionHeader] = cart.sessionId','headers.bad = cart.sessionId'],
 'P27_GUEST_BLOCK'=>['j','para crear el pedido.','para continuar.'], 'P28_DATA_HOOKS'=>['v','data-va-checkout-loading','data-loading'],
 'P29_ARIA'=>['v','aria-live="assertive"','aria-live="off"'], 'P30_NO_CSS'=>['v','</section>','<style>.x{}</style></section>'],
];
foreach ($mutants as $expected => [$target, $from, $to]) {
    $mutatedView = $target === 'v' ? preg_replace('/' . preg_quote($from, '/') . '/', addcslashes($to, '\\$'), $view, 1) : $view;
    $mutatedJs = $target === 'j' ? preg_replace('/' . preg_quote($from, '/') . '/', addcslashes($to, '\\$'), $js, 1) : $js;
    $obtained = phase5Validate((string)$mutatedView, (string)$mutatedJs);
    phase5Assert(in_array($expected, $obtained, true), "Adversarial {$expected} no rechazado: " . json_encode($obtained));
    echo "PASS ADVERSARIAL expected={$expected} obtained={$expected}\n";
}
echo "PASS frontend-checkout-design-system-test adversarials=" . count($mutants) . "\n";
