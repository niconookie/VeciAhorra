<?php

declare(strict_types=1);

use VeciAhorra\Tests\Manual\A11\DurableRetryA11CanonicalJson;
use VeciAhorra\Tests\Manual\A11\DurableRetryA11ActionCapture;
use VeciAhorra\Tests\Manual\A11\DurableRetryA11CapturePlan;
use VeciAhorra\Tests\Manual\A11\DurableRetryA11RuntimeCaptureStore;

require_once __DIR__ . '/support/durable-retry-a11-runtime-capture-contract.php';

$a11Cases = 0;
$a11Assertions = 0;
$a11Failures = [];

function a11Assert(bool $condition, string $message): void
{
    global $a11Assertions;
    $a11Assertions++;
    if (!$condition) throw new RuntimeException($message);
}

function a11Throws(callable $callable, string $error): void
{
    global $a11Assertions;
    $a11Assertions++;
    try { $callable(); } catch (Throwable $caught) {
        if ($caught->getMessage() === $error || $caught->getPrevious()?->getMessage() === $error) return;
        throw new RuntimeException('Expected '.$error.', got '.$caught->getMessage());
    }
    throw new RuntimeException('Expected '.$error.' but no error was thrown.');
}

function a11FixturePlan(array $overrides = []): array
{
    $plan = array_fill_keys(DurableRetryA11CapturePlan::FIXTURE_ID_KEYS, []);
    foreach ($overrides as $key => $aliases) $plan[$key] = $aliases;
    return $plan;
}

function a11Entry(string $case, string $phase = 'setup', string $source = 'repository.insert', string $required = 'first_delivery', string $type = 'positive-int'): array
{
    return ['type'=>$type,'owner'=>$case,'source_phase'=>$phase,'source'=>$source,'cardinality'=>'exactly-one','required_before'=>$required,'immutable'=>true,'equality'=>'same-on-replay','cleanup'=>true];
}

function a11Execution(int $suffix): string
{
    return 'a11_20260803000000_'.$suffix.'_0123456789abcdef';
}

function a11Plan(string $case = 'A11-OP-01', array $entries = [], array $fixture = [], array $business = []): DurableRetryA11CapturePlan
{
    return new DurableRetryA11CapturePlan($case, $entries, a11FixturePlan($fixture), $business);
}

function a11Store(int $suffix, string $case = 'A11-OP-01', array $entries = [], array $fixture = [], array $business = []): DurableRetryA11RuntimeCaptureStore
{
    return new DurableRetryA11RuntimeCaptureStore(a11Execution($suffix), a11Plan($case, $entries, $fixture, $business));
}

function a11Delta(DurableRetryA11RuntimeCaptureStore $store, array $captures = []): array
{
    return ['schema'=>'veciahorra-a11-capture/v1','kind'=>'capture_delta','case_id'=>$store->plan()->caseId(),'execution_id'=>$store->executionId(),'phase'=>$store->phase(),'base_snapshot_hash'=>$store->currentSnapshot()['snapshot_hash'],'captures'=>$captures];
}

function a11Run(string $name, callable $case): void
{
    global $a11Cases, $a11Failures;
    $a11Cases++;
    try { $case(); } catch (Throwable $error) { $a11Failures[] = $name.': '.$error->getMessage(); }
}

$alias = 'A11-OP-01.order.primary';

