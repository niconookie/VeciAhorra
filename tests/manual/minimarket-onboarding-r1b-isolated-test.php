<?php

declare(strict_types=1);

const ARRAY_A = 'ARRAY_A';

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed { return $value; }
function sanitize_email(string $email): string
{
    if (strlen($email) < 6 || strpos($email, '@', 1) === false) return '';
    [$local,$domain]=explode('@',$email,2);
    $local=preg_replace('/[^a-zA-Z0-9!#$%&\'*+\/=\?\^_`{|}~\.-]/','',$local)??'';
    $domain=preg_replace('/\.{2,}/','',$domain)??'';
    $domain=trim($domain," \t\n\r\0\x0B.");
    return $local!==''&&str_contains($domain,'.')?$local.'@'.$domain:'';
}
function is_email(string $email): string|false
{
    return filter_var($email,FILTER_VALIDATE_EMAIL)!==false?$email:false;
}

require_once dirname(__DIR__,2).'/vendor/autoload.php';

use VeciAhorra\Modules\Minimarket\Onboarding\Application\StartStoreOnboarding;
use VeciAhorra\Modules\Minimarket\Onboarding\Application\StartStoreOnboardingCommand;
use VeciAhorra\Modules\Minimarket\Onboarding\Contracts\CurrentOnboardingTerms;
use VeciAhorra\Modules\Minimarket\Onboarding\Contracts\OnboardingClock;
use VeciAhorra\Modules\Minimarket\Onboarding\Contracts\OnboardingPublicIdGenerator;
use VeciAhorra\Modules\Minimarket\Onboarding\Contracts\StoreOnboardingApplicationWriter;
use VeciAhorra\Modules\Minimarket\Onboarding\Exceptions\OnboardingConflictException;
use VeciAhorra\Modules\Minimarket\Onboarding\Exceptions\OnboardingInputException;
use VeciAhorra\Modules\Minimarket\Onboarding\Exceptions\OnboardingPersistenceException;
use VeciAhorra\Modules\Minimarket\Onboarding\Exceptions\OnboardingPublicIdCollisionException;
use VeciAhorra\Modules\Minimarket\Onboarding\StoreOnboardingApplication;
use VeciAhorra\Modules\Minimarket\Onboarding\StoreOnboardingApplicationRepository;
use VeciAhorra\Modules\Minimarket\Onboarding\Support\ChileanRutNormalizer;
use VeciAhorra\Modules\Minimarket\Onboarding\Support\OnboardingEmailNormalizer;
use VeciAhorra\Modules\Minimarket\Onboarding\Support\RandomOnboardingPublicIdGenerator;
use VeciAhorra\Modules\Minimarket\Onboarding\Support\SystemOnboardingClock;

function r1bAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function r1bReason(callable $call,string $class,string $reason):void
{
    try{$call();throw new RuntimeException("No rechazo {$reason}");}
    catch(Throwable $exception){r1bAssert($exception instanceof $class,"Clase incorrecta para {$reason}");r1bAssert($exception->reason()===$reason,"Reason incorrecto para {$reason}");r1bAssert($exception->getPrevious()===null,"Causa previa expuesta para {$reason}");r1bAssert(!str_contains($exception->getMessage(),'@')&&!str_contains($exception->getMessage(),'12345678'),'Excepcion expuso PII.');}
}
function r1bAssertSanitized(Throwable $exception,string $class,string $reason,string $case):void
{
    r1bAssert($exception instanceof $class,"Clase hostil incorrecta: {$case}");
    r1bAssert(method_exists($exception,'reason')&&$exception->reason()===$reason,"Reason hostil incorrecto: {$case}");
    $markers=['PII_SENTINEL_EMAIL','PII_SENTINEL_RUT','RAW_KEY_SENTINEL','SQL_SENTINEL','PAYLOAD_SENTINEL','HASH_SENTINEL','INTERNAL_PATH_SENTINEL'];
    $depth=0;
    for($current=$exception;$current!==null;$current=$current->getPrevious()){
        $depth++;
        $observable=$current::class.'|'.$current->getMessage().'|'.$current->getCode().'|'.json_encode(get_object_vars($current));
        foreach($markers as $marker)r1bAssert(!str_contains($observable,$marker),"Contenido prohibido detectado: {$case}");
    }
    r1bAssert($depth===1,"Cadena hostil conservada: {$case}");
}
function r1bHostile():RuntimeException{return new RuntimeException('PII_SENTINEL_EMAIL PII_SENTINEL_RUT RAW_KEY_SENTINEL SQL_SENTINEL PAYLOAD_SENTINEL HASH_SENTINEL INTERNAL_PATH_SENTINEL');}

