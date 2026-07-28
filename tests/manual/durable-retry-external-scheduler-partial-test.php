<?php

declare(strict_types=1);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
$combinations = [
    'none' => [false, false, false, ['unavailable', 'unavailable', 'unavailable']],
    'schedule' => [true, false, false, ['unavailable', 'unavailable', 'unavailable']],
    'get' => [false, true, false, ['unavailable', 'not_found', 'unavailable']],
    'unschedule' => [false, false, true, ['unavailable', 'unavailable', 'unavailable']],
    'schedule_get' => [true, true, false, ['scheduled', 'not_found', 'unavailable']],
    'get_unschedule' => [false, true, true, ['unavailable', 'not_found', 'already_absent']],
    'schedule_unschedule' => [true, false, true, ['unavailable', 'unavailable', 'unavailable']],
    'all' => [true, true, true, ['scheduled', 'not_found', 'already_absent']],
];

foreach ($combinations as $name => [$schedule, $get, $unschedule, $expected]) {
    $definitions = '';
    if ($schedule) {
        $definitions .= 'function as_schedule_single_action($t,$h,$a=[],$g="",'
            . '$u=false){return 41;}';
    }
    if ($get) {
        $definitions .= 'function as_get_scheduled_actions($q=[],$f="OBJECT"){return [];}';
    }
    if ($unschedule) {
        $definitions .= 'function as_unschedule_action($h,$a=[],$g=""){return null;}';
    }
    $code = 'declare(strict_types=1);'
        . $definitions
        . 'require ' . var_export($autoload, true) . ';'
        . '$a=new VeciAhorra\\Modules\\Orders\\Infrastructure\\DurableRetry\\'
        . 'ActionSchedulerDurableRetryAdapter();'
        . '$c=VeciAhorra\\Modules\\Orders\\Domain\\DurableRetry\\'
        . 'DurableRetryExternalScheduleCatalog::class;'
        . '$h=$c::RECONCILIATION;$g=$c::GROUP;'
        . '$x=["schedule_id"=>1,"generation"=>1];'
        . 'echo json_encode(['
        . '$a->schedule($h,$x,$g,"2035-01-01 00:00:00")->code(),'
        . '$a->findPending($h,$x,$g)->code(),'
        . '$a->cancel(1,$h,$x,$g)->code()]);';
    $process = proc_open(
        [PHP_BINARY, '-r', $code],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $actual = json_decode($output, true);
    $assert($exitCode === 0 && $error === '', "{$name} does not fatal");
    $assert($actual === $expected, "{$name} availability matrix");
}

echo "durable retry external scheduler partial: {$assertions} assertions\n";
