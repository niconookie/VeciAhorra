<?php

declare(strict_types=1);

$browser = null;
foreach ([
    (string) getenv('ProgramFiles(x86)') . '/Microsoft/Edge/Application/msedge.exe',
    (string) getenv('ProgramFiles') . '/Google/Chrome/Application/chrome.exe',
] as $candidate) {
    if (is_file($candidate)) {
        $browser = $candidate;
        break;
    }
}
if ($browser === null) {
    throw new RuntimeException('La prueba requiere Edge o Chrome.');
}

$root = dirname(__DIR__, 2);
$port = 19500 + (getmypid() % 400);
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $root], [
    1 => ['file', 'NUL', 'a'], 2 => ['file', 'NUL', 'a'],
], $serverPipes, $root);
if (! is_resource($server)) {
    throw new RuntimeException('No se inició el servidor del harness.');
}
$profile = sys_get_temp_dir() . '/veciahorra-catalog-detail-' . getmypid();
usleep(250000);
try {
    $process = proc_open([
        $browser, '--headless=new', '--disable-gpu', '--disable-dev-shm-usage',
        '--no-first-run', '--no-default-browser-check',
        '--user-data-dir=' . $profile, '--virtual-time-budget=10000', '--dump-dom',
        "http://127.0.0.1:{$port}/tests/manual/public-catalog-detail-link-test.html",
    ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__);
    if (! is_resource($process)) {
        throw new RuntimeException('No se inició el navegador.');
    }
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0 || ! str_contains($output, '<pre id="result">PASS public-catalog-detail-link-test</pre>')) {
        preg_match('/<pre id="result">([^<]*)<\/pre>/', $output, $match);
        throw new RuntimeException('Harness falló: ' . ($match[1] ?? $errors));
    }
} finally {
    proc_terminate($server);
    proc_close($server);
    if (is_dir($profile)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($profile, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($profile);
    }
}
echo "PASS public-catalog-detail-link-browser-test\n";