r1bAssert(class_exists(StartStoreOnboarding::class)&&interface_exists(StoreOnboardingApplicationWriter::class),'Autoload PSR-4 fallo.');
$emails=new OnboardingEmailNormalizer();
foreach([' OWNER@EXAMPLE.COM '=>'owner@example.com','first.last+tag@example-domain.cl'=>'first.last+tag@example-domain.cl'] as $input=>$expected)r1bAssert($emails->normalize($input)===$expected,'Email valido no normalizado.');
foreach(['','bad','owner @example.com','owñer@example.com','owner@example..com',str_repeat('a',180).'@example.com'] as $invalid)r1bReason(static fn()=>$emails->normalize($invalid),OnboardingInputException::class,'invalid_email');
r1bAssert(sanitize_email('owner (x)@example.com')==='ownerx@example.com','Fixture no reproduce transformacion sanitize_email.');
r1bReason(static fn()=>$emails->normalize('owner (x)@example.com'),OnboardingInputException::class,'invalid_email');
$email190=str_repeat('a',64).'@'.str_repeat('b',63).'.'.str_repeat('c',57).'.com';
$email191=str_repeat('a',64).'@'.str_repeat('b',63).'.'.str_repeat('c',58).'.com';
r1bAssert(strlen($email190)===190&&$emails->normalize($email190)===$email190,'Email de 190 bytes valido fallo.');
r1bAssert(strlen($email191)===191,'Fixture email 191 bytes incorrecto.');
r1bReason(static fn()=>$emails->normalize($email191),OnboardingInputException::class,'invalid_email');

$ruts=new ChileanRutNormalizer();
foreach(['12.345.678-5','12345678-5','12 345 678 5'] as $input)r1bAssert($ruts->normalizeAndValidate($input)==='12345678-5','RUT numerico valido fallo.');
foreach(['1.000.005-k','1000005K','1 000 005 k'] as $input)r1bAssert($ruts->normalizeAndValidate($input)==='1000005-K','RUT K valido fallo.');
foreach(['123456-0','123456789-0','12A34567-5','12345678-4','6812766-X',''] as $invalid)r1bReason(static fn()=>$ruts->normalizeAndValidate($invalid),OnboardingInputException::class,'invalid_rut');

final class R1bClock implements OnboardingClock{public int $calls=0;public function __construct(private string $time='2026-08-21 12:00:00'){}public function nowUtc():DateTimeImmutable{$this->calls++;return new DateTimeImmutable($this->time,new DateTimeZone('America/Santiago'));}}
final class R1bIds implements OnboardingPublicIdGenerator{public int $calls=0;public function __construct(private array $ids){}public function generate():string{$this->calls++;return array_shift($this->ids)??'';}}
final class R1bTerms implements CurrentOnboardingTerms{public function __construct(private string $value){}public function version():string{return $this->value;}}
final class R1bWriter implements StoreOnboardingApplicationWriter
{
    public array $inputs=[];public array $outcomes=[];
    public function createProvisioning(array $data):StoreOnboardingApplication
    {
        $this->inputs[]=$data;$outcome=array_shift($this->outcomes);
        if($outcome instanceof Throwable)throw $outcome;
        if($outcome instanceof StoreOnboardingApplication)return $outcome;
        return new StoreOnboardingApplication(['id'=>'1']+$data+['user_id'=>null,'status'=>'provisioning','store_id'=>null,'failure_code'=>null,'attempt_count'=>'0','last_attempt_at'=>null,'abandoned_at'=>null]);
    }
}
function r1bService(StoreOnboardingApplicationWriter $writer,R1bClock $clock,R1bIds $ids,string $terms='2026-08'):StartStoreOnboarding{return new StartStoreOnboarding($writer,$clock,$ids,new R1bTerms($terms),new OnboardingEmailNormalizer(),new ChileanRutNormalizer());}
function r1bCommand(string $email='Owner@Example.com',string $rut='12.345.678-5',string $key='',bool $accepted=true):StartStoreOnboardingCommand{return new StartStoreOnboardingCommand($email,$rut,$key!==''?$key:str_repeat('A',32),$accepted);}

