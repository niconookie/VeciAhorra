<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';

const VA_HERO_CANONICAL_SCHEMA = 'veciahorra.elementor.canonical/2';
const VA_HERO_REJECTED_CANONICAL_SCHEMA = 'veciahorra.elementor.canonical/1';
const VA_HERO_INITIAL_CANONICAL_HASH = '96dc0336cb9bba59f13b6e5697045bfb97242fca70ba0d6c227a2e9c3305cb0e';
const VA_HERO_FINAL_CANONICAL_HASH = '0fe96d5057a4e5fb51583c2390c8d5f2f7252784b5b1db8a6874cf8f52e9da04';
const VA_HERO_INITIAL_ELEMENTOR_DATA_SHA256 = '3cf07bce72bc77dec2f35b5d9296bac56e1bdab253df8f89168370f62d2c127f';

function vaSize(string $unit, int|float|string $size): array
{
    return ['$$type' => 'size', 'value' => ['size' => $size, 'unit' => $unit]];
}

function vaString(string $value): array
{
    return ['$$type' => 'string', 'value' => $value];
}

function vaColor(string $value): array
{
    return ['$$type' => 'color', 'value' => $value];
}

function vaDimensions(string $unit, int|float|string $top, int|float|string $right, int|float|string $bottom, int|float|string $left): array
{
    return ['$$type' => 'dimensions', 'value' => [
        'block-start' => vaSize($unit, $top),
        'inline-end' => vaSize($unit, $right),
        'block-end' => vaSize($unit, $bottom),
        'inline-start' => vaSize($unit, $left),
    ]];
}

function vaLegacyDimensions(string $unit, string $top, string $right, string $bottom, string $left): array
{
    return compact('unit', 'top', 'right', 'bottom', 'left') + ['isLinked' => false];
}

function vaFind(array &$elements, string $id): ?array
{
    foreach ($elements as &$element) {
        if (($element['id'] ?? null) === $id) {
            return $element;
        }
        if (isset($element['elements']) && is_array($element['elements'])) {
            $found = vaFind($element['elements'], $id);
            if ($found !== null) {
                return $found;
            }
        }
    }
    return null;
}

function vaSet(array &$elements, string $id, callable $mutator): void
{
    foreach ($elements as &$element) {
        if (($element['id'] ?? null) === $id) {
            $mutator($element);
            return;
        }
        if (isset($element['elements']) && is_array($element['elements'])) {
            vaSet($element['elements'], $id, $mutator);
        }
    }
}

function vaCanonical(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('vaCanonical', $value);
    }
    $isMediaObject = array_key_exists('id', $value)
        && array_key_exists('url', $value)
        && (array_key_exists('source', $value) || array_key_exists('size', $value));
    if ($isMediaObject) {
        $value['url'] = null;
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        $value[$key] = vaCanonical($item);
    }
    return $value;
}

function vaHash(array $value): string
{
    return hash('sha256', json_encode(
        vaCanonical($value),
        JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_THROW_ON_ERROR
    ));
}

/** @return array<string, mixed> */
function vaDecodeCanonicalEnvelope(string $json): array
{
    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($decoded) || ($decoded['canonical_schema'] ?? null) !== VA_HERO_CANONICAL_SCHEMA) {
        throw new InvalidArgumentException('unsupported canonical schema');
    }
    return $decoded;
}

function vaDiff(mixed $expected, mixed $actual, string $path = '$'): array
{
    if (gettype($expected) !== gettype($actual)) {
        return [$path . ' type ' . gettype($expected) . ' != ' . gettype($actual)];
    }
    if (! is_array($expected)) {
        return $expected === $actual ? [] : [$path . ' value differs'];
    }
    $diff = [];
    foreach (array_unique(array_merge(array_keys($expected), array_keys($actual))) as $key) {
        if (! array_key_exists($key, $expected)) {
            $diff[] = $path . '.' . $key . ' added';
        } elseif (! array_key_exists($key, $actual)) {
            $diff[] = $path . '.' . $key . ' removed';
        } else {
            $diff = array_merge($diff, vaDiff($expected[$key], $actual[$key], $path . '.' . $key));
        }
    }
    return $diff;
}

$validEnvelope = vaDecodeCanonicalEnvelope(json_encode([
    'canonical_schema' => 'veciahorra.elementor.canonical/2',
], JSON_THROW_ON_ERROR));
if ($validEnvelope['canonical_schema'] !== VA_HERO_CANONICAL_SCHEMA) {
    throw new RuntimeException('canonical/2 envelope differs');
}
foreach ([
    '{"canonical_schema":"veciahorra.elementor.canonical/1"}',
    '{invalid-json',
] as $rejectedEnvelope) {
    try {
        vaDecodeCanonicalEnvelope($rejectedEnvelope);
        throw new RuntimeException('invalid canonical envelope accepted');
    } catch (JsonException|InvalidArgumentException) {
        // Expected: canonical/1 and invalid JSON are both rejected explicitly.
    }
}