a11Run('01 valid plan', function () use ($alias): void {
    $plan=a11Plan('A11-OP-01',[$alias=>a11Entry('A11-OP-01')],['orders'=>[$alias]]);
    a11Assert($plan->has($alias) && strlen($plan->hash())===64, 'valid plan');
});
a11Run('02 positive capture', function () use ($alias): void {
    $s=a11Store(2,'A11-OP-01',[$alias=>a11Entry('A11-OP-01')],['orders'=>[$alias]]); $s->capture($alias,7,'repository.insert','setup');
    a11Assert($s->resolve($alias)===7,'capture');
});
a11Run('03 resolve', function () use ($alias): void {
    $s=a11Store(3,'A11-OP-01',[$alias=>a11Entry('A11-OP-01')],['orders'=>[$alias]]); $s->capture($alias,8,'repository.insert','setup');
    a11Assert($s->resolve($alias)===8,'resolve');
});
a11Run('04 identical capture', function () use ($alias): void {
    $s=a11Store(4,'A11-OP-01',[$alias=>a11Entry('A11-OP-01')],['orders'=>[$alias]]); $s->capture($alias,9,'repository.insert','setup'); $s->capture($alias,9,'repository.insert','setup');
    a11Assert(count($s->currentSnapshot()['captures'])===0 && $s->resolve($alias)===9,'idempotent');
});
a11Run('05 conflicting capture', function () use ($alias): void {
    $s=a11Store(5,'A11-OP-01',[$alias=>a11Entry('A11-OP-01')],['orders'=>[$alias]]); $s->capture($alias,9,'repository.insert','setup');
    a11Throws(fn()=>$s->capture($alias,10,'repository.insert','setup'),'duplicate_capture_conflict');
});
a11Run('06 unknown alias', function (): void { $s=a11Store(6); a11Throws(fn()=>$s->resolve('A11-OP-01.order.missing'),'unknown_alias'); });
a11Run('07 other owner', function (): void { a11Throws(fn()=>a11Plan('A11-OP-01',['A11-OP-02.order.primary'=>a11Entry('A11-OP-01')]),'wrong_owner'); });
a11Run('08 wrong type', function () use ($alias): void {
    $s=a11Store(8,'A11-OP-01',[$alias=>a11Entry('A11-OP-01')],['orders'=>[$alias]]);
    foreach ([0,-1,1.0,'1',true,null,[],new stdClass()] as $bad) a11Throws(fn()=>$s->capture($alias,$bad,'repository.insert','setup'),'wrong_type');
});
a11Run('09 wrong source', function () use ($alias): void { $s=a11Store(9,'A11-OP-01',[$alias=>a11Entry('A11-OP-01')],['orders'=>[$alias]]); a11Throws(fn()=>$s->capture($alias,1,'wrong.source','setup'),'invalid_delta'); });
a11Run('10 wrong phase', function () use ($alias): void { $s=a11Store(10,'A11-OP-01',[$alias=>a11Entry('A11-OP-01')],['orders'=>[$alias]]); a11Throws(fn()=>$s->capture($alias,1,'repository.insert','replay'),'wrong_phase'); });
a11Run('11 premature resolve', function () use ($alias): void { $s=a11Store(11,'A11-OP-01',[$alias=>a11Entry('A11-OP-01')],['orders'=>[$alias]]); a11Throws(fn()=>$s->resolve($alias),'missing_capture'); });
a11Run('12 exact cardinality', function () use ($alias): void { $s=a11Store(12,'A11-OP-01',[$alias=>a11Entry('A11-OP-01')],['orders'=>[$alias]]); a11Throws(fn()=>$s->integrateDelta(a11Delta($s)),'cardinality_mismatch'); });
a11Run('13 valid list', function () use ($alias): void {
    $second='A11-OP-01.order.secondary'; $s=a11Store(13,'A11-OP-01',[$alias=>a11Entry('A11-OP-01'),$second=>a11Entry('A11-OP-01')],['orders'=>[$alias,$second]]);
    $s->integrateDelta(a11Delta($s,[$alias=>['type'=>'positive-int','value'=>21,'source'=>'repository.insert'],$second=>['type'=>'positive-int','value'=>22,'source'=>'repository.insert']]));
    a11Assert($s->resolvedFixtureIds()['orders']===[21,22],'list order');
});
a11Run('14 duplicate list', function () use ($alias): void {
    $second='A11-OP-01.order.secondary'; $s=a11Store(14,'A11-OP-01',[$alias=>a11Entry('A11-OP-01'),$second=>a11Entry('A11-OP-01')],['orders'=>[$alias,$second]]);
    $s->integrateDelta(a11Delta($s,[$alias=>['type'=>'positive-int','value'=>21,'source'=>'repository.insert'],$second=>['type'=>'positive-int','value'=>21,'source'=>'repository.insert']]));
    a11Throws(fn()=>$s->resolvedFixtureIds(),'cardinality_mismatch');
});
a11Run('15 sealing', function () use ($alias): void {
    $s=a11Store(15,'A11-OP-01',[$alias=>a11Entry('A11-OP-01')],['orders'=>[$alias]]); $s1=$s->integrateDelta(a11Delta($s,[$alias=>['type'=>'positive-int','value'=>1,'source'=>'repository.insert']]));
    a11Assert($s1['snapshot_name']==='S1'&&$s1['previous_snapshot_hash']===$s->snapshot('S0')['snapshot_hash'],'seal');
});
a11Run('16 immutable snapshot copy', function (): void { $s=a11Store(16); $copy=$s->snapshot('S0'); $copy['phase']='bad'; a11Assert($s->snapshot('S0')['phase']==='bootstrap','immutable'); });
a11Run('17 canonical hash', function (): void { a11Assert(DurableRetryA11CanonicalJson::hash(['b'=>2,'a'=>1])===DurableRetryA11CanonicalJson::hash(['a'=>1,'b'=>2]),'canonical'); });
a11Run('18 rehash', function (): void { a11Assert(DurableRetryA11CanonicalJson::hash(['a'=>1])!==DurableRetryA11CanonicalJson::hash(['a'=>2]),'rehash'); });
a11Run('19 valid delta', function () use ($alias): void { $s=a11Store(19,'A11-OP-01',[$alias=>a11Entry('A11-OP-01')],['orders'=>[$alias]]); $s->integrateDelta(a11Delta($s,[$alias=>['type'=>'positive-int','value'=>31,'source'=>'repository.insert']])); a11Assert($s->phase()==='first_delivery','delta'); });
a11Run('20 stale hash', function (): void { $s=a11Store(20); $d=a11Delta($s); $d['base_snapshot_hash']=str_repeat('0',64); a11Throws(fn()=>$s->integrateDelta($d),'base_hash_mismatch'); });
a11Run('21 replay same snapshot', function (): void { $s=a11Store(21); $s->integrateDelta(a11Delta($s)); $s->integrateDelta(a11Delta($s)); $hash=$s->snapshot('S2')['snapshot_hash']; a11Assert($s->requestEnvelope(5)['input_snapshot']['snapshot_hash']===$hash,'replay snapshot'); });
a11Run('22 replay replacement', function () use ($alias): void {
    $s=a11Store(22,'A11-OP-01',[$alias=>a11Entry('A11-OP-01')],['orders'=>[$alias]]); $s->integrateDelta(a11Delta($s,[$alias=>['type'=>'positive-int','value'=>4,'source'=>'repository.insert']])); $s->integrateDelta(a11Delta($s));
    $d=a11Delta($s,[$alias=>['type'=>'positive-int','value'=>5,'source'=>'repository.insert']]); a11Throws(fn()=>$s->integrateDelta($d),'duplicate_capture_conflict');
});
a11Run('23 business identifiers', function (): void { $p=a11Plan('A11-OP-01',[],[],['buy_order'=>['type'=>'non-empty-string','value'=>'A11-OP-01-BO']]); a11Assert($p->fixtureIdPlan()['orders']===[]&&isset($p->businessIdentifiers()['buy_order']),'business separate'); });
a11Run('24 external ids separated', function (): void {
    $schedule='A11-OP-01.durable_schedule.initial'; $action='A11-OP-01.external_action.initial';
    $p=a11Plan('A11-OP-01',[$schedule=>a11Entry('A11-OP-01','first_delivery','schedule.repository','replay'),$action=>a11Entry('A11-OP-01','first_delivery','scheduler.double','replay')],['durable_retry_schedules'=>[$schedule],'action_scheduler_actions'=>[$action]]);
    a11Assert($p->entry($schedule)['source']!==$p->entry($action)['source'],'external domains');
});
a11Run('25 fifteen-key projection', function () use ($alias): void { $s=a11Store(25,'A11-OP-01',[$alias=>a11Entry('A11-OP-01')],['orders'=>[$alias]]); $s->integrateDelta(a11Delta($s,[$alias=>['type'=>'positive-int','value'=>44,'source'=>'repository.insert']])); $ids=$s->resolvedFixtureIds(); a11Assert(array_keys($ids)===DurableRetryA11CapturePlan::FIXTURE_ID_KEYS&&count($ids)===15,'projection'); });
a11Run('26 partial cleanup transport', function (): void { $s=a11Store(26); $s->integrateDelta(a11Delta($s)); $s->integrateDelta(a11Delta($s)); $s->integrateDelta(a11Delta($s)); $s->integrateDelta(a11Delta($s)); a11Assert($s->phase()==='cleanup'&&$s->currentSnapshot()['snapshot_name']==='S4','cleanup input'); });
a11Run('27 discarded after cleanup', function (): void { $s=a11Store(27); for($i=0;$i<4;$i++)$s->integrateDelta(a11Delta($s)); $s->finishCleanup(true); a11Throws(fn()=>$s->currentSnapshot(),'wrong_phase'); });
a11Run('28 repeated different PK', function () use ($alias): void { $a=a11Store(28,'A11-OP-01',[$alias=>a11Entry('A11-OP-01')],['orders'=>[$alias]]); $b=a11Store(29,'A11-OP-01',[$alias=>a11Entry('A11-OP-01')],['orders'=>[$alias]]); $a->capture($alias,100,'repository.insert','setup'); $b->capture($alias,200,'repository.insert','setup'); a11Assert($a->resolve($alias)!==$b->resolve($alias)&&$a->plan()->fixtureIdPlan()===$b->plan()->fixtureIdPlan(),'repeat relations'); });
a11Run('29 ownership two cases', function (): void { $a=a11Store(30,'A11-OP-01'); $b=a11Store(31,'A11-OP-02'); a11Assert($a->plan()->caseId()!==$b->plan()->caseId(),'ownership'); });
a11Run('30 full S0-S4 cycle', function (): void { $s=a11Store(32); for($i=0;$i<4;$i++)$s->integrateDelta(a11Delta($s)); a11Assert(array_keys($s->history())===['S0','S1','S2','S3','S4']&&$s->phase()==='cleanup','cycle'); });

