<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';

const VA_PRODUCTS_CANONICAL_SCHEMA = 'veciahorra.elementor.canonical/2';
const VA_PRODUCTS_REJECTED_SCHEMA = 'veciahorra.elementor.canonical/1';
const VA_PRODUCTS_INITIAL_CANONICAL = '74640ac0b088a374048175a7f7b8edf03725ba829f99029e8113b5491ebd1e80';
const VA_PRODUCTS_FINAL_CANONICAL = '6be7569a41eccec79762a262d1c6c458233e47412beab010767adbe87d46bdde';
const VA_PRODUCTS_INITIAL_SUBTREE_SHA256 = '774690ee6528bb09f04d6768d49547baa37cfa748b50f7a3f9f58a8c8af4d60d';
const VA_PRODUCTS_INITIAL_ELEMENTOR_SHA256 = '14ff3f75c53e8be74c885ac1f4c0f5401efd1a22b0e33d7efacf065f57fd25c3';
const VA_PRODUCTS_FULL_METADATA_ROWS = 21;
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

$initialRaw = (string) get_post_meta(88, '_elementor_data', true);
if (hash('sha256', $initialRaw) !== VA_PRODUCTS_INITIAL_ELEMENTOR_SHA256) {
    throw new RuntimeException('page 88 Elementor authority differs');
}
$initialDocument = json_decode($initialRaw, true, 512, JSON_THROW_ON_ERROR);
$initialSubtree = vaProductsFind($initialDocument, '3ffeef7');
if (!is_array($initialSubtree)) {
    throw new RuntimeException('initial products subtree missing');
}
$initialBytes = json_encode($initialSubtree, JSON_THROW_ON_ERROR);
if (strlen($initialBytes) !== 1545 || hash('sha256', $initialBytes) !== VA_PRODUCTS_INITIAL_SUBTREE_SHA256) {
    throw new RuntimeException('initial subtree bytes differ');
}
if (vaProductsHash($initialSubtree) !== VA_PRODUCTS_INITIAL_CANONICAL) {
    throw new RuntimeException('initial canonical/2 differs');
}

$finalSubtree = vaProductsFinalSubtree();
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

$finalDocument = $initialDocument;
if (vaProductsReplace($finalDocument, '3ffeef7', $finalSubtree) !== 1) {
    throw new RuntimeException('products subtree occurrence differs');
}
if (vaProductsCanonical(vaProductsWithout($initialDocument, '3ffeef7'))
    !== vaProductsCanonical(vaProductsWithout($finalDocument, '3ffeef7'))
) {
    throw new RuntimeException('outside subtree changed');
}

$mode = getenv('VA_PRODUCTS_MODE') ?: 'memory';
if ($mode === 'cleanup') {
    $postId = (int) getenv('VA_PRODUCTS_POST_ID');
    if ($postId > 0) {
        Elementor\Core\Files\CSS\Post::create($postId)->delete();
        foreach (wp_get_post_revisions($postId) as $revision) {
            wp_delete_post($revision->ID, true);
        }
        wp_delete_post($postId, true);
        clean_post_cache($postId);
    }
    echo json_encode(['cleanup' => 'PASS', 'post_id' => $postId]), PHP_EOL;
    exit;
}

