<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\Verification;

use InvalidArgumentException;
use RuntimeException;
use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Minimarket\Onboarding\StoreOnboardingApplication;
use VeciAhorra\Modules\Minimarket\Onboarding\StoreOnboardingApplicationRepository;

final class StoreOnboardingEmailVerificationRepository
{
    public function findById(int $id): ?StoreOnboardingEmailVerification{return $this->find('id',$id);}
    public function findByApplicationId(int $id): ?StoreOnboardingEmailVerification{return $this->find('application_id',$id);}
    public function findByTokenHash(string $hash): ?StoreOnboardingEmailVerification{$this->hash($hash);return $this->find('token_hash',$hash);}

    public function create(int $applicationId,string $purpose,?int $candidateUserId,string $emailHash,string $tokenHash,string $expiresAt,string $now,?callable $candidateCompatible=null): StoreOnboardingEmailVerification
    {
        $this->intent($applicationId,$purpose,$candidateUserId,$emailHash,$tokenHash,$expiresAt,$now);
        return $this->transaction(function()use($applicationId,$purpose,$candidateUserId,$emailHash,$tokenHash,$expiresAt,$now,$candidateCompatible){
            $this->eligibleApplication($applicationId);
            $existing=$this->findLockedByApplication($applicationId);
            if($existing!==null){if($existing->purpose===$purpose&&$existing->generation===1&&$existing->candidateUserId===$candidateUserId&&hash_equals($existing->emailBindingHash,$emailHash)&&hash_equals($existing->tokenHash,$tokenHash)&&$existing->expiresAt===$expiresAt)return $existing;throw new RuntimeException('verification_create_conflict');}
            if($candidateUserId!==null){global $wpdb;if((int)$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE ID=%d FOR UPDATE",$candidateUserId))!==$candidateUserId||$candidateCompatible===null||!$this->compatible($candidateCompatible,[$candidateUserId]))throw new RuntimeException('verification_candidate_user_incompatible');}
            global $wpdb;$ok=$wpdb->insert($this->table(),['application_id'=>$applicationId,'purpose'=>$purpose,'generation'=>1,'candidate_user_id'=>$candidateUserId,'attached_user_id'=>null,'email_binding_hash'=>$emailHash,'token_hash'=>$tokenHash,'expires_at'=>$expiresAt,'consumed_at'=>null,'failed_attempts'=>0,'resend_count'=>0,'last_sent_at'=>null,'delivery_state'=>StoreOnboardingEmailVerification::PENDING,'delivery_attempt_count'=>0,'last_error_code'=>null,'created_at'=>$now,'updated_at'=>$now]);
            if($ok!==1)throw new RuntimeException('verification_create_failed');return $this->findLockedByApplication($applicationId)??throw new RuntimeException('verification_outcome_uncertain');
        });
    }

    public function rotate(int $applicationId,int $expectedGeneration,string $expectedUpdatedAt,string $tokenHash,string $expiresAt,string $now): StoreOnboardingEmailVerification
    {
        $this->hash($tokenHash);StoreOnboardingEmailVerification::timestamp($expiresAt);$this->advance($expectedUpdatedAt,$now);
        if($expiresAt<=$now)throw new InvalidArgumentException('verification_invalid_expiry');
        return $this->transaction(function()use($applicationId,$expectedGeneration,$expectedUpdatedAt,$tokenHash,$expiresAt,$now){
            $this->eligibleApplication($applicationId);$v=$this->findLockedByApplication($applicationId)??throw new RuntimeException('verification_not_found');
            if($v->consumedAt!==null||$v->generation===PHP_INT_MAX||$v->resendCount>=65535)throw new RuntimeException('verification_rotation_forbidden');
            if($v->generation===$expectedGeneration+1&&hash_equals($v->tokenHash,$tokenHash)&&$v->expiresAt===$expiresAt)return $v;
            if($v->generation!==$expectedGeneration||$v->updatedAt!==$expectedUpdatedAt)throw new RuntimeException('verification_concurrent_modification');
            global $wpdb;$changed=$wpdb->update($this->table(),['generation'=>$expectedGeneration+1,'token_hash'=>$tokenHash,'expires_at'=>$expiresAt,'consumed_at'=>null,'attached_user_id'=>null,'failed_attempts'=>0,'resend_count'=>$v->resendCount+1,'last_sent_at'=>null,'delivery_state'=>StoreOnboardingEmailVerification::PENDING,'delivery_attempt_count'=>0,'last_error_code'=>null,'updated_at'=>$now],['id'=>$v->id,'generation'=>$expectedGeneration,'updated_at'=>$expectedUpdatedAt]);
            if($changed!==1)throw new RuntimeException('verification_concurrent_modification');return $this->findLockedByApplication($applicationId)??throw new RuntimeException('verification_outcome_uncertain');
        });
    }

    public function markDeliveryAttempt(int $applicationId,int $generation,string $expectedUpdatedAt,string $now): StoreOnboardingEmailVerification{return $this->mutate($applicationId,$generation,$expectedUpdatedAt,$now,function($v){if($v->consumedAt!==null||$v->deliveryAttemptCount>=65535)throw new RuntimeException('verification_delivery_forbidden');return ['delivery_attempt_count'=>$v->deliveryAttemptCount+1];});}
    public function markSent(int $a,int $g,string $e,string $now): StoreOnboardingEmailVerification{return $this->delivery($a,$g,$e,$now,StoreOnboardingEmailVerification::SENT,null);}
    public function markFailed(int $a,int $g,string $e,string $now): StoreOnboardingEmailVerification{return $this->delivery($a,$g,$e,$now,StoreOnboardingEmailVerification::FAILED,StoreOnboardingEmailVerification::DELIVERY_FAILED);}
    public function markUncertain(int $a,int $g,string $e,string $now): StoreOnboardingEmailVerification{return $this->delivery($a,$g,$e,$now,StoreOnboardingEmailVerification::UNCERTAIN,StoreOnboardingEmailVerification::DELIVERY_UNCERTAIN);}

    public function recordInvalidAttempt(int $applicationId,int $generation,string $expectedUpdatedAt,string $now): StoreOnboardingEmailVerification
    {
        return $this->mutate($applicationId,$generation,$expectedUpdatedAt,$now,function($v)use($now){if($v->consumedAt!==null||$v->expiresAt<=$now)throw new RuntimeException('verification_attempt_forbidden');if($v->failedAttempts>=5)return [];return ['failed_attempts'=>$v->failedAttempts+1];});
    }

    public function consumeAndAttach(int $applicationId,int $generation,string $tokenHash,int $userId,string $expectedApplicationUpdatedAt,string $expectedVerificationUpdatedAt,string $now,callable $compatible): StoreOnboardingEmailVerification
    {
        $this->hash($tokenHash);if($userId<=0)throw new InvalidArgumentException('verification_invalid_user');StoreOnboardingEmailVerification::timestamp($now);
        return $this->transaction(function()use($applicationId,$generation,$tokenHash,$userId,$expectedApplicationUpdatedAt,$expectedVerificationUpdatedAt,$now,$compatible){
            global $wpdb;$app=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->applicationsTable()} WHERE id=%d FOR UPDATE",$applicationId),ARRAY_A);if(!is_array($app)||$wpdb->last_error!=='')throw new RuntimeException('verification_reference_invalid');
            $v=$this->findLockedByApplication($applicationId)??throw new RuntimeException('verification_not_found');
            if($v->generation!==$generation||!hash_equals($v->tokenHash,$tokenHash))throw new RuntimeException('verification_consumption_forbidden');
            if($v->consumedAt!==null){if($v->attachedUserId===$userId&&(int)($app['user_id']??0)===$userId&&$app['status']===StoreOnboardingApplication::ACCOUNT_CREATED)return $v;throw new RuntimeException('verification_consumption_conflict');}
            if($v->updatedAt!==$expectedVerificationUpdatedAt||$v->expiresAt<=$now||$v->failedAttempts>=5||$v->deliveryState!==StoreOnboardingEmailVerification::SENT||$app['store_id']!==null)throw new RuntimeException('verification_consumption_forbidden');
            if((int)$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE ID=%d FOR UPDATE",$userId))!==$userId||!$this->compatible($compatible,[$userId,(string)$app['account_email'],$v->candidateUserId]))throw new RuntimeException('verification_user_incompatible');
            $application=(new StoreOnboardingApplicationRepository())->attachUserInTransaction($applicationId,$userId,$expectedApplicationUpdatedAt,$now);
            $changed=$wpdb->update($this->table(),['consumed_at'=>$now,'attached_user_id'=>$userId,'last_error_code'=>null,'updated_at'=>$now],['id'=>$v->id,'generation'=>$generation,'updated_at'=>$expectedVerificationUpdatedAt,'consumed_at'=>null]);
            if($changed!==1||(int)$application->data['user_id']!==$userId)throw new RuntimeException('verification_concurrent_modification');return $this->findLockedByApplication($applicationId)??throw new RuntimeException('verification_outcome_uncertain');
        });
    }

    private function delivery(int $a,int $g,string $e,string $now,string $state,?string $error):StoreOnboardingEmailVerification{return $this->mutate($a,$g,$e,$now,function($v)use($state,$error,$now){if($v->deliveryAttemptCount<1||$v->consumedAt!==null)throw new RuntimeException('verification_delivery_forbidden');return ['delivery_state'=>$state,'last_sent_at'=>$state===StoreOnboardingEmailVerification::SENT?$now:null,'last_error_code'=>$error];});}
    private function mutate(int $a,int $g,string $e,string $now,callable $change):StoreOnboardingEmailVerification{$this->advance($e,$now);return $this->transaction(function()use($a,$g,$e,$now,$change){$this->eligibleApplication($a);$v=$this->findLockedByApplication($a)??throw new RuntimeException('verification_not_found');if($v->generation!==$g||$v->updatedAt!==$e)throw new RuntimeException('verification_concurrent_modification');$data=$change($v);if($data===[])return $v;global $wpdb;$data['updated_at']=$now;$n=$wpdb->update($this->table(),$data,['id'=>$v->id,'generation'=>$g,'updated_at'=>$e]);if($n!==1)throw new RuntimeException('verification_concurrent_modification');return $this->findLockedByApplication($a)??throw new RuntimeException('verification_outcome_uncertain');});}
    private function transaction(callable $work):mixed{global $wpdb;if($wpdb->query('START TRANSACTION')===false)throw new RuntimeException('verification_transaction_failed');try{$r=$work();if($wpdb->query('COMMIT')===false)throw new RuntimeException('verification_commit_failed');return $r;}catch(\Throwable $e){$wpdb->query('ROLLBACK');throw $e;}}
    private function eligibleApplication(int $id):array{global $wpdb;$r=$wpdb->get_row($wpdb->prepare("SELECT id,status,user_id,store_id FROM {$this->applicationsTable()} WHERE id=%d FOR UPDATE",$id),ARRAY_A);if(!is_array($r)||$wpdb->last_error!==''||$r['status']!==StoreOnboardingApplication::PROVISIONING||$r['user_id']!==null||$r['store_id']!==null)throw new RuntimeException('verification_application_ineligible');return $r;}
    private function findLockedByApplication(int $id):?StoreOnboardingEmailVerification{return $this->find('application_id',$id,true);}
    private function find(string $column,string|int $value,bool $lock=false):?StoreOnboardingEmailVerification{if(!in_array($column,['id','application_id','token_hash'],true))throw new InvalidArgumentException('verification_invalid_lookup');global $wpdb;$p=is_int($value)?'%d':'%s';$wpdb->last_error='';$r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE {$column}={$p} LIMIT 1".($lock?' FOR UPDATE':''),$value),ARRAY_A);if($wpdb->last_error!=='' )throw new RuntimeException('verification_read_failed');if(!is_array($r))return null;$v=StoreOnboardingEmailVerification::fromRow($r);$app=$wpdb->get_row($wpdb->prepare("SELECT user_id FROM {$this->applicationsTable()} WHERE id=%d",$v->applicationId),ARRAY_A);if(!is_array($app)||$wpdb->last_error!=='')throw new RuntimeException('verification_reference_invalid');if($v->attachedUserId!==null&&(int)($app['user_id']??0)!==$v->attachedUserId)throw new RuntimeException('verification_reference_invalid');return $v;}
    private function intent(int $a,string $p,?int $u,string $eh,string $th,string $x,string $n):void{if($a<=0||($u!==null&&$u<=0)||$p!==StoreOnboardingEmailVerification::PURPOSE)throw new InvalidArgumentException('verification_invalid_intent');$this->hash($eh);$this->hash($th);StoreOnboardingEmailVerification::timestamp($x);StoreOnboardingEmailVerification::timestamp($n);if($x<=$n)throw new InvalidArgumentException('verification_invalid_expiry');}
    private function hash(string $h):void{if(strlen($h)!==32)throw new InvalidArgumentException('verification_invalid_hash');}
    private function advance(string $old,string $new):void{StoreOnboardingEmailVerification::timestamp($old);StoreOnboardingEmailVerification::timestamp($new);if($new<=$old)throw new InvalidArgumentException('verification_timestamp_must_advance');}
    private function compatible(callable $validator,array $arguments):bool{try{return $validator(...$arguments)===true;}catch(\Throwable){return false;}}
    private function table():string{global $wpdb;return $wpdb->prefix.Config::TABLE_PREFIX.'store_onboarding_email_verifications';}
    private function applicationsTable():string{global $wpdb;return $wpdb->prefix.Config::TABLE_PREFIX.'store_onboarding_applications';}
}
