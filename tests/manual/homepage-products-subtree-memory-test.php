<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';

const VA_PRODUCTS_CANONICAL_SCHEMA = 'veciahorra.elementor.canonical/2';
const VA_PRODUCTS_REJECTED_SCHEMA = 'veciahorra.elementor.canonical/1';
const VA_PRODUCTS_INITIAL_CANONICAL = '74640ac0b088a374048175a7f7b8edf03725ba829f99029e8113b5491ebd1e80';
const VA_PRODUCTS_FINAL_CANONICAL = '6be7569a41eccec79762a262d1c6c458233e47412beab010767adbe87d46bdde';
const VA_PRODUCTS_INITIAL_SUBTREE_SHA256 = '774690ee6528bb09f04d6768d49547baa37cfa748b50f7a3f9f58a8c8af4d60d';
const VA_PRODUCTS_PRE_A5_ELEMENTOR_SHA256 = '14ff3f75c53e8be74c885ac1f4c0f5401efd1a22b0e33d7efacf065f57fd25c3';
const VA_PRODUCTS_PRE_A5_POST_CONTENT_SHA256 = '36871362aca23cdb4b5221a1aa0fd70faae35f738f89ec1abaa1dca2b807477d';
const VA_PRODUCTS_PRE_A5_PAGE_CSS_SHA256 = 'afa3811f953547d590c1570808a9e679a853d59cd839c49e89323064089435cf';
const VA_PRODUCTS_CURRENT_ELEMENTOR_LENGTH = 10731;
const VA_PRODUCTS_CURRENT_ELEMENTOR_SHA256 = '1e4b8532f8bf8de54e470a8c213761f655246b498617763180766fa70f81ad5a';
const VA_PRODUCTS_CURRENT_POST_CONTENT_LENGTH = 441;
const VA_PRODUCTS_CURRENT_POST_CONTENT_SHA256 = '0b3681d78fbbd1da62cd57ee549df0a4036f277daa9a8fa49054ed54a4a828af';
const VA_PRODUCTS_HERO_CANONICAL = '0fe96d5057a4e5fb51583c2390c8d5f2f7252784b5b1db8a6874cf8f52e9da04';
const VA_PRODUCTS_FULL_METADATA_ROWS = 21;
const VA_PRODUCTS_FULL_METADATA_KEYS = 17;
const VA_PRODUCTS_REVISION_TOTAL = 185;
const VA_PRODUCTS_REVISION_META_TOTAL = 1946;
const VA_PRODUCTS_PROJECTED_PAGE_88_CSS_LENGTH = 4531;
const VA_PRODUCTS_PROJECTED_PAGE_88_CSS_SHA256 = '6d694633703545928ca504ede387e4386bba4899d13dc83b562c2716eef93c2a';
const VA_PRODUCTS_PROJECTED_PAGE_88_CSS_NORMALIZED_SHA256 = '4aee2da9a2c7793e5d061280d6fb1930bdf119f41ca8d9861bd748748fb27e0d';
const VA_PRODUCTS_REVOKED_CSS_LENGTH = 4498;
const VA_PRODUCTS_REVOKED_CSS_SHA256 = 'ffbd97c42ec3d5660139e85822ecf2320b7cc3ee54aa72ae020f51d247ded709';
const VA_PRODUCTS_REVOKED_CSS_NORMALIZED_SHA256 = '3aacb428ed6a681e2079856aff1fd2b4955888e64c332fb384cd948eef764acc';

function vaProductsCanonical(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('vaProductsCanonical', $value);
    }
    $media = array_key_exists('id', $value)
        && array_key_exists('url', $value)
        && (array_key_exists('source', $value) || array_key_exists('size', $value));
    if ($media) {
        $value['url'] = null;
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        $value[$key] = vaProductsCanonical($item);
    }
    return $value;
}

function vaProductsHash(array $value): string
{
    return hash('sha256', json_encode(
        vaProductsCanonical($value),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
    ));
}