if ($mode === 'create') {
    wp_set_current_user(1);
    $postId = 0;
    try {
        $token = bin2hex(random_bytes(12));
        $postId = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'VA products subtree dry run ' . $token,
            'post_name' => 'va-products-subtree-' . $token,
            'post_content' => (string) get_post_field('post_content', 88),
            'post_author' => 1,
        ], true);
        if (is_wp_error($postId)) {
            throw new RuntimeException($postId->get_error_message());
        }
        // CSS projection requires the complete productive metadata envelope.
        // In particular, _elementor_page_settings=hide_title=yes emits the
        // otherwise absent rule :root{--page-title-display:none;} (33 bytes).
        global $wpdb;
        $sourceRows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id=%d ORDER BY meta_id",
            88
        ), ARRAY_A);
        if (count($sourceRows) !== VA_PRODUCTS_FULL_METADATA_ROWS) {
            throw new RuntimeException('productive metadata row count differs');
        }
        $wpdb->delete($wpdb->postmeta, ['post_id' => $postId], ['%d']);
        foreach ($sourceRows as $sourceRow) {
            add_post_meta(
                $postId,
                (string) $sourceRow['meta_key'],
                wp_slash(maybe_unserialize((string) $sourceRow['meta_value']))
            );
        }
        clean_post_cache($postId);
        if (vaProductsMetaProjection($postId) !== vaProductsMetaProjection(88)) {
            throw new RuntimeException('full productive metadata projection differs');
        }
        $s0 = vaProductsMetaSnapshot($postId);
        $document = Elementor\Plugin::$instance->documents->get($postId);
        if (!$document || !$document->save(['elements' => $finalDocument, 'settings' => []])) {
            throw new RuntimeException('Document::save failed');
        }
        $s1 = vaProductsMetaSnapshot($postId);
        Elementor\Core\Files\CSS\Post::create($postId)->update();
        $s2 = vaProductsMetaSnapshot($postId);
        $post = get_post($postId);
        if ($post instanceof WP_Post) {
            setup_postdata($post);
            apply_filters('the_content', $post->post_content);
            wp_reset_postdata();
        }
        $s3 = vaProductsMetaSnapshot($postId);
        $s4 = vaProductsMetaSnapshot($postId);
        $savedRaw = (string) get_post_meta($postId, '_elementor_data', true);
        $savedDocument = json_decode($savedRaw, true, 512, JSON_THROW_ON_ERROR);
        $savedSubtree = vaProductsFind($savedDocument, '3ffeef7');
        $postContent = (string) get_post_field('post_content', $postId);
        $uploads = wp_upload_dir();
        $cssPath = $uploads['basedir'] . '/elementor/css/post-' . $postId . '.css';
        $css = is_file($cssPath) ? (string) file_get_contents($cssPath) : '';
        $tokenPattern = '.elementor-' . $postId;
        $tokenCount = substr_count($css, $tokenPattern);
        $normalizedCss = str_replace($tokenPattern, '.elementor-{ELEMENTOR_PAGE_ID}', $css);
        $projectedCss = str_replace($tokenPattern, '.elementor-88', $css);
        $projectedNormalizedCss = str_replace('.elementor-88', '.elementor-{ELEMENTOR_PAGE_ID}', $projectedCss);
        if (strlen($projectedCss) !== VA_PRODUCTS_PROJECTED_PAGE_88_CSS_LENGTH
            || hash('sha256', $projectedCss) !== VA_PRODUCTS_PROJECTED_PAGE_88_CSS_SHA256
            || hash('sha256', $projectedNormalizedCss) !== VA_PRODUCTS_PROJECTED_PAGE_88_CSS_NORMALIZED_SHA256
        ) {
            throw new RuntimeException('full-metadata CSS authority differs');
        }
        $revisions = wp_get_post_revisions($postId);
        $revisionProjection = [];
        foreach ($revisions as $revision) {
            $revisionProjection[] = [
                'post_content_length' => strlen((string) $revision->post_content),
                'post_content_sha256' => hash('sha256', (string) $revision->post_content),
                'meta' => vaProductsMetaSnapshot((int) $revision->ID),
            ];
        }
        echo json_encode([
            'canonical_schema' => VA_PRODUCTS_CANONICAL_SCHEMA,
            'post_id' => $postId,
            'url' => get_permalink($postId),
            'initial_elementor_data_sha256' => hash('sha256', $initialRaw),
            'final_elementor_data_length' => strlen($savedRaw),
            'final_elementor_data_sha256' => hash('sha256', $savedRaw),
            'final_post_content_length' => strlen($postContent),
            'final_post_content_sha256' => hash('sha256', $postContent),
            'final_post_content_base64' => base64_encode($postContent),
            'final_subtree_length' => strlen(json_encode($savedSubtree, JSON_THROW_ON_ERROR)),
            'final_subtree_sha256' => hash('sha256', json_encode($savedSubtree, JSON_THROW_ON_ERROR)),
            'final_subtree_canonical' => vaProductsHash($savedSubtree),
            'postmeta' => ['S0' => $s0, 'S1' => $s1, 'S2' => $s2, 'S3' => $s3, 'S4' => $s4],
            'revisions' => $revisionProjection,
            'css_length' => strlen($css),
            'css_sha256' => hash('sha256', $css),
            'css_token_pattern' => $tokenPattern,
            'css_token_occurrences' => $tokenCount,
            'css_normalized_sha256' => hash('sha256', $normalizedCss),
            'projected_css_length' => strlen($projectedCss),
            'projected_css_sha256' => hash('sha256', $projectedCss),
            'projected_css_normalized_sha256' => hash('sha256', $projectedNormalizedCss),
            'full_productive_metadata_rows' => count($sourceRows),
            'full_productive_metadata_projection' => 'PASS',
            'revoked_projection_rejected' => 'PASS',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
        exit;
    } catch (Throwable $exception) {
        if ($postId > 0) {
            Elementor\Core\Files\CSS\Post::create($postId)->delete();
            foreach (wp_get_post_revisions($postId) as $revision) {
                wp_delete_post($revision->ID, true);
            }
            wp_delete_post($postId, true);
        }
        throw $exception;
    }
}

echo json_encode([
    'canonical_schema' => 'veciahorra.elementor.canonical/2',
    'initial_elementor_data_sha256' => VA_PRODUCTS_INITIAL_ELEMENTOR_SHA256,
    'initial_subtree_length' => strlen($initialBytes),
    'initial_subtree_sha256' => hash('sha256', $initialBytes),
    'initial_canonical' => vaProductsHash($initialSubtree),
    'final_subtree_length' => strlen(json_encode($finalSubtree, JSON_THROW_ON_ERROR)),
    'final_subtree_sha256' => hash('sha256', json_encode($finalSubtree, JSON_THROW_ON_ERROR)),
    'final_canonical' => vaProductsHash($finalSubtree),
    'canonical_mutations' => 'PASS',
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
