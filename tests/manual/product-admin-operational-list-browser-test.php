<?php

declare(strict_types=1);

function productListBrowser(): string
{
    foreach ([
        (string) getenv('ProgramFiles(x86)') . '/Microsoft/Edge/Application/msedge.exe',
        (string) getenv('ProgramFiles') . '/Google/Chrome/Application/chrome.exe',
    ] as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException('La prueba requiere Edge o Chrome.');
}

function productListFileUrl(string $path): string
{
    $path = str_replace('\\', '/', realpath($path) ?: $path);

    return 'file:///' . ltrim(
        implode('/', array_map('rawurlencode', explode('/', $path))),
        '/'
    );
}

function productListRemoveProfile(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $directory,
            FilesystemIterator::SKIP_DOTS
        ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        $file->isDir()
            ? @rmdir($file->getPathname())
            : @unlink($file->getPathname());
    }
    @rmdir($directory);
}

$port = 18000 + (getmypid() % 1000);
$root = dirname(__DIR__, 2);
$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $root],
    [1 => ['file', 'NUL', 'a'], 2 => ['file', 'NUL', 'a']],
    $serverPipes,
    $root
);
if (! is_resource($server)) {
    throw new RuntimeException('No se inicio el servidor local del harness.');
}
usleep(250000);
$results = [];
try {
    $widths = isset($argv[1]) ? [(int) $argv[1]] : [1440];
    foreach ($widths as $width) {
        $profile = sys_get_temp_dir()
            . "/veciahorra-product-list-{$width}-"
            . getmypid();
        $process = proc_open([
            productListBrowser(),
            '--headless=new',
            '--disable-gpu',
            '--disable-dev-shm-usage',
            '--no-first-run',
            '--no-default-browser-check',
            '--user-data-dir=' . $profile,
            "--window-size={$width},900",
            '--virtual-time-budget=30000',
            '--dump-dom',
            "http://127.0.0.1:{$port}/tests/manual/product-admin-operational-list-browser-test.html",
        ], [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, __DIR__);
        if (! is_resource($process)) {
            throw new RuntimeException('No se inicio navegador headless.');
        }
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        productListRemoveProfile($profile);
        if ($exit !== 0) {
            throw new RuntimeException(
                "Navegador {$width}px fallo ({$exit}): {$errors}"
            );
        }
        if (preg_match(
            '/<pre id="test-results" data-status="([^"]+)">([^<]*)<\/pre>/',
            $output,
            $matches
        ) !== 1) {
            throw new RuntimeException(
                "Harness {$width}px no publico resultado."
            );
        }
        $status = html_entity_decode(
            $matches[1],
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
        $payload = html_entity_decode(
            $matches[2],
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
        if ($status !== 'pass') {
            throw new RuntimeException(
                "Harness {$width}px fallo: {$payload}"
            );
        }
        $results[$width] = json_decode($payload, true);
    }
} finally {
    proc_terminate($server);
    proc_close($server);
}

echo 'PASS product-admin-operational-list-browser-test '
    . json_encode($results, JSON_UNESCAPED_SLASHES) . "\n";