foreach([
    [r1bCommand('', 'bad',str_repeat('!',31),false),'invalid_email'],
    [r1bCommand('owner@example.com','bad',str_repeat('!',31),false),'invalid_rut'],
    [r1bCommand('owner@example.com','12.345.678-5',str_repeat('!',31),false),'terms_not_accepted'],
] as [$command,$reason]){r1bReason(static fn()=>r1bService(new R1bWriter(),new R1bClock(),new R1bIds(['onb_'.str_repeat('a',40)]))->execute($command),OnboardingInputException::class,$reason);}
foreach(['',str_repeat('v',33),"2026\n08"] as $version)r1bReason(static fn()=>r1bService(new R1bWriter(),new R1bClock(),new R1bIds(['onb_'.str_repeat('a',40)]),$version)->execute(r1bCommand()),OnboardingInputException::class,'terms_version_unavailable');
foreach([str_repeat('a',31),str_repeat('a',129),str_repeat('a',31).'!',str_repeat('á',32)] as $key)r1bReason(static fn()=>r1bService(new R1bWriter(),new R1bClock(),new R1bIds(['onb_'.str_repeat('a',40)]))->execute(r1bCommand(key:$key)),OnboardingInputException::class,'invalid_idempotency_key');
foreach([str_repeat('a',32),str_repeat('Z',128),'A_b-'.str_repeat('0',28)] as $key){$writer=new R1bWriter();r1bService($writer,new R1bClock(),new R1bIds(['onb_'.str_repeat('a',40)]))->execute(r1bCommand(key:$key));r1bAssert($writer->inputs[0]['idempotency_key_hash']===hash('sha256','minimarket-onboarding-v1|'.$key),'Hash de dominio incorrecto.');r1bAssert(!in_array($key,$writer->inputs[0],true),'Raw key llego al repositorio.');}

$writer=new R1bWriter();$clock=new R1bClock();$ids=new R1bIds(['onb_'.str_repeat('a',40)]);$result=r1bService($writer,$clock,$ids)->execute(r1bCommand());
r1bAssert($clock->calls===1&&$ids->calls===1,'Clock o generador tuvo cardinalidad incorrecta.');
r1bAssert($result->publicId==='onb_'.str_repeat('a',40)&&$result->status==='provisioning'&&$result->createdAt==='2026-08-21 16:00:00'&&$result->updatedAt===$result->createdAt,'Resultado durable incorrecto.');
$input=$writer->inputs[0];r1bAssert($input['account_email']==='owner@example.com'&&$input['owner_rut_normalized']==='12345678-5'&&$input['terms_accepted_at']==='2026-08-21 16:00:00'&&count($input)===8,'Shape interno incorrecto.');
r1bAssert(!str_contains(json_encode($result),'owner@example.com')&&!str_contains(json_encode($result),'12345678'),'Resultado expuso PII.');

