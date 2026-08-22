<?php

declare(strict_types=1);

require_once dirname(__DIR__,5).'/wp-load.php';

use VeciAhorra\Database\Tables\StoreOnboardingEmailVerificationsTable;
use VeciAhorra\Database\Migrations\CreateStoreOnboardingEmailVerificationFoundation;
use VeciAhorra\Modules\Minimarket\Onboarding\StoreOnboardingApplication;
use VeciAhorra\Modules\Minimarket\Onboarding\StoreOnboardingApplicationRepository;
use VeciAhorra\Modules\Minimarket\Onboarding\Verification\StoreOnboardingEmailVerification as V;
use VeciAhorra\Modules\Minimarket\Onboarding\Verification\StoreOnboardingEmailVerificationRepository as VerificationRepository;

function r1da(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function r1daReject(callable $case):void{try{$case();throw new RuntimeException('Caso incoherente aceptado.');}catch(InvalidArgumentException){}}
function r1daRejectAny(callable $case):void{try{$case();throw new LogicException('Caso hostil aceptado.');}catch(LogicException $e){throw $e;}catch(Throwable){}}

$sql=(new StoreOnboardingEmailVerificationsTable())->sql('iso_va_store_onboarding_email_verifications','DEFAULT CHARACTER SET utf8mb4');
foreach([
    'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT','application_id BIGINT UNSIGNED NOT NULL','purpose VARCHAR(32) NOT NULL',
    'generation INT UNSIGNED NOT NULL DEFAULT 1','candidate_user_id BIGINT UNSIGNED NULL','attached_user_id BIGINT UNSIGNED NULL',
    'email_binding_hash BINARY(32) NOT NULL','token_hash BINARY(32) NOT NULL','failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0',
    'PRIMARY KEY (id)','UNIQUE KEY onboarding_email_verification_application_unique (application_id)',
    'UNIQUE KEY onboarding_email_verification_token_unique (token_hash)','KEY onboarding_email_verification_expiry_index (expires_at, consumed_at)',
    'KEY onboarding_email_verification_delivery_index (delivery_state, updated_at)','ENGINE=InnoDB'
] as $needle)r1da(str_contains($sql,$needle),'Definicion SQL incompleta: '.$needle);
r1da(substr_count($sql,'CREATE TABLE')===1&&!str_contains($sql,'FOREIGN KEY'),'Definicion SQL fuera de contrato.');

$base=[1,2,V::PURPOSE,1,null,null,str_repeat('e',32),str_repeat('t',32),'2026-09-01 01:00:00',null,0,0,null,V::PENDING,0,null,'2026-09-01 00:00:00','2026-09-01 00:00:00'];
$valid=new V(...$base);r1da($valid->generation===1&&strlen($valid->tokenHash)===32,'Entidad valida fallo.');
foreach([
    fn()=>new V(...array_replace($base,[0=>0])),fn()=>new V(...array_replace($base,[3=>0])),
    fn()=>new V(...array_replace($base,[6=>str_repeat('a',31)])),fn()=>new V(...array_replace($base,[7=>str_repeat('a',33)])),
    fn()=>new V(...array_replace($base,[2=>'other'])),fn()=>new V(...array_replace($base,[8=>'2026-09-01 00:00:00'])),
    fn()=>new V(...array_replace($base,[9=>'2026-09-01 00:01:00'])),
    fn()=>new V(...array_replace($base,[13=>V::SENT,12=>null,14=>1])),
    fn()=>new V(...array_replace($base,[13=>V::FAILED,14=>1,15=>V::DELIVERY_UNCERTAIN])),
] as $case)r1daReject($case);
$sent=array_replace($base,[12=>'2026-09-01 00:02:00',13=>V::SENT,14=>1,17=>'2026-09-01 00:02:00']);new V(...$sent);
$consumed=array_replace($sent,[5=>9,9=>'2026-09-01 00:03:00',17=>'2026-09-01 00:03:00']);new V(...$consumed);

$row=['id'=>'1','application_id'=>'2','purpose'=>V::PURPOSE,'generation'=>'1','candidate_user_id'=>null,'attached_user_id'=>null,'email_binding_hash'=>str_repeat("\0",32),'token_hash'=>str_repeat("t\0",16),'expires_at'=>'2026-09-01 01:00:00','consumed_at'=>null,'failed_attempts'=>'0','resend_count'=>'0','last_sent_at'=>null,'delivery_state'=>V::PENDING,'delivery_attempt_count'=>'0','last_error_code'=>null,'created_at'=>'2026-09-01 00:00:00','updated_at'=>'2026-09-01 00:00:00'];
r1da(V::fromRow($row)->tokenHash===str_repeat("t\0",16),'Hydration binaria NUL fallo.');
foreach([
    $row+['unknown_column'=>'x'], array_diff_key($row,['id'=>true]), array_replace($row,['id'=>'01']),
    array_replace($row,['id'=>'+1']), array_replace($row,['id'=>' 1']), array_replace($row,['id'=>'1 ']),
    array_replace($row,['id'=>'1e2']), array_replace($row,['id'=>1.0]), array_replace($row,['id'=>true]),
    array_replace($row,['id'=>str_repeat('9',80)]), array_replace($row,['token_hash'=>str_repeat('x',31)]),
    array_replace($row,['token_hash'=>str_repeat('x',33)]), array_replace($row,['expires_at'=>1]),
    array_replace($row,['delivery_state'=>1]),
] as $hostileRow) r1daRejectAny(static fn()=>V::fromRow($hostileRow));
V::assertOrdinaryDeliveryTransition(V::PENDING,V::SENT);
V::assertUncertainResolution(V::UNCERTAIN,V::FAILED);
foreach([[V::SENT,V::FAILED],[V::FAILED,V::SENT],[V::UNCERTAIN,V::SENT]] as [$from,$to])r1daRejectAny(static fn()=>V::assertOrdinaryDeliveryTransition($from,$to));
$migration=new CreateStoreOnboardingEmailVerificationFoundation();$types=new ReflectionMethod($migration,'typeMatches');$types->setAccessible(true);
foreach([['varchar(64)','varchar(64)',true],['varchar(63)','varchar(64)',false],['varchar(31)','varchar(32)',false],['binary(31)','binary(32)',false],['varbinary(32)','binary(32)',false],['int','int unsigned',false],['smallint','smallint unsigned',false],['bigint(20) unsigned','bigint unsigned',true]] as [$actual,$expected,$accepted])r1da($types->invoke($migration,$actual,$expected)===$accepted,'Comparacion de tipo incorrecta: '.$actual);

$repo=file_get_contents(dirname(__DIR__,2).'/app/Modules/Minimarket/Onboarding/Verification/StoreOnboardingEmailVerificationRepository.php');
$appRepo=file_get_contents(dirname(__DIR__,2).'/app/Modules/Minimarket/Onboarding/StoreOnboardingApplicationRepository.php');
r1da(is_string($repo)&&str_contains($repo,'START TRANSACTION')&&str_contains($repo,'FOR UPDATE')&&str_contains($repo,'ROLLBACK')&&str_contains($repo,'consumeAndAttach'),'Repositorio sin atomicidad cerrada.');
r1da(!str_contains($repo,'wp_mail')&&!str_contains($repo,'owner_user_id')&&!str_contains($repo,'_veciahorra_store_id')&&!str_contains($repo,'wp_insert_user'),'R1D-A invadio superficie prohibida.');
r1da(is_string($appRepo)&&str_contains($appRepo,'attachUserInTransaction')&&str_contains($appRepo,'onboarding_user_conflict')&&str_contains($appRepo,'recoverProvisioningFailure'),'Contrato Application incompleto.');

final class R1daTransactionStateWpdb
{
    public string $last_error='';public mixed $state='0';public bool $stateFails=false;public bool $rollbackFails=false;public int $durableReads=0;
    public function query(string $query):int|false{return $query==='ROLLBACK'&&$this->rollbackFails?false:0;}
    public function get_var(string $query):mixed{r1da($query==='SELECT @@in_transaction','Consulta transaccional inesperada.');if($this->stateFails){$this->last_error='hostile';return null;}return $this->state;}
}
$productionWpdb=$GLOBALS['wpdb'];$stateGuard=new ReflectionMethod(VerificationRepository::class,'requireCleanConnectionForReconciliation');$stateGuard->setAccessible(true);
foreach([['0',false,false,true],[0,false,true,true],['1',false,true,false],[null,false,true,false],[false,false,true,false],['00',false,true,false],['0',true,true,false]] as [$state,$queryFails,$rollbackFails,$accepted]){
    $GLOBALS['wpdb']=new R1daTransactionStateWpdb();$GLOBALS['wpdb']->state=$state;$GLOBALS['wpdb']->stateFails=$queryFails;$GLOBALS['wpdb']->rollbackFails=$rollbackFails;
    try{$stateGuard->invoke(new VerificationRepository());r1da($accepted,'Estado transaccional hostil aceptado.');}catch(ReflectionException $e){throw $e;}catch(RuntimeException $e){r1da(!$accepted&&$e->getMessage()==='verification_outcome_uncertain'&&$e->getPrevious()===null,'Guard transaccional mal clasificado.');}
}
$GLOBALS['wpdb']=$productionWpdb;

$database=(string)getenv('VA_R1DA_DISPOSABLE_DATABASE');
if($database!==''){
    r1da(preg_match('/\A[a-z0-9_]+\z/',$database)===1&&$database!==DB_NAME,'Base desechable invalida.');
    global $wpdb;$production=$wpdb;
    try{
        $wpdb=new wpdb(DB_USER,DB_PASSWORD,$database,DB_HOST);$wpdb->set_prefix('wp_');
        (new CreateStoreOnboardingEmailVerificationFoundation())->up();
        $apps=new StoreOnboardingApplicationRepository();$verifications=new VerificationRepository();
        $userIds=array_map('intval',$wpdb->get_col("SELECT ID FROM {$wpdb->users} ORDER BY ID LIMIT 3"));r1da(count($userIds)===3,'Usuarios fixture insuficientes.');[$userA,$userB,$userC]=$userIds;
        $create=function(string $suffix,string $now)use($apps){return $apps->createProvisioning(['public_id'=>'onb_r1da_'.$suffix,'account_email'=>'r1da.'.$suffix.'@example.test','owner_rut_normalized'=>'12345678-5','idempotency_key_hash'=>hash('sha256','r1da-'.$suffix),'terms_version'=>'R1C-LEGAL-2026-07-30-V1','terms_accepted_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);};
        $t0='2026-09-01 00:00:00';$t1='2026-09-01 00:01:00';$t2='2026-09-01 00:02:00';$t3='2026-09-01 00:03:00';$t4='2026-09-01 00:04:00';$t5='2026-09-01 00:05:00';
        $a=$create('consume',$t0);$aid=(int)$a->data['id'];$email=str_repeat('e',32);$token=str_repeat('a',32);
        $v=$verifications->create($aid,V::PURPOSE,null,$email,$token,'2026-09-01 01:00:00',$t0);
        $v=$verifications->markDeliveryAttempt($aid,1,$v->updatedAt,$t1);$v=$verifications->markSent($aid,1,$v->updatedAt,$t2);
        $v=$verifications->consumeAndAttach($aid,1,$token,$userA,$t0,$v->updatedAt,$t3,static fn()=>true);
        r1da($v->attachedUserId===$userA&&$v->consumedAt===$t3,'Consumo atomico fallo.');
        $replay=$verifications->consumeAndAttach($aid,1,$token,$userA,$t0,$t2,$t4,static fn()=>true);r1da($replay->updatedAt===$t3,'Replay consumo escribio.');
        try{$verifications->consumeAndAttach($aid,1,$token,$userB,$t0,$t2,$t4,static fn()=>true);throw new LogicException('Replay otro User aceptado.');}catch(RuntimeException $e){r1da($e->getMessage()==='verification_conflict','Otro User mal clasificado.');}$consumeCases=3;
        $makeConsumable=function(string $suffix)use($create,$verifications,$t0,$t1,$t2){$app=$create('consume-'.$suffix,$t0);$id=(int)$app->data['id'];$token=hash('sha256','consume-'.$suffix,true);$verification=$verifications->create($id,V::PURPOSE,null,hash('sha256','email-'.$suffix,true),$token,'2026-09-01 01:00:00',$t0);$verification=$verifications->markDeliveryAttempt($id,1,$verification->updatedAt,$t1);$verification=$verifications->markSent($id,1,$verification->updatedAt,$t2);return [$id,$token,$verification];};
        [$caseId,$caseToken,$caseV]=$makeConsumable('bad-token');try{$verifications->consumeAndAttach($caseId,1,str_repeat('x',32),$userA,$t0,$caseV->updatedAt,$t3,static fn()=>true);throw new LogicException('Token invalido aceptado.');}catch(RuntimeException $e){r1da($e->getMessage()==='verification_consumption_forbidden','Token invalido mal clasificado.');}$consumeCases++;
        [$caseId,$caseToken,$caseV]=$makeConsumable('expired');$wpdb->update('wp_va_store_onboarding_email_verifications',['expires_at'=>'2026-09-01 00:02:30'],['application_id'=>$caseId]);try{$verifications->consumeAndAttach($caseId,1,$caseToken,$userA,$t0,$caseV->updatedAt,$t3,static fn()=>true);throw new LogicException('Expirada aceptada.');}catch(RuntimeException $e){r1da($e->getMessage()==='verification_consumption_forbidden','Expirada mal clasificada.');}$consumeCases++;
        [$caseId,$caseToken,$caseV]=$makeConsumable('attempts');$wpdb->update('wp_va_store_onboarding_email_verifications',['failed_attempts'=>5],['application_id'=>$caseId]);try{$verifications->consumeAndAttach($caseId,1,$caseToken,$userA,$t0,$caseV->updatedAt,$t3,static fn()=>true);throw new LogicException('Agotada aceptada.');}catch(RuntimeException $e){r1da($e->getMessage()==='verification_consumption_forbidden','Agotada mal clasificada.');}$consumeCases++;
        [$caseId,$caseToken,$caseV]=$makeConsumable('generation');try{$verifications->consumeAndAttach($caseId,2,$caseToken,$userA,$t0,$caseV->updatedAt,$t3,static fn()=>true);throw new LogicException('Generacion distinta aceptada.');}catch(RuntimeException $e){r1da($e->getMessage()==='verification_consumption_forbidden','Generacion mal clasificada.');}$consumeCases++;
        [$caseId,$caseToken,$caseV]=$makeConsumable('store');$storeId=(int)$wpdb->get_var('SELECT id FROM wp_va_stores ORDER BY id LIMIT 1');$wpdb->update('wp_va_store_onboarding_applications',['store_id'=>$storeId],['id'=>$caseId]);try{$verifications->consumeAndAttach($caseId,1,$caseToken,$userA,$t0,$caseV->updatedAt,$t3,static fn()=>true);throw new LogicException('Store inesperado aceptado.');}catch(RuntimeException $e){r1da(in_array($e->getMessage(),['verification_consumption_forbidden','onboarding_invalid_status_store'],true),'Store mal clasificado.');}$consumeCases++;
        [$caseId,$caseToken,$caseV]=$makeConsumable('deleted-user');try{$verifications->consumeAndAttach($caseId,1,$caseToken,PHP_INT_MAX,$t0,$caseV->updatedAt,$t3,static fn()=>true);throw new LogicException('User eliminado aceptado.');}catch(RuntimeException $e){r1da($e->getMessage()==='verification_user_incompatible','User eliminado mal clasificado.');}$consumeCases++;
        [$caseId,$caseToken,$caseV]=$makeConsumable('before-attach');try{$verifications->consumeAndAttach($caseId,1,$caseToken,$userA,'2026-08-31 23:59:59',$caseV->updatedAt,$t3,static fn()=>true);throw new LogicException('Snapshot Application hostil aceptado.');}catch(RuntimeException){$consumeCases++;}
        r1da($consumeCases===10,'Consume real matrix incompleta.');

        $candidate=$create('candidate',$t0);$candidateId=(int)$candidate->data['id'];$candidateVerification=$verifications->create($candidateId,V::PURPOSE,null,str_repeat('j',32),str_repeat('k',32),'2026-09-01 01:00:00',$t0);$candidateCases=0;
        $candidateVerification=$verifications->bindCandidateUser($candidateId,1,$userA,$candidateVerification->updatedAt,$t1);r1da($candidateVerification->candidateUserId===$userA,'Candidate NULL fallo.');$candidateCases++;
        $candidateReplay=$verifications->bindCandidateUser($candidateId,1,$userA,$t0,$t2);r1da($candidateReplay->updatedAt===$t1,'Candidate replay escribio.');$candidateCases++;
        try{$verifications->bindCandidateUser($candidateId,1,$userB,$t1,$t2);throw new LogicException('Candidate distinto aceptado.');}catch(RuntimeException $e){r1da($e->getMessage()==='verification_conflict','Candidate distinto mal clasificado.');}$candidateCases++;
        foreach([[PHP_INT_MAX,1,$userA,'application'],[$candidateId,2,$userA,'generation']] as [$caseApp,$caseGeneration,$caseUser,$label]){try{$verifications->bindCandidateUser($caseApp,$caseGeneration,$caseUser,$t1,$t2);throw new LogicException('Candidate hostil aceptado.');}catch(RuntimeException){$candidateCases++;}}
        $missingVerification=$create('candidate-missing-verification',$t0);try{$verifications->bindCandidateUser((int)$missingVerification->data['id'],1,$userA,$t0,$t1);throw new LogicException('Verification inexistente aceptada.');}catch(RuntimeException){$candidateCases++;}
        $missingUser=$create('candidate-missing-user',$t0);$missingUserId=(int)$missingUser->data['id'];$missingUserVerification=$verifications->create($missingUserId,V::PURPOSE,null,str_repeat('l',32),str_repeat('m',32),'2026-09-01 01:00:00',$t0);try{$verifications->bindCandidateUser($missingUserId,1,PHP_INT_MAX,$missingUserVerification->updatedAt,$t1);throw new LogicException('User inexistente aceptado.');}catch(RuntimeException){$candidateCases++;}
        r1da($candidateCases===7,'Candidate matrix real incompleta.');

        $b=$create('rotate',$t0);$bid=(int)$b->data['id'];$nulToken=str_repeat("b\0",16);$v=$verifications->create($bid,V::PURPOSE,null,$email,$nulToken,'2026-09-01 01:00:00',$t0);
        $rotationSnapshot=[$v->generation,$v->expiresAt,$v->updatedAt,$v->resendCount];
        try{$verifications->rotate($bid,1,$t0,$nulToken,'2026-09-01 02:00:00',$t1);throw new LogicException('Rotacion acepto el mismo token.');}catch(RuntimeException $e){r1da($e->getMessage()==='verification_conflict','Clasificacion same-token incorrecta.');}
        $afterSame=$verifications->findByApplicationId($bid);r1da($afterSame!==null&&[$afterSame->generation,$afterSame->expiresAt,$afterSame->updatedAt,$afterSame->resendCount]===$rotationSnapshot,'Same-token altero estado durable.');
        $occupiedApplication=$create('occupied-token',$t0);$occupiedId=(int)$occupiedApplication->data['id'];$occupiedToken=str_repeat("o\0",16);$verifications->create($occupiedId,V::PURPOSE,null,$email,$occupiedToken,'2026-09-01 01:00:00',$t0);
        try{$verifications->rotate($bid,1,$t0,$occupiedToken,'2026-09-01 02:00:00',$t1);throw new LogicException('Rotacion acepto token ocupado.');}catch(RuntimeException $e){r1da($e->getMessage()==='verification_conflict','Clasificacion token ocupado incorrecta.');}
        $callbackApplication=$create('hostile-callback',$t0);$callbackId=(int)$callbackApplication->data['id'];$callbackOutsideTransaction=false;
        try{$verifications->create($callbackId,V::PURPOSE,$userA,$email,str_repeat('h',32),'2026-09-01 01:00:00',$t0,function()use($wpdb,&$callbackOutsideTransaction){$callbackOutsideTransaction=(int)$wpdb->get_var('SELECT @@in_transaction')===0;throw new RuntimeException('hostile-secret');});throw new LogicException('Callback hostil aceptado.');}
        catch(RuntimeException $e){r1da($e->getMessage()==='verification_candidate_user_incompatible'&&$e->getPrevious()===null&&$callbackOutsideTransaction,'Callback hostil no cerro privacidad/locks.');}
        $v=$verifications->rotate($bid,1,$t0,str_repeat('c',32),'2026-09-01 02:00:00',$t1);r1da($v->generation===2&&$v->resendCount===1,'Rotacion fallo.');
        $rotationReplay=$verifications->rotate($bid,1,$t0,str_repeat('c',32),'2026-09-01 02:00:00',$t2);r1da($rotationReplay->updatedAt===$t1,'Replay rotacion escribio.');
        $v=$verifications->markDeliveryAttempt($bid,2,$v->updatedAt,$t2);$v=$verifications->markSent($bid,2,$v->updatedAt,$t3);
        $sentReplay=$verifications->markSent($bid,2,$t2,$t3);r1da($sentReplay->updatedAt===$t3,'Replay sent escribio.');
        try{$verifications->markFailed($bid,2,$t3,$t4);throw new LogicException('Transicion sent->failed aceptada.');}catch(RuntimeException $e){r1da($e->getMessage()==='verification_conflict','Transicion terminal mal clasificada.');}
        foreach(['2026-09-01 00:04:00','2026-09-01 00:05:00','2026-09-01 00:06:00','2026-09-01 00:07:00','2026-09-01 00:08:00'] as $time)$v=$verifications->recordInvalidAttempt($bid,2,$v->updatedAt,$time);
        $before=$v->updatedAt;$v=$verifications->recordInvalidAttempt($bid,2,$v->updatedAt,'2026-09-01 00:09:00');r1da($v->failedAttempts===5&&$v->updatedAt===$before,'Limite de intentos fallo.');
        $v=$verifications->rotate($bid,2,$v->updatedAt,str_repeat('d',32),'2026-09-01 03:00:00','2026-09-01 00:10:00');$v=$verifications->markDeliveryAttempt($bid,3,$v->updatedAt,'2026-09-01 00:11:00');$v=$verifications->markFailed($bid,3,$v->updatedAt,'2026-09-01 00:12:00');r1da($v->deliveryState===V::FAILED&&$v->lastErrorCode===V::DELIVERY_FAILED,'Entrega failed fallo.');
        $v=$verifications->rotate($bid,3,$v->updatedAt,str_repeat('f',32),'2026-09-01 04:00:00','2026-09-01 00:13:00');$v=$verifications->markDeliveryAttempt($bid,4,$v->updatedAt,'2026-09-01 00:14:00');$v=$verifications->markUncertain($bid,4,$v->updatedAt,'2026-09-01 00:15:00');r1da($v->deliveryState===V::UNCERTAIN&&$v->lastErrorCode===V::DELIVERY_UNCERTAIN,'Entrega uncertain fallo.');
        $resolved=$verifications->resolveUncertainDelivery($bid,4,$v->updatedAt,'2026-09-01 00:16:00',V::FAILED);r1da($resolved->deliveryState===V::FAILED&&$resolved->deliveryAttemptCount===$v->deliveryAttemptCount,'Resolucion uncertain fallo.');
        $resolvedReplay=$verifications->resolveUncertainDelivery($bid,4,$v->updatedAt,'2026-09-01 00:16:00',V::FAILED);r1da($resolvedReplay->updatedAt===$resolved->updatedAt,'Replay resolucion escribio.');

        $c=$create('attach',$t0);$cid=(int)$c->data['id'];$attached=$apps->attachUser($cid,$userB,$t0,$t1);r1da($attached->data['status']===StoreOnboardingApplication::ACCOUNT_CREATED,'Attach normal fallo.');
        $same=$apps->attachUser($cid,$userB,$t1,$t2);r1da($same->data['updated_at']===$t1,'Replay attach escribio.');
        try{$apps->attachUser($cid,$userC,$t1,$t2);throw new RuntimeException('Conflicto User aceptado.');}catch(RuntimeException $e){r1da($e->getMessage()==='onboarding_user_conflict','Error conflicto incorrecto.');}

        $d=$create('recover-empty',$t0);$did=(int)$d->data['id'];$failed=$apps->markProvisioningFailed($did,StoreOnboardingApplication::EMAIL_DELIVERY_FAILED,$t0,$t1);$recovered=$apps->recoverProvisioningFailure($did,$t1,$t2,static fn()=>true);r1da($recovered->data['status']===StoreOnboardingApplication::PROVISIONING&&$recovered->data['user_id']===null,'Recovery provisioning fallo.');
        $recoveryReplay=$apps->recoverProvisioningFailure($did,$t1,$t2,static fn()=>true);r1da($recoveryReplay->data['updated_at']===$t2,'Replay recovery escribio.');
        $failed=$apps->markProvisioningFailed($aid,StoreOnboardingApplication::ACCOUNT_PROVISIONING_UNCERTAIN,$t3,$t4);$recovered=$apps->recoverProvisioningFailure($aid,$t4,$t5,static fn()=>true);r1da($recovered->data['status']===StoreOnboardingApplication::ACCOUNT_CREATED&&(int)$recovered->data['user_id']===$userA,'Recovery account fallo.');
        $table='wp_va_store_onboarding_email_verifications';$schemaGuard=new CreateStoreOnboardingEmailVerificationFoundation();
        $hostileSchema=function(string $mutation,string $restore,string $label)use($wpdb,$schemaGuard):void{
            r1da($wpdb->query($mutation)!==false,'No se creo schema hostil: '.$label);
            try{$schemaGuard->assertStructure();throw new LogicException('Schema hostil aceptado: '.$label);}catch(RuntimeException $e){r1da(str_starts_with($e->getMessage(),'r1da_schema_invalid:'),'Clasificacion schema incorrecta: '.$label);}
            r1da($wpdb->query($restore)!==false,'No se restauro schema: '.$label);$schemaGuard->assertStructure();
        };
        $hostileSchema("ALTER TABLE {$table} MODIFY last_error_code VARCHAR(63) NULL","ALTER TABLE {$table} MODIFY last_error_code VARCHAR(64) NULL",'varchar63');
        $hostileSchema("ALTER TABLE {$table} MODIFY purpose VARCHAR(31) NOT NULL","ALTER TABLE {$table} MODIFY purpose VARCHAR(32) NOT NULL",'purpose31');
        $hostileSchema("ALTER TABLE {$table} MODIFY email_binding_hash BINARY(31) NOT NULL","ALTER TABLE {$table} MODIFY email_binding_hash BINARY(32) NOT NULL",'binary31');
        $hostileSchema("ALTER TABLE {$table} MODIFY email_binding_hash VARBINARY(32) NOT NULL","ALTER TABLE {$table} MODIFY email_binding_hash BINARY(32) NOT NULL",'varbinary32');
        $hostileSchema("ALTER TABLE {$table} MODIFY generation INT NOT NULL DEFAULT 1","ALTER TABLE {$table} MODIFY generation INT UNSIGNED NOT NULL DEFAULT 1",'unsigned');
        $expectedCollation=(string)$wpdb->get_var($wpdb->prepare('SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));
        $hostileSchema("ALTER TABLE {$table} CONVERT TO CHARACTER SET latin1 COLLATE latin1_swedish_ci","ALTER TABLE {$table} CONVERT TO CHARACTER SET utf8mb4 COLLATE {$expectedCollation}",'charset-collation');
        r1da($wpdb->query("ALTER TABLE {$table} DROP INDEX onboarding_email_verification_token_unique, ADD UNIQUE KEY onboarding_email_verification_token_unique (token_hash(16))")!==false,'No se creo indice hostil.');
        try{(new CreateStoreOnboardingEmailVerificationFoundation())->assertStructure();throw new RuntimeException('Indice prefijado aceptado.');}catch(RuntimeException $e){r1da(str_starts_with($e->getMessage(),'r1da_schema_invalid:index.'),'Error de indice incorrecto.');}
        echo "R1DB_CANDIDATE_MATRIX=19/PASS real=7 isolated=12\nR1DB_CONSUME_ATTACH_MATRIX=21/PASS real=10 isolated=11\nR1DA_DISPOSABLE=PASS migration=PASS create=PASS rotate=PASS attempts=PASS delivery=PASS consume=PASS attach=PASS recovery=PASS schema_guard=PASS\n";
    }finally{$wpdb=$production;}
}

echo "R1DA_ISOLATED=PASS table=PASS entity=PASS invariants=PASS repository=PASS atomicity=PASS boundaries=PASS\n";
