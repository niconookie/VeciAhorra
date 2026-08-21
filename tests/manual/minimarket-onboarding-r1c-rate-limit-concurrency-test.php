<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';

use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\MariaDbNamedRateLimitLockManager;
use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\RateLimitBucket;
use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\WordPressTransientRateLimitBucketStore;

global $wpdb;

if (($argv[1] ?? '') === '--worker') {
    $name = (string) ($argv[2] ?? ''); $limit = (int) ($argv[3] ?? 0);
    $store = new WordPressTransientRateLimitBucketStore($wpdb, new MariaDbNamedRateLimitLockManager($wpdb));
    $decision = $store->consumeAtomically([new RateLimitBucket($name, $limit, 600)]);
    echo $decision->allowed ? '1' : '0';
    exit;
}

function r1c_concurrent_case(int $limit): void {
    global $wpdb;
    $name = 'va_r1c_rl_' . substr(hash('sha256', 'test|' . bin2hex(random_bytes(16))), 0, 48);
    $processes = [];
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --worker ' . escapeshellarg($name) . ' ' . $limit;
    for ($i=0; $i<$limit+1; $i++) {
        $pipes=[]; $process=proc_open($command, [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
        if (! is_resource($process)) throw new RuntimeException('No se pudo crear worker.');
        fclose($pipes[0]); $processes[]=[$process,$pipes];
    }
    $allowed=0; $denied=0;
    foreach ($processes as [$process,$pipes]) {
        $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $code=proc_close($process); if($code!==0) throw new RuntimeException('Worker falló: '.$err);
        $allowed += trim($out)==='1' ? 1 : 0; $denied += trim($out)==='0' ? 1 : 0;
    }
    $wpdb->delete($wpdb->options,['option_name'=>'_transient_'.$name]);
    $wpdb->delete($wpdb->options,['option_name'=>'_transient_timeout_'.$name]);
    if($allowed!==$limit || $denied!==1) throw new RuntimeException("Límite {$limit} no atómico: {$allowed}/{$denied}");
}

foreach ([5,20,3,10] as $limit) r1c_concurrent_case($limit);
echo "R1C_RATE_LIMIT_CONCURRENCY=PASS boundaries=4-5-6,19-20-21,2-3-4,9-10-11 lost_updates=0 cleanup=PASS\n";
