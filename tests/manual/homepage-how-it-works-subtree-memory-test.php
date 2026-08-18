<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';

const VA_HOW_SCHEMA = 'veciahorra.elementor.canonical/2';
const VA_HOW_PAGE_ID = 88;
const VA_HOW_PRE_APPLICATION_ELEMENTOR_DATA_SHA256 = '1e4b8532f8bf8de54e470a8c213761f655246b498617763180766fa70f81ad5a';
const VA_HOW_PRE_APPLICATION_POST_CONTENT_SHA256 = '0b3681d78fbbd1da62cd57ee549df0a4036f277daa9a8fa49054ed54a4a828af';
const VA_HOW_PRE_APPLICATION_PAGE_CSS_SHA256 = '6d694633703545928ca504ede387e4386bba4899d13dc83b562c2716eef93c2a';
const VA_HOW_PRE_APPLICATION_OCCURRENCES = 0;
const VA_HOW_ELEMENTOR_LENGTH = 12060;
const VA_HOW_ELEMENTOR_SHA256 = 'c80f058668c470c8a28f65287c35cb77ca34d3c3722df585962092cfebde1057';
const VA_HOW_POST_LENGTH = 859;
const VA_HOW_POST_SHA256 = 'dcfb94dfa22116fc5d1218af0251a5119f25efd5eec62f376e15067190ecd41a';
const VA_HOW_CSS_LENGTH = 4531;
const VA_HOW_CSS_SHA256 = '6d694633703545928ca504ede387e4386bba4899d13dc83b562c2716eef93c2a';
const VA_HOW_CSS_NORMALIZED_SHA256 = '4aee2da9a2c7793e5d061280d6fb1930bdf119f41ca8d9861bd748748fb27e0d';
const VA_HOW_HERO_CANONICAL = '0fe96d5057a4e5fb51583c2390c8d5f2f7252784b5b1db8a6874cf8f52e9da04';
const VA_HOW_PRODUCTS_CANONICAL = '6be7569a41eccec79762a262d1c6c458233e47412beab010767adbe87d46bdde';
const VA_HOW_ROOT_ID = '7a1c9e4';
const VA_HOW_HEADING_ID = '2f6b8d1';
const VA_HOW_EDITOR_ID = '5c3e7a9';
const VA_HOW_EXPECTED_CANONICAL = '52442b82ecb7a1fd301e9ad45534748a49570e694fee158d83b455144e961345';
const VA_HOW_EXPECTED_RAW_SHA256 = 'ebed657013eb54d141115b00e46ad5a7f6829598c018235927be77ab9ccc41b2';
const VA_HOW_EDITOR_HTML = '<ol class="va-home-how-it-works__list"><li class="va-home-how-it-works__step"><h3 class="va-home-how-it-works__step-title">Selecciona tu sector</h3><p class="va-home-how-it-works__step-description">Indica dónde estás para ver los productos disponibles en los minimarkets de tu sector.</p></li><li class="va-home-how-it-works__step"><h3 class="va-home-how-it-works__step-title">Compara productos y ofertas</h3><p class="va-home-how-it-works__step-description">Revisa precios y disponibilidad antes de elegir la opción que más te conviene.</p></li><li class="va-home-how-it-works__step"><h3 class="va-home-how-it-works__step-title">Compra de forma segura</h3><p class="va-home-how-it-works__step-description">Completa tu compra mediante el flujo seguro de VeciAhorra.</p></li></ol>';

function normalizeCanonicalV2(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }

    if (array_is_list($value)) {
        return array_map(
            static fn (mixed $item): mixed => normalizeCanonicalV2($item),
            $value
        );
    }

    $isMediaObject =
        array_key_exists('id', $value)
        && array_key_exists('url', $value)
        && (
            array_key_exists('source', $value)
            || array_key_exists('size', $value)
        );

    if ($isMediaObject) {
        $value['url'] = null;
    }

    foreach ($value as $key => $item) {
        $value[$key] = normalizeCanonicalV2($item);
    }

    ksort($value, SORT_STRING);

    return $value;
}

