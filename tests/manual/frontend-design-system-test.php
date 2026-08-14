<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Frontend\Assets\FrontendAssets;

ini_set('session.save_path', sys_get_temp_dir());
require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertDesignSystem(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string, array{body: string, visibility: string, static: bool}> */
function designSystemClassMethods(string $source): array
{
    $tokens = token_get_all($source);
    $methods = [];
    $count = count($tokens);
    $classDepth = null;
    $classBraceDepth = 0;
    for ($index = 0; $index < $count; $index++) {
        $token = $tokens[$index];
        if ($token === '{') {
            $classBraceDepth++;
        } elseif ($token === '}') {
            $classBraceDepth--;
            assertDesignSystem($classBraceDepth >= 0, 'Clase PHP con llaves desbalanceadas.');
        }
        if (is_array($token) && $token[0] === T_CLASS) {
            for ($cursor = $index + 1; $cursor < $count; $cursor++) {
                if (is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_STRING) {
                    assertDesignSystem($tokens[$cursor][1] === 'FrontendAssets', 'Clase productiva inesperada.');
                }
                if ($tokens[$cursor] === '{') {
                    $classDepth = $classBraceDepth + 1;
                    break;
                }
            }
        }
        if ($classDepth === null || $classBraceDepth !== $classDepth || ! is_array($token) || $token[0] !== T_FUNCTION) {
            continue;
        }
        $name = null;
        $visibility = 'public';
        $static = false;
        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            if ($tokens[$cursor] === ';' || $tokens[$cursor] === '{' || $tokens[$cursor] === '}') {
                break;
            }
            if (is_array($tokens[$cursor])) {
                $visibility = match ($tokens[$cursor][0]) {
                    T_PRIVATE => 'private',
                    T_PROTECTED => 'protected',
                    T_PUBLIC => 'public',
                    default => $visibility,
                };
                $static = $static || $tokens[$cursor][0] === T_STATIC;
            }
        }
        for ($cursor = $index + 1; $cursor < $count; $cursor++) {
            if ($name === null && is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_STRING) {
                $name = $tokens[$cursor][1];
                continue;
            }
            if ($tokens[$cursor] === '{' || $tokens[$cursor] === ';') {
                break;
            }
        }
        assertDesignSystem($name !== null && $tokens[$cursor] === '{', 'No fue posible delimitar un metodo de FrontendAssets.');
        $methodDepth = 1;
        $body = '';
        for ($cursor++; $cursor < $count && $methodDepth > 0; $cursor++) {
            $token = $tokens[$cursor];
            if ($token === '{') {
                $methodDepth++;
            } elseif ($token === '}') {
                $methodDepth--;
            }
            $ignored = [
                T_COMMENT,
                T_DOC_COMMENT,
                T_CONSTANT_ENCAPSED_STRING,
                T_ENCAPSED_AND_WHITESPACE,
                T_START_HEREDOC,
                T_END_HEREDOC,
            ];
            if ($methodDepth > 0 && (! is_array($token) || ! in_array($token[0], $ignored, true))) {
                $body .= is_array($token) ? $token[1] : $token;
            }
        }
        assertDesignSystem($methodDepth === 0, "Metodo desbalanceado: {$name}.");
        assertDesignSystem(! isset($methods[$name]), "Metodo duplicado: {$name}.");
        $methods[$name] = ['body' => $body, 'visibility' => $visibility, 'static' => $static];
        $index = $cursor - 1;
    }
    assertDesignSystem($classDepth !== null && $classBraceDepth === 0, 'No fue posible delimitar FrontendAssets.');
    return $methods;
}

