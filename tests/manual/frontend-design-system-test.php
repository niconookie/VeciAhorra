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

/** @return list<array{0: int|null, 1: string}> */
function designSystemExecutableTokens(array $tokens): array
{
    $result = [];
    $heredoc = false;
    $doubleQuoted = false;
    foreach ($tokens as $token) {
        $id = is_array($token) ? $token[0] : null;
        $text = is_array($token) ? $token[1] : $token;
        if ($id === T_START_HEREDOC) {
            $heredoc = true;
            continue;
        }
        if ($heredoc) {
            if ($id === T_END_HEREDOC) {
                $heredoc = false;
            }
            continue;
        }
        if ($id === null && $text === '"') {
            $doubleQuoted = ! $doubleQuoted;
            continue;
        }
        if ($doubleQuoted) {
            continue;
        }
        if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            continue;
        }
        $result[] = [$id, $text];
    }
    assertDesignSystem(! $heredoc && ! $doubleQuoted, 'PHP_TOKEN_STRING: literal no delimitable.');
    return $result;
}

/** @return array<string, array{tokens: list<array{0: int|null, 1: string}>, visibility: string, static: bool}> */
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
        $bodyTokens = [];
        for ($cursor++; $cursor < $count && $methodDepth > 0; $cursor++) {
            $token = $tokens[$cursor];
            if ($token === '{') {
                $methodDepth++;
            } elseif ($token === '}') {
                $methodDepth--;
            }
            if ($methodDepth > 0) {
                $bodyTokens[] = $token;
            }
        }
        assertDesignSystem($methodDepth === 0, "PHP_METHOD_BALANCE: {$name}.");
        assertDesignSystem(! isset($methods[$name]), "PHP_HELPER_COUNT: metodo duplicado {$name}.");
        $methods[$name] = ['tokens' => designSystemExecutableTokens($bodyTokens), 'visibility' => $visibility, 'static' => $static];
        $index = $cursor - 1;
    }
    assertDesignSystem($classDepth !== null && $classBraceDepth === 0, 'No fue posible delimitar FrontendAssets.');
    return $methods;
}

/** @param list<array{0: int|null, 1: string}> $tokens */
function designSystemCountThisCalls(array $tokens, string $method): int
{
    $count = 0;
    for ($index = 0, $length = count($tokens); $index + 3 < $length; $index++) {
        if (
            $tokens[$index] === [T_VARIABLE, '$this']
            && $tokens[$index + 1][0] === T_OBJECT_OPERATOR
            && $tokens[$index + 2] === [T_STRING, $method]
            && $tokens[$index + 3][1] === '('
        ) {
            $count++;
        }
    }
    return $count;
}

/** @param list<array{0: int|null, 1: string}> $tokens */
function designSystemCountHandleCalls(array $tokens, string $function): int
{
    $count = 0;
    for ($index = 0, $length = count($tokens); $index + 4 < $length; $index++) {
        if (
            $tokens[$index] === [T_STRING, $function]
            && $tokens[$index + 1][1] === '('
            && strtolower($tokens[$index + 2][1]) === 'self'
            && $tokens[$index + 3][0] === T_DOUBLE_COLON
            && $tokens[$index + 4] === [T_STRING, 'DESIGN_SYSTEM_STYLE_HANDLE']
        ) {
            $count++;
        }
    }
    return $count;
}

