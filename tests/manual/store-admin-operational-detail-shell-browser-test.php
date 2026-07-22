<?php

declare(strict_types=1);

function detailShellBrowser(): string
{
    foreach ([(string) getenv('ProgramFiles') . '/Google/Chrome/Application/chrome.exe', (string) getenv('ProgramFiles(x86)') . '/Microsoft/Edge/Application/msedge.exe'] as $candidate) {
        if (is_file($candidate)) return $candidate;
    }
    throw new RuntimeException('La prueba requiere Chrome o Edge.');
}

function detailShellFileUrl(string $path): string
{
    $path = str_replace('\\', '/', realpath($path) ?: $path);
    return 'file:///' . ltrim(implode('/', array_map('rawurlencode', explode('/', $path))), '/');
}

function detailShellRemoveProfile(string $directory): void
{
    if (! is_dir($directory)) return;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    @rmdir($directory);
}

$profile = sys_get_temp_dir() . '/veciahorra-store-detail-shell-' . getmypid();
$process = proc_open([
    detailShellBrowser(), '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    '--allow-file-access-from-files', '--user-data-dir=' . $profile, '--virtual-time-budget=5000', '--dump-dom',
    detailShellFileUrl(__DIR__ . '/store-admin-operational-detail-shell-test.html'),
], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__);
if (! is_resource($process)) throw new RuntimeException('No se inició el browser.');
$output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process); detailShellRemoveProfile($profile);
if ($exit !== 0) throw new RuntimeException("Chrome falló ({$exit}): {$errors}");
if (preg_match('/<pre id="test-results" data-status="([^"]+)">([^<]*)<\/pre>/', $output, $matches) !== 1) throw new RuntimeException('El harness no publicó resultado.');
$status = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
$payload = html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
if ($status !== 'pass') throw new RuntimeException('Harness browser falló: ' . $payload);
echo "PASS store-admin-operational-detail-shell-browser-test {$payload}\n";
