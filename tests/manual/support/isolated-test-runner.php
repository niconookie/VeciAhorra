<?php

declare(strict_types=1);

$target = $argv[1] ?? '';
$wordpressRoot = getenv('VECIAHORRA_TEST_WORDPRESS_ROOT') ?: '';
$pluginRoot = getenv('VECIAHORRA_TEST_PLUGIN_ROOT') ?: '';
if ($target === '' || ! is_file($target) || $wordpressRoot === '' || ! is_file($wordpressRoot . '/wp-load.php') || $pluginRoot === '' || ! is_dir($pluginRoot)) {
    fwrite(STDERR, "Usage: VECIAHORRA_TEST_WORDPRESS_ROOT=/path/to/wordpress php isolated-test-runner.php test.php\n");
    exit(2);
}

if (! defined('WP_PLUGIN_DIR')) {
    define('WP_PLUGIN_DIR', str_replace('\\', '/', $pluginRoot));
}
$commerce = getenv('VECIAHORRA_TEST_COMMERCE_ENABLED');
if ($commerce !== false && ! defined('VECIAHORRA_PUBLIC_COMMERCE_ENABLED')) {
    define('VECIAHORRA_PUBLIC_COMMERCE_ENABLED', $commerce === '1');
}

$source = file_get_contents($target);
if (! is_string($source)) {
    fwrite(STDERR, "Unable to read test.\n");
    exit(2);
}

$testDirectory = str_replace('\\', '/', dirname(realpath($target) ?: $target));
$testFile = str_replace('\\', '/', realpath($target) ?: $target);
$wpLoad = str_replace('\\', '/', realpath($wordpressRoot . '/wp-load.php') ?: $wordpressRoot . '/wp-load.php');
$source = preg_replace(
    "~require_once dirname\\(__DIR__,\\s*\\d+\\) \\. '/wp-load\\.php';~",
    "require_once '" . addslashes($wpLoad) . "';",
    $source
);
$source = str_replace('__DIR__', "'" . addslashes($testDirectory) . "'", $source);
$source = str_replace('__FILE__', "'" . addslashes($testFile) . "'", $source);
$source = preg_replace('/^<\\?php\\s*/', '', $source, 1);

if (! is_string($source)) {
    fwrite(STDERR, "Unable to prepare test.\n");
    exit(2);
}

$argv = array_merge([$target], array_slice($argv, 2));
eval($source);
