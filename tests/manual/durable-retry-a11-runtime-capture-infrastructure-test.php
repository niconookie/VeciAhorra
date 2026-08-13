<?php

declare(strict_types=1);

use VeciAhorra\Tests\Manual\A11\DurableRetryA11CanonicalJson;
use VeciAhorra\Tests\Manual\A11\DurableRetryA11ActionCapture;
use VeciAhorra\Tests\Manual\A11\DurableRetryA11CapturePlan;
use VeciAhorra\Tests\Manual\A11\DurableRetryA11Coordinator;
use VeciAhorra\Tests\Manual\A11\DurableRetryA11Invocation;

require_once __DIR__ . '/support/durable-retry-a11-coordinator.php';

if (($argv[1] ?? '') === '--a11-capture-child') {
    $line = stream_get_contents(STDIN);
    try {
        $request = DurableRetryA11CanonicalJson::decodeEnvelope($line);
        $phase = (string)($request['phase'] ?? '');
        $entries = (array)($request['capture_plan']['capture_plan'] ?? []);
        $sources = array_column($entries, 'source');
        if (in_array('test.sleep', $sources, true) || str_contains((string)($request['execution_id'] ?? ''), '_14_')) { usleep(5000000); }
        if (in_array('test.invalid_json', $sources, true)) { echo "not-json\n"; exit(0); }
        if (in_array('test.stdout_log', $sources, true)) { echo "log\n"; }
        if (in_array('test.stderr', $sources, true)) { fwrite(STDERR, 'diagnostic'); }
        if (in_array('test.exit65', $sources, true)) { exit(65); }
        if ($phase === 'cleanup') {
            $output = ['schema'=>'veciahorra-a11-capture/v1','kind'=>'cleanup_result','case_id'=>$request['case_id'],'execution_id'=>$request['execution_id'],'phase'=>'cleanup','base_snapshot_hash'=>$request['input_snapshot']['snapshot_hash'],'status'=>'clean'];
        } else {
            $captures = [];
            foreach ($entries as $alias => $entry) {
                if (($entry['source_phase'] ?? null) !== $phase || ($entry['cardinality'] ?? null) === 'exactly-zero') continue;
                $type = $entry['type'];
                $value = match ($type) {
                    'positive-int' => (hexdec(substr(hash('sha256', $request['execution_id'].$alias), 0, 7)) % 1000000) + 1,
                    'non-empty-string' => $request['case_id'].'-'.$phase.'-'.$alias,
                    'utc-second-timestamp' => '2026-08-03T00:00:00Z',
                    'sha256-lowercase-hex' => hash('sha256', $alias),
                    'boolean' => true,
                    default => null,
                };
                $captures[$alias] = ['type'=>$type,'value'=>$value,'source'=>$entry['source']];
            }
            ksort($captures, SORT_STRING);
            $output = ['schema'=>'veciahorra-a11-capture/v1','kind'=>'capture_delta','case_id'=>$request['case_id'],'execution_id'=>$request['execution_id'],'phase'=>$phase,'base_snapshot_hash'=>$request['input_snapshot']['snapshot_hash'],'captures'=>$captures];
            if (in_array('test.wrongcase', $sources, true)) $output['case_id'] = 'A11-OP-31';
            if (in_array('test.stale', $sources, true)) $output['base_snapshot_hash'] = str_repeat('0', 64);
        }
        $encoded = DurableRetryA11CanonicalJson::encode($output)."\n";
        echo $encoded;
        if (in_array('test.duplicate_lines', $sources, true)) echo $encoded;
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage());
        exit(64);
    }
}

