<?php

declare(strict_types=1);

$browser = null;
foreach ([
    getenv('ProgramFiles') . '/Google/Chrome/Application/chrome.exe',
    getenv('ProgramFiles(x86)') . '/Microsoft/Edge/Application/msedge.exe',
] as $candidate) if (is_file($candidate)) { $browser = $candidate; break; }
if ($browser === null) throw new RuntimeException('Se requiere Chrome o Edge.');
$root = dirname(__DIR__, 2);
$port = 19500 + getmypid() % 400;
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $root], [1=>['file','NUL','a'],2=>['file','NUL','a']], $pipes, $root);
usleep(250000);
try {
    foreach ([1440, 1024, 768, 375] as $width) {
        $profile = sys_get_temp_dir() . "/orders-admin-{$width}-" . getmypid();
        $url = "http://127.0.0.1:{$port}/tests/manual/order-admin-list-browser-test.html";
        $proc = proc_open([$browser,'--headless=new','--disable-gpu','--disable-dev-shm-usage','--no-first-run','--no-default-browser-check','--user-data-dir='.$profile,"--window-size={$width},900",'--virtual-time-budget=30000','--dump-dom',$url],[1=>['pipe','w'],2=>['pipe','w']],$io,$root);
        $html=stream_get_contents($io[1]);$error=stream_get_contents($io[2]);fclose($io[1]);fclose($io[2]);$exit=proc_close($proc);
        if ($exit!==0 || !str_contains($html,'data-status="pass"')) {
            preg_match('/<pre id="test-results"[^>]*>(.*?)<\/pre>/s', $html, $result);
            throw new RuntimeException("Browser {$width} fallo: " . ($result[1] ?? $error));
        }
    }
} finally { proc_terminate($server); proc_close($server); }
echo "PASS order-admin-list-browser-test widths=1440,1024,768,375 requests=1 overflow=0\n";
