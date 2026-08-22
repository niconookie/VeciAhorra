<?php
declare(strict_types=1);
require_once dirname(__DIR__,5).'/wp-load.php';

use VeciAhorra\Modules\Minimarket\Identity\PendingMinimarketRole;
use VeciAhorra\Modules\Minimarket\Onboarding\Account\ActivatePendingMinimarketAccountResult;
use VeciAhorra\Modules\Minimarket\Onboarding\Account\MariaDbPendingAccountReconciliationSession;
use VeciAhorra\Modules\Minimarket\Onboarding\Account\PendingAccountActivationReceipt;
use VeciAhorra\Modules\Minimarket\Onboarding\Account\PendingAccountException;
use VeciAhorra\Modules\Minimarket\Onboarding\Account\PendingAccountReconciliationReader;
use VeciAhorra\Modules\Minimarket\Onboarding\Account\PendingAccountReconciliationSnapshot;
use VeciAhorra\Modules\Minimarket\Onboarding\Account\PendingUser;
use VeciAhorra\Modules\Minimarket\Onboarding\StoreOnboardingApplication;
use VeciAhorra\Modules\Minimarket\Onboarding\Verification\StoreOnboardingEmailVerification;

function r1dbCleanupAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

final class R1dbCleanupWpdb extends wpdb
{
    public array $releaseResults=[];public array $released=[];public int $releaseCalls=0;public bool $releaseSqlError=false;public bool $releaseThrows=false;public bool $rollbackFails=false;public bool $closeThrows=false;public int $closeCalls=0;
    public function __construct(){}
    public function prepare($query,...$args){return vsprintf(str_replace(['%s','%f'],["'%s'",'%F'],$query),$args);}
    public function query($query){$query=(string)$query;if($query==='ROLLBACK'&&$this->rollbackFails){$this->last_error='rollback_failed';return false;}return 1;}
    public function get_var($query=null,$x=0,$y=0){$query=(string)$query;if(str_contains($query,'@@in_transaction'))return '0';if(str_contains($query,'GET_LOCK'))return '1';if(str_contains($query,'RELEASE_LOCK')){$this->releaseCalls++;preg_match("/RELEASE_LOCK\\('([^']+)'\\)/",$query,$match);$this->released[]=$match[1]??'';if($this->releaseThrows&&$this->releaseCalls===1)throw new Error('hostile release secret');if($this->releaseSqlError&&$this->releaseCalls===1)$this->last_error='release sql secret';return $this->releaseResults!==[]?array_shift($this->releaseResults):'1';}return null;}
    public function close(){$this->closeCalls++;if($this->closeThrows)throw new Error('hostile close secret');return true;}
}

final class R1dbCleanupReader implements PendingAccountReconciliationReader
{
    public int $calls=0;public function __construct(private PendingAccountReconciliationSnapshot $snapshot,private string $mode='success'){}
    public function read(int $applicationId,int $userId):PendingAccountReconciliationSnapshot{$this->calls++;if($this->mode==='conflict')throw new PendingAccountException('pending_account_conflict');if($this->mode==='closed')throw new PendingAccountException('pending_account_creation_failed');if($this->mode==='hostile')throw new Error('hostile reader pii@example.test secret');return $this->snapshot;}
}

