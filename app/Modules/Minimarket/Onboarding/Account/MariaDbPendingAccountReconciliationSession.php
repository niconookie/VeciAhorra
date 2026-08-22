<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Minimarket\Onboarding\Account;
use VeciAhorra\Modules\Minimarket\Identity\PendingMinimarketRole;
use VeciAhorra\Modules\Minimarket\Onboarding\StoreOnboardingApplication;
use VeciAhorra\Modules\Minimarket\Onboarding\Verification\StoreOnboardingEmailVerification;
final class MariaDbPendingAccountReconciliationSession implements PendingAccountReconciliationSession
{
    public function __construct(private \wpdb $database,private PendingAccountReconciliationReader $reader){}
    public function reconcile(array $lockNames,PendingAccountActivationReceipt $receipt):PendingAccountActivationReceipt
    {
        $acquired=[];$transaction=false;$snapshot=null;$primaryReason=null;$transactionUncertain=false;$releaseFailed=false;
        try{
            $locks=array_values(array_unique($lockNames));sort($locks,SORT_STRING);if(count($locks)<3||count($locks)>4)throw new PendingAccountException('pending_account_outcome_uncertain');
            foreach($locks as $lock){if(!is_string($lock)||!str_starts_with($lock,'va-r1db-'))throw new PendingAccountException('pending_account_outcome_uncertain');$this->database->last_error='';$value=$this->database->get_var($this->database->prepare('SELECT GET_LOCK(%s, %f)',$lock,1.0));if($this->database->last_error!==''||(string)$value!=='1')throw new PendingAccountException('pending_account_outcome_uncertain');$acquired[]=$lock;}
            $this->database->last_error='';$state=$this->database->get_var('SELECT @@in_transaction');if($this->database->last_error!==''||(string)$state!=='0')throw new PendingAccountException('pending_account_outcome_uncertain');
            $this->database->last_error='';if($this->database->query('SET TRANSACTION READ ONLY')===false||$this->database->last_error!=='')throw new PendingAccountException('pending_account_outcome_uncertain');
            $transaction=true;$this->database->last_error='';if($this->database->query('START TRANSACTION WITH CONSISTENT SNAPSHOT')===false||$this->database->last_error!=='')throw new PendingAccountException('pending_account_outcome_uncertain');
            $snapshot=$this->reader->read($receipt->applicationId(),$receipt->result()->userId);$this->assertExact($snapshot,$receipt);
            if($this->database->query('COMMIT')===false||$this->database->last_error!=='')throw new PendingAccountException('pending_account_outcome_uncertain');$transaction=false;
        }catch(PendingAccountException $exception){$primaryReason=$exception->reason;unset($exception);}catch(\Throwable $throwable){$primaryReason='pending_account_outcome_uncertain';unset($throwable);}
        if($transaction){try{$this->database->last_error='';$rolledBack=$this->database->query('ROLLBACK');if($rolledBack===false||$this->database->last_error!=='')$transactionUncertain=true;}catch(\Throwable){$transactionUncertain=true;}}
        foreach(array_reverse($acquired) as $lock){try{$this->database->last_error='';$released=$this->database->get_var($this->database->prepare('SELECT RELEASE_LOCK(%s)',$lock));if($this->database->last_error!==''||(string)$released!=='1')$releaseFailed=true;}catch(\Throwable){$releaseFailed=true;}}
        try{$this->database->close();}catch(\Throwable){}
        if($transactionUncertain||$releaseFailed)throw new PendingAccountException('pending_account_outcome_uncertain');
        if($primaryReason!==null)throw new PendingAccountException($primaryReason);
        if(!$snapshot instanceof PendingAccountReconciliationSnapshot)throw new PendingAccountException('pending_account_outcome_uncertain');
        return $receipt;
    }
    private function assertExact(PendingAccountReconciliationSnapshot $snapshot,PendingAccountActivationReceipt $receipt):void
    {
        $app=$snapshot->application;$verification=$snapshot->verification;$user=$snapshot->user;$result=$receipt->result();
        $exact=(int)$app['id']===$receipt->applicationId()&&(int)$app['user_id']===$result->userId&&$app['status']===StoreOnboardingApplication::ACCOUNT_CREATED&&$app['store_id']===null&&$app['failure_code']===null&&$verification['purpose']===StoreOnboardingEmailVerification::PURPOSE&&(int)$verification['generation']===$receipt->generation()&&hash_equals((string)$verification['token_hash'],$receipt->tokenHash())&&(int)$verification['candidate_user_id']===$result->userId&&(int)$verification['attached_user_id']===$result->userId&&$verification['consumed_at']!==null&&$user->id===$result->userId&&$user->login===$receipt->expectedLogin()&&hash_equals($user->integrityFingerprint,$receipt->expectedFingerprint())&&$user->email===(string)$app['account_email']&&$user->roles===[PendingMinimarketRole::ROLE]&&!$snapshot->hasStore&&!$snapshot->hasStoreMeta&&!$snapshot->hasOtherApplication;
        if(!$exact)throw new PendingAccountException('pending_account_conflict');
    }
}