foreach(['','ONB_'.str_repeat('a',40),'onb_'.str_repeat('A',40),'onb_'.str_repeat('a',39),'onb_'.str_repeat('g',40)] as $badId)r1bReason(static fn()=>r1bService(new R1bWriter(),new R1bClock(),new R1bIds([$badId]))->execute(r1bCommand()),OnboardingPersistenceException::class,'identity_generation_failed');
foreach([1,2] as $collisions){$writer=new R1bWriter();$writer->outcomes=array_fill(0,$collisions,new OnboardingPublicIdCollisionException());$idsList=[];for($i=0;$i<=$collisions;$i++)$idsList[]='onb_'.str_repeat((string)($i+1),40);$ids=new R1bIds($idsList);$result=r1bService($writer,new R1bClock(),$ids)->execute(r1bCommand());r1bAssert($ids->calls===$collisions+1&&count($writer->inputs)===$collisions+1,'Retry de public ID incorrecto.');}
$writer=new R1bWriter();$writer->outcomes=[new OnboardingPublicIdCollisionException(),new OnboardingPublicIdCollisionException(),new OnboardingPublicIdCollisionException()];r1bReason(static fn()=>r1bService($writer,new R1bClock(),new R1bIds(['onb_'.str_repeat('1',40),'onb_'.str_repeat('2',40),'onb_'.str_repeat('3',40)]))->execute(r1bCommand()),OnboardingPersistenceException::class,'identity_generation_failed');
$writer=new R1bWriter();$writer->outcomes=[new OnboardingPublicIdCollisionException()];$repeated='onb_'.str_repeat('4',40);r1bReason(static fn()=>r1bService($writer,new R1bClock(),new R1bIds([$repeated,$repeated]))->execute(r1bCommand()),OnboardingPersistenceException::class,'identity_generation_failed');
$writer=new R1bWriter();$writer->outcomes=[new RuntimeException('onboarding_idempotency_conflict')];$ids=new R1bIds(['onb_'.str_repeat('a',40),'onb_'.str_repeat('b',40)]);r1bReason(static fn()=>r1bService($writer,new R1bClock(),$ids)->execute(r1bCommand()),OnboardingConflictException::class,'idempotency_conflict');r1bAssert($ids->calls===1,'Duplicado hash consumio retry ID.');
foreach([['onboarding_create_failed','persistence_failed'],['onboarding_create_uncertain','outcome_uncertain']] as [$message,$reason]){$writer=new R1bWriter();$writer->outcomes=[new RuntimeException($message)];$ids=new R1bIds(['onb_'.str_repeat('a',40),'onb_'.str_repeat('b',40)]);r1bReason(static fn()=>r1bService($writer,new R1bClock(),$ids)->execute(r1bCommand()),OnboardingPersistenceException::class,$reason);r1bAssert($ids->calls===1,'Fallo SQL consumio retry ID.');}

r1bAssert((new SystemOnboardingClock())->nowUtc()->getTimezone()->getName()==='UTC','System clock no usa UTC.');
r1bAssert(preg_match('/\Aonb_[a-f0-9]{40}\z/',(new RandomOnboardingPublicIdGenerator())->generate())===1,'Generador real invalido.');