global $wpdb;
$initialRaw = null;
$revisionRows = $wpdb->get_results(
    "SELECT pm.meta_value FROM {$wpdb->posts} p
     INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_elementor_data'
     WHERE p.post_parent = 88 AND p.post_type = 'revision'
     ORDER BY p.ID DESC",
    ARRAY_A
);
foreach ($revisionRows as $revisionRow) {
    if (hash('sha256', (string) $revisionRow['meta_value']) === VA_HERO_INITIAL_ELEMENTOR_DATA_SHA256) {
        $initialRaw = (string) $revisionRow['meta_value'];
        break;
    }
}
if ($initialRaw === null) {
    throw new RuntimeException('certified initial Elementor snapshot missing');
}
$document = json_decode($initialRaw, true, 512, JSON_THROW_ON_ERROR);
$initial = null;
foreach ($document as $element) {
    if (($element['id'] ?? null) === '1ba9d62') {
        $initial = $element;
        break;
    }
}
if (! is_array($initial)) {
    throw new RuntimeException('initial subtree missing');
}

$final = $initial;
$final['settings']['classes']['value'][] = 'va-home-hero';

$shadow = ['$$type' => 'box-shadow', 'value' => [[
    '$$type' => 'shadow',
    'value' => [
        'hOffset' => vaSize('px', 0),
        'vOffset' => vaSize('px', 16),
        'blur' => vaSize('px', 40),
        'spread' => vaSize('px', 0),
        'color' => vaColor('rgba(6,44,87,.12)'),
    ],
]]];

$final['styles'] = ['e-4626780' => [
    'id' => 'e-4626780',
    'label' => 'local',
    'type' => 'class',
    'variants' => [
        ['meta' => ['breakpoint' => 'desktop', 'state' => null], 'props' => [
            'background' => ['$$type' => 'background', 'value' => [
                'color' => vaColor('#f4f8f2'),
                'background-overlay' => ['$$type' => 'background-overlay', 'value' => []],
                'clip' => vaString('padding-box'),
            ]],
            'flex-direction' => vaString('row'),
            'align-items' => vaString('center'),
            'gap' => vaSize('px', 32),
            'min-height' => vaSize('px', 640),
            'max-width' => vaSize('px', 1280),
            'margin' => vaDimensions('custom', '0', 'auto', '0', 'auto'),
            'padding' => vaDimensions('px', 48, 32, 48, 32),
            'overflow' => vaString('hidden'),
            'border-radius' => vaSize('px', 16),
            'box-shadow' => $shadow,
        ], 'custom_css' => null],
        ['meta' => ['breakpoint' => 'tablet', 'state' => null], 'props' => [
            'flex-direction' => vaString('column'),
            'align-items' => vaString('stretch'),
            'gap' => vaSize('px', 24),
            'min-height' => vaSize('custom', 'auto'),
            'max-width' => vaSize('%', 100),
            'padding' => vaDimensions('px', 32, 24, 32, 24),
            'border-radius' => vaSize('px', 16),
        ], 'custom_css' => null],
        ['meta' => ['breakpoint' => 'mobile', 'state' => null], 'props' => [
            'flex-direction' => vaString('column'),
            'align-items' => vaString('stretch'),
            'gap' => vaSize('px', 16),
            'min-height' => vaSize('custom', 'auto'),
            'max-width' => vaSize('%', 100),
            'padding' => vaDimensions('px', 24, 16, 24, 16),
            'border-radius' => vaSize('px', 0),
            'box-shadow' => ['$$type' => 'box-shadow', 'value' => []],
        ], 'custom_css' => null],
    ],
]];