a11Run('31 action catalogs exact', function (): void {
    a11Assert(DurableRetryA11ActionCapture::PHASES===['first_delivery','replay'],'phases');
    a11Assert(DurableRetryA11ActionCapture::PORTS===['webpay.commit','woocommerce.payment_complete','scheduler.action_schedule','scheduler.action_cancel','legacy.retry_schedule','durable.worker_execute'],'ports');
});
a11Run('32 dense zero map', function (): void {
    $map=DurableRetryA11ActionCapture::zeroMap();
    a11Assert(array_keys($map)===DurableRetryA11ActionCapture::PHASES,'dense phases');
    foreach($map as $ports)a11Assert(array_keys($ports)===DurableRetryA11ActionCapture::PORTS&&array_sum($ports)===0,'dense ports');
});
a11Run('33 increment every first delivery port', function (): void {
    foreach(DurableRetryA11ActionCapture::PORTS as $i=>$port){$a=new DurableRetryA11ActionCapture('A11-OP-01');$a->increment('first_delivery',$port,$i+1);a11Assert($a->counts()['first_delivery'][$port]===$i+1,'fd '.$port);}
});
a11Run('34 increment every replay port', function (): void {
    foreach(DurableRetryA11ActionCapture::PORTS as $port){$a=new DurableRetryA11ActionCapture('A11-OP-01');$a->increment('replay',$port,1);a11Assert($a->counts()['replay'][$port]===1,'replay '.$port);}
});
a11Run('35 accumulated and isolated slots', function (): void {
    $a=new DurableRetryA11ActionCapture('A11-OP-01');$a->increment('first_delivery','webpay.commit',1);$a->increment('first_delivery','webpay.commit',2);$a->increment('replay','webpay.commit',4);
    a11Assert($a->counts()['first_delivery']['webpay.commit']===3&&$a->counts()['replay']['webpay.commit']===4,'accumulate');
    a11Assert($a->counts()['first_delivery']['scheduler.action_schedule']===0,'port isolation');
});
a11Run('36 reject unknown action coordinates', function (): void {
    $a=new DurableRetryA11ActionCapture('A11-OP-01');a11Throws(fn()=>$a->increment('setup','webpay.commit',1),'actions_phase_invalid');a11Throws(fn()=>$a->increment('first_delivery','unknown',1),'actions_port_invalid');
});
a11Run('37 reject invalid deltas without mutation', function (): void {
    foreach([0,-1,1.0,'1',true,null,[],new stdClass()]as$bad){$a=new DurableRetryA11ActionCapture('A11-OP-01');$hash=$a->hash();a11Throws(fn()=>$a->increment('first_delivery','webpay.commit',$bad),'actions_delta_invalid');a11Assert($a->hash()===$hash,'delta atomic');}
});
a11Run('38 reject overflow without mutation', function (): void {
    $a=new DurableRetryA11ActionCapture('A11-OP-01');$a->increment('first_delivery','webpay.commit',DurableRetryA11ActionCapture::MAX_COUNT);$hash=$a->hash();a11Throws(fn()=>$a->increment('first_delivery','webpay.commit',1),'actions_overflow');a11Assert($a->hash()===$hash,'overflow atomic');
});
a11Run('39 strict complete maps', function (): void {
    $map=DurableRetryA11ActionCapture::zeroMap();$partial=$map;unset($partial['replay']['webpay.commit']);a11Throws(fn()=>DurableRetryA11ActionCapture::normalizeMap($partial),'actions_port_invalid');
    $extra=$map;$extra['replay']['extra']=0;a11Throws(fn()=>DurableRetryA11ActionCapture::normalizeMap($extra),'actions_port_invalid');
    foreach([null,true,1.0,'0',[]]as$bad){$wrong=$map;$wrong['replay']['webpay.commit']=$bad;a11Throws(fn()=>DurableRetryA11ActionCapture::normalizeMap($wrong),'actions_count_invalid');}
});
a11Run('40 canonical action hash', function (): void {
    $zero=DurableRetryA11ActionCapture::zeroMap();$reordered=['replay'=>array_reverse($zero['replay'],true),'first_delivery'=>array_reverse($zero['first_delivery'],true)];
    a11Assert(DurableRetryA11ActionCapture::hashMap($zero)===DurableRetryA11ActionCapture::hashMap($reordered),'order hash');
    $changed=$zero;$changed['first_delivery']['webpay.commit']=1;a11Assert(DurableRetryA11ActionCapture::hashMap($zero)!==DurableRetryA11ActionCapture::hashMap($changed),'count hash');
    $changed['first_delivery']['webpay.commit']=0;a11Assert(DurableRetryA11ActionCapture::hashMap($zero)===DurableRetryA11ActionCapture::hashMap($changed),'restored hash');
});
a11Run('41 snapshot hash includes actions', function (): void {
    $a=a11Store(41);$b=a11Store(42);$a->integrateDelta(a11Delta($a));$b->integrateDelta(a11Delta($b));$b->recordAction('A11-OP-01',$b->executionId(),'first_delivery','webpay.commit',1);$a->integrateDelta(a11Delta($a));$b->integrateDelta(a11Delta($b));
    a11Assert($a->snapshot('S2')['actions']!==$b->snapshot('S2')['actions'],'snapshot actions');a11Assert($a->snapshot('S2')['snapshot_hash']!==$b->snapshot('S2')['snapshot_hash'],'snapshot hash');
});
a11Run('42 irreversible phase and case sealing', function (): void {
    $s=a11Store(43);$s->integrateDelta(a11Delta($s));$s->recordAction('A11-OP-01',$s->executionId(),'first_delivery','webpay.commit',1);$s->integrateDelta(a11Delta($s));a11Throws(fn()=>$s->recordAction('A11-OP-01',$s->executionId(),'first_delivery','webpay.commit',1),'wrong_phase');
    $s->recordAction('A11-OP-01',$s->executionId(),'replay','webpay.commit',1);$s->integrateDelta(a11Delta($s));a11Throws(fn()=>$s->recordAction('A11-OP-01',$s->executionId(),'replay','webpay.commit',1),'wrong_phase');
});
a11Run('43 exact comparison and diagnostics', function (): void {
    $s=a11Store(44);$s->integrateDelta(a11Delta($s));$s->recordAction('A11-OP-01',$s->executionId(),'first_delivery','webpay.commit',1);$expected=DurableRetryA11ActionCapture::zeroMap();$expected['first_delivery']['webpay.commit']=1;$s->assertExpectedActions($expected);a11Assert(true,'exact');
    $low=$expected;$low['first_delivery']['webpay.commit']=2;try{$s->assertExpectedActions($low);throw new RuntimeException('missing mismatch');}catch(\VeciAhorra\Tests\Manual\A11\DurableRetryA11ActionMismatch $e){a11Assert($e->caseId==='A11-OP-01'&&$e->reason==='observed_lower','lower diagnostic');}
    $high=$expected;$high['first_delivery']['webpay.commit']=0;try{$s->assertExpectedActions($high);throw new RuntimeException('missing mismatch');}catch(\VeciAhorra\Tests\Manual\A11\DurableRetryA11ActionMismatch $e){a11Assert($e->reason==='observed_higher','higher diagnostic');}
});
a11Run('44 case and ownership isolation', function (): void {
    $s=a11Store(45);$s->integrateDelta(a11Delta($s));$hash=$s->actionHash();a11Throws(fn()=>$s->recordAction('A11-OP-02',$s->executionId(),'first_delivery','webpay.commit',1),'wrong_owner');a11Throws(fn()=>$s->recordAction('A11-OP-01',a11Execution(99),'first_delivery','webpay.commit',1),'wrong_owner');a11Assert($s->actionHash()===$hash,'owner atomic');
});
a11Run('45 strict action delta ingestion', function (): void {
    $s=a11Store(46);$s->integrateDelta(a11Delta($s));$delta=['schema'=>'veciahorra-a11-capture/v1','kind'=>'action_delta','case_id'=>'A11-OP-01','ownership_token'=>$s->executionId(),'phase'=>'first_delivery','port'=>'scheduler.action_schedule','delta'=>1,'base_action_hash'=>$s->actionHash()];$s->integrateActionDelta($delta);a11Assert($s->actionCounts()['first_delivery']['scheduler.action_schedule']===1,'ingested');
    $bad=$delta;$bad['base_action_hash']=str_repeat('0',64);a11Throws(fn()=>$s->integrateActionDelta($bad),'actions_base_hash_mismatch');$extra=$delta;$extra['extra']=true;a11Throws(fn()=>$s->integrateActionDelta($extra),'wrong_owner');
});
a11Run('46 external actions separated', function (): void {
    $s=a11Store(47);a11Assert(!array_key_exists('external_actions',$s->actionCounts()),'no projection');a11Assert(!array_key_exists('actions',$s->plan()->toArray()),'static plan unchanged');
});
a11Run('47 dense actions across S0-S4', function (): void {
    $s=a11Store(48);for($i=0;$i<4;$i++)$s->integrateDelta(a11Delta($s));foreach($s->history()as$snapshot)a11Assert(DurableRetryA11ActionCapture::normalizeMap($snapshot['actions'])===DurableRetryA11ActionCapture::zeroMap(),'dense history');
});
a11Run('48 independent cases', function (): void {
    $a=a11Store(49,'A11-OP-01');$b=a11Store(50,'A11-OP-02');$a->integrateDelta(a11Delta($a));$b->integrateDelta(a11Delta($b));$a->recordAction('A11-OP-01',$a->executionId(),'first_delivery','webpay.commit',1);a11Assert(array_sum($b->actionCounts()['first_delivery'])===0,'case isolation');
});
a11Run('49 active phase enforced', function (): void {
    $s=a11Store(51);a11Throws(fn()=>$s->recordAction('A11-OP-01',$s->executionId(),'first_delivery','webpay.commit',1),'wrong_phase');
});
a11Run('50 cleanup preserves action lifecycle', function (): void {
    $s=a11Store(52);for($i=0;$i<4;$i++)$s->integrateDelta(a11Delta($s));$s->finishCleanup(true);a11Throws(fn()=>$s->actionCounts(),'wrong_phase');
});

$summary=['suite'=>'durable-retry-a11-runtime-capture-functional','cases'=>$a11Cases,'assertions'=>$a11Assertions,'failures'=>$a11Failures,'warnings'=>0,'notices'=>0,'deprecations'=>0];
echo json_encode($summary, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($a11Failures===[]?0:1);
