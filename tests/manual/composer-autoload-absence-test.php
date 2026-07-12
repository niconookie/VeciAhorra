<?php

declare(strict_types=1);

function assertComposerAutoloadAbsence(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function plugin_dir_path(string $file): string
{
    return dirname($file) . DIRECTORY_SEPARATOR;
}

function plugin_dir_url(string $file): string
{
    return 'https://example.test/plugins/veciahorra/';
}

function plugin_basename(string $file): string
{
    return basename($file);
}

function add_action(string $hook, callable $callback): void
{
    if ($hook !== 'admin_notices') {
        return;
    }

    ob_start();
    $callback();
    $GLOBALS['va_test_notice'] = (string) ob_get_clean();
}

define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
$source = dirname(__DIR__, 2) . '/veciahorra.php';
$contents = (string) file_get_contents($source);
assertComposerAutoloadAbsence(
    substr_count($contents, "require_once \$autoload") === 1,
    'vendor/autoload.php no se carga exactamente una vez.'
);
assertComposerAutoloadAbsence(
    substr_count($contents, "vendor/autoload.php") === 1,
    'El entrypoint contiene cargas duplicadas del autoloader.'
);
$directory = sys_get_temp_dir()
    . DIRECTORY_SEPARATOR
    . 'veciahorra-autoload-'
    . bin2hex(random_bytes(6));

if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
    throw new RuntimeException('No se creo el directorio temporal.');
}

$entrypoint = $directory . DIRECTORY_SEPARATOR . 'veciahorra.php';
copy($source, $entrypoint);

try {
    include $entrypoint;
    $notice = $GLOBALS['va_test_notice'] ?? '';
    assertComposerAutoloadAbsence(
        str_contains($notice, 'composer install'),
        'La ausencia del autoloader no produjo un error comprensible.'
    );
} finally {
    unlink($entrypoint);
    rmdir($directory);
}

echo "PASS composer-autoload-absence-test\n";