function designSystemValidateAssetsSource(string $source): void
{
    $methods = designSystemClassMethods($source);
    $approved = ['enqueueCatalog', 'enqueueProductOffers', 'enqueueCart', 'enqueueCheckout'];
    assertDesignSystem(isset($methods['enqueueDesignSystem']), 'PHP_HELPER_COUNT: helper ausente.');
    $helper = $methods['enqueueDesignSystem'];
    assertDesignSystem($helper['visibility'] === 'private' && ! $helper['static'], 'PHP_HELPER_VISIBILITY: debe ser private y no static.');
    assertDesignSystem(designSystemCountThisCalls($helper['tokens'], 'registerAssets') === 1, 'PHP_HELPER_REGISTER: registro previo invalido.');
    assertDesignSystem(designSystemCountHandleCalls($helper['tokens'], 'wp_enqueue_style') === 1, 'PHP_HELPER_ENQUEUE: enqueue privado invalido.');
    assertDesignSystem(
        isset($methods['registerAssets'])
            && designSystemCountHandleCalls($methods['registerAssets']['tokens'], 'wp_register_style') === 1,
        'PHP_STYLESHEET_REGISTER: registro productivo invalido.'
    );
    foreach ($methods as $name => $method) {
        $expected = in_array($name, $approved, true) ? 1 : 0;
        assertDesignSystem(designSystemCountThisCalls($method['tokens'], 'enqueueDesignSystem') === $expected, "PHP_CALLER_AUTHORITY: {$name}.");
        assertDesignSystem(
            designSystemCountHandleCalls($method['tokens'], 'wp_enqueue_style') === ($name === 'enqueueDesignSystem' ? 1 : 0),
            "PHP_DIRECT_ENQUEUE: {$name}."
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

function designSystemCssWithoutComments(string $css): string
{
    $result = '';
    $quote = null;
    for ($index = 0, $length = strlen($css); $index < $length; $index++) {
        $character = $css[$index];
        if ($quote !== null) {
            $result .= $character;
            if ($character === '\\' && $index + 1 < $length) {
                $result .= $css[++$index];
            } elseif ($character === $quote) {
                $quote = null;
            }
            continue;
        }
        if ($character === '"' || $character === "'") {
            $quote = $character;
            $result .= $character;
            continue;
        }
        if ($character === '/' && ($css[$index + 1] ?? '') === '*') {
            $end = strpos($css, '*/', $index + 2);
            assertDesignSystem($end !== false, 'CSS_COMMENT: comentario no delimitable.');
            $result .= ' ';
            $index = $end + 1;
            continue;
        }
        $result .= $character;
    }
    assertDesignSystem($quote === null, 'CSS_STRING: string no delimitable.');
    return $result;
}

/** @return list<string> */
function designSystemCssSelectorBranches(string $selectorList): array
{
    $branches = [];
    $start = 0;
    $parentheses = 0;
    $brackets = 0;
    $quote = null;
    for ($index = 0, $length = strlen($selectorList); $index < $length; $index++) {
        $character = $selectorList[$index];
        if ($quote !== null) {
            if ($character === '\\') {
                $index++;
            } elseif ($character === $quote) {
                $quote = null;
            }
            continue;
        }
        if ($character === '"' || $character === "'") {
            $quote = $character;
        } elseif ($character === '(') {
            $parentheses++;
        } elseif ($character === ')') {
            $parentheses--;
        } elseif ($character === '[') {
            $brackets++;
        } elseif ($character === ']') {
            $brackets--;
        } elseif ($character === ',' && $parentheses === 0 && $brackets === 0) {
            $branches[] = trim(substr($selectorList, $start, $index - $start));
            $start = $index + 1;
        }
        assertDesignSystem($parentheses >= 0 && $brackets >= 0, 'CSS_SELECTOR_BALANCE: selector desbalanceado.');
    }
    assertDesignSystem($quote === null && $parentheses === 0 && $brackets === 0, 'CSS_SELECTOR_BALANCE: selector desbalanceado.');
    $branches[] = trim(substr($selectorList, $start));
    return $branches;
}

function designSystemValidateCssSelector(string $selector): void
{
    $root = '.veciahorra-frontend.va-design-system';
    assertDesignSystem($selector !== '', 'CSS_SCOPE: selector vacio.');
    assertDesignSystem(str_starts_with($selector, $root), "CSS_SCOPE: {$selector}.");
    $remainder = substr($selector, strlen($root));
    if ($remainder === '') {
        return;
    }
    assertDesignSystem(preg_match('/^(?:\s|>|:)/', $remainder) === 1, "CSS_SCOPE: frontera invalida {$selector}.");
    $trimmed = ltrim($remainder);
    assertDesignSystem(! str_starts_with($trimmed, '+'), "CSS_SIBLING_COMBINATOR: {$selector}.");
    assertDesignSystem(! str_starts_with($trimmed, '~'), "CSS_SIBLING_COMBINATOR: {$selector}.");
    $parentheses = 0;
    $brackets = 0;
    $quote = null;
    for ($index = 0, $length = strlen($remainder); $index < $length; $index++) {
        $character = $remainder[$index];
        if ($quote !== null) {
            if ($character === '\\') {
                $index++;
            } elseif ($character === $quote) {
                $quote = null;
            }
            continue;
        }
        if ($character === '"' || $character === "'") {
            $quote = $character;
        } elseif ($character === '(') {
            $parentheses++;
        } elseif ($character === ')') {
            $parentheses--;
        } elseif ($character === '[') {
            $brackets++;
        } elseif ($character === ']') {
            $brackets--;
        } elseif (($character === '+' || $character === '~') && $parentheses === 0 && $brackets === 0) {
            throw new RuntimeException("CSS_SIBLING_COMBINATOR: {$selector}.");
        }
    }
    assertDesignSystem(! preg_match('/\.(?:ct-|woocommerce)/i', $selector), "CSS_EXTERNAL_CLASS: {$selector}.");
}

function designSystemCssHasStructuralBrace(string $css): bool
{
    $quote = null;
    for ($index = 0, $length = strlen($css); $index < $length; $index++) {
        $character = $css[$index];
        if ($quote !== null) {
            if ($character === '\\') {
                $index++;
            } elseif ($character === $quote) {
                $quote = null;
            }
        } elseif ($character === '"' || $character === "'") {
            $quote = $character;
        } elseif ($character === '{' || $character === '}') {
            return true;
        }
    }
    return false;
}

function designSystemValidateCssScope(string $css): void
{
    $css = designSystemCssWithoutComments($css);
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
            $open = null;
            $quote = null;
            $parentheses = 0;
            for ($scan = $offset; $scan < $length; $scan++) {
                $character = $block[$scan];
                if ($quote !== null) {
                    if ($character === '\\') {
                        $scan++;
                    } elseif ($character === $quote) {
                        $quote = null;
                    }
                    continue;
                }
                if ($character === '"' || $character === "'") {
                    $quote = $character;
                } elseif ($character === '(') {
                    $parentheses++;
                } elseif ($character === ')') {
                    $parentheses--;
                } elseif ($character === '{' && $parentheses === 0) {
                    $open = $scan;
                    break;
                } elseif ($character === ';' && $parentheses === 0) {
                    throw new RuntimeException('CSS_AT_RULE: sentencia superior no autorizada.');
                }
            }
            assertDesignSystem($open !== null, 'CSS_RULE_DELIMITATION: regla no delimitable.');
            $prelude = trim(substr($block, $offset, $open - $offset));
            assertDesignSystem($prelude !== '', 'CSS_SCOPE: selector vacio.');
            $depth = 1;
            $quote = null;
            for ($close = $open + 1; $close < $length && $depth > 0; $close++) {
                $character = $block[$close];
                if ($quote !== null) {
                    if ($character === '\\') {
                        $close++;
                    } elseif ($character === $quote) {
                        $quote = null;
                    }
                } elseif ($character === '"' || $character === "'") {
                    $quote = $character;
                } elseif ($character === '{') {
                    $depth++;
                } elseif ($character === '}') {
                    $depth--;
                }
            }
            assertDesignSystem($depth === 0 && $quote === null, "CSS_BLOCK_BALANCE: {$prelude}.");
            $contents = substr($block, $open + 1, $close - $open - 2);
            if (str_starts_with($prelude, '@')) {
                assertDesignSystem(
                    preg_match('/^@(media|supports|layer|container|scope)\b/i', $prelude) === 1,
                    "CSS_AT_RULE: {$prelude}."
                );
                $validate($contents);
            } else {
                foreach (designSystemCssSelectorBranches($prelude) as $selector) {
                    designSystemValidateCssSelector($selector);
                }
                assertDesignSystem(! designSystemCssHasStructuralBrace($contents), "CSS_RULE_NESTING: {$prelude}.");
            }
            $offset = $close;
        }
    };
    $validate($css);
}

function designSystemValidateCss(string $css): array
{
    assertDesignSystem(! preg_match('/!important/i', $css), 'CSS_IMPORTANT: declaracion prohibida.');
    assertDesignSystem(! preg_match('/@import/i', $css), 'CSS_IMPORT: dependencia prohibida.');
    assertDesignSystem(! preg_match('/url\s*\(/i', $css), 'CSS_URL: recurso externo prohibido.');
    assertDesignSystem(! preg_match('/https?:\/\//i', $css), 'CSS_EXTERNAL_URL: URL externa prohibida.');
    assertDesignSystem(! preg_match('/@font-face/i', $css), 'CSS_FONT_FACE: fuente prohibida.');
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
    $white = designSystemColor($tokens, '--va-color-surface');
    $ratios = [];
    foreach (['primary' => 4.5, 'primary-hover' => 4.5, 'navy-700' => 4.5, 'secondary' => 3.0, 'success' => 4.5, 'warning' => 4.5, 'error' => 4.5] as $name => $minimum) {
        $ratios[$name] = designSystemContrast(designSystemColor($tokens, '--va-color-' . $name), $white);
        assertDesignSystem($ratios[$name] >= $minimum, "Contraste insuficiente: {$name}.");
    }
    $ratios['focus'] = designSystemContrast(designSystemColor($tokens, '--va-color-secondary'), $white);
    assertDesignSystem($ratios['focus'] >= 3.0, 'Contraste de foco insuficiente.');
    assertDesignSystem(designSystemColor($tokens, '--va-color-primary') === '#3f7f16', 'Color primario contractual alterado.');
    assertDesignSystem(designSystemColor($tokens, '--va-color-primary-hover') === '#326612', 'Color hover contractual alterado.');
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

function designSystemExpectRejection(callable $validator, mixed $candidate, string $expectedDiagnostic, string $label): void
{
    try {
        $validator($candidate);
    } catch (RuntimeException $exception) {
        assertDesignSystem(
            $exception->getMessage() !== '' && str_contains($exception->getMessage(), $expectedDiagnostic),
            "ADVERSARIAL_WRONG_CAUSE: {$label}; esperado={$expectedDiagnostic}; obtenido={$exception->getMessage()}."
        );
        $GLOBALS['designSystemAdversarialResults'][] = [$label, $expectedDiagnostic, $exception->getMessage()];
        return;
    } catch (Throwable $exception) {
        throw new RuntimeException("ADVERSARIAL_UNEXPECTED_EXCEPTION: {$label}; " . $exception::class . ': ' . $exception->getMessage(), 0, $exception);
    }
    throw new RuntimeException("ADVERSARIAL_ACCEPTED: {$label}; esperado={$expectedDiagnostic}.");
}

function designSystemExpectAcceptance(callable $validator, mixed $candidate, string $label): void
{
    try {
        $validator($candidate);
    } catch (Throwable $exception) {
        throw new RuntimeException("ADVERSARIAL_POSITIVE_REJECTED: {$label}; " . $exception->getMessage(), 0, $exception);
    }
    $GLOBALS['designSystemAdversarialResults'][] = [$label, 'ACCEPT', 'ACCEPT'];
}

function designSystemValidateEnvironmentalInventory(string $root, array $untracked): void
{
    sort($untracked, SORT_STRING);
    assertDesignSystem(count($untracked) === 519, 'ENV_COUNT: se esperaban 519 ambientales.');
    assertDesignSystem(
        hash('sha256', implode("\n", $untracked)) === '15a45f3aa19cacb8be80b0963476671e388e75501ff5088f839c385bf1d1433d',
        'ENV_FINGERPRINT: rutas ambientales alteradas.'
    );

    $artifactPaths = array_values(array_filter($untracked, static fn (string $path): bool => str_starts_with($path, 'artifacts/')));
    $pycPaths = array_values(array_filter($untracked, static fn (string $path): bool => str_ends_with($path, '.pyc')));
    $jsonPaths = array_values(array_filter($untracked, static fn (string $path): bool => str_starts_with($path, 'tests/manual/') && str_ends_with($path, '-result.json')));
    $other = array_values(array_diff($untracked, $artifactPaths, $pycPaths, $jsonPaths));
    assertDesignSystem(count($artifactPaths) === 513 && count($pycPaths) === 3 && count($jsonPaths) === 3 && $other === [], 'ENV_CATEGORIES: categorias ambientales invalidas.');

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
    assertDesignSystem([$artifactFileCount, $artifactDirectoryCount, $artifactBytes] === [513, 309, 28537157], 'ENV_ARTIFACT_METRICS: artifacts alterado.');
    assertDesignSystem([$visualFileCount, $visualBytes] === [9, 928002], 'ENV_VISUAL_METRICS: service-providers-visual alterado.');
    assertDesignSystem(
        [$artifactFileCount - $visualFileCount, $artifactDirectoryCount - $visualDirectoryCount - 1, $artifactBytes - $visualBytes] === [504, 308, 27609155],
        'ENV_HISTORIC_METRICS: historico alterado.'
    );
}

$root = dirname(__DIR__, 2);
$GLOBALS['designSystemAdversarialResults'] = [];
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
    $caseHandles = [FrontendAssets::DESIGN_SYSTEM_STYLE_HANDLE, FrontendAssets::STYLE_HANDLE];
    $wp_styles->queue = array_values(array_diff($originalQueue, $caseHandles));
    $wp_styles->done = array_values(array_diff($originalDone, $caseHandles));
    $wp_styles->to_do = array_values(array_diff($originalToDo, $caseHandles));
    $assets = new FrontendAssets();
    $assets->{$method}();
    $registered = $wp_styles->registered[FrontendAssets::DESIGN_SYSTEM_STYLE_HANDLE] ?? null;
    assertDesignSystem($registered instanceof _WP_Dependency, "Handle no registrado: {$method}.");
    assertDesignSystem($registered->src === VA_PLUGIN_URL . 'assets/frontend/css/veciahorra-design-system.css', "Ruta runtime invalida: {$method}.");
    assertDesignSystem($registered->ver === Config::PLUGIN_VERSION && $registered->deps === [] && $registered->args === 'all', "Contrato runtime invalido: {$method}.");
    assertDesignSystem(count(array_filter($wp_styles->queue, static fn (string $handle): bool => $handle === FrontendAssets::DESIGN_SYSTEM_STYLE_HANDLE)) === 1, "Handle no encolado exactamente una vez: {$method}.");
    $designPosition = array_search(FrontendAssets::DESIGN_SYSTEM_STYLE_HANDLE, $wp_styles->queue, true);
    $legacyPosition = array_search(FrontendAssets::STYLE_HANDLE, $wp_styles->queue, true);
    assertDesignSystem(is_int($designPosition) && is_int($legacyPosition) && $designPosition < $legacyPosition, "Orden de styles invalido: {$method}.");
    $assets->{$method}();
    assertDesignSystem(count(array_filter($wp_styles->queue, static fn (string $handle): bool => $handle === FrontendAssets::DESIGN_SYSTEM_STYLE_HANDLE)) === 1, "Enqueue no idempotente: {$method}.");
}
$wp_styles->registered = $originalRegistered;
$wp_styles->queue = $originalQueue;
$wp_styles->done = $originalDone;
$wp_styles->to_do = $originalToDo;
$ratios = designSystemValidateCss($css);

$mutations = [
    ['--va-color-green-700: #3f7f16;', '--va-color-green-700: #4d9818;', 'Contraste insuficiente: primary', 'primario insuficiente'],
    ['--va-color-green-700: #3f7f16;', "--va-color-green-700: #3f7f16;\n    --va-color-green-700: #ffffff;", 'ausente o duplicado', 'token duplicado'],
    ['--va-color-primary: var(--va-color-green-700);', '--va-color-primary: var(--va-color-missing);', 'Token no resoluble', 'referencia rota'],
    ["--va-color-primary: var(--va-color-green-700);\n    --va-color-primary-hover: var(--va-color-green-800);", "--va-color-primary: var(--va-color-primary-hover);\n    --va-color-primary-hover: var(--va-color-primary);", 'Token no resoluble', 'ciclo de tokens'],
    ["    background: var(--va-color-surface-soft, #f4f8f2);\n    color: var(--va-color-secondary, #0b4778);\n    transform: translateY(-1px);", "    background: var(--va-color-primary);\n    color: var(--va-color-secondary, #0b4778);\n    transform: translateY(-1px);", 'Declaracion invalida', 'secondary hover verde'],
    ['background: rgb(11 71 120 / 8%);', 'background: var(--va-color-primary);', 'Declaracion invalida', 'text hover verde'],
    ["\n@media (max-width: 40rem) {", "\nbody { color: red; }\n\n@media (max-width: 40rem) {", 'CSS_SCOPE', 'selector body'],
    ['transition: none;', 'transition: none !important;', 'CSS_IMPORTANT', '!important'],
    ['.veciahorra-frontend.va-design-system {', "@import 'unsafe.css';\n\n.veciahorra-frontend.va-design-system {", 'CSS_IMPORT', '@import'],
    ['background: transparent;', "background: url('x');", 'CSS_URL', 'url'],
    ["    min-width: 2.75rem;\n    min-height: 2.75rem;\n    border: 1px solid transparent;", "    min-width: 0;\n    min-height: 2.75rem;\n    border: 1px solid transparent;", 'Contrato CSS ausente', 'ancho tactil'],
    ["\n@media (max-width: 40rem) {", "\n.otra-clase { color: red; }\n\n@media (max-width: 40rem) {", 'CSS_SCOPE', 'otra clase'],
    ["\n@media (max-width: 40rem) {", "\n* { color: red; }\n\n@media (max-width: 40rem) {", 'CSS_SCOPE', 'selector universal'],
    ["\n@media (max-width: 40rem) {", "\n[data-x] { color: red; }\n\n@media (max-width: 40rem) {", 'CSS_SCOPE', 'selector de atributo'],
    ['.veciahorra-frontend.va-design-system .va-container,', ".veciahorra-frontend.va-design-system .va-container,\n.otra-clase,", 'CSS_SCOPE', 'lista mixta'],
    ["@media (max-width: 40rem) {\n    .veciahorra-frontend.va-design-system .va-container {", "@media (max-width: 40rem) {\n    .otra-clase {", 'CSS_SCOPE', 'media sin scope'],
    ["\n@media (max-width: 40rem) {", "\n.veciahorra-frontend.va-design-system + body { color: red; }\n\n@media (max-width: 40rem) {", 'CSS_SIBLING_COMBINATOR', 'hermano adyacente'],
    ["\n@media (max-width: 40rem) {", "\n.veciahorra-frontend.va-design-system ~ .otra-clase { color: red; }\n\n@media (max-width: 40rem) {", 'CSS_SIBLING_COMBINATOR', 'hermano general'],
    ["\n@media (max-width: 40rem) {", "\n.veciahorra-frontend.va-design-system:is(.activa, body) + body { color: red; }\n\n@media (max-width: 40rem) {", 'CSS_SIBLING_COMBINATOR', 'escape mediante is'],
    ["\n@media (max-width: 40rem) {", "\n.veciahorra-frontend.va-design-system:where(.activa, body) ~ .otra-clase { color: red; }\n\n@media (max-width: 40rem) {", 'CSS_SIBLING_COMBINATOR', 'escape mediante where'],
    ["\n@media (max-width: 40rem) {", "\n.veciahorra-frontend.va-design-system:not(.inactiva) + body { color: red; }\n\n@media (max-width: 40rem) {", 'CSS_SIBLING_COMBINATOR', 'escape mediante not'],
    ["\n@media (max-width: 40rem) {", "\n@supports (display: grid) { body { color: red; } }\n\n@media (max-width: 40rem) {", 'CSS_SCOPE', 'supports sin scope'],
    ["\n@media (max-width: 40rem) {", "\n@layer probe { body { color: red; } }\n\n@media (max-width: 40rem) {", 'CSS_SCOPE', 'layer sin scope'],
];
foreach ($mutations as [$search, $replace, $diagnostic, $label]) {
    designSystemExpectRejection('designSystemValidateCss', designSystemMutateOnce($css, $search, $replace), $diagnostic, 'CSS ' . $label);
}

$cssStringMutations = [
    ['--va-color-navy-900: #062c57;', "--va-probe: \"} , body {\";\n    --va-color-navy-900: #062c57;", 'string CSS doble con estructura aparente'],
    ['--va-color-navy-900: #062c57;', "--va-probe: '} , .otra-clase {';\n    --va-color-navy-900: #062c57;", 'string CSS simple con estructura aparente'],
];
foreach ($cssStringMutations as [$search, $replace, $label]) {
    designSystemExpectAcceptance('designSystemValidateCssScope', designSystemMutateOnce($css, $search, $replace), $label);
}

$assetMutations = [
    ["        \$this->registerAssets();\n        wp_enqueue_style(self::DESIGN_SYSTEM_STYLE_HANDLE);", '        wp_enqueue_style(self::DESIGN_SYSTEM_STYLE_HANDLE);', 'PHP_HELPER_REGISTER', 'helper sin registro'],
    ['    private function enqueueDesignSystem(): void', '    public function enqueueDesignSystem(): void', 'PHP_HELPER_VISIBILITY', 'helper public'],
    ['    private function enqueueDesignSystem(): void', '    protected function enqueueDesignSystem(): void', 'PHP_HELPER_VISIBILITY', 'helper protected'],
    ["        \$this->enqueueDesignSystem();\n        \$this->enqueue();\n        wp_enqueue_script(self::OFFER_SCRIPT_HANDLE);", "        '\$this->enqueueDesignSystem();';\n        \$this->enqueue();\n        wp_enqueue_script(self::OFFER_SCRIPT_HANDLE);", 'PHP_CALLER_AUTHORITY', 'caller ficticio en string simple'],
    ["        \$this->enqueueDesignSystem();\n        \$this->enqueue();\n        wp_enqueue_script(self::OFFER_SCRIPT_HANDLE);", "        \"\$this->enqueueDesignSystem();\";\n        \$this->enqueue();\n        wp_enqueue_script(self::OFFER_SCRIPT_HANDLE);", 'PHP_CALLER_AUTHORITY', 'caller ficticio en string doble'],
    ["        \$this->enqueueDesignSystem();\n        \$this->enqueue();\n        wp_enqueue_script(self::OFFER_SCRIPT_HANDLE);", "        <<<CALLER\n\$this->enqueueDesignSystem();\nCALLER;\n        \$this->enqueue();\n        wp_enqueue_script(self::OFFER_SCRIPT_HANDLE);", 'PHP_CALLER_AUTHORITY', 'caller ficticio en heredoc'],
    ["        \$this->enqueueDesignSystem();\n        \$this->enqueue();\n        wp_enqueue_script(self::OFFER_SCRIPT_HANDLE);", "        <<<'CALLER'\n\$this->enqueueDesignSystem();\nCALLER;\n        \$this->enqueue();\n        wp_enqueue_script(self::OFFER_SCRIPT_HANDLE);", 'PHP_CALLER_AUTHORITY', 'caller ficticio en nowdoc'],
    ["        \$this->enqueueDesignSystem();\n        \$this->enqueue();\n        wp_enqueue_script(self::CATALOG_SCRIPT_HANDLE);", "        // \$this->enqueueDesignSystem();\n        \$this->enqueue();\n        wp_enqueue_script(self::CATALOG_SCRIPT_HANDLE);", 'PHP_CALLER_AUTHORITY', 'caller ficticio en comentario'],
    ['        return $this->routes ??= new PublicRouteResolver();', "        wp_enqueue_style(self::DESIGN_SYSTEM_STYLE_HANDLE);\n        return \$this->routes ??= new PublicRouteResolver();", 'PHP_DIRECT_ENQUEUE', 'enqueue productivo adicional'],
];
foreach ($assetMutations as [$search, $replace, $diagnostic, $label]) {
    designSystemExpectRejection('designSystemValidateAssetsSource', designSystemMutateOnce($assetsSource, $search, $replace), $diagnostic, $label);
}

$assetPositiveMutations = [
    ["        \$this->enqueueDesignSystem();\n        \$this->enqueue();\n        wp_enqueue_script(self::OFFER_SCRIPT_HANDLE);", "        \$this\n            -> enqueueDesignSystem\n            ();\n        \$this->enqueue();\n        wp_enqueue_script(self::OFFER_SCRIPT_HANDLE);", 'caller multilinea'],
    ["        \$this->enqueueDesignSystem();\n        \$this->enqueue();\n        wp_enqueue_script(self::CATALOG_SCRIPT_HANDLE);", "        \$this /* intercalado */\n            ->enqueueDesignSystem   (   );\n        \$this->enqueue();\n        wp_enqueue_script(self::CATALOG_SCRIPT_HANDLE);", 'caller con comentario y whitespace'],
];
foreach ($assetPositiveMutations as [$search, $replace, $label]) {
    designSystemExpectAcceptance('designSystemValidateAssetsSource', designSystemMutateOnce($assetsSource, $search, $replace), $label);
}
designSystemExpectRejection(
    'designSystemValidateHandleReferences',
    ['app/Modules/Frontend/Assets/FrontendAssets.php', 'veciahorra.php'],
    'Inventario literal del handle invalido',
    'referencia productiva adicional'
);

$untracked = array_values(array_filter(preg_split('/\R/', trim((string) shell_exec('git ls-files --others --exclude-standard'))) ?: []));
designSystemValidateEnvironmentalInventory($root, $untracked);
$simulatedUntracked = $untracked;
$simulatedUntracked[] = 'artifacts/adversarial-extra.txt';
designSystemExpectRejection(
    static function (array $candidate) use ($root): void {
        designSystemValidateEnvironmentalInventory($root, $candidate);
    },
    $simulatedUntracked,
    'ENV_COUNT',
    'archivo adicional dentro de artifacts'
);
$phaseDiff = array_values(array_filter(preg_split('/\R/', trim((string) shell_exec('git diff --name-only e875e87e46d8880306e13f6b24e24e70dd672385'))) ?: []));
sort($phaseDiff, SORT_STRING);
assertDesignSystem($phaseDiff === ['tests/manual/frontend-design-system-test.php'], 'Alcance Git inesperado: ' . implode(', ', $phaseDiff));

$adversarialCount = count($GLOBALS['designSystemAdversarialResults']);
foreach ($GLOBALS['designSystemAdversarialResults'] as [$label, $expected, $obtained]) {
    printf("ADVERSARIAL label=%s expected=%s obtained=%s\n", $label, $expected, $obtained);
}
printf("PASS frontend-design-system-test primary=%.2f hover=%.2f navy=%.2f focus=%.2f adversarials=%d browser_evidence=external\n", $ratios['primary'], $ratios['primary-hover'], $ratios['navy-700'], $ratios['focus'], $adversarialCount);