function designSystemValidateAssetsSource(string $source): void
{
    $methods = designSystemClassMethods($source);
    $approved = ['enqueueCatalog', 'enqueueProductOffers', 'enqueueCart', 'enqueueCheckout'];
    assertDesignSystem(isset($methods['enqueueDesignSystem']), 'Helper enqueueDesignSystem ausente.');
    $helper = $methods['enqueueDesignSystem'];
    assertDesignSystem($helper['visibility'] === 'private' && ! $helper['static'], 'Helper debe ser private y no static.');
    assertDesignSystem(substr_count($helper['body'], '$this->registerAssets();') === 1, 'Helper sin registro previo unico.');
    assertDesignSystem(substr_count($helper['body'], 'wp_enqueue_style(self::DESIGN_SYSTEM_STYLE_HANDLE)') === 1, 'Enqueue privado invalido.');
    assertDesignSystem(
        isset($methods['registerAssets'])
            && substr_count($methods['registerAssets']['body'], 'self::DESIGN_SYSTEM_STYLE_HANDLE') === 1,
        'Registro productivo del stylesheet invalido.'
    );
    foreach ($methods as $name => $method) {
        $expected = in_array($name, $approved, true) ? 1 : 0;
        assertDesignSystem(substr_count($method['body'], '$this->enqueueDesignSystem();') === $expected, "Autoridad inesperada en {$name}.");
        assertDesignSystem(
            substr_count($method['body'], 'wp_enqueue_style(self::DESIGN_SYSTEM_STYLE_HANDLE)') === ($name === 'enqueueDesignSystem' ? 1 : 0),
            "Enqueue directo inesperado en {$name}."
        );
    }
}

/** @return list<string> */
function designSystemProductFiles(string $root): array
{
    $roots = [$root . '/veciahorra.php', $root . '/app', $root . '/assets'];
    foreach ($roots as $path) {
        assertDesignSystem(file_exists($path), "Raiz productiva ausente: {$path}.");
    }
    $files = [$roots[0]];
    foreach ([$roots[1], $roots[2]] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($iterator as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), ['php', 'css', 'js'], true)) {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }
    }
    sort($files);
    assertDesignSystem(count($files) > 1, 'Inventario productivo vacio.');
    foreach ($files as $file) {
        assertDesignSystem(is_readable($file), "Archivo productivo ilegible: {$file}.");
    }
    return $files;
}

/** @return array<string, string> */
function designSystemContractTokens(string $css): array
{
    assertDesignSystem(preg_match_all('/(?:^|})\s*\.veciahorra-frontend\.va-design-system\s*\{([^}]*)\}/m', $css, $roots) === 1, 'Raiz CSS ausente o duplicada.');
    $names = ['green-700', 'green-800', 'primary', 'primary-hover', 'navy-700', 'secondary', 'surface', 'success', 'warning', 'error'];
    $tokens = [];
    foreach ($names as $name) {
        $token = '--va-color-' . $name;
        assertDesignSystem(preg_match_all('/^\s*' . preg_quote($token, '/') . '\s*:\s*([^;]+);\s*$/m', $roots[1][0], $matches) === 1, "Token {$token} ausente o duplicado.");
        $tokens[$token] = trim($matches[1][0]);
    }
    return $tokens;
}

function designSystemColor(array $tokens, string $name, array $seen = []): string
{
    assertDesignSystem(isset($tokens[$name]) && ! isset($seen[$name]), "Token no resoluble: {$name}.");
    $seen[$name] = true;
    $value = $tokens[$name];
    if (preg_match('/^#[0-9a-f]{6}$/i', $value) === 1) {
        return $value;
    }
    assertDesignSystem(preg_match('/^var\((--va-color-[a-z0-9-]+)\)$/i', $value, $reference) === 1, "Gramatica no autorizada: {$name}.");
    return designSystemColor($tokens, $reference[1], $seen);
}

function designSystemLuminance(string $hex): float
{
    $values = [];
    foreach ([1, 3, 5] as $offset) {
        $value = hexdec(substr($hex, $offset, 2)) / 255;
        $values[] = $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
    }
    return (0.2126 * $values[0]) + (0.7152 * $values[1]) + (0.0722 * $values[2]);
}

function designSystemContrast(string $one, string $two): float
{
    $one = designSystemLuminance($one);
    $two = designSystemLuminance($two);
    return (max($one, $two) + 0.05) / (min($one, $two) + 0.05);
}