$a11OnlyIntegration=(($argv[1]??'')==='--integration-only');
$a11Cases=0; $a11Assertions=0; $a11Failures=[];
function a11iAssert(bool $condition,string $message):void{global $a11Assertions;$a11Assertions++;if(!$condition)throw new RuntimeException($message);}
function a11iThrows(callable $fn,string $message):void{global $a11Assertions;$a11Assertions++;try{$fn();}catch(Throwable $e){if($e->getMessage()===$message||$e->getPrevious()?->getMessage()===$message)return;throw new RuntimeException("Expected $message, got ".$e->getMessage());}throw new RuntimeException("Expected $message");}
function a11iRun(string $name,callable $fn):void{global $a11Cases,$a11Failures,$a11OnlyIntegration;if($a11OnlyIntegration&&!str_starts_with($name,'25 '))return;$a11Cases++;try{$fn();}catch(Throwable $e){$a11Failures[]=$name.': '.$e->getMessage();}}
function a11iFixture(array $values=[]):array{$r=array_fill_keys(DurableRetryA11CapturePlan::FIXTURE_ID_KEYS,[]);foreach($values as $k=>$v)$r[$k]=$v;return $r;}
function a11iEntry(string $case,string $phase,string $source,string $before):array{return ['type'=>'positive-int','owner'=>$case,'source_phase'=>$phase,'source'=>$source,'cardinality'=>'exactly-one','required_before'=>$before,'immutable'=>true,'equality'=>'same-on-replay','cleanup'=>true];}
function a11iId(int $n):string{return 'a11_20260803010101_'.$n.'_fedcba9876543210';}
function a11iCoordinator():DurableRetryA11Coordinator{return new DurableRetryA11Coordinator(PHP_BINARY);}
function a11iBootstrap(DurableRetryA11Coordinator $c,int $n,string $source='repository.insert'):string{$id=a11iId($n);$alias='A11-OP-01.order.primary';$c->bootstrap($id,'A11-OP-01',[$alias=>a11iEntry('A11-OP-01','setup',$source,'first_delivery')],a11iFixture(['orders'=>[$alias]]));return $id;}
function a11iInvoke(DurableRetryA11Coordinator $c,string $id,int $timeout=5):mixed{return $c->runPhase(new DurableRetryA11Invocation($id,__FILE__,$timeout));}