final class R1bRepositoryWpdb
{
    public string $prefix='iso_';public string $users='iso_users';public string $last_error='';public int $insert_id=0;public int $insertCalls=0;public int $getRowCalls=0;public array $rows=[];public array $existingUsers=[7=>true];public array $stores=[10=>7];public ?string $forcedInsertError=null;public ?Throwable $insertException=null;public ?int $throwGetRowAt=null;public bool $throwGetVar=false;private array $values=[];private int $sequence=0;
    public function suppress_errors(bool $suppress):bool{return false;}
    public function prepare(string $query,mixed ...$values):string{$this->values=$values;return $query;}
    public function insert(string $table,array $row):int|false
    {
        $this->insertCalls++;if($this->insertException!==null)throw $this->insertException;
        if($this->forcedInsertError!==null){$this->last_error=$this->forcedInsertError;return false;}
        foreach($this->rows as $existing){if($existing['idempotency_key_hash']===$row['idempotency_key_hash']||$existing['public_id']===$row['public_id']){$this->last_error='Duplicate key';return false;}}
        $this->last_error='';$this->insert_id=++$this->sequence;$this->rows[$this->insert_id]=['id'=>(string)$this->insert_id]+$row;return 1;
    }
    public function get_row(string $query,string $format):?array
    {
        $this->getRowCalls++;if($this->throwGetRowAt===$this->getRowCalls)throw r1bHostile();
        if(str_contains($query,'FROM iso_va_stores')){$id=(int)($this->values[0]??0);return isset($this->stores[$id])?['id'=>(string)$id,'owner_user_id'=>(string)$this->stores[$id]]:null;}
        $value=$this->values[0]??null;$column=str_contains($query,'idempotency_key_hash=')?'idempotency_key_hash':(str_contains($query,'public_id=')?'public_id':'id');
        foreach($this->rows as $row)if((string)$row[$column]===(string)$value)return $row;return null;
    }
    public function update(string $table,array $set,array $where):int|false{return false;}
    public function query(string $query):int|false{return false;}
    public function get_var(string $query):mixed{if($this->throwGetVar)throw r1bHostile();if(str_contains($query,'SELECT ID FROM')){$id=(int)($this->values[0]??0);return isset($this->existingUsers[$id])?$id:null;}return null;}
}
function r1bRow(string $public,string $hash,string $email='owner@example.com',string $rut='12345678-5',string $status='provisioning'):array{return ['public_id'=>$public,'account_email'=>$email,'owner_rut_normalized'=>$rut,'idempotency_key_hash'=>$hash,'terms_version'=>'2026-08','terms_accepted_at'=>'2026-08-21 16:00:00','created_at'=>'2026-08-21 16:00:00','updated_at'=>'2026-08-21 16:00:00','user_id'=>null,'status'=>$status,'store_id'=>null,'failure_code'=>null,'attempt_count'=>0,'last_attempt_at'=>null,'abandoned_at'=>null];}

$wpdb=new R1bRepositoryWpdb();$repo=new StoreOnboardingApplicationRepository();$hash=str_repeat('a',64);$first=$repo->createProvisioning(array_intersect_key(r1bRow('onb_'.str_repeat('1',40),$hash),array_flip(['public_id','account_email','owner_rut_normalized','idempotency_key_hash','terms_version','terms_accepted_at','created_at','updated_at'])));$retryInput=['public_id'=>'onb_'.str_repeat('2',40),'account_email'=>'owner@example.com','owner_rut_normalized'=>'12345678-5','idempotency_key_hash'=>$hash,'terms_version'=>'2026-08','terms_accepted_at'=>'2026-08-22 00:00:00','created_at'=>'2026-08-22 00:00:00','updated_at'=>'2026-08-22 00:00:00'];$replay=$repo->createProvisioning($retryInput);r1bAssert($replay->data['public_id']===$first->data['public_id']&&$replay->data['created_at']===$first->data['created_at'],'Repositorio no preservo primer replay.');
$conflicting=$retryInput;$conflicting['account_email']='other@example.com';try{$repo->createProvisioning($conflicting);throw new RuntimeException('No rechazo intencion incompatible.');}catch(RuntimeException $exception){r1bAssert($exception->getMessage()==='onboarding_idempotency_conflict','Conflicto repo incorrecto.');}
$advanced=$wpdb->rows[1];$advanced['status']='account_created';$advanced['user_id']='7';$wpdb->rows[1]=$advanced;r1bAssert($repo->createProvisioning($retryInput)->data['status']==='account_created','Replay avanzado valido rechazado.');
$wpdb->rows[1]['user_id']=null;try{$repo->createProvisioning($retryInput);throw new RuntimeException('Replay corrupto aceptado.');}catch(RuntimeException $exception){r1bAssert($exception->getMessage()==='onboarding_replay_incompatible','Replay corrupto incorrecto.');}
$wpdb->rows[1]['status']='store_materialized';$wpdb->rows[1]['user_id']='7';$wpdb->rows[1]['store_id']='10';r1bAssert($repo->createProvisioning($retryInput)->data['status']==='store_materialized','Replay materializado valido rechazado.');$wpdb->stores[10]=8;try{$repo->createProvisioning($retryInput);throw new RuntimeException('Replay con owner ajeno aceptado.');}catch(RuntimeException $exception){r1bAssert($exception->getMessage()==='onboarding_replay_incompatible','Referencia materializada incorrecta.');}
$wpdb=new R1bRepositoryWpdb();$wpdb->rows[9]=['id'=>'9']+r1bRow('onb_'.str_repeat('9',40),str_repeat('9',64));$collisionInput=$retryInput;$collisionInput['public_id']='onb_'.str_repeat('9',40);$collisionInput['idempotency_key_hash']=str_repeat('8',64);try{(new StoreOnboardingApplicationRepository())->createProvisioning($collisionInput);throw new RuntimeException('No detecto colision public ID.');}catch(OnboardingPublicIdCollisionException){}
$wpdb=new R1bRepositoryWpdb();$repo=new StoreOnboardingApplicationRepository();$service1=new StartStoreOnboarding($repo,new R1bClock('2026-08-21 12:00:00'),new R1bIds(['onb_'.str_repeat('5',40)]),new R1bTerms('2026-08'),new OnboardingEmailNormalizer(),new ChileanRutNormalizer());$service2=new StartStoreOnboarding($repo,new R1bClock('2026-08-22 12:00:00'),new R1bIds(['onb_'.str_repeat('6',40)]),new R1bTerms('2026-08'),new OnboardingEmailNormalizer(),new ChileanRutNormalizer());$firstService=$service1->execute(r1bCommand());$replayService=$service2->execute(r1bCommand());r1bAssert($replayService==$firstService,'Servicio+repositorio no preservo identidad/timestamps en replay.');
foreach([['database failure','onboarding_create_failed'],['','onboarding_create_uncertain']] as [$sqlError,$expected]){$wpdb=new R1bRepositoryWpdb();$wpdb->forcedInsertError=$sqlError;try{(new StoreOnboardingApplicationRepository())->createProvisioning($retryInput);throw new RuntimeException("No produjo {$expected}");}catch(RuntimeException $exception){r1bAssert($exception->getMessage()===$expected,"Clasificacion SQL incorrecta: {$exception->getMessage()}");}}