function designSystemValidateCssScope(string $css): void
{
    $css = preg_replace('~/\*.*?\*/~s', '', $css);
    assertDesignSystem(is_string($css), 'No fue posible normalizar CSS.');
    $validate = static function (string $block) use (&$validate): void {
        $length = strlen($block);
        $offset = 0;
        while ($offset < $length) {
            while ($offset < $length && ctype_space($block[$offset])) {
                $offset++;
            }
            if ($offset === $length) {
                return;
            }
            $open = strpos($block, '{', $offset);
            assertDesignSystem($open !== false, 'Regla CSS no delimitable.');
            $prelude = trim(substr($block, $offset, $open - $offset));
            assertDesignSystem($prelude !== '', 'Selector CSS vacio.');
            $depth = 1;
            for ($close = $open + 1; $close < $length && $depth > 0; $close++) {
                if ($block[$close] === '{') {
                    $depth++;
                } elseif ($block[$close] === '}') {
                    $depth--;
                }
            }
            assertDesignSystem($depth === 0, "Bloque CSS desbalanceado: {$prelude}.");
            $contents = substr($block, $open + 1, $close - $open - 2);
            if (str_starts_with($prelude, '@')) {
                assertDesignSystem(
                    preg_match('/^@(media|supports)\b/i', $prelude) === 1,
                    "At-rule de bloque no autorizada: {$prelude}."
                );
                $validate($contents);
            } else {
                $selectors = [];
                $selectorStart = 0;
                $parentheses = 0;
                for ($selectorOffset = 0, $selectorLength = strlen($prelude); $selectorOffset < $selectorLength; $selectorOffset++) {
                    $parentheses += $prelude[$selectorOffset] === '(' ? 1 : ($prelude[$selectorOffset] === ')' ? -1 : 0);
                    assertDesignSystem($parentheses >= 0, "Selector desbalanceado: {$prelude}.");
                    if ($prelude[$selectorOffset] === ',' && $parentheses === 0) {
                        $selectors[] = substr($prelude, $selectorStart, $selectorOffset - $selectorStart);
                        $selectorStart = $selectorOffset + 1;
                    }
                }
                assertDesignSystem($parentheses === 0, "Selector desbalanceado: {$prelude}.");
                $selectors[] = substr($prelude, $selectorStart);
                foreach ($selectors as $selector) {
                    $selector = trim($selector);
                    assertDesignSystem(
                        $selector === '.veciahorra-frontend.va-design-system'
                            || str_starts_with($selector, '.veciahorra-frontend.va-design-system '),
                        "Selector fuera de scope: {$selector}."
                    );
                    assertDesignSystem(! preg_match('/\.(?:ct-|woocommerce)/i', $selector), "Selector externo: {$selector}.");
                }
                assertDesignSystem(! str_contains($contents, '{') && ! str_contains($contents, '}'), "Regla ordinaria anidada invalida: {$prelude}.");
            }
            $offset = $close;
        }
    };
    $validate($css);
}