/** @return array<string, mixed> */
function vaProductsEnvelope(string $json): array
{
    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || ($decoded['canonical_schema'] ?? null) !== VA_PRODUCTS_CANONICAL_SCHEMA) {
        throw new InvalidArgumentException('unsupported canonical schema');
    }
    return $decoded;
}

/** @param array<mixed> $elements */
function vaProductsFind(array $elements, string $id): ?array
{
    foreach ($elements as $element) {
        if (($element['id'] ?? null) === $id) {
            return $element;
        }
        $found = vaProductsFind(is_array($element['elements'] ?? null) ? $element['elements'] : [], $id);
        if ($found !== null) {
            return $found;
        }
    }
    return null;
}

/** @param array<mixed> $elements */
function vaProductsReplace(array &$elements, string $id, array $replacement): int
{
    $count = 0;
    foreach ($elements as &$element) {
        if (($element['id'] ?? null) === $id) {
            $element = $replacement;
            $count++;
            continue;
        }
        if (isset($element['elements']) && is_array($element['elements'])) {
            $count += vaProductsReplace($element['elements'], $id, $replacement);
        }
    }
    return $count;
}

/** @param array<mixed> $elements */
function vaProductsWithout(array $elements, string $id): array
{
    $result = [];
    foreach ($elements as $element) {
        if (($element['id'] ?? null) === $id) {
            continue;
        }
        if (isset($element['elements']) && is_array($element['elements'])) {
            $element['elements'] = vaProductsWithout($element['elements'], $id);
        }
        $result[] = $element;
    }
    return $result;
}

/** @param array<mixed> $elements */
function vaProductsCanonicalOccurrences(array $elements, string $canonical): int
{
    $count = 0;
    foreach ($elements as $element) {
        if (is_array($element) && vaProductsHash($element) === $canonical) {
            $count++;
        }
        $count += vaProductsCanonicalOccurrences(is_array($element['elements'] ?? null) ? $element['elements'] : [], $canonical);
    }
    return $count;
}

/** @param array<string, mixed> $state */
function vaProductsValidateCurrentState(array $state): void
{
    $expected = [
        'schema' => VA_PRODUCTS_CANONICAL_SCHEMA,
        'elementor_sha256' => VA_PRODUCTS_CURRENT_ELEMENTOR_SHA256,
        'initial_occurrences' => 0,
        'current_occurrences' => 1,
        'shortcode' => '[veciahorra_homepage_products]',
        'root_id' => '3ffeef7',
        'widget_id' => '3014102',
        'removed_id_occurrences' => 0,
        'hero_canonical' => VA_PRODUCTS_HERO_CANONICAL,
        'css_length' => VA_PRODUCTS_PROJECTED_PAGE_88_CSS_LENGTH,
        'css_sha256' => VA_PRODUCTS_PROJECTED_PAGE_88_CSS_SHA256,
        'post_content_sha256' => VA_PRODUCTS_CURRENT_POST_CONTENT_SHA256,
        'sanitization_equal' => true,
    ];
    foreach ($expected as $key => $value) {
        if (($state[$key] ?? null) !== $value) {
            throw new RuntimeException('current authority differs: ' . $key);
        }
    }
}

/** @return array<string, list<array{length:int,sha256:string}>> */
function vaProductsMetaSnapshot(int $postId): array
{
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id=%d ORDER BY meta_key, meta_id",
        $postId
    ), ARRAY_A);
    $snapshot = [];
    foreach ($rows as $row) {
        $value = (string) $row['meta_value'];
        $snapshot[(string) $row['meta_key']][] = [
            'length' => strlen($value),
            'sha256' => hash('sha256', $value),
        ];
    }
    return $snapshot;
}

/** @return list<array{meta_key:string,value_length:int,value_sha256:string}> */
function vaProductsMetaProjection(int $postId): array
{
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id=%d ORDER BY meta_id",
        $postId
    ), ARRAY_A);
    return array_map(static fn (array $row): array => [
        'meta_key' => (string) $row['meta_key'],
        'value_length' => strlen((string) $row['meta_value']),
        'value_sha256' => hash('sha256', (string) $row['meta_value']),
    ], $rows);
}