// Every dependency boundary must discard an arbitrary hostile cause completely.
$hostileCases=[];
$hostileWriters=['terms'=>new R1bWriter(),'clock'=>new R1bWriter(),'generator'=>new R1bWriter(),'writer'=>new R1bWriter()];$hostileWriters['writer']->outcomes=[r1bHostile()];
$hostileGenerator=new class implements OnboardingPublicIdGenerator{public int $calls=0;public function generate():string{$this->calls++;throw r1bHostile();}};
$hostileCases['terms']=static function()use($hostileWriters):void{$service=new StartStoreOnboarding($hostileWriters['terms'],new R1bClock(),new R1bIds(['onb_'.str_repeat('a',40)]),new class implements CurrentOnboardingTerms{public function version():string{throw r1bHostile();}},new OnboardingEmailNormalizer(),new ChileanRutNormalizer());$service->execute(r1bCommand());};
$hostileCases['clock']=static function()use($hostileWriters):void{$service=new StartStoreOnboarding($hostileWriters['clock'],new class implements OnboardingClock{public function nowUtc():DateTimeImmutable{throw r1bHostile();}},new R1bIds(['onb_'.str_repeat('a',40)]),new R1bTerms('2026-08'),new OnboardingEmailNormalizer(),new ChileanRutNormalizer());$service->execute(r1bCommand());};
$hostileCases['generator']=static function()use($hostileWriters,$hostileGenerator):void{$service=new StartStoreOnboarding($hostileWriters['generator'],new R1bClock(),$hostileGenerator,new R1bTerms('2026-08'),new OnboardingEmailNormalizer(),new ChileanRutNormalizer());$service->execute(r1bCommand());};
$hostileCases['writer']=static function()use($hostileWriters):void{r1bService($hostileWriters['writer'],new R1bClock(),new R1bIds(['onb_'.str_repeat('a',40)]))->execute(r1bCommand());};
foreach($hostileCases as $case=>$call){try{$call();throw new RuntimeException("Fallo hostil no rechazado: {$case}");}catch(Throwable $exception){$reason=$case==='terms'?'terms_version_unavailable':($case==='generator'?'identity_generation_failed':'persistence_failed');$class=$case==='terms'?OnboardingInputException::class:OnboardingPersistenceException::class;r1bAssertSanitized($exception,$class,$reason,$case);}}
r1bAssert($hostileWriters['terms']->inputs===[]&&$hostileWriters['clock']->inputs===[]&&$hostileWriters['generator']->inputs===[],'Fallo previo al writer expuso la clave o escribio.');
r1bAssert(count($hostileWriters['writer']->inputs)===1&&$hostileGenerator->calls===1,'Fallo hostil consumio intentos adicionales.');

