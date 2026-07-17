<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertCustomerPanelList(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function customerPanelBrowser(): string
{
    $programFiles = (string) getenv('ProgramFiles');
    $programFilesX86 = (string) getenv('ProgramFiles(x86)');
    $localAppData = (string) getenv('LOCALAPPDATA');
    $candidates = [
        $programFiles . '/Google/Chrome/Application/chrome.exe',
        $programFilesX86 . '/Google/Chrome/Application/chrome.exe',
        $localAppData . '/Google/Chrome/Application/chrome.exe',
        $programFilesX86 . '/Microsoft/Edge/Application/msedge.exe',
        $programFiles . '/Microsoft/Edge/Application/msedge.exe',
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException('La prueba dinámica requiere Chrome o Edge instalado.');
}

function customerPanelFileUrl(string $path): string
{
    $path = str_replace('\\', '/', realpath($path) ?: $path);
    $segments = array_map('rawurlencode', explode('/', $path));

    return 'file:///' . ltrim(implode('/', $segments), '/');
}

function removeCustomerPanelBrowserProfile(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($files as $file) {
        if ($file->isDir()) {
            @rmdir($file->getPathname());
        } else {
            @unlink($file->getPathname());
        }
    }

    @rmdir($directory);
}

$browser = customerPanelBrowser();
$harness = __DIR__ . '/customer-panel-purchases-list-browser-test.html';
$profile = sys_get_temp_dir() . '/veciahorra-customer-panel-browser-' . getmypid();
$command = [
    $browser,
    '--headless=new',
    '--disable-gpu',
    '--no-first-run',
    '--no-default-browser-check',
    '--allow-file-access-from-files',
    '--user-data-dir=' . $profile,
    '--virtual-time-budget=16000',
    '--dump-dom',
    customerPanelFileUrl($harness),
];
$pipes = [];
$process = proc_open($command, [
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $pipes, __DIR__);

assertCustomerPanelList(is_resource($process), 'No se pudo iniciar el navegador headless.');
$output = stream_get_contents($pipes[1]);
$errors = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);
removeCustomerPanelBrowserProfile($profile);

assertCustomerPanelList(
    $exitCode === 0,
    "El navegador headless falló con código {$exitCode}: {$errors}"
);
assertCustomerPanelList(
    preg_match('/<pre id="test-results" data-status="([^"]+)">([^<]*)<\/pre>/', $output, $matches) === 1,
    'El harness dinámico no publicó un resultado.'
);

$status = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
$payload = json_decode(
    html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
    true
);
assertCustomerPanelList(is_array($payload), 'El resultado dinámico no es JSON válido.');
assertCustomerPanelList(
    $status === 'pass',
    'Falló escenario dinámico: ' . (string) ($payload['message'] ?? 'sin detalle')
        . '; ejecutados=' . wp_json_encode($payload['scenarios'] ?? [])
);

foreach ([
    'single_request', 'duplicate_guard', 'loading', 'empty', 'results',
    'http_error', 'network_error', 'invalid_json', 'invalid_contract', 'timeout',
    'router', 'canonicalization', 'popstate',
] as $scenario) {
    assertCustomerPanelList(
        ($payload['scenarios'][$scenario] ?? false) === true,
        "No pasó el escenario dinámico {$scenario}."
    );
}

echo "PASS customer-panel-purchases-list-test\n";