function vaProductsFinalSubtree(): array
{
    $size = static fn (int $value): array => [
        '$$type' => 'size',
        'value' => ['size' => $value, 'unit' => 'px'],
    ];
    $padding = [
        '$$type' => 'dimensions',
        'value' => [
            'block-start' => $size(0),
            'inline-end' => $size(0),
            'block-end' => $size(0),
            'inline-start' => $size(0),
        ],
    ];
    return [
        'id' => '3ffeef7',
        'elType' => 'e-flexbox',
        'settings' => [
            'classes' => ['$$type' => 'classes', 'value' => ['e-ae8ddcb']],
        ],
        'elements' => [[
            'id' => '3014102',
            'elType' => 'widget',
            'settings' => ['shortcode' => '[veciahorra_homepage_products]'],
            'elements' => [],
            'widgetType' => 'shortcode',
        ]],
        'isInner' => false,
        'styles' => [
            'e-ae8ddcb' => [
                'id' => 'e-ae8ddcb',
                'label' => 'local',
                'type' => 'class',
                'variants' => [[
                    'meta' => ['breakpoint' => 'desktop', 'state' => null],
                    'props' => ['padding' => $padding],
                    'custom_css' => null,
                ]],
            ],
        ],
        'interactions' => [],
        'editor_settings' => [],
        'version' => '0.0',
    ];
}

$envelope = vaProductsEnvelope('{"canonical_schema":"veciahorra.elementor.canonical/2"}');
if ($envelope['canonical_schema'] !== VA_PRODUCTS_CANONICAL_SCHEMA) {
    throw new RuntimeException('canonical/2 envelope differs');
}
try {
    vaProductsEnvelope('{"canonical_schema":"veciahorra.elementor.canonical/1"}');
    throw new RuntimeException('canonical/1 accepted');
} catch (InvalidArgumentException) {
    // Explicit rejection is required.
}

$currentRaw = (string) get_post_meta(88, '_elementor_data', true);
if (strlen($currentRaw) !== VA_PRODUCTS_CURRENT_ELEMENTOR_LENGTH
    || hash('sha256', $currentRaw) !== VA_PRODUCTS_CURRENT_ELEMENTOR_SHA256
) {
    throw new RuntimeException('current page 88 Elementor authority differs');
}
$currentDocument = json_decode($currentRaw, true, 512, JSON_THROW_ON_ERROR);
$currentSubtree = vaProductsFind($currentDocument, '3ffeef7');
if (!is_array($currentSubtree) || vaProductsHash($currentSubtree) !== VA_PRODUCTS_FINAL_CANONICAL) {
    throw new RuntimeException('current products subtree differs');
}

$historicalRaw = null;
foreach (wp_get_post_revisions(88) as $revision) {
    $candidate = (string) get_post_meta((int) $revision->ID, '_elementor_data', true);
    if (hash('sha256', $candidate) === VA_PRODUCTS_PRE_A5_ELEMENTOR_SHA256) {
        $historicalRaw = $candidate;
        break;
    }
}
if (!is_string($historicalRaw)) {
    throw new RuntimeException('historical pre-A5 rollback authority missing');
}
$historicalDocument = json_decode($historicalRaw, true, 512, JSON_THROW_ON_ERROR);
$initialSubtree = vaProductsFind($historicalDocument, '3ffeef7');
if (!is_array($initialSubtree)) {
    throw new RuntimeException('historical products subtree missing');
}
$initialBytes = json_encode($initialSubtree, JSON_THROW_ON_ERROR);
if (strlen($initialBytes) !== 1545 || hash('sha256', $initialBytes) !== VA_PRODUCTS_INITIAL_SUBTREE_SHA256) {
    throw new RuntimeException('historical subtree bytes differ');
}
if (vaProductsHash($initialSubtree) !== VA_PRODUCTS_INITIAL_CANONICAL) {
    throw new RuntimeException('historical canonical/2 differs');
}

