<?php

declare(strict_types=1);

$browser = null;
foreach ([
    getenv('ProgramFiles(x86)') . '/Microsoft/Edge/Application/msedge.exe',
    getenv('ProgramFiles') . '/Google/Chrome/Application/chrome.exe',
] as $candidate) {
    if (is_file($candidate)) {
        $browser = $candidate;
        break;
    }
}
if ($browser === null) {
    throw new RuntimeException('Se requiere Chrome o Edge.');
}

$root = dirname(__DIR__, 2);
$port = 19900 + getmypid() % 500;
$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $root],
    [1 => ['file', 'NUL', 'a'], 2 => ['file', 'NUL', 'a']],
    $pipes,
    $root
);
usleep(250000);
try {
    $profile = sys_get_temp_dir() . '/orders-detail-transport-' . getmypid();
    $url = "http://127.0.0.1:{$port}/tests/manual/order-admin-detail-transport-test.html";
    $process = proc_open(
        [
            $browser,
            '--headless=new',
            '--disable-gpu',
            '--disable-gpu-compositing',
            '--disable-gpu-sandbox',
            '--disable-dev-shm-usage',
            '--disable-features=Vulkan',
            '--enable-unsafe-swiftshader',
            '--no-sandbox',
            '--no-first-run',
            '--no-default-browser-check',
            '--user-data-dir=' . $profile,
            '--virtual-time-budget=30000',
            '--dump-dom',
            $url,
        ],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $io,
        $root
    );
    $html = stream_get_contents($io[1]);
    $error = stream_get_contents($io[2]);
    fclose($io[1]);
    fclose($io[2]);
    $exit = proc_close($process);
    if ($exit !== 0 || ! str_contains($html, 'data-status="pass"')) {
        preg_match('/<pre id="results"[^>]*>(.*?)<\/pre>/s', $html, $result);
        throw new RuntimeException('Transport browser fallo: ' . ($result[1] ?? $error));
    }
    preg_match('/<pre id="results"[^>]*>(.*?)<\/pre>/s', $html, $result);
    echo ($result[1] ?? 'PASS order-admin-detail-transport-browser-test') . PHP_EOL;
} finally {
    proc_terminate($server);
    proc_close($server);
}