function vaHowHash(array $value): string
{
    return hash('sha256', json_encode(normalizeCanonicalV2($value), JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
}

function vaHowAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string,mixed> */
function vaHowEnvelope(string $json): array
{
    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || ($decoded['canonical_schema'] ?? null) !== VA_HOW_SCHEMA) {
        throw new InvalidArgumentException('unsupported canonical schema');
    }
    return $decoded;
}

/** @return array<string,mixed> */
function vaHowCandidate(): array
{
    return [
        'id' => VA_HOW_ROOT_ID,
        'elType' => 'e-flexbox',
        'settings' => [
            'classes' => ['$$type' => 'classes', 'value' => ['va-home-how-it-works']],
            'tag' => ['$$type' => 'string', 'value' => 'section'],
        ],
        'elements' => [
            [
                'id' => VA_HOW_HEADING_ID,
                'elType' => 'widget',
                'settings' => ['title' => 'Cómo funciona VeciAhorra', 'header_size' => 'h2'],
                'elements' => [],
                'widgetType' => 'heading',
            ],
            [
                'id' => VA_HOW_EDITOR_ID,
                'elType' => 'widget',
                'settings' => ['editor' => VA_HOW_EDITOR_HTML],
                'elements' => [],
                'widgetType' => 'text-editor',
            ],
        ],
        'isInner' => false,
        'styles' => [],
        'interactions' => [],
        'editor_settings' => [],
        'version' => '0.0',
    ];
}

/** @param array<mixed> $elements */
function vaHowOccurrences(array $elements, string $canonical): int
{
    $count = 0;
    foreach ($elements as $element) {
        if (is_array($element) && vaHowHash($element) === $canonical) {
            $count++;
        }
        $count += vaHowOccurrences(is_array($element['elements'] ?? null) ? $element['elements'] : [], $canonical);
    }
    return $count;
}

/** @param array<mixed> $value @return list<string> */
function vaHowIds(array $value): array
{
    $ids = [];
    foreach ($value as $item) {
        if (!is_array($item)) {
            continue;
        }
        if (isset($item['id']) && is_string($item['id'])) {
            $ids[] = $item['id'];
        }
        $ids = array_merge($ids, vaHowIds(is_array($item['elements'] ?? null) ? $item['elements'] : []));
    }
    return $ids;
}

function vaHowRender(array $candidate): string
{
    $instance = Elementor\Plugin::$instance->elements_manager->create_element_instance($candidate);
    vaHowAssert((bool) $instance, 'Elementor rejected candidate');
    ob_start();
    $instance->print_element();
    return (string) ob_get_clean();
}

/** @return array<string,mixed> */
function vaHowDomFacts(string $html): array
{
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?><div id="va-test-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($dom);
    $count = static fn(string $query): int => $xpath->query($query)?->length ?? -1;
    $texts = static function (string $query) use ($xpath): array {
        $values = [];
        foreach ($xpath->query($query) ?: [] as $node) {
            $values[] = trim((string) $node->textContent);
        }
        return $values;
    };
    return [
        'section' => $count('//*[@id="va-test-root"]//section[contains(concat(" ",normalize-space(@class)," ")," va-home-how-it-works ")]'),
        'h2' => $count('//*[@id="va-test-root"]//h2'), 'ol' => $count('//*[@id="va-test-root"]//ol'),
        'li' => $count('//*[@id="va-test-root"]//li'), 'h3' => $count('//*[@id="va-test-root"]//h3'),
        'p' => $count('//*[@id="va-test-root"]//p'),
        'forbidden_nodes' => $count('//*[@id="va-test-root"]//*[self::a or self::button or self::img or self::style or self::script or self::iframe or self::object or self::embed]'),
        'forbidden_attrs' => $count('//*[@id="va-test-root"]//*[@href or @src or @style or @onclick or @onerror or @onload]'),
        'html_ids' => $count('//*[@id="va-test-root"]//*[@id]'),
        'h2_text' => $texts('//*[@id="va-test-root"]//h2'), 'h3_text' => $texts('//*[@id="va-test-root"]//h3'),
        'p_text' => $texts('//*[@id="va-test-root"]//p'),
    ];
}

function vaHowValidate(array $candidate, array $liveIds): void
{
    vaHowAssert($candidate === vaHowCandidate(), 'candidate differs from frozen manifest');
    vaHowAssert(count(array_unique(vaHowIds([$candidate]))) === 3, 'candidate IDs are not unique');
    vaHowAssert(array_intersect(vaHowIds([$candidate]), $liveIds) === [], 'candidate ID collides with page 88');
    vaHowAssert(wp_kses_post(VA_HOW_EDITOR_HTML) === VA_HOW_EDITOR_HTML, 'KSES normalization differs');
    $instance = Elementor\Plugin::$instance->elements_manager->create_element_instance($candidate);
    vaHowAssert((bool) $instance, 'Elementor rejected candidate');
    vaHowAssert($instance->get_data_for_save() === $candidate, 'get_data_for_save differs');
    $facts = vaHowDomFacts(vaHowRender($candidate));
    vaHowAssert([$facts['section'], $facts['h2'], $facts['ol'], $facts['li'], $facts['h3'], $facts['p']] === [1, 1, 1, 3, 3, 3], 'render structure differs');
    vaHowAssert($facts['forbidden_nodes'] === 0 && $facts['forbidden_attrs'] === 0 && $facts['html_ids'] === 0, 'render contains forbidden markup');
    vaHowAssert($facts['h2_text'] === ['Cómo funciona VeciAhorra'], 'render heading differs');
    vaHowAssert($facts['h3_text'] === ['Selecciona tu sector', 'Compara productos y ofertas', 'Compra de forma segura'], 'render step titles differ');
    vaHowAssert($facts['p_text'] === [
        'Indica dónde estás para ver los productos disponibles en los minimarkets de tu sector.',
        'Revisa precios y disponibilidad antes de elegir la opción que más te conviene.',
        'Completa tu compra mediante el flujo seguro de VeciAhorra.',
    ], 'render descriptions differ');
}

$writes = [];
add_filter('query', static function (string $query) use (&$writes): string {
    if (preg_match('/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE)\b/i', $query)) {
        $writes[] = $query;
    }
    return $query;
});

vaHowEnvelope('{"canonical_schema":"veciahorra.elementor.canonical/2"}');
try {
    vaHowEnvelope('{"canonical_schema":"veciahorra.elementor.canonical/1"}');
    throw new RuntimeException('canonical/1 accepted');
} catch (InvalidArgumentException) {
}

$raw = (string) get_post_meta(VA_HOW_PAGE_ID, '_elementor_data', true);
$postContent = (string) get_post_field('post_content', VA_HOW_PAGE_ID);
$uploads = wp_upload_dir();
$cssPath = $uploads['basedir'] . '/elementor/css/post-88.css';
$css = is_file($cssPath) ? (string) file_get_contents($cssPath) : '';
vaHowAssert(strlen($raw) === VA_HOW_ELEMENTOR_LENGTH && hash('sha256', $raw) === VA_HOW_ELEMENTOR_SHA256, 'page 88 Elementor authority differs');
vaHowAssert(strlen($postContent) === VA_HOW_POST_LENGTH && hash('sha256', $postContent) === VA_HOW_POST_SHA256, 'page 88 post_content authority differs');
vaHowAssert(strlen($css) === VA_HOW_CSS_LENGTH && hash('sha256', $css) === VA_HOW_CSS_SHA256, 'page 88 CSS authority differs');
vaHowAssert(hash('sha256', str_replace('.elementor-88', '.elementor-{ELEMENTOR_PAGE_ID}', $css)) === VA_HOW_CSS_NORMALIZED_SHA256, 'page 88 normalized CSS authority differs');
$document = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
vaHowAssert(is_array($document) && count($document) === 3, 'page 88 top-level root count differs');
vaHowAssert(vaHowOccurrences($document, VA_HOW_HERO_CANONICAL) === 1, 'hero authority differs');
vaHowAssert(vaHowOccurrences($document, VA_HOW_PRODUCTS_CANONICAL) === 1, 'products authority differs');
vaHowAssert(vaHowOccurrences($document, VA_HOW_EXPECTED_CANONICAL) === 1, 'how-it-works authority differs');
$postmetaRows = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d", VA_HOW_PAGE_ID));
$postmetaKeys = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT meta_key) FROM {$wpdb->postmeta} WHERE post_id = %d", VA_HOW_PAGE_ID));
$revisionTotal = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'revision'", VA_HOW_PAGE_ID));
$revisionMetaTotal = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_parent = %d AND p.post_type = 'revision'", VA_HOW_PAGE_ID));
vaHowAssert([$postmetaRows, $postmetaKeys, $revisionTotal, $revisionMetaTotal] === [23, 17, 186, 1960], 'page 88 metadata or revision authority differs');