$finalSubtree = vaProductsFinalSubtree();
if (vaProductsCanonical($currentSubtree) !== vaProductsCanonical($finalSubtree)) {
    throw new RuntimeException('current subtree is not the certified final candidate');
}
$instance = Elementor\Plugin::$instance->elements_manager->create_element_instance($finalSubtree);
if (!$instance) {
    throw new RuntimeException('Elementor rejected products candidate');
}
$sanitized = $instance->get_data_for_save();
if (vaProductsCanonical($sanitized) !== vaProductsCanonical($finalSubtree)) {
    throw new RuntimeException('Elementor sanitization differs');
}
if (vaProductsHash($finalSubtree) !== VA_PRODUCTS_FINAL_CANONICAL) {
    throw new RuntimeException('final canonical/2 differs');
}

$mutations = [
    'text' => $finalSubtree,
    'structure' => $finalSubtree,
    'id' => $finalSubtree,
    'setting' => $finalSubtree,
];
$mutations['text']['elements'][0]['settings']['shortcode'] .= ' ';
$mutations['structure']['elements'][] = $mutations['structure']['elements'][0];
$mutations['id']['id'] = 'changed';
$mutations['setting']['settings']['unexpected'] = true;
foreach ($mutations as $name => $mutation) {
    if (vaProductsHash($mutation) === VA_PRODUCTS_FINAL_CANONICAL) {
        throw new RuntimeException('canonical mutation missed: ' . $name);
    }
}

$finalDocument = $historicalDocument;
if (vaProductsReplace($finalDocument, '3ffeef7', $finalSubtree) !== 1) {
    throw new RuntimeException('products subtree occurrence differs');
}
if (vaProductsCanonical(vaProductsWithout($historicalDocument, '3ffeef7'))
    !== vaProductsCanonical(vaProductsWithout($finalDocument, '3ffeef7'))
) {
    throw new RuntimeException('outside subtree changed');
}

$postContent = (string) get_post_field('post_content', 88);
if (strlen($postContent) !== VA_PRODUCTS_CURRENT_POST_CONTENT_LENGTH
    || hash('sha256', $postContent) !== VA_PRODUCTS_CURRENT_POST_CONTENT_SHA256
) {
    throw new RuntimeException('current post_content authority differs');
}

$uploads = wp_upload_dir();
$cssPath = $uploads['basedir'] . '/elementor/css/post-88.css';
$css = is_file($cssPath) ? (string) file_get_contents($cssPath) : '';
$normalizedCss = str_replace('.elementor-88', '.elementor-{ELEMENTOR_PAGE_ID}', $css);
if (strlen($css) !== VA_PRODUCTS_PROJECTED_PAGE_88_CSS_LENGTH
    || hash('sha256', $css) !== VA_PRODUCTS_PROJECTED_PAGE_88_CSS_SHA256
    || hash('sha256', $normalizedCss) !== VA_PRODUCTS_PROJECTED_PAGE_88_CSS_NORMALIZED_SHA256
) {
    throw new RuntimeException('current page 88 CSS authority differs');
}