$userId=710001;$applicationId=720001;$login='va_mm_'.str_repeat('a',32);$email='cleanup@example.test';$fingerprint=str_repeat('f',64);$tokenHash=str_repeat('t',32);
$result=new ActivatePendingMinimarketAccountResult('onb_cleanup',StoreOnboardingApplication::ACCOUNT_CREATED,$userId,ActivatePendingMinimarketAccountResult::CREATED,'2026-01-01 00:00:00','2026-01-01 00:00:01');
$receipt=new PendingAccountActivationReceipt($result,$applicationId,$login,$fingerprint,1,$tokenHash);
$snapshot=new PendingAccountReconciliationSnapshot(['id'=>$applicationId,'user_id'=>$userId,'status'=>StoreOnboardingApplication::ACCOUNT_CREATED,'store_id'=>null,'failure_code'=>null,'account_email'=>$email],['purpose'=>StoreOnboardingEmailVerification::PURPOSE,'generation'=>1,'token_hash'=>$tokenHash,'candidate_user_id'=>$userId,'attached_user_id'=>$userId,'consumed_at'=>'2026-01-01 00:00:01'],new PendingUser($userId,$login,$email,[PendingMinimarketRole::ROLE],['read'=>true],$fingerprint),false,false,false);
$cases=[
    ['success',[],'success'],['replay',[],'success'],['conflict',[],'conflict'],['closed exception',[],'closed'],['hostile throwable',[],'hostile'],
    ['success first release fails',['releaseResults'=>['0','1','1']],'success'],['replay release fails',['releaseResults'=>['1','0','1']],'success'],['conflict release fails',['releaseResults'=>['1','1',null]],'conflict'],['prior exception release fails',['releaseResults'=>['0','1','1']],'closed'],['prior throwable release fails',['releaseResults'=>['0','1','1']],'hostile'],
    ['release zero',['releaseResults'=>['0','1','1']],'success'],['release null',['releaseResults'=>[null,'1','1']],'success'],['release sql error',['releaseSqlError'=>true],'success'],['release throwable',['releaseThrows'=>true],'success'],['multiple releases fail',['releaseResults'=>['0',null,'0']],'success'],['remaining releases attempted',['releaseResults'=>['0','1','1']],'success'],['read-only transaction does not end',['rollbackFails'=>true],'closed'],['close fails after proven cleanup',['closeThrows'=>true],'success'],['close fails with uncertain release',['closeThrows'=>true,'releaseResults'=>['0','1','1']],'success'],['previous is always null',['releaseThrows'=>true],'hostile'],
];
$seen=[];$passed=0;$locks=['va-r1db-c','va-r1db-a','va-r1db-b'];
foreach($cases as $index=>[$description,$configuration,$readerMode]){
    $id=$index+1;r1dbCleanupAssert(!isset($seen[$id]),'Duplicate cleanup case ID.');$seen[$id]=true;$db=new R1dbCleanupWpdb();foreach($configuration as $property=>$value)$db->{$property}=$value;$reader=new R1dbCleanupReader($snapshot,$readerMode);$reason=null;
    try{$actual=(new MariaDbPendingAccountReconciliationSession($db,$reader))->reconcile($locks,$receipt);r1dbCleanupAssert($actual===$receipt,'Receipt changed.');}catch(PendingAccountException $exception){$reason=$exception->reason;r1dbCleanupAssert($exception->getPrevious()===null&&!str_contains($exception->getMessage(),'hostile')&&!str_contains($exception->getMessage(),'secret'),'Cleanup exception leaked cause.');}
    $releaseUncertain=isset($configuration['releaseResults'])&&in_array(false,array_map(static fn($value)=>(string)$value==='1',$configuration['releaseResults']),true)||isset($configuration['releaseSqlError'])||isset($configuration['releaseThrows']);
    $expected=$releaseUncertain||isset($configuration['rollbackFails'])?'pending_account_outcome_uncertain':match($readerMode){'conflict'=>'pending_account_conflict','closed'=>'pending_account_creation_failed','hostile'=>'pending_account_outcome_uncertain',default=>null};
    r1dbCleanupAssert($reason===$expected,'Wrong exterior reason in case '.$id.': '.$description);r1dbCleanupAssert($db->releaseCalls===3,'Not every release attempted in case '.$id);r1dbCleanupAssert($db->released===array_reverse(['va-r1db-a','va-r1db-b','va-r1db-c']),'Release order changed in case '.$id);r1dbCleanupAssert($db->closeCalls===1,'Connection not closed in case '.$id);r1dbCleanupAssert($reader->calls===1,'Commercial reconciliation repeated in case '.$id);$passed++;
}
r1dbCleanupAssert($passed===20&&count($seen)===20,'Cleanup registry incomplete.');
echo 'R1DB_CLEANUP_PRECEDENCE='.$passed."/PASS\n";