$candidate = vaHowCandidate();
$rawCandidate = json_encode($candidate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
$candidateHash = vaHowHash($candidate);
if (VA_HOW_EXPECTED_CANONICAL !== '__CANONICAL__') {
    vaHowAssert($candidateHash === VA_HOW_EXPECTED_CANONICAL, 'candidate canonical differs');
    vaHowAssert(hash('sha256', $rawCandidate) === VA_HOW_EXPECTED_RAW_SHA256, 'candidate raw fingerprint differs');
}
$liveIds = vaHowIds([$document[0], $document[1]]);
vaHowValidate($candidate, $liveIds);

$projectedDocument = [$document[0], $document[1], $candidate];
vaHowAssert(count($projectedDocument) === 3 && $projectedDocument[2] === $candidate, 'candidate insertion position differs');
vaHowAssert($projectedDocument[0] === $document[0] && $projectedDocument[1] === $document[1], 'outside candidate changed');
vaHowAssert($projectedDocument === $document, 'stored post-application document differs from candidate projection');
$projectedPost = Elementor\Plugin::$instance->db->get_plain_text_from_data([$candidate]);
$projectedFacts = vaHowDomFacts($projectedPost);
vaHowAssert([$projectedFacts['h2'], $projectedFacts['ol'], $projectedFacts['li'], $projectedFacts['h3'], $projectedFacts['p']] === [1, 1, 3, 3, 3], 'projected post_content differs');

$editorInstance = Elementor\Plugin::$instance->elements_manager->create_element_instance($candidate['elements'][1]);
vaHowAssert((bool) $editorInstance && $editorInstance->get_style_depends() === [] && $editorInstance->get_script_depends() === [], 'candidate introduces asset dependencies');

$rejected = 0;
$reject = static function (string $name, callable $test) use (&$rejected): void {
    try {
        $test();
        throw new RuntimeException('adversarial mutation accepted: ' . $name);
    } catch (Throwable $error) {
        if (str_starts_with($error->getMessage(), 'adversarial mutation accepted:')) {
            throw $error;
        }
        $rejected++;
    }
};
$mutate = static function (string $name, callable $change) use ($candidate, $liveIds, $reject): void {
    $reject($name, static function () use ($candidate, $liveIds, $change): void {
        $changed = $candidate;
        $change($changed);
        vaHowValidate($changed, $liveIds);
    });
};

$mutate('heading_text', static fn(array &$c) => $c['elements'][0]['settings']['title'] .= '!');
$mutate('heading_level', static fn(array &$c) => $c['elements'][0]['settings']['header_size'] = 'h3');
$replacements = [
    'step1_title' => ['Selecciona tu sector', 'Selecciona otro sector'],
    'step1_description' => ['Indica dónde estás', 'Indica otra ubicación'],
    'step2_title' => ['Compara productos y ofertas', 'Compara ofertas'],
    'step2_description' => ['Revisa precios y disponibilidad', 'Revisa solamente precios'],
    'step3_title' => ['Compra de forma segura', 'Compra ahora'],
    'step3_description' => ['Completa tu compra', 'Finaliza tu compra'],
];
foreach ($replacements as $name => [$from, $to]) {
    $mutate($name, static fn(array &$c) => $c['elements'][1]['settings']['editor'] = str_replace($from, $to, $c['elements'][1]['settings']['editor']));
}
$htmlMutations = [
    'delete_step' => ['<li class="va-home-how-it-works__step"><h3 class="va-home-how-it-works__step-title">Compra de forma segura</h3><p class="va-home-how-it-works__step-description">Completa tu compra mediante el flujo seguro de VeciAhorra.</p></li>', ''],
    'duplicate_step' => ['</ol>', '<li class="va-home-how-it-works__step"><h3>X</h3><p>Y</p></li></ol>'],
    'reorder_steps' => ['Selecciona tu sector', 'Temporal'],
    'change_ol' => ['<ol ', '<ul '], 'change_li' => ['<li ', '<div '],
    'shortcode' => ['</ol>', '[danger]</ol>'], 'link' => ['Selecciona tu sector', '<a href="/x">Selecciona tu sector</a>'],
    'button' => ['Selecciona tu sector', '<button>Selecciona tu sector</button>'],
    'image_media' => ['</ol>', '<img src="x"></ol>'], 'attachment_id' => ['</ol>', '<span data-id="315"></span></ol>'],
    'revoked_custom_attribute' => ['<ol ', '<ol data-va-home-how-it-works="1" '],
    'endpoint_query_dynamic' => ['</ol>', '<a href="?rest_route=/x">x</a></ol>'],
    'script' => ['</ol>', '<script>alert(1)</script></ol>'], 'onclick' => ['<ol ', '<ol onclick="x()" '],
    'javascript_url' => ['</ol>', '<a href="javascript:x()">x</a></ol>'], 'data_url' => ['</ol>', '<a href="data:text/html,x">x</a></ol>'],
    'href' => ['<ol ', '<ol href="/x" '], 'src' => ['<ol ', '<ol src="/x" '],
    'shortcode_again' => ['</ol>', '[veciahorra_x]</ol>'], 'unbalanced_html' => ['</ol>', ''],
    'extra_semantic_node' => ['</ol>', '<aside>x</aside></ol>'],
    'tinymce_kses_normalization' => ['<p class="va-home-how-it-works__step-description">', '<p onclick="x()" class="va-home-how-it-works__step-description">'],
];
foreach ($htmlMutations as $name => [$from, $to]) {
    $mutate($name, static fn(array &$c) => $c['elements'][1]['settings']['editor'] = str_replace($from, $to, $c['elements'][1]['settings']['editor']));
}
$mutate('root_id', static fn(array &$c) => $c['id'] = '1111111');
$mutate('descendant_id', static fn(array &$c) => $c['elements'][0]['id'] = '1111111');
$mutate('duplicate_id', static fn(array &$c) => $c['elements'][1]['id'] = VA_HOW_HEADING_ID);
$mutate('collide_hero_id', static fn(array &$c) => $c['id'] = '1ba9d62');
$mutate('collide_products_id', static fn(array &$c) => $c['id'] = '3ffeef7');
$mutate('remove_root_class', static fn(array &$c) => $c['settings']['classes']['value'] = []);
$mutate('duplicate_root_class', static fn(array &$c) => $c['settings']['classes']['value'][] = 'va-home-how-it-works');
$mutate('duplicate_root_class_again', static fn(array &$c) => array_unshift($c['settings']['classes']['value'], 'va-home-how-it-works'));
$mutate('change_section', static fn(array &$c) => $c['settings']['tag']['value'] = 'div');
$mutate('visual_setting', static fn(array &$c) => $c['styles']['x'] = ['id' => 'x']);
$mutate('list_array_order', static fn(array &$c) => $c['elements'] = array_reverse($c['elements']));

$reject('candidate_before_products', static function () use ($document, $candidate): void { $d = $document; array_splice($d, 1, 0, [$candidate]); vaHowAssert($d[2] === $candidate, 'wrong insertion'); });
$reject('hero_change', static function () use ($document): void { $d = $document; $d[0]['id'] = 'changed'; vaHowAssert(vaHowOccurrences($d, VA_HOW_HERO_CANONICAL) === 1, 'hero changed'); });
$reject('products_change', static function () use ($document): void { $d = $document; $d[1]['id'] = 'changed'; vaHowAssert(vaHowOccurrences($d, VA_HOW_PRODUCTS_CANONICAL) === 1, 'products changed'); });
$reject('outside_root2_change', static function () use ($document, $candidate): void { $d = $document; $d[] = $candidate; $d[0]['settings']['unexpected'] = true; vaHowAssert($d[0] === $document[0] && $d[1] === $document[1], 'outside changed'); });
$reject('accept_canonical_1', static fn() => vaHowEnvelope('{"canonical_schema":"veciahorra.elementor.canonical/1"}'));
$reject('sanitization_unequal', static function () use ($candidate): void { $saved = $candidate; $saved['unexpected'] = true; vaHowAssert($saved === $candidate, 'sanitization unequal'); });
$reject('get_data_for_save_unequal', static function () use ($candidate): void { $saved = $candidate; $saved['elements'][0]['settings']['title'] .= '!'; vaHowAssert($saved === $candidate, 'save differs'); });
$reject('stored_render_dom_divergence', static function () use ($candidate): void { $changed = $candidate; $changed['elements'][1]['settings']['editor'] = '<p>different</p>'; vaHowAssert(vaHowDomFacts(vaHowRender($changed)) === vaHowDomFacts(vaHowRender($candidate)), 'DOM differs'); });

vaHowAssert($rejected === 49, 'adversarial mutation count differs: ' . $rejected);
$reordered = ['settings' => $candidate['settings'], 'id' => $candidate['id'], 'elType' => $candidate['elType'], 'elements' => $candidate['elements'], 'isInner' => false, 'styles' => [], 'interactions' => [], 'editor_settings' => [], 'version' => '0.0'];
vaHowAssert(vaHowHash($reordered) === $candidateHash, 'object key order changed canonical');
vaHowAssert($writes === [], 'database writes detected');

echo json_encode([
    'verdict' => 'HOW_IT_WORKS_SUBTREE_B1_MEMORY_PASS',
    'live_state' => 'POST_APPLICATION',
    'post_application_authority_status' => 'published',
    'pre_application_authority' => [
        'elementor_data_sha256' => VA_HOW_PRE_APPLICATION_ELEMENTOR_DATA_SHA256,
        'post_content_sha256' => VA_HOW_PRE_APPLICATION_POST_CONTENT_SHA256,
        'page_css_sha256' => VA_HOW_PRE_APPLICATION_PAGE_CSS_SHA256,
        'occurrences' => VA_HOW_PRE_APPLICATION_OCCURRENCES,
    ],
    'canonical_schema' => VA_HOW_SCHEMA,
    'candidate_ids' => [VA_HOW_ROOT_ID, VA_HOW_HEADING_ID, VA_HOW_EDITOR_ID],
    'candidate_raw_length' => strlen($rawCandidate),
    'candidate_raw_sha256' => hash('sha256', $rawCandidate),
    'candidate_canonical_sha256' => $candidateHash,
    'candidate_base64' => base64_encode($rawCandidate),
    'editor_html_length' => strlen(VA_HOW_EDITOR_HTML),
    'editor_html_sha256' => hash('sha256', VA_HOW_EDITOR_HTML),
    'projected_post_content_scope' => 'ISOLATED_CANDIDATE',
    'projected_post_content_length' => strlen($projectedPost),
    'projected_post_content_sha256' => hash('sha256', $projectedPost),
    'projected_post_content_base64' => base64_encode($projectedPost),
    'elementor_sanitization' => 'PASS',
    'render_dom' => 'PASS',
    'asset_dependencies' => 0,
    'adversarial_mutations' => $rejected . '/49',
    'object_key_reordering' => 'INVARIANT',
    'list_order_text_id_setting_mutations' => 'DETECTED',
    'database_writes' => count($writes),
    'wp_options_writes' => count(array_filter($writes, static fn(string $q): bool => stripos($q, 'options') !== false)),
    'page_88_elementor_sha256' => hash('sha256', $raw),
    'page_88_post_content_sha256' => hash('sha256', $postContent),
    'page_88_css_sha256' => hash('sha256', $css),
    'page_88_css_normalized_sha256' => hash('sha256', str_replace('.elementor-88', '.elementor-{ELEMENTOR_PAGE_ID}', $css)),
    'page_88_postmeta_rows' => $postmetaRows,
    'page_88_postmeta_distinct_keys' => $postmetaKeys,
    'page_88_revision_total' => $revisionTotal,
    'page_88_revision_meta_total' => $revisionMetaTotal,
    'page_88_top_level_roots' => count($document),
    'page_88_how_it_works_occurrences' => vaHowOccurrences($document, VA_HOW_EXPECTED_CANONICAL),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