function designSystemValidateCss(string $css): array
{
    assertDesignSystem(! preg_match('/!important|@import|url\s*\(|https?:\/\/|@font-face/i', $css), 'CSS contiene dependencia o escape prohibido.');
    designSystemValidateCssScope($css);
    foreach (['.va-container', '.va-section', '.va-section-heading', '.va-eyebrow', '.va-button', '.va-button--primary', '.va-button--secondary', '.va-button--text', '.va-card', '.va-badge', '.va-field-group', '.va-field', '.va-alert', '.va-empty-state'] as $component) {
        assertDesignSystem(str_contains($css, $component), "Componente ausente: {$component}.");
    }
    foreach (['min-width: 2.75rem;', 'min-height: 2.75rem;', ':focus-visible', 'prefers-reduced-motion'] as $contract) {
        assertDesignSystem(substr_count($css, $contract) >= 1, "Contrato CSS ausente: {$contract}.");
    }
    $hoverSelectors = [
        '.veciahorra-frontend.va-design-system .va-button:not(.va-button--secondary, .va-button--text):hover:not(:disabled)',
        '.veciahorra-frontend.va-design-system .va-button--primary:hover:not(:disabled)',
        '.veciahorra-frontend.va-design-system .va-button--secondary:hover:not(:disabled)',
        '.veciahorra-frontend.va-design-system .va-button--text:hover:not(:disabled)',
    ];
    assertDesignSystem(substr_count($css, ':hover') === count($hoverSelectors), 'Cardinalidad de hover alterada.');
    foreach ($hoverSelectors as $selector) {
        assertDesignSystem(substr_count($css, $selector) === 1, "Selector hover ausente o duplicado: {$selector}.");
    }
    $expectedRules = [
        '.va-button--primary' => ['var(--va-color-primary', 'color: #ffffff'],
        '.va-button--primary:hover:not(:disabled)' => ['var(--va-color-primary-hover', 'color: #ffffff'],
        '.va-button--secondary' => ['var(--va-color-secondary', 'var(--va-color-surface'],
        '.va-button--secondary:hover:not(:disabled)' => ['var(--va-color-surface-soft', 'var(--va-color-secondary'],
        '.va-button--text' => ['background: transparent', 'var(--va-color-secondary'],
        '.va-button--text:hover:not(:disabled)' => ['background: rgb(11 71 120 / 8%)', 'var(--va-color-secondary'],
    ];
    foreach ($expectedRules as $suffix => $declarations) {
        $selector = '.veciahorra-frontend.va-design-system ' . $suffix;
        assertDesignSystem(preg_match_all('/(?:^|})\s*(?:[^{}]*,\s*)?' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/m', $css, $matches) === 1, "Regla contractual ausente o duplicada: {$suffix}.");
        foreach ($declarations as $declaration) {
            assertDesignSystem(str_contains($matches[1][0], $declaration), "Declaracion invalida en {$suffix}.");
        }
    }
    $tokens = designSystemContractTokens($css);
    assertDesignSystem(designSystemColor($tokens, '--va-color-primary') === '#3f7f16', 'Color primario contractual alterado.');
    assertDesignSystem(designSystemColor($tokens, '--va-color-primary-hover') === '#326612', 'Color hover contractual alterado.');
    $white = designSystemColor($tokens, '--va-color-surface');
    $ratios = [];
    foreach (['primary' => 4.5, 'primary-hover' => 4.5, 'navy-700' => 4.5, 'secondary' => 3.0, 'success' => 4.5, 'warning' => 4.5, 'error' => 4.5] as $name => $minimum) {
        $ratios[$name] = designSystemContrast(designSystemColor($tokens, '--va-color-' . $name), $white);
        assertDesignSystem($ratios[$name] >= $minimum, "Contraste insuficiente: {$name}.");
    }
    $ratios['focus'] = designSystemContrast(designSystemColor($tokens, '--va-color-secondary'), $white);
    assertDesignSystem($ratios['focus'] >= 3.0, 'Contraste de foco insuficiente.');
    return $ratios;
}

function designSystemMutateOnce(string $source, string $search, string $replace): string
{
    assertDesignSystem(substr_count($source, $search) === 1, "Mutacion no unitaria: {$search}.");
    $mutated = str_replace($search, $replace, $source);
    assertDesignSystem($mutated !== $source, 'Mutacion sin efecto.');
    return $mutated;
}

function designSystemValidateHandleReferences(array $references): void
{
    assertDesignSystem(
        $references === ['app/Modules/Frontend/Assets/FrontendAssets.php'],
        'Inventario literal del handle invalido: ' . implode(', ', $references)
    );
}

function designSystemExpectRejection(callable $validator, mixed $candidate, string $label): void
{
    $rejected = false;
    try {
        $validator($candidate);
    } catch (RuntimeException $exception) {
        $rejected = $exception->getMessage() !== '';
    }
    assertDesignSystem($rejected, "Adversarial aceptado: {$label}.");
}

/** @param list<string> $expectedEnvironmental */
function designSystemValidateEnvironmentalInventory(string $root, array $untracked, array $expectedEnvironmental): void
{
    $phaseFiles = ['assets/frontend/css/veciahorra-design-system.css', 'tests/manual/frontend-design-system-test.php'];
    sort($phaseFiles);
    $actualPhase = array_values(array_intersect($untracked, $phaseFiles));
    sort($actualPhase);
    assertDesignSystem(count($untracked) === 521 && $actualPhase === $phaseFiles, 'Inventario no rastreado de fase invalido.');
    $environmental = array_values(array_diff($untracked, $phaseFiles));
    sort($environmental);
    sort($expectedEnvironmental);
    assertDesignSystem($environmental === $expectedEnvironmental && count($environmental) === 519, 'Allowlist ambiental exacta alterada.');

    $artifactPaths = array_values(array_filter($environmental, static fn (string $path): bool => str_starts_with($path, 'artifacts/')));
    $pycPaths = array_values(array_filter($environmental, static fn (string $path): bool => str_ends_with($path, '.pyc')));
    $jsonPaths = array_values(array_filter($environmental, static fn (string $path): bool => str_starts_with($path, 'tests/manual/') && str_ends_with($path, '-result.json')));
    $other = array_values(array_diff($environmental, $artifactPaths, $pycPaths, $jsonPaths));
    assertDesignSystem(count($artifactPaths) === 513 && count($pycPaths) === 3 && count($jsonPaths) === 3 && $other === [], 'Categorias ambientales invalidas.');

    $measure = static function (string $directory): array {
        $files = [];
        $directories = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                $directories[] = $entry->getPathname();
            } elseif ($entry->isFile()) {
                $files[] = $entry;
            }
        }
        return [count($files), count($directories), array_sum(array_map(static fn (SplFileInfo $file): int => $file->getSize(), $files))];
    };
    [$artifactFileCount, $artifactDirectoryCount, $artifactBytes] = $measure($root . '/artifacts');
    [$visualFileCount, $visualDirectoryCount, $visualBytes] = $measure($root . '/artifacts/service-providers-visual');
    assertDesignSystem([$artifactFileCount, $artifactDirectoryCount, $artifactBytes] === [513, 309, 28537157], 'Metricas de artifacts alteradas.');
    assertDesignSystem([$visualFileCount, $visualBytes] === [9, 928002], 'Metricas service-providers-visual alteradas.');
    assertDesignSystem(
        [$artifactFileCount - $visualFileCount, $artifactDirectoryCount - $visualDirectoryCount - 1, $artifactBytes - $visualBytes] === [504, 308, 27609155],
        'Metricas historicas alteradas.'
    );
}

