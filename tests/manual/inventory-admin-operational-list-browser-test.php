<?php

declare(strict_types=1);

function inventoryListBrowser(): string
{
    foreach ([
        (string) getenv('ProgramFiles')
            . '/Google/Chrome/Application/chrome.exe',
        (string) getenv('ProgramFiles(x86)')
            . '/Microsoft/Edge/Application/msedge.exe',
    ] as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    throw new RuntimeException('Se requiere Edge o Chrome.');
}

function inventoryListRemoveProfile(string $directory): void
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

$port = 19000 + (getmypid() % 500);
$root = dirname(__DIR__, 2);
$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $root],
    [1 => ['file', 'NUL', 'a'], 2 => ['file', 'NUL', 'a']],
    $serverPipes,
    $root
);
if (! is_resource($server)) {
    throw new RuntimeException('No se inicio servidor del harness.');
}
usleep(250000);
$results = [];
try {
    $widths = isset($argv[1])
        ? [(int) $argv[1]]
        : [1440, 1024, 768, 375];
    foreach ($widths as $width) {
        $windowWidth = $width >= 768 ? $width + 18 : $width;
        $profile = sys_get_temp_dir()
            . "/veciahorra-inventory-list-{$width}-"
            . getmypid();
        $errorFile = sys_get_temp_dir()
            . "/veciahorra-inventory-list-{$width}-"
            . getmypid()
            . '.log';
        $process = proc_open([
            inventoryListBrowser(),
            '--headless=new',
            '--disable-gpu',
            '--disable-gpu-sandbox',
            '--disable-features=CanvasOopRasterization,UseD3D12',
            '--disable-background-mode',
            '--disable-background-networking',
            '--disable-component-update',
            '--disable-extensions',
            '--no-sandbox',
            '--no-first-run',
            '--no-default-browser-check',
            '--user-data-dir=' . $profile,
            "--window-size={$windowWidth},1000",
            '--virtual-time-budget=15000',
            '--dump-dom',
            "http://127.0.0.1:{$port}/tests/manual/"
                . "inventory-admin-operational-list-test.html?test_width={$width}",
        ], [1 => ['pipe', 'w'], 2 => ['file', $errorFile, 'w']], $pipes, __DIR__);
        if (! is_resource($process)) {
            throw new RuntimeException('No se inicio navegador.');
        }
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($process);
        $errors = is_file($errorFile)
            ? (string) file_get_contents($errorFile)
            : '';
        @unlink($errorFile);
        inventoryListRemoveProfile($profile);
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
            throw new RuntimeException("Harness {$width}px sin resultado.");
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

echo 'PASS inventory-admin-operational-list-browser-test '
    . json_encode($results, JSON_UNESCAPED_SLASHES) . "\n";
