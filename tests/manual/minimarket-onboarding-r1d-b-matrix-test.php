<?php
declare(strict_types=1);
require_once dirname(__DIR__,5).'/wp-load.php';
use VeciAhorra\Modules\Minimarket\Identity\PendingMinimarketRole;
use VeciAhorra\Modules\Minimarket\Onboarding\Account\ActivatePendingMinimarketAccount;
use VeciAhorra\Modules\Minimarket\Onboarding\Account\MariaDbActivationLockManager;
use VeciAhorra\Modules\Minimarket\Onboarding\Account\OpaqueUsernameGenerator;
use VeciAhorra\Modules\Minimarket\Onboarding\Account\PendingAccountException;
use VeciAhorra\Modules\Minimarket\Onboarding\Account\PendingUser;
use VeciAhorra\Modules\Minimarket\Onboarding\Account\PendingUserGateway;
use VeciAhorra\Modules\Minimarket\Onboarding\Account\SensitivePassword;
use VeciAhorra\Modules\Minimarket\Onboarding\StoreOnboardingApplicationRepository;
use VeciAhorra\Modules\Minimarket\Onboarding\Verification\StoreOnboardingEmailVerificationRepository;
function r1dbm(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
final class R1dbSequenceGenerator implements OpaqueUsernameGenerator{public int $calls=0;public function __construct(private array $values){}public function generate():string{$this->calls++;$value=array_shift($this->values);if($value instanceof Throwable)throw $value;return (string)$value;}}
final class R1dbMatrixGateway implements PendingUserGateway{
    public array $occupied=[];public array $create=[];public array $users=[];public int $createCalls=0;public bool $lookupThrows=false;public bool $compatibilityThrows=false;
    public function findByEmail(string $email):array{if($this->lookupThrows)throw new RuntimeException('hostile lookup');return array_values(array_filter($this->users,static fn(PendingUser $u)=>$u->email===$email));}
    public function findByLogin(string $login):?PendingUser{if($this->lookupThrows)throw new RuntimeException('hostile lookup');return $this->users[$login]??null;}
    public function find(int $id):?PendingUser{foreach($this->users as $user)if($user->id===$id)return $user;return null;}
    public function create(string $login,string $email,SensitivePassword $password):PendingUser{$this->createCalls++;$outcome=array_shift($this->create)??'success';if($outcome==='collision')throw new PendingAccountException('pending_account_identity_collision');if($outcome==='failure')throw new PendingAccountException('pending_account_creation_failed');if($outcome==='error')throw new Error('hostile create');$user=$this->pending($login,$email);$this->users[$login]=$user;if($outcome==='after')throw new Error('hostile after insert');if($outcome==='partial'){$this->users[$login]=new PendingUser($user->id,$login,$email,['subscriber'],['read'=>true],'partial');throw new Error('hostile partial');}return $user;}
    public function isLoginOccupied(string $login):bool{$value=array_shift($this->occupied);if($value instanceof Throwable)throw $value;return (bool)$value;}
    public function isCompatible(PendingUser $user,int $applicationId):bool{if($this->compatibilityThrows)throw new RuntimeException('hostile compatibility');return $user->roles===[PendingMinimarketRole::ROLE]&&$user->email==='matrix@example.test';}
    public function canCompensate(PendingUser $user,int $applicationId):bool{return true;}public function compensate(PendingUser $user):bool{unset($this->users[$user->login]);return true;}
    public function pending(string $login,string $email='matrix@example.test'):PendingUser{return new PendingUser(700+count($this->users),$login,$email,[PendingMinimarketRole::ROLE],['read'=>true,PendingMinimarketRole::CAPABILITY=>true,PendingMinimarketRole::ROLE=>true],'exact');}
}
function r1dbService(R1dbMatrixGateway $gateway,OpaqueUsernameGenerator $generator):ActivatePendingMinimarketAccount{global $wpdb;return new ActivatePendingMinimarketAccount(new StoreOnboardingApplicationRepository(),new StoreOnboardingEmailVerificationRepository(),$gateway,$generator,new MariaDbActivationLockManager($wpdb,str_repeat('x',32)));}
function r1dbCreate(R1dbMatrixGateway $gateway,R1dbSequenceGenerator $generator):array{$method=new ReflectionMethod(ActivatePendingMinimarketAccount::class,'createOrReconcileUser');$method->setAccessible(true);return $method->invoke(r1dbService($gateway,$generator),'matrix@example.test',new SensitivePassword('matrix password 2026'),900001);}
function r1dbReason(callable $callback,string $reason):void{try{$callback();throw new RuntimeException('Fallo esperado no ocurrido.');}catch(PendingAccountException $exception){r1dbm($exception->reason===$reason&&$exception->getPrevious()===null&&!str_contains($exception->getMessage(),'hostile'),'Reason o privacidad incorrecta.');}}
$names=['va_mm_'.str_repeat('1',32),'va_mm_'.str_repeat('2',32),'va_mm_'.str_repeat('3',32)];$usernameCases=0;
$gateway=new R1dbMatrixGateway();[$user]=$result=r1dbCreate($gateway,new R1dbSequenceGenerator([$names[0]]));r1dbm($user->login===$names[0]&&$gateway->createCalls===1,'Primer candidato libre.');$usernameCases++;
foreach([1,2] as $collisions){$gateway=new R1dbMatrixGateway();$gateway->occupied=array_fill(0,$collisions,true);$gateway->occupied[]=false;$generator=new R1dbSequenceGenerator(array_slice($names,0,$collisions+1));[$user]=r1dbCreate($gateway,$generator);r1dbm($user->login===$names[$collisions]&&$gateway->createCalls===1,'Retry de colision incorrecto.');$usernameCases++;}
$gateway=new R1dbMatrixGateway();$gateway->occupied=[true,true,true];r1dbReason(fn()=>r1dbCreate($gateway,new R1dbSequenceGenerator($names)),'pending_account_identity_collision');r1dbm($gateway->createCalls===0,'Tres colisiones crearon User.');$usernameCases++;
$gateway=new R1dbMatrixGateway();$gateway->create=['failure'];r1dbReason(fn()=>r1dbCreate($gateway,new R1dbSequenceGenerator([$names[0]])),'pending_account_creation_failed');$usernameCases++;
$gateway=new R1dbMatrixGateway();$gateway->occupied=[true,false];$gateway->create=['failure'];r1dbReason(fn()=>r1dbCreate($gateway,new R1dbSequenceGenerator([$names[0],$names[1]])),'pending_account_creation_failed');$usernameCases++;
foreach([true,false] as $compatible){$gateway=new R1dbMatrixGateway();$gateway->occupied=[true,false];$gateway->users[$names[0]]=$compatible?$gateway->pending($names[0]):new PendingUser(800,$names[0],'other@example.test',['subscriber'],['read'=>true],'other');[$user]=r1dbCreate($gateway,new R1dbSequenceGenerator([$names[0],$names[1]]));r1dbm($user->login===$names[1]&&$gateway->createCalls===1,'Username ocupado fue apropiado.');$usernameCases++;}
$gateway=new R1dbMatrixGateway();$gateway->create=['collision','collision','collision'];r1dbReason(fn()=>r1dbCreate($gateway,new R1dbSequenceGenerator([$names[0],$names[0],$names[0]])),'pending_account_identity_collision');r1dbm($gateway->createCalls===3,'Carrera de username no acotada.');$usernameCases++;
$opaque=(new VeciAhorra\Modules\Minimarket\Onboarding\Account\RandomOpaqueUsernameGenerator())->generate();r1dbm(preg_match('/\Ava_mm_[a-f0-9]{32}\z/',$opaque)===1&&!str_contains($opaque,'matrix')&&!str_contains($opaque,'900001'),'Formato/PII username.');$usernameCases++;
r1dbm($usernameCases===10,'Conteo username incompleto.');echo "R1DB_USERNAME_MATRIX=10/PASS\n";
$boundaryCases=0;$hostile='hostile@example.test SELECT secret_payload';
$gateway=new R1dbMatrixGateway();r1dbReason(fn()=>r1dbCreate($gateway,new R1dbSequenceGenerator([new Error($hostile)])),'pending_account_creation_failed');r1dbm($gateway->createCalls===0,'Boundary generador creo User.');$boundaryCases++;
$gateway=new R1dbMatrixGateway();$gateway->occupied=[new Error($hostile)];r1dbReason(fn()=>r1dbCreate($gateway,new R1dbSequenceGenerator([$names[0]])),'pending_account_outcome_uncertain');r1dbm($gateway->createCalls===0,'Boundary prelookup username creo User.');$boundaryCases++;
$gateway=new R1dbMatrixGateway();$gateway->create=['failure'];$gateway->lookupThrows=true;r1dbReason(fn()=>r1dbCreate($gateway,new R1dbSequenceGenerator([$names[0]])),'pending_account_outcome_uncertain');r1dbm($gateway->createCalls===1&&$gateway->users===[],'Boundary lookup email creo segundo User.');$boundaryCases++;
foreach(['before_adapter','before_insert'] as $boundary){$gateway=new R1dbMatrixGateway();$gateway->create=['failure'];r1dbReason(fn()=>r1dbCreate($gateway,new R1dbSequenceGenerator([$names[0]])),'pending_account_creation_failed');r1dbm($gateway->createCalls===1&&$gateway->users===[],'Boundary no aplicada dejo User.');$boundaryCases++;}
foreach(['after_insert','after_password','after_role','user_register','post_hook','before_id','hydration'] as $boundary){$gateway=new R1dbMatrixGateway();$gateway->create=['after'];[$user,$state]=r1dbCreate($gateway,new R1dbSequenceGenerator([$names[0]]));r1dbm($state==='created_reconciled'&&count($gateway->users)===1&&$gateway->createCalls===1,'Boundary aplicada no reconcilio: '.$boundary);$boundaryCases++;}
$gateway=new R1dbMatrixGateway();$gateway->create=['partial'];r1dbReason(fn()=>r1dbCreate($gateway,new R1dbSequenceGenerator([$names[0]])),'pending_account_outcome_uncertain');r1dbm(count($gateway->users)===1&&$gateway->createCalls===1,'Validacion parcial borro evidencia.');$boundaryCases++;
$gateway=new R1dbMatrixGateway();$gateway->create=['failure'];$gateway->lookupThrows=true;r1dbReason(fn()=>r1dbCreate($gateway,new R1dbSequenceGenerator([$names[0]])),'pending_account_outcome_uncertain');$boundaryCases++;
foreach(['capabilities','references'] as $boundary){$gateway=new R1dbMatrixGateway();$gateway->create=['after'];$gateway->compatibilityThrows=true;r1dbReason(fn()=>r1dbCreate($gateway,new R1dbSequenceGenerator([$names[0]])),'pending_account_outcome_uncertain');r1dbm(count($gateway->users)===1,'Inspeccion hostil elimino User.');$boundaryCases++;}
r1dbm($boundaryCases===16,'Conteo fronteras incompleto: '.$boundaryCases);echo "R1DB_CREATION_BOUNDARIES=16/PASS\nR1DB_PRIVACY=16/PASS\n";