$root = dirname(__DIR__, 2);
$assetsPath = $root . '/app/Modules/Frontend/Assets/FrontendAssets.php';
$cssPath = $root . '/assets/frontend/css/veciahorra-design-system.css';
$assetsSource = (string) file_get_contents($assetsPath);
$css = (string) file_get_contents($cssPath);
assertDesignSystem($assetsSource !== '' && $css !== '', 'Fuentes visuales ilegibles.');

$approved = ['enqueueCatalog', 'enqueueProductOffers', 'enqueueCart', 'enqueueCheckout'];
designSystemValidateAssetsSource($assetsSource);
$reflection = new ReflectionClass(FrontendAssets::class);
$helperMethods = array_values(array_filter(
    $reflection->getMethods(),
    static fn (ReflectionMethod $method): bool => $method->getName() === 'enqueueDesignSystem'
));
assertDesignSystem(count($helperMethods) === 1, 'Helper real ausente o duplicado.');
$reflectedHelper = $helperMethods[0];
assertDesignSystem(
    $reflectedHelper->isPrivate() && ! $reflectedHelper->isPublic() && ! $reflectedHelper->isProtected() && ! $reflectedHelper->isStatic(),
    'Visibilidad real del helper invalida.'
);

$references = [];
foreach (designSystemProductFiles($root) as $file) {
    $contents = file_get_contents($file);
    assertDesignSystem(is_string($contents), "Lectura fallida: {$file}.");
    if (str_contains($contents, 'veciahorra-design-system')) {
        $references[] = substr($file, strlen(str_replace('\\', '/', $root)) + 1);
    }
}
designSystemValidateHandleReferences($references);

