<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Minimarket\Onboarding\Account;
use Throwable;
use VeciAhorra\Modules\Minimarket\Onboarding\StoreOnboardingApplication;
use VeciAhorra\Modules\Minimarket\Onboarding\StoreOnboardingApplicationRepository;
use VeciAhorra\Modules\Minimarket\Onboarding\Verification\StoreOnboardingEmailVerification;
use VeciAhorra\Modules\Minimarket\Onboarding\Verification\StoreOnboardingEmailVerificationRepository;
final class ActivatePendingMinimarketAccount
{
    public function __construct(private StoreOnboardingApplicationRepository $applications,private StoreOnboardingEmailVerificationRepository $verifications,private PendingUserGateway $users,private OpaqueUsernameGenerator $usernames,private ActivationLockManager $locks,private ?PendingAccountReconciliationConnectionFactory $reconciliationConnections=null){}
    public function execute(ActivatePendingMinimarketAccountCommand $command):ActivatePendingMinimarketAccountResult
    {
        StoreOnboardingEmailVerification::timestamp($command->now);
        $application=$this->applications->findByApplicationId($command->applicationId)??throw new PendingAccountException('verification_unavailable');
        $email=(string)$application->data['account_email'];$rut=(string)$application->data['owner_rut_normalized'];
        $receipt=$this->locks->synchronized(
            ['application'=>(string)$command->applicationId,'email'=>$email,'rut'=>$rut,'verification'=>hash('sha256',$command->tokenHash)],
            fn()=>$this->activateLocked($command),
            function(PendingAccountActivationReceipt $receipt,array $lockNames,?int $originalConnectionId):PendingAccountActivationReceipt{if($this->reconciliationConnections===null)throw new PendingAccountException('pending_account_outcome_uncertain');return $this->reconciliationConnections->open($originalConnectionId)->reconcile($lockNames,$receipt);}
        );
        if(!$receipt instanceof PendingAccountActivationReceipt)throw new PendingAccountException('pending_account_outcome_uncertain');return $receipt->result();
    }
    private function activateLocked(ActivatePendingMinimarketAccountCommand $command):PendingAccountActivationReceipt
    {
        $app=$this->applications->findByApplicationId($command->applicationId)??throw new PendingAccountException('verification_unavailable');
        $v=$this->verifications->findByApplicationId($command->applicationId)??throw new PendingAccountException('verification_unavailable');
        if($v->purpose!==StoreOnboardingEmailVerification::PURPOSE||$v->generation!==$command->generation||!hash_equals($v->tokenHash,$command->tokenHash))throw new PendingAccountException('verification_unavailable');
        if($v->expiresAt<=$command->now)throw new PendingAccountException('verification_expired');if($v->failedAttempts>=5)throw new PendingAccountException('verification_attempts_exhausted');
        if($v->consumedAt!==null)return $this->replay($app,$v,$command);
        if($app->data['status']!==StoreOnboardingApplication::PROVISIONING||$app->data['user_id']!==null||$app->data['store_id']!==null||$v->deliveryState!==StoreOnboardingEmailVerification::SENT)throw new PendingAccountException('pending_account_conflict');
        if($v->candidateUserId!==null){$candidate=$this->users->find($v->candidateUserId);if($candidate===null)throw new PendingAccountException('pending_account_conflict');if($candidate->email!==(string)$app->data['account_email'])throw new PendingAccountException('pending_account_incompatible');}
        $matches=$this->users->findByEmail((string)$app->data['account_email']);if(count($matches)>1)throw new PendingAccountException('pending_account_conflict');
        $state='not_created';$user=$matches[0]??null;$bound=false;
        try{
            if($user===null){[$user,$state]=$this->createOrReconcileUser((string)$app->data['account_email'],$command->password,$command->applicationId);}
            else{$state='existing_compatible';if(!$this->users->isCompatible($user,$command->applicationId))throw new PendingAccountException('pending_account_incompatible');}
            if($v->candidateUserId!==null&&$v->candidateUserId!==$user->id)throw new PendingAccountException('pending_account_conflict');
            $bound=$v->candidateUserId===$user->id;
            if($v->candidateUserId===null){$v=$this->verifications->bindCandidateUser($command->applicationId,$command->generation,$user->id,$v->updatedAt,$command->now);$bound=true;}
            $this->verifications->consumeAndAttach($command->applicationId,$command->generation,$command->tokenHash,$user->id,(string)$app->data['updated_at'],$v->updatedAt,$command->now,fn(int $id,string $email,?int $candidate):bool=>$id===$user->id&&$email===$user->email&&($candidate===null||$candidate===$id)&&$this->users->isCompatible($user,$command->applicationId));
            $final=$this->applications->findByApplicationId($command->applicationId)??throw new PendingAccountException('pending_account_outcome_uncertain');
            $result=new ActivatePendingMinimarketAccountResult((string)$final->data['public_id'],(string)$final->data['status'],$user->id,in_array($state,['created_confirmed','created_reconciled'],true)?ActivatePendingMinimarketAccountResult::CREATED:ActivatePendingMinimarketAccountResult::RECOVERED,(string)$final->data['created_at'],(string)$final->data['updated_at']);
            $result=$this->reconcileResult($command->applicationId,$result);$freshUser=$this->users->find($user->id)??throw new PendingAccountException('pending_account_outcome_uncertain');return new PendingAccountActivationReceipt($result,$command->applicationId,$freshUser->login,$freshUser->integrityFingerprint,$command->generation,$command->tokenHash);
        }catch(Throwable $e){
            if($state==='creation_uncertain'||$bound||($e instanceof PendingAccountException&&$e->reason==='pending_account_outcome_uncertain'))throw new PendingAccountException('pending_account_outcome_uncertain');
            if(in_array($state,['created_confirmed','created_reconciled'],true)&&$user instanceof PendingUser){try{$allowed=$this->users->canCompensate($user,$command->applicationId);}catch(Throwable){throw new PendingAccountException('pending_account_outcome_uncertain');}if(!$allowed)throw new PendingAccountException('pending_account_outcome_uncertain');try{$compensated=$this->users->compensate($user);}catch(Throwable){throw new PendingAccountException('pending_account_compensation_failed');}if(!$compensated)throw new PendingAccountException('pending_account_compensation_failed');}
            if($e instanceof PendingAccountException&&in_array($e->reason,['pending_account_creation_failed','pending_account_identity_collision','pending_account_incompatible'],true))throw new PendingAccountException($e->reason);
            throw new PendingAccountException('pending_account_conflict');
        }
    }
    /** @return array{PendingUser,string} */
    private function createOrReconcileUser(string $email,SensitivePassword $password,int $applicationId):array
    {
        for($i=0;$i<3;$i++){
            try{$login=$this->usernames->generate();}catch(Throwable){throw new PendingAccountException('pending_account_creation_failed');}
            if(!preg_match('/\Ava_mm_[a-f0-9]{32}\z/',$login))throw new PendingAccountException('pending_account_creation_failed');
            try{if($this->users->isLoginOccupied($login))continue;}catch(Throwable){throw new PendingAccountException('pending_account_outcome_uncertain');}
            try{$user=$this->users->create($login,$email,$password);if($user->login!==$login||$user->email!==$email||!$this->users->isCompatible($user,$applicationId))throw new PendingAccountException('pending_account_outcome_uncertain');return [$user,'created_confirmed'];}
            catch(Throwable $creationFailure){
                try{$byLogin=$this->users->findByLogin($login);$byEmail=$this->users->findByEmail($email);$compatible=$byLogin!==null&&$this->users->isCompatible($byLogin,$applicationId);}catch(Throwable){throw new PendingAccountException('pending_account_outcome_uncertain');}
                if($byLogin!==null&&count($byEmail)===1&&$byEmail[0]->id===$byLogin->id&&$byLogin->login===$login&&$byLogin->email===$email&&$compatible)return [$byLogin,'created_reconciled'];
                if($creationFailure instanceof PendingAccountException&&$creationFailure->reason==='pending_account_identity_collision'&&$byLogin!==null&&count($byEmail)===0)continue;
                if($byLogin!==null||count($byEmail)>0)throw new PendingAccountException('pending_account_outcome_uncertain');
                if($creationFailure instanceof PendingAccountException&&$creationFailure->reason==='pending_account_identity_collision')continue;
                throw new PendingAccountException('pending_account_creation_failed');
            }
        }
        throw new PendingAccountException('pending_account_identity_collision');
    }
    private function reconcileResult(int $applicationId,ActivatePendingMinimarketAccountResult $result):ActivatePendingMinimarketAccountResult
    {
        try{$app=$this->applications->findByApplicationId($applicationId);$v=$this->verifications->findByApplicationId($applicationId);$user=$this->users->find($result->userId);}catch(Throwable){throw new PendingAccountException('pending_account_outcome_uncertain');}
        if($app===null||$v===null||$user===null)throw new PendingAccountException('pending_account_outcome_uncertain');
        if((int)$app->data['user_id']!==$result->userId||$app->data['status']!==StoreOnboardingApplication::ACCOUNT_CREATED||$app->data['store_id']!==null||$v->attachedUserId!==$result->userId||$v->candidateUserId!==$result->userId||$v->consumedAt===null||$user->email!==(string)$app->data['account_email']||!$this->users->isCompatible($user,$applicationId))throw new PendingAccountException('pending_account_conflict');
        return $result;
    }
    private function replay(StoreOnboardingApplication $app,StoreOnboardingEmailVerification $v,ActivatePendingMinimarketAccountCommand $command):PendingAccountActivationReceipt
    {
        $uid=(int)($app->data['user_id']??0);if($uid<1||$v->attachedUserId!==$uid||$app->data['status']!==StoreOnboardingApplication::ACCOUNT_CREATED||$app->data['store_id']!==null)throw new PendingAccountException('pending_account_conflict');
        $user=$this->users->find($uid);if($user===null||$user->email!==(string)$app->data['account_email']||!$this->users->isCompatible($user,(int)$app->data['id']))throw new PendingAccountException('pending_account_incompatible');
        $result=new ActivatePendingMinimarketAccountResult((string)$app->data['public_id'],(string)$app->data['status'],$uid,ActivatePendingMinimarketAccountResult::REPLAYED,(string)$app->data['created_at'],(string)$app->data['updated_at']);return new PendingAccountActivationReceipt($result,(int)$app->data['id'],$user->login,$user->integrityFingerprint,$command->generation,$command->tokenHash);
    }
}