$allowlist=['tests/manual/support/durable-retry-a11-coordinator.php','tests/manual/support/durable-retry-a11-runtime-capture-contract.php','tests/manual/durable-retry-a11-runtime-capture-test.php','tests/manual/durable-retry-a11-runtime-capture-infrastructure-test.php'];
a11iRun('01 allowlist exact',function()use($allowlist):void{foreach($allowlist as $p)a11iAssert(is_file(dirname(__DIR__,2).DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$p)),$p);a11iAssert(count($allowlist)===4,'four');});
a11iRun('02 no productive dependency',function():void{$text=file_get_contents(__DIR__.'/support/durable-retry-a11-runtime-capture-contract.php');a11iAssert(!str_contains($text,'wp-load')&&!str_contains($text,'wpdb'),'product');});
a11iRun('03 no temporary writes',function():void{$all=file_get_contents(__DIR__.'/support/durable-retry-a11-coordinator.php').file_get_contents(__DIR__.'/support/durable-retry-a11-runtime-capture-contract.php');a11iAssert(!preg_match('/file_put_contents|tempnam|sys_get_temp_dir|fopen\s*\(/',$all),'writes');});
a11iRun('04 runtime absent',fn()=>a11iAssert(!file_exists(dirname(__DIR__,2).'/.a11-runtime'),'runtime'));
a11iRun('05 artifacts intact',fn()=>a11iAssert(iterator_count(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__,2).'/artifacts',FilesystemIterator::SKIP_DOTS)))===504,'artifacts'));
a11iRun('06 stdin stdout cycle',function():void{$c=a11iCoordinator();$id=a11iBootstrap($c,6);$r=a11iInvoke($c,$id);a11iAssert($r->exitCode===0&&$r->stderr===''&&$c->store($id)->phase()==='first_delivery','pipes');});
a11iRun('07 no capture env transport',function():void{$t=file_get_contents(__DIR__.'/support/durable-retry-a11-coordinator.php');a11iAssert(!str_contains($t,'$_ENV')&&!str_contains($t,'putenv('),'env');});
a11iRun('08 no snapshot arguments',function():void{$t=file_get_contents(__DIR__.'/support/durable-retry-a11-coordinator.php');a11iAssert(str_contains($t,"'--a11-capture-child'")&&!str_contains($t,'snapshot_hash]'), 'args');});
a11iRun('09 no snapshot disk',function():void{$t=file_get_contents(__DIR__.'/support/durable-retry-a11-coordinator.php');a11iAssert(!str_contains($t,'manifest.json')&&!str_contains($t,'file_put_contents'),'disk');});
a11iRun('10 invalid json',function():void{$c=a11iCoordinator();$id=a11iBootstrap($c,10,'test.invalid_json');a11iThrows(fn()=>a11iInvoke($c,$id),'unexpected_child_output');});
a11iRun('11 contaminated stdout',function():void{$c=a11iCoordinator();$id=a11iBootstrap($c,11,'test.stdout_log');a11iThrows(fn()=>a11iInvoke($c,$id),'unexpected_child_output');});
a11iRun('12 stderr and exit',function():void{$c=a11iCoordinator();$id=a11iBootstrap($c,12,'test.stderr');a11iThrows(fn()=>a11iInvoke($c,$id),'unexpected_child_output');$d=a11iCoordinator();$id2=a11iBootstrap($d,13,'test.exit65');a11iThrows(fn()=>a11iInvoke($d,$id2),'invalid_delta');});
a11iRun('13 timeout',function():void{$c=a11iCoordinator();$id=a11iBootstrap($c,14,'test.sleep');a11iThrows(fn()=>a11iInvoke($c,$id,1),'timeout');a11iAssert($c->activeProcesses()===[],'terminated');});
a11iRun('14 no residual child',function():void{$c=a11iCoordinator();$id=a11iBootstrap($c,15);a11iInvoke($c,$id);a11iAssert($c->activeProcesses()===[],'residual');});
a11iRun('15 coordinator rehash',function():void{$c=a11iCoordinator();$id=a11iBootstrap($c,16,'test.stale');a11iThrows(fn()=>a11iInvoke($c,$id),'base_hash_mismatch');});
a11iRun('16 immutable child snapshot',function():void{$c=a11iCoordinator();$id=a11iBootstrap($c,17);$before=$c->store($id)->snapshot('S0');a11iInvoke($c,$id);a11iAssert($before===$c->store($id)->snapshot('S0'),'immutable');});
a11iRun('17 serialized object rejected',function():void{a11iThrows(fn()=>DurableRetryA11CanonicalJson::encode(['x'=>new stdClass()]),'wrong_type');});
a11iRun('18 other case rejected',function():void{$c=a11iCoordinator();$id=a11iBootstrap($c,18,'test.wrongcase');a11iThrows(fn()=>a11iInvoke($c,$id),'wrong_owner');});
a11iRun('19 duplicate output rejected',function():void{$c=a11iCoordinator();$id=a11iBootstrap($c,19,'test.duplicate_lines');a11iThrows(fn()=>a11iInvoke($c,$id),'unexpected_child_output');});
a11iRun('20 cleanup after partial failure',function():void{$c=a11iCoordinator();$id=a11iBootstrap($c,20,'test.invalid_json');a11iThrows(fn()=>a11iInvoke($c,$id),'unexpected_child_output');a11iAssert($c->hasExecution($id),'state for cleanup');});
a11iRun('21 zero retry',function():void{$c=a11iCoordinator();$id=a11iBootstrap($c,21,'test.invalid_json');a11iThrows(fn()=>a11iInvoke($c,$id),'unexpected_child_output');a11iAssert($c->transportAttempts()===1,'retry');});
a11iRun('22 sequential phase',function():void{$c=a11iCoordinator();$id=a11iBootstrap($c,22);a11iInvoke($c,$id);a11iAssert($c->store($id)->phase()==='first_delivery','sequential');});
a11iRun('23 global case grammar',function():void{foreach(['A11-OP-01','A11-CON-01','A11-CR-01','A11-WR-01','A11-EX-01']as$i=>$case){$c=a11iCoordinator();$id=a11iId(30+$i);$c->bootstrap($id,$case,[],a11iFixture());a11iAssert($c->store($id)->plan()->caseId()===$case,'case');}});
a11iRun('24 static v1 not modified',function():void{$t=file_get_contents(__DIR__.'/support/durable-retry-a11-coordinator.php');a11iAssert(!str_contains($t,'veciahorra-a11/v1'),'v1');});
a11iRun('25 full real coordinator integration',function():void{
    $c=a11iCoordinator();$id=a11iId(40);$setup='A11-OP-01.order.primary';$schedule='A11-OP-01.durable_schedule.initial';$action='A11-OP-01.external_action.initial';
    $entries=[$setup=>a11iEntry('A11-OP-01','setup','repository.insert','first_delivery'),$schedule=>a11iEntry('A11-OP-01','first_delivery','schedule.repository','replay'),$action=>a11iEntry('A11-OP-01','first_delivery','scheduler.double','replay')];
    $c->bootstrap($id,'A11-OP-01',$entries,a11iFixture(['orders'=>[$setup],'durable_retry_schedules'=>[$schedule],'action_scheduler_actions'=>[$action]]),['buy_order'=>['type'=>'non-empty-string','value'=>'A11-OP-01-BO']]);
    foreach(['setup','first_delivery','replay','assertions_finales']as$phase){a11iAssert($c->store($id)->phase()===$phase,'phase '.$phase);a11iInvoke($c,$id);}
    $ids=$c->store($id)->resolvedFixtureIds();a11iAssert(count($c->store($id)->history())===5&&count($ids['orders'])===1&&count($ids['action_scheduler_actions'])===1,'S0-S4');
    $cleanup=a11iInvoke($c,$id);a11iAssert($cleanup->cleanupStatus==='clean'&&!$c->hasExecution($id)&&$c->activeProcesses()===[],'cleanup');
});
a11iRun('26 coordinator owns action state',function():void{$c=a11iCoordinator();$id=a11iId(41);$c->bootstrap($id,'A11-OP-01',[],a11iFixture());a11iInvoke($c,$id);$c->recordAction($id,'A11-OP-01',$id,'first_delivery','webpay.commit',1);a11iAssert($c->observedActions($id)['first_delivery']['webpay.commit']===1,'authority');});
a11iRun('27 coordinator action delta ingestion',function():void{$c=a11iCoordinator();$id=a11iId(42);$c->bootstrap($id,'A11-OP-01',[],a11iFixture());a11iInvoke($c,$id);$s=$c->store($id);$d=['schema'=>'veciahorra-a11-capture/v1','kind'=>'action_delta','case_id'=>'A11-OP-01','ownership_token'=>$id,'phase'=>'first_delivery','port'=>'scheduler.action_schedule','delta'=>1,'base_action_hash'=>$s->actionHash()];$c->ingestActionDelta($id,$d);a11iAssert($c->observedActions($id)['first_delivery']['scheduler.action_schedule']===1,'delta');});
a11iRun('28 coordinator exact comparison',function():void{$c=a11iCoordinator();$id=a11iId(43);$c->bootstrap($id,'A11-OP-01',[],a11iFixture());$c->assertExpectedActions($id,DurableRetryA11ActionCapture::zeroMap());a11iAssert(true,'compare');});
a11iRun('29 action snapshot transport',function():void{$c=a11iCoordinator();$id=a11iId(44);$c->bootstrap($id,'A11-OP-01',[],a11iFixture());$s0=$c->store($id)->snapshot('S0');a11iAssert(DurableRetryA11ActionCapture::normalizeMap($s0['actions'])===DurableRetryA11ActionCapture::zeroMap(),'S0 actions');a11iInvoke($c,$id);$request=$c->store($id)->requestEnvelope(5);a11iAssert(isset($request['input_snapshot']['actions']),'stdin snapshot');});
a11iRun('30 no product instrumentation',function():void{$root=dirname(__DIR__,2);$product=file_get_contents($root.'/app/Modules/Payments/Service/WebpayReturnService.php').file_get_contents($root.'/app/Modules/Orders/Services/DurableRetryExecutor.php');a11iAssert(!str_contains($product,'recordAction(')&&!str_contains($product,'action_delta'),'product clean');});
a11iRun('31 closed literal catalogs',function():void{a11iAssert(DurableRetryA11ActionCapture::PHASES===['first_delivery','replay'],'phases');a11iAssert(count(DurableRetryA11ActionCapture::PORTS)===6&&count(DurableRetryA11ActionCapture::zeroMap(),COUNT_RECURSIVE)===14,'dense');});
a11iRun('32 no fixtures or H1-H5',function():void{$root=dirname(__DIR__,2);foreach(['durable-retry-a11-operational-acceptance-test.php','durable-retry-a11-multiprocess-concurrency-test.php','durable-retry-a11-crash-recovery-test.php','durable-retry-a11-webpay-replay-test.php','durable-retry-a11-legacy-exclusion-test.php']as$f)a11iAssert(!file_exists($root.'/tests/manual/'.$f),$f);});
a11iRun('33 stdin stdout remains only channel',function():void{$text=file_get_contents(__DIR__.'/support/durable-retry-a11-coordinator.php');a11iAssert(str_contains($text,'proc_open')&&str_contains($text,"0 => ['pipe', 'r']")&&!str_contains($text,'putenv(')&&!str_contains($text,'file_put_contents'),'channel');});
a11iRun('34 action errors registered',function():void{$codes=DurableRetryA11Coordinator::ERROR_CODES;foreach(['actions_phase_invalid','actions_port_invalid','actions_delta_invalid','actions_overflow','actions_sealed','actions_base_hash_mismatch','actions_count_mismatch']as$code)a11iAssert(in_array($code,$codes,true),$code);});
a11iRun('35 action external separation',function():void{$contract=file_get_contents(__DIR__.'/support/durable-retry-a11-runtime-capture-contract.php');a11iAssert(!str_contains($contract,"'external_actions' =>")&&!str_contains($contract,'project_to_external_actions'),'separate');});

$root=dirname(__DIR__,2);
$typedMatches=[];preg_match_all('/public function durableRetryWebpayMaterializer\(\): WebpayReconciliationMaterializer/',(string)file_get_contents($root.'/app/Core/Application.php'),$typedMatches);
a11iAssert(count($typedMatches[0])===1,'typed accessor');
a11iAssert(!file_exists($root.'/.a11-runtime'),'final runtime guard');
$summary=['suite'=>'durable-retry-a11-runtime-capture-infrastructure','cases'=>$a11Cases,'assertions'=>$a11Assertions,'failures'=>$a11Failures,'warnings'=>0,'notices'=>0,'deprecations'=>0];
echo json_encode($summary,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),PHP_EOL;
exit($a11Failures===[]?0:1);