// Hostile failures from INSERT, hash re-read, public-id re-read and hydration.
foreach(['insert'=>0,'hash_read'=>1,'public_read'=>2,'duplicate_classifier'=>2,'hydration'=>1,'select'=>0] as $case=>$readAt){
    $wpdb=new R1bRepositoryWpdb();
    if($case==='insert')$wpdb->insertException=r1bHostile();
    elseif($case==='select'){$key=str_repeat('A',32);$wpdb->rows[1]=['id'=>'1']+array_replace(r1bRow('onb_'.str_repeat('7',40),hash('sha256','minimarket-onboarding-v1|'.$key),status:'account_created'),['user_id'=>'7']);$wpdb->throwGetVar=true;}
    elseif($case==='hydration')$wpdb->throwGetRowAt=$readAt;
    else{$wpdb->forcedInsertError='duplicate';$wpdb->throwGetRowAt=$readAt;}
    $ids=new R1bIds(['onb_'.str_repeat('d',40),'onb_'.str_repeat('e',40)]);
    try{r1bService(new StoreOnboardingApplicationRepository(),new R1bClock(),$ids)->execute(r1bCommand());throw new RuntimeException("Fallo wpdb no rechazado: {$case}");}
    catch(Throwable $exception){r1bAssertSanitized($exception,OnboardingPersistenceException::class,'persistence_failed',$case);r1bAssert($ids->calls===1,"Fallo hostil consumio retry: {$case}");r1bAssert($wpdb->insertCalls===1,"Fallo hostil produjo segunda escritura: {$case}");}
}

// Independent intent conflicts preserve the original row and never insert twice.
foreach(['email','rut','terms'] as $case){
    $key=str_repeat('Q',32);$hash=hash('sha256','minimarket-onboarding-v1|'.$key);$wpdb=new R1bRepositoryWpdb();
    $wpdb->rows[1]=['id'=>'1']+r1bRow('onb_'.str_repeat('1',40),$hash);$before=$wpdb->rows[1];
    $email=$case==='email'?'other@example.com':'owner@example.com';$rut=$case==='rut'?'1.000.005-K':'12.345.678-5';$terms=$case==='terms'?'2026-09':'2026-08';
    try{r1bService(new StoreOnboardingApplicationRepository(),new R1bClock(),new R1bIds(['onb_'.str_repeat('2',40)]),$terms)->execute(r1bCommand($email,$rut,$key));throw new RuntimeException("Conflicto no rechazado: {$case}");}
    catch(Throwable $exception){r1bAssertSanitized($exception,OnboardingConflictException::class,'idempotency_conflict',"intent_{$case}");r1bAssert($wpdb->insertCalls===1&&count($wpdb->rows)===1&&$wpdb->rows[1]===$before,"Conflicto altero Application: {$case}");}
}