global $wp_styles;
$originalRegistered = $wp_styles->registered;
$originalQueue = $wp_styles->queue;
$originalDone = $wp_styles->done;
$originalToDo = $wp_styles->to_do;
foreach ($approved as $method) {
    unset($wp_styles->registered[FrontendAssets::DESIGN_SYSTEM_STYLE_HANDLE]);
    $wp_styles->queue = array_values(array_diff($wp_styles->queue, [FrontendAssets::DESIGN_SYSTEM_STYLE_HANDLE]));
    $wp_styles->done = array_values(array_diff($wp_styles->done, [FrontendAssets::DESIGN_SYSTEM_STYLE_HANDLE]));
    $wp_styles->to_do = array_values(array_diff($wp_styles->to_do, [FrontendAssets::DESIGN_SYSTEM_STYLE_HANDLE]));
    $assets = new FrontendAssets();
    $assets->{$method}();
    $registered = $wp_styles->registered[FrontendAssets::DESIGN_SYSTEM_STYLE_HANDLE] ?? null;
    assertDesignSystem($registered instanceof _WP_Dependency, "Handle no registrado: {$method}.");
    assertDesignSystem($registered->src === VA_PLUGIN_URL . 'assets/frontend/css/veciahorra-design-system.css', "Ruta runtime invalida: {$method}.");
    assertDesignSystem($registered->ver === Config::PLUGIN_VERSION && $registered->deps === [], "Contrato runtime invalido: {$method}.");
    assertDesignSystem(count(array_filter($wp_styles->queue, static fn (string $handle): bool => $handle === FrontendAssets::DESIGN_SYSTEM_STYLE_HANDLE)) === 1, "Handle no encolado exactamente una vez: {$method}.");
    $assets->{$method}();
    assertDesignSystem(count(array_filter($wp_styles->queue, static fn (string $handle): bool => $handle === FrontendAssets::DESIGN_SYSTEM_STYLE_HANDLE)) === 1, "Enqueue no idempotente: {$method}.");
}
$wp_styles->registered = $originalRegistered;
$wp_styles->queue = $originalQueue;
$wp_styles->done = $originalDone;
$wp_styles->to_do = $originalToDo;
$ratios = designSystemValidateCss($css);

$mutations = [
    ['--va-color-green-700: #3f7f16;', '--va-color-green-700: #4d9818;'],
    ['--va-color-green-700: #3f7f16;', "--va-color-green-700: #3f7f16;\n    --va-color-green-700: #ffffff;"],
    ['--va-color-primary: var(--va-color-green-700);', '--va-color-primary: var(--va-color-missing);'],
    ["--va-color-primary: var(--va-color-green-700);\n    --va-color-primary-hover: var(--va-color-green-800);", "--va-color-primary: var(--va-color-primary-hover);\n    --va-color-primary-hover: var(--va-color-primary);"],
    ["    background: var(--va-color-surface-soft, #f4f8f2);\n    color: var(--va-color-secondary, #0b4778);\n    transform: translateY(-1px);", "    background: var(--va-color-primary);\n    color: var(--va-color-secondary, #0b4778);\n    transform: translateY(-1px);"],
    ['background: rgb(11 71 120 / 8%);', 'background: var(--va-color-primary);'],
    ["\n@media (max-width: 40rem) {", "\nbody { color: red; }\n\n@media (max-width: 40rem) {"],
    ['transition: none;', 'transition: none !important;'],
    ['.veciahorra-frontend.va-design-system {', "@import 'unsafe.css';\n\n.veciahorra-frontend.va-design-system {"],
    ['background: transparent;', "background: url('x');"],
    ["    min-width: 2.75rem;\n    min-height: 2.75rem;\n    border: 1px solid transparent;", "    min-width: 0;\n    min-height: 2.75rem;\n    border: 1px solid transparent;"],
    ["\n@media (max-width: 40rem) {", "\n.otra-clase { color: red; }\n\n@media (max-width: 40rem) {"],
    ["\n@media (max-width: 40rem) {", "\n* { color: red; }\n\n@media (max-width: 40rem) {"],
    ["\n@media (max-width: 40rem) {", "\n[data-x] { color: red; }\n\n@media (max-width: 40rem) {"],
    ['.veciahorra-frontend.va-design-system .va-container,', ".veciahorra-frontend.va-design-system .va-container,\n.otra-clase,"],
    ["@media (max-width: 40rem) {\n    .veciahorra-frontend.va-design-system .va-container {", "@media (max-width: 40rem) {\n    .otra-clase {"],
];
foreach ($mutations as [$search, $replace]) {
    designSystemExpectRejection('designSystemValidateCss', designSystemMutateOnce($css, $search, $replace), 'CSS ' . $search);
}