vaSet($final['elements'], 'cd4ac48', static function (array &$e): void {
    $e['settings'] = array_merge($e['settings'], [
        'width' => ['unit' => '%', 'size' => 60, 'sizes' => []],
        'width_tablet' => ['unit' => '%', 'size' => 100, 'sizes' => []],
        'width_mobile' => ['unit' => '%', 'size' => 100, 'sizes' => []],
        'flex_direction' => 'column', 'flex_justify_content' => 'center',
        'flex_gap' => ['unit' => 'px', 'size' => 16, 'sizes' => []],
        'flex_gap_tablet' => ['unit' => 'px', 'size' => 16, 'sizes' => []],
        'flex_gap_mobile' => ['unit' => 'px', 'size' => 12, 'sizes' => []],
        'padding' => vaLegacyDimensions('px', '0', '0', '0', '0'),
        'padding_tablet' => vaLegacyDimensions('px', '0', '0', '0', '0'),
        'padding_mobile' => vaLegacyDimensions('px', '0', '0', '0', '0'),
    ]);
    $e['elements'] = array_values(array_filter($e['elements'], static fn (array $child): bool => ($child['id'] ?? '') !== 'd4ba6c6'));
});

vaSet($final['elements'], 'bb23146', static function (array &$e): void {
    $e['settings'] = array_merge($e['settings'], [
        'editor' => '<p>COMPRA EN TU SECTOR</p>', 'typography_font_family' => 'Arial',
        'typography_font_size' => ['unit' => 'px', 'size' => 12, 'sizes' => []],
        'typography_font_weight' => '800', 'typography_line_height' => ['unit' => 'px', 'size' => 18, 'sizes' => []],
        'typography_letter_spacing' => ['unit' => 'px', 'size' => 1.44, 'sizes' => []],
        'text_color' => '#3f7f16', 'align' => 'left',
        '_margin' => vaLegacyDimensions('px', '0', '0', '8', '0'),
    ]);
});

vaSet($final['elements'], '4575511', static function (array &$e): void {
    $e['settings'] = array_merge($e['settings'], [
        'title' => '¿Qué necesitas hoy?', 'header_size' => 'h1', 'typography_font_family' => 'Arial',
        'typography_font_size' => ['unit' => 'px', 'size' => 48, 'sizes' => []],
        'typography_font_size_tablet' => ['unit' => 'px', 'size' => 40, 'sizes' => []],
        'typography_font_size_mobile' => ['unit' => 'px', 'size' => 32, 'sizes' => []],
        'typography_font_weight' => '700',
        'typography_line_height' => ['unit' => 'em', 'size' => 1.15, 'sizes' => []],
        'typography_line_height_tablet' => ['unit' => 'em', 'size' => 1.15, 'sizes' => []],
        'typography_line_height_mobile' => ['unit' => 'em', 'size' => 1.2, 'sizes' => []],
        'title_color' => '#10233a', 'align' => 'left',
        '_margin' => vaLegacyDimensions('px', '0', '0', '0', '0'),
    ]);
});

vaSet($final['elements'], 'b52522a', static function (array &$e): void {
    unset($e['settings']['__globals__']);
    $e['settings'] = array_merge($e['settings'], [
        'editor' => '<p>Explora productos ofrecidos por minimarkets habilitados para tu sector y compara las opciones disponibles antes de comprar.</p>',
        'typography_typography' => 'custom', 'typography_font_family' => 'Arial',
        'typography_font_size' => ['unit' => 'px', 'size' => 18, 'sizes' => []],
        'typography_font_size_tablet' => ['unit' => 'px', 'size' => 17, 'sizes' => []],
        'typography_font_size_mobile' => ['unit' => 'px', 'size' => 16, 'sizes' => []],
        'typography_font_weight' => '400', 'typography_line_height' => ['unit' => 'em', 'size' => 1.6, 'sizes' => []],
        'text_color' => '#5b6878', 'align' => 'left', '_element_width' => 'initial',
        '_element_custom_width' => ['unit' => 'px', 'size' => 720, 'sizes' => []],
        '_element_custom_width_tablet' => ['unit' => '%', 'size' => 100, 'sizes' => []],
        '_element_custom_width_mobile' => ['unit' => '%', 'size' => 100, 'sizes' => []],
        '_margin' => vaLegacyDimensions('px', '0', '0', '0', '0'),
    ]);
});

vaSet($final['elements'], '2837d7d', static function (array &$e): void {
    $e['settings'] = array_merge($e['settings'], [
        'flex_direction' => 'row', 'flex_justify_content' => 'flex-start',
        'flex_gap' => ['unit' => 'px', 'size' => 0, 'sizes' => []],
        'margin' => vaLegacyDimensions('px', '8', '0', '0', '0'),
    ]);
});

vaSet($final['elements'], '8670370', static function (array &$e): void {
    $e['settings']['shortcode'] = '[veciahorra_public_route_link route="catalog" label="Explorar catálogo"]';
});