// Every catalog state is replayable only with its structural invariants.
$stateFixtures=[
    'provisioning'=>[],
    'account_created'=>['user_id'=>'7'],
    'profile_incomplete'=>['user_id'=>'7'],
    'ready_to_materialize'=>['user_id'=>'7'],
    'provisioning_failed'=>['failure_code'=>'account_provisioning_failed'],
    'store_materialized'=>['user_id'=>'7','store_id'=>'10'],
    'abandoned'=>['abandoned_at'=>'2026-08-22 01:00:00'],
];
foreach($stateFixtures as $status=>$changes){
    $key=str_repeat('S',32);$hash=hash('sha256','minimarket-onboarding-v1|'.$key);$wpdb=new R1bRepositoryWpdb();$row=['id'=>'1']+r1bRow('onb_'.str_repeat('3',40),$hash,status:$status);$row=array_replace($row,$changes);$row['updated_at']='2026-08-23 02:00:00';$wpdb->rows[1]=$row;
    $result=r1bService(new StoreOnboardingApplicationRepository(),new R1bClock('2026-08-24 12:00:00'),new R1bIds(['onb_'.str_repeat('4',40)]))->execute(r1bCommand(key:$key));
    r1bAssert($result->publicId===$row['public_id']&&$result->createdAt===$row['created_at']&&$result->updatedAt===$row['updated_at']&&$wpdb->rows[1]['terms_accepted_at']===$row['terms_accepted_at'],"Replay de estado fallo: {$status}");
    r1bAssert($wpdb->insertCalls===1&&count($wpdb->rows)===1&&!str_contains(json_encode($result),'owner@example.com'),"Replay de estado altero datos: {$status}");
}

$invalidStates=[
    'user_missing'=>['status'=>'account_created'],
    'store_missing'=>['status'=>'store_materialized','user_id'=>'7'],
    'foreign_owner'=>['status'=>'store_materialized','user_id'=>'7','store_id'=>'10','foreign_owner'=>true],
    'failure_missing'=>['status'=>'provisioning_failed'],
    'abandoned_at_missing'=>['status'=>'abandoned'],
    'unknown'=>['status'=>'unknown_state'],
];
foreach($invalidStates as $case=>$changes){
    $key=str_repeat('T',32);$hash=hash('sha256','minimarket-onboarding-v1|'.$key);$wpdb=new R1bRepositoryWpdb();$row=['id'=>'1']+r1bRow('onb_'.str_repeat('5',40),$hash,status:$changes['status']);foreach(['user_id','store_id'] as $field)if(isset($changes[$field]))$row[$field]=$changes[$field];if($case==='foreign_owner')$wpdb->stores[10]=8;$wpdb->rows[1]=$row;
    try{r1bService(new StoreOnboardingApplicationRepository(),new R1bClock(),new R1bIds(['onb_'.str_repeat('6',40)]))->execute(r1bCommand(key:$key));throw new RuntimeException("Estado invalido aceptado: {$case}");}
    catch(Throwable $exception){r1bAssertSanitized($exception,OnboardingPersistenceException::class,'persistence_failed',"state_{$case}");}
}

$source=file_get_contents(dirname(__DIR__,2).'/app/Modules/Minimarket/Onboarding/Application/StartStoreOnboarding.php');
r1bAssert(is_string($source)&&!preg_match('/add_action|add_filter|register_rest_route|add_shortcode/',$source),'Servicio registro superficie publica.');
r1bAssert(!str_contains($source,'wp_insert_user')&&!str_contains($source,'wp_create_user')&&!str_contains($source,'owner_user_id')&&!str_contains($source,'_veciahorra_store_id'),'Servicio escribio autoridad diferida.');

echo "R1B_AUTOLOAD=PASS\nR1B_EMAIL=PASS cases=11\nR1B_RUT=PASS cases=12\nR1B_INPUT_PRECEDENCE=PASS\nR1B_TERMS_IDEMPOTENCY=PASS\nR1B_PUBLIC_ID=PASS\nR1B_CREATE_REPLAY=PASS\nR1B_REPOSITORY_ADAPTER=PASS\nR1B_PRIVACY_BOUNDARIES=PASS cases=10\nR1B_INTENT_CONFLICTS=PASS cases=3\nR1B_REPLAY_STATES=PASS cases=13\nR1B_NO_COMPOSITION=PASS\n";
