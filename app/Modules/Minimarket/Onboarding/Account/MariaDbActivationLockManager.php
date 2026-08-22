<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Minimarket\Onboarding\Account;
final class MariaDbActivationLockManager implements ActivationLockManager
{
    public function __construct(private \wpdb $database,private string $secret){}
    public function synchronized(array $identities,callable $criticalSection,?callable $reconcileAfterReleaseFailure=null):mixed
    {
        if(strlen($this->secret)<32||count($identities)<3||count($identities)>4)throw new PendingAccountException('pending_account_lock_unavailable');
        $locks=[];foreach($identities as $domain=>$identity){if(!is_string($domain)||!is_string($identity)||$identity==='')throw new PendingAccountException('pending_account_lock_unavailable');$locks[]='va-r1db-'.hash_hmac('sha256',$domain."\0".$identity,$this->secret);}
        $locks=array_values(array_unique($locks));sort($locks,SORT_STRING);$acquired=[];$result=null;$completed=false;$failure=null;$originalConnectionId=null;
        try{foreach($locks as $lock){$this->database->last_error='';$ok=$this->database->get_var($this->database->prepare('SELECT GET_LOCK(%s, %f)',$lock,1.0));if($this->database->last_error!==''||(string)$ok!=='1')throw new PendingAccountException('pending_account_lock_unavailable');$acquired[]=$lock;}$this->database->last_error='';$connectionId=$this->database->get_var('SELECT CONNECTION_ID()');if($this->database->last_error!==''||!preg_match('/\A\d+\z/',(string)$connectionId)||(int)$connectionId<1)throw new PendingAccountException('pending_account_lock_unavailable');$originalConnectionId=(int)$connectionId;$result=$criticalSection();$completed=true;}catch(\Throwable $throwable){$failure=$throwable;}
        $releaseFailed=false;foreach(array_reverse($acquired) as $lock){$released=false;for($attempt=0;$attempt<2&&!$released;$attempt++){try{$this->database->last_error='';$ok=$this->database->get_var($this->database->prepare('SELECT RELEASE_LOCK(%s)',$lock));$released=$this->database->last_error===''&&(string)$ok==='1';}catch(\Throwable){$released=false;}}if(!$released)$releaseFailed=true;}
        if($completed&&$releaseFailed){if($reconcileAfterReleaseFailure===null)throw new PendingAccountException('pending_account_outcome_uncertain');try{$result=$reconcileAfterReleaseFailure($result,$locks,$originalConnectionId);}catch(PendingAccountException $exception){throw new PendingAccountException($exception->reason);}catch(\Throwable){throw new PendingAccountException('pending_account_outcome_uncertain');}}
        if(!$completed){if($failure instanceof PendingAccountException){if($releaseFailed&&$failure->reason==='pending_account_lock_unavailable')throw new PendingAccountException('pending_account_lock_unavailable');throw new PendingAccountException($failure->reason);}throw new PendingAccountException($acquired===[]?'pending_account_lock_unavailable':'pending_account_outcome_uncertain');}
        return $result;
    }
}