vaSet($final['elements'], '07fe521', static function (array &$e): void {
    $e['settings'] = array_merge($e['settings'], [
        'width' => ['unit' => '%', 'size' => 40, 'sizes' => []],
        'width_tablet' => ['unit' => '%', 'size' => 100, 'sizes' => []],
        'width_mobile' => ['unit' => '%', 'size' => 100, 'sizes' => []],
        'min_height' => ['unit' => 'custom', 'size' => 'auto', 'sizes' => []],
    ]);
});

vaSet($final['elements'], '9b083c2', static function (array &$e): void {
    $e['settings'] = array_merge($e['settings'], [
        'flex_direction' => 'column', 'flex_justify_content' => 'center', 'flex_align_items' => 'center',
        'margin' => vaLegacyDimensions('px', '0', '0', '0', '0'),
    ]);
});

vaSet($final['elements'], 'df4acd5', static function (array &$e): void {
    $url = wp_get_attachment_image_url(315, 'full');
    if (! is_string($url) || get_post_mime_type(315) !== 'image/jpeg') {
        throw new RuntimeException('attachment 315 invalid');
    }
    $e['settings'] = array_merge($e['settings'], [
        'image' => ['url' => $url, 'id' => 315, 'size' => '', 'alt' => '', 'source' => 'library'],
        'image_size' => 'medium', 'align' => 'center',
        'width' => ['unit' => 'px', 'size' => 320, 'sizes' => []],
        'width_tablet' => ['unit' => 'px', 'size' => 280, 'sizes' => []],
        'width_mobile' => ['unit' => 'px', 'size' => 240, 'sizes' => []],
        'height' => ['unit' => 'custom', 'size' => 'auto', 'sizes' => []],
        'height_tablet' => ['unit' => 'custom', 'size' => 'auto', 'sizes' => []],
        'height_mobile' => ['unit' => 'custom', 'size' => 'auto', 'sizes' => []],
        'object-fit' => 'contain', 'opacity' => ['unit' => 'px', 'size' => 1, 'sizes' => []],
        'image_border_radius' => vaLegacyDimensions('px', '16', '16', '16', '16'),
        'image_box_shadow_box_shadow_type' => 'yes',
        'image_box_shadow_box_shadow' => ['horizontal' => 0, 'vertical' => 4, 'blur' => 12, 'spread' => 0, 'color' => 'rgba(6,44,87,.08)'],
        '_margin' => vaLegacyDimensions('custom', '0', 'auto', '0', 'auto'),
    ]);
});

$heroClassCount = 0;
$countHeroClasses = static function (mixed $value) use (&$countHeroClasses, &$heroClassCount): void {
    if (! is_array($value)) {
        return;
    }
    if (($value['$$type'] ?? null) === 'classes' && isset($value['value']) && is_array($value['value'])) {
        $heroClassCount += count(array_filter(
            $value['value'],
            static fn (mixed $class): bool => $class === 'va-home-hero'
        ));
    }
    foreach ($value as $item) {
        $countHeroClasses($item);
    }
};
$countHeroClasses($final);
if ($heroClassCount !== 1) {
    throw new RuntimeException('hero class count differs: ' . $heroClassCount);
}
$revokedAttribute = 'data-va-' . 'home-hero';
if (str_contains(json_encode($final, JSON_THROW_ON_ERROR), $revokedAttribute)) {
    throw new RuntimeException('revoked hero attribute remains');
}

$instance = Elementor\Plugin::$instance->elements_manager->create_element_instance($final);
if (! $instance) {
    throw new RuntimeException('Elementor rejected candidate');
}
$sanitized = $instance->get_data_for_save();
if (vaCanonical($sanitized) !== vaCanonical($final)) {
    throw new RuntimeException('sanitized candidate differs: ' . implode('; ', vaDiff($final, $sanitized)));
}

$canonicalFinal = $final;
vaSet($canonicalFinal['elements'], 'df4acd5', static function (array &$e): void {
    $e['settings']['image']['url'] = null;
});
$canonicalInitial = $initial;
vaSet($canonicalInitial['elements'], 'df4acd5', static function (array &$e): void {
    $e['settings']['image']['url'] = null;
});