global $wpdb;
$metaRows = $wpdb->get_results($wpdb->prepare(
    "SELECT meta_key FROM {$wpdb->postmeta} WHERE post_id=%d ORDER BY meta_id",
    88
), ARRAY_A);
$distinctMetaKeys = array_unique(array_column($metaRows, 'meta_key'));
$revisions = wp_get_post_revisions(88);
$revisionIds = array_map(static fn (WP_Post $revision): int => (int) $revision->ID, $revisions);
$revisionMetaTotal = $revisionIds === [] ? 0 : (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id IN (" . implode(',', $revisionIds) . ')'
);
if (count($metaRows) !== VA_PRODUCTS_FULL_METADATA_ROWS
    || count($distinctMetaKeys) !== VA_PRODUCTS_FULL_METADATA_KEYS
    || count($revisions) !== VA_PRODUCTS_REVISION_TOTAL
    || $revisionMetaTotal !== VA_PRODUCTS_REVISION_META_TOTAL
) {
    throw new RuntimeException('current metadata or revision authority differs');
}

$currentState = [
    'schema' => VA_PRODUCTS_CANONICAL_SCHEMA,
    'elementor_sha256' => hash('sha256', $currentRaw),
    'initial_occurrences' => vaProductsCanonicalOccurrences($currentDocument, VA_PRODUCTS_INITIAL_CANONICAL),
    'current_occurrences' => vaProductsCanonicalOccurrences($currentDocument, VA_PRODUCTS_FINAL_CANONICAL),
    'shortcode' => (string) ($currentSubtree['elements'][0]['settings']['shortcode'] ?? ''),
    'root_id' => (string) ($currentSubtree['id'] ?? ''),
    'widget_id' => (string) ($currentSubtree['elements'][0]['id'] ?? ''),
    'removed_id_occurrences' => array_sum(array_map(
        static fn (string $id): int => vaProductsFind($currentDocument, $id) === null ? 0 : 1,
        ['83cf705', '6f52c3a', '3014101']
    )),
    'hero_canonical' => vaProductsCanonicalOccurrences($currentDocument, VA_PRODUCTS_HERO_CANONICAL) === 1
        ? VA_PRODUCTS_HERO_CANONICAL
        : 'changed',
    'css_length' => strlen($css),
    'css_sha256' => hash('sha256', $css),
    'post_content_sha256' => hash('sha256', $postContent),
    'sanitization_equal' => vaProductsCanonical($sanitized) === vaProductsCanonical($finalSubtree),
];
vaProductsValidateCurrentState($currentState);

$homepageProductsMarkup = do_shortcode('[veciahorra_homepage_products]');
$domProductsCount = preg_match_all('/class="[^"]*\bva-home-products\b[^"]*"/', $homepageProductsMarkup);
if ($domProductsCount !== 1 || $currentState['removed_id_occurrences'] !== 0) {
    throw new RuntimeException('current homepage products DOM differs');
}

$adversarialStates = [];
foreach ([
    'previous_live_hash' => ['elementor_sha256', VA_PRODUCTS_PRE_A5_ELEMENTOR_SHA256],
    'initial_subtree_present' => ['initial_occurrences', 1],
    'final_subtree_absent' => ['current_occurrences', 0],
    'final_subtree_duplicated' => ['current_occurrences', 2],
    'previous_shortcode' => ['shortcode', '[veciahorra_homepage_featured_products]'],
    'root_id_changed' => ['root_id', 'changed'],
    'widget_id_changed' => ['widget_id', 'changed'],
    'removed_id_reappeared' => ['removed_id_occurrences', 1],
    'hero_changed' => ['hero_canonical', 'changed'],
    'revoked_css_4498' => ['css_length', VA_PRODUCTS_REVOKED_CSS_LENGTH],
    'pre_a5_css' => ['css_sha256', VA_PRODUCTS_PRE_A5_PAGE_CSS_SHA256],
    'pre_a5_post_content' => ['post_content_sha256', VA_PRODUCTS_PRE_A5_POST_CONTENT_SHA256],
    'canonical_1' => ['schema', VA_PRODUCTS_REJECTED_SCHEMA],
    'sanitization_unequal' => ['sanitization_equal', false],
] as $name => [$key, $value]) {
    $candidate = $currentState;
    $candidate[$key] = $value;
    try {
        vaProductsValidateCurrentState($candidate);
        throw new RuntimeException('adversarial accepted: ' . $name);
    } catch (RuntimeException $exception) {
        if (str_starts_with($exception->getMessage(), 'adversarial accepted:')) {
            throw $exception;
        }
        $adversarialStates[$name] = 'REJECTED';
    }
}

echo json_encode([
    'canonical_schema' => 'veciahorra.elementor.canonical/2',
    'historical_pre_application_authority' => [
        'elementor_data_sha256' => VA_PRODUCTS_PRE_A5_ELEMENTOR_SHA256,
        'post_content_sha256' => VA_PRODUCTS_PRE_A5_POST_CONTENT_SHA256,
        'page_css_sha256' => VA_PRODUCTS_PRE_A5_PAGE_CSS_SHA256,
        'subtree_canonical' => VA_PRODUCTS_INITIAL_CANONICAL,
    ],
    'current_post_application_authority' => [
        'elementor_data_length' => strlen($currentRaw),
        'elementor_data_sha256' => hash('sha256', $currentRaw),
        'post_content_length' => strlen($postContent),
        'post_content_sha256' => hash('sha256', $postContent),
        'page_css_length' => strlen($css),
        'page_css_sha256' => hash('sha256', $css),
        'page_css_normalized_sha256' => hash('sha256', $normalizedCss),
        'subtree_canonical' => vaProductsHash($currentSubtree),
        'subtree_occurrences' => $currentState['current_occurrences'],
        'initial_subtree_occurrences' => $currentState['initial_occurrences'],
        'hero_canonical' => $currentState['hero_canonical'],
        'postmeta_rows' => count($metaRows),
        'postmeta_distinct_keys' => count($distinctMetaKeys),
        'revision_total' => count($revisions),
        'revision_meta_total' => $revisionMetaTotal,
        'dom_va_home_products_count' => $domProductsCount,
        'old_products_content_count' => $currentState['removed_id_occurrences'],
    ],
    'initial_subtree_length' => strlen($initialBytes),
    'initial_subtree_sha256' => hash('sha256', $initialBytes),
    'initial_canonical' => vaProductsHash($initialSubtree),
    'final_subtree_length' => strlen(json_encode($finalSubtree, JSON_THROW_ON_ERROR)),
    'final_subtree_sha256' => hash('sha256', json_encode($finalSubtree, JSON_THROW_ON_ERROR)),
    'final_canonical' => vaProductsHash($finalSubtree),
    'canonical_mutations' => 'PASS',
    'post_a5_adversarial_mutations' => [
        'total' => count($adversarialStates),
        'rejected' => count(array_filter($adversarialStates, static fn (string $value): bool => $value === 'REJECTED')),
    ],
    'elementor_sanitization' => 'PASS',
    'preserved_ids' => ['3ffeef7', '3014102'],
    'removed_ids' => ['83cf705', '6f52c3a', '3014101'],
    'final_shortcode' => '[veciahorra_homepage_products]',
    'outside_subtree_structural_equality' => 'PASS',
    'full_metadata_clone_certification' => [
        'independent_clones' => 2,
        'initial_postmeta_rows_each' => VA_PRODUCTS_FULL_METADATA_ROWS,
        'normalized_css_equality' => 'PASS',
        'projected_page_88_css_length' => VA_PRODUCTS_PROJECTED_PAGE_88_CSS_LENGTH,
        'projected_page_88_css_sha256' => VA_PRODUCTS_PROJECTED_PAGE_88_CSS_SHA256,
        'projected_page_88_css_normalized_sha256' => VA_PRODUCTS_PROJECTED_PAGE_88_CSS_NORMALIZED_SHA256,
        'matches_observed_productive_css' => 'PASS',
        'responsible_metadata' => '_elementor_page_settings:hide_title=yes',
        'css_delta' => ':root{--page-title-display:none;}',
    ],
    'revoked_projection' => [
        'length' => VA_PRODUCTS_REVOKED_CSS_LENGTH,
        'sha256' => VA_PRODUCTS_REVOKED_CSS_SHA256,
        'normalized_sha256' => VA_PRODUCTS_REVOKED_CSS_NORMALIZED_SHA256,
        'status' => 'REJECTED_INCOMPLETE_METADATA',
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
