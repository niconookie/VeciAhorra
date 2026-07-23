<?php

declare(strict_types=1);

$browser=(string)getenv('ProgramFiles(x86)').'/Microsoft/Edge/Application/msedge.exe';
if (!is_file($browser)) throw new RuntimeException('Se requiere Edge.');
$width=isset($argv[1])?(int)$argv[1]:1440;
$port=19000+(getmypid()%500);
$root=dirname(__DIR__,2);
$server=proc_open([PHP_BINARY,'-S',"127.0.0.1:{$port}",'-t',$root],[1=>['file','NUL','a'],2=>['file','NUL','a']],$serverPipes,$root);
if (!is_resource($server)) throw new RuntimeException('Sin servidor.');
usleep(250000);
$profile=sys_get_temp_dir()."/va-product-detail-{$width}-".getmypid();
try {
    $process=proc_open([
        $browser,'--headless=new','--disable-gpu','--disable-dev-shm-usage',
        '--no-first-run','--no-default-browser-check',
        "--user-data-dir={$profile}","--window-size={$width},900",
        '--virtual-time-budget=20000','--dump-dom',
        "http://127.0.0.1:{$port}/tests/manual/product-admin-operational-detail-browser-test.html",
    ],[1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__);
    if (!is_resource($process)) throw new RuntimeException('Sin browser.');
    $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
    if ($exit!==0) throw new RuntimeException("Edge fallo: {$errors}");
    if (preg_match('/<pre id="test-results" data-status="([^"]+)">([^<]*)<\/pre>/',$output,$m)!==1) throw new RuntimeException('Sin resultado.');
    $status=html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
    $payload=html_entity_decode($m[2],ENT_QUOTES|ENT_HTML5,'UTF-8');
    if ($status!=='pass') throw new RuntimeException("Harness {$width}px: {$payload}; browser={$errors}");
    echo "PASS product-admin-operational-detail-browser-test {$width}px {$payload}\n";
} finally {
    proc_terminate($server);proc_close($server);
    if (is_dir($profile)) {
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($profile,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($it as $file) $file->isDir()?@rmdir($file->getPathname()):@unlink($file->getPathname());
        @rmdir($profile);
    }
}