$baseHash = vaHash($canonicalFinal);
if (vaHash($canonicalInitial) !== VA_HERO_INITIAL_CANONICAL_HASH || $baseHash !== VA_HERO_FINAL_CANONICAL_HASH) {
    throw new RuntimeException('certified canonical fingerprints differ');
}
$mutations = [];
$mutations['text'] = $canonicalFinal;
vaSet($mutations['text']['elements'], '4575511', static function (array &$e): void { $e['settings']['title'] .= '!'; });
$mutations['structure'] = $canonicalFinal;
$mutations['structure']['elements'] = array_reverse($mutations['structure']['elements']);
$mutations['id'] = $canonicalFinal;
$mutations['id']['id'] = 'changed';
$mutations['style'] = $canonicalFinal;
$mutations['style']['styles']['e-4626780']['variants'][0]['props']['gap'] = vaSize('px', 33);
$mutations['prop_type'] = $canonicalFinal;
$mutations['prop_type']['styles']['e-4626780']['variants'][0]['props']['gap']['$$type'] = 'string';
$mutations['array_order'] = $canonicalFinal;
$mutations['array_order']['settings']['classes']['value'] = array_reverse($mutations['array_order']['settings']['classes']['value']);
foreach ($mutations as $name => $mutation) {
    if (vaHash($mutation) === $baseHash) {
        throw new RuntimeException('canonical/2 mutation not detected: ' . $name);
    }
}
$urlMutation = $canonicalFinal;
vaSet($urlMutation['elements'], 'df4acd5', static function (array &$e): void { $e['settings']['image']['url'] = 'https://different.invalid/image.jpg'; });
if (vaHash($urlMutation) !== $baseHash) {
    throw new RuntimeException('canonical/2 media URL changed hash');
}
$attachmentMutation = $canonicalFinal;
vaSet($attachmentMutation['elements'], 'df4acd5', static function (array &$e): void { $e['settings']['image']['id'] = 316; });
if (vaHash($attachmentMutation) === $baseHash) {
    throw new RuntimeException('canonical/2 attachment id did not change hash');
}

$mode = getenv('VA_HERO_MODE') ?: 'memory';
if ($mode === 'cleanup') {
    $temporaryId = (int) getenv('VA_HERO_POST_ID');
    if ($temporaryId > 0) {
        Elementor\Core\Files\CSS\Post::create($temporaryId)->delete();
        foreach (wp_get_post_revisions($temporaryId) as $revision) {
            wp_delete_post($revision->ID, true);
        }
        wp_delete_post($temporaryId, true);
        clean_post_cache($temporaryId);
    }
    echo 'CLEANUP=PASS POST_ID=' . $temporaryId . PHP_EOL;
    exit;
}

if ($mode === 'create') {
    wp_set_current_user(1);
    $temporaryId = 0;
    try {
        $token = bin2hex(random_bytes(18));
        $password = bin2hex(random_bytes(24));
        $temporaryId = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'draft',
            'post_title' => 'VA isolated hero test ' . $token,
            'post_name' => 'va-isolated-' . $token,
            'post_content' => '',
            'post_password' => $password,
            'post_author' => 1,
        ], true);
        if (is_wp_error($temporaryId)) {
            throw new RuntimeException($temporaryId->get_error_message());
        }
        update_post_meta($temporaryId, '_elementor_edit_mode', 'builder');
        update_post_meta($temporaryId, '_elementor_template_type', 'wp-page');
        update_post_meta($temporaryId, '_wp_page_template', 'elementor_header_footer');
        wp_update_post(['ID' => $temporaryId, 'post_status' => 'draft']);
        wp_update_post(['ID' => $temporaryId, 'post_status' => 'publish']);
        wp_update_post(['ID' => $temporaryId, 'post_status' => 'publish']);
        $temporaryDocument = Elementor\Plugin::$instance->documents->get($temporaryId);
        if (! $temporaryDocument || ! $temporaryDocument->save(['elements' => [$final], 'settings' => []])) {
            throw new RuntimeException('Document::save failed');
        }
        Elementor\Core\Files\CSS\Post::create($temporaryId)->update();
        echo json_encode([
            'post_id' => $temporaryId,
            'url' => get_permalink($temporaryId),
            'password' => $password,
            'canonical_hash' => $baseHash,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
        exit;
    } catch (Throwable $exception) {
        if ($temporaryId > 0) {
            Elementor\Core\Files\CSS\Post::create($temporaryId)->delete();
            foreach (wp_get_post_revisions($temporaryId) as $revision) {
                wp_delete_post($revision->ID, true);
            }
            wp_delete_post($temporaryId, true);
        }
        throw $exception;
    }
}

echo json_encode([
    'canonical_schema' => VA_HERO_CANONICAL_SCHEMA,
    'initial_elementor_data_sha256' => VA_HERO_INITIAL_ELEMENTOR_DATA_SHA256,
    'initial_hash' => vaHash($canonicalInitial),
    'final_hash' => $baseHash,
    'canonical_v2_mutations' => 'PASS',
    'initial' => vaCanonical($canonicalInitial),
    'final' => vaCanonical($canonicalFinal),
    'sanitized' => vaCanonical($sanitized),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