$assetMutations = [
    ["        \$this->registerAssets();\n        wp_enqueue_style(self::DESIGN_SYSTEM_STYLE_HANDLE);", '        wp_enqueue_style(self::DESIGN_SYSTEM_STYLE_HANDLE);', 'helper sin registro'],
    ['    private function enqueueDesignSystem(): void', '    public function enqueueDesignSystem(): void', 'helper public'],
    ['    private function enqueueDesignSystem(): void', '    protected function enqueueDesignSystem(): void', 'helper protected'],
    ["        \$this->enqueueDesignSystem();\n        \$this->enqueue();\n        wp_enqueue_script(self::OFFER_SCRIPT_HANDLE);", "        '\$this->enqueueDesignSystem();';\n        \$this->enqueue();\n        wp_enqueue_script(self::OFFER_SCRIPT_HANDLE);", 'caller ficticio en string'],
    ["        \$this->enqueueDesignSystem();\n        \$this->enqueue();\n        wp_enqueue_script(self::CATALOG_SCRIPT_HANDLE);", "        // \$this->enqueueDesignSystem();\n        \$this->enqueue();\n        wp_enqueue_script(self::CATALOG_SCRIPT_HANDLE);", 'caller ficticio en comentario'],
    ['        return $this->routes ??= new PublicRouteResolver();', "        wp_enqueue_style(self::DESIGN_SYSTEM_STYLE_HANDLE);\n        return \$this->routes ??= new PublicRouteResolver();", 'enqueue productivo adicional'],
];
foreach ($assetMutations as [$search, $replace, $label]) {
    designSystemExpectRejection('designSystemValidateAssetsSource', designSystemMutateOnce($assetsSource, $search, $replace), $label);
}
$referenceRejected = false;
try {
    designSystemValidateHandleReferences(['app/Modules/Frontend/Assets/FrontendAssets.php', 'veciahorra.php']);
} catch (RuntimeException) {
    $referenceRejected = true;
}
assertDesignSystem($referenceRejected, 'Adversarial de referencia adicional aceptado.');

$tracked = array_values(array_filter(preg_split('/\R/', trim((string) shell_exec('git diff --name-only'))) ?: []));
$untracked = array_values(array_filter(preg_split('/\R/', trim((string) shell_exec('git ls-files --others --exclude-standard'))) ?: []));
$phaseUntracked = ['assets/frontend/css/veciahorra-design-system.css', 'tests/manual/frontend-design-system-test.php'];
$environmental = array_values(array_diff($untracked, $phaseUntracked));
designSystemValidateEnvironmentalInventory($root, $untracked, $environmental);
$simulatedUntracked = $untracked;
$simulatedUntracked[] = 'artifacts/adversarial-extra.txt';
designSystemExpectRejection(
    static function (array $candidate) use ($root, $environmental): void {
        designSystemValidateEnvironmentalInventory($root, $candidate, $environmental);
    },
    $simulatedUntracked,
    'archivo adicional dentro de artifacts'
);
$work = array_values(array_diff(array_unique(array_merge($tracked, $untracked)), $environmental));
sort($work);
$allowed = ['app/Modules/Frontend/Assets/FrontendAssets.php', 'assets/frontend/css/veciahorra-design-system.css', 'tests/manual/frontend-design-system-test.php'];
sort($allowed);
assertDesignSystem($work === $allowed, 'Alcance Git inesperado: ' . implode(', ', $work));

printf("PASS frontend-design-system-test primary=%.2f hover=%.2f navy=%.2f focus=%.2f adversarials=%d browser_evidence=external\n", $ratios['primary'], $ratios['primary-hover'], $ratios['navy-700'], $ratios['focus'], count($mutations) + count($assetMutations) + 2);
