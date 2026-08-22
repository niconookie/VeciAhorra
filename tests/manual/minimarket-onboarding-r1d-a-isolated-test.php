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

$repo=file_get_contents(dirname(__DIR__,2).'/app/Modules/Minimarket/Onboarding/Verification/StoreOnboardingEmailVerificationRepository.php');
$appRepo=file_get_contents(dirname(__DIR__,2).'/app/Modules/Minimarket/Onboarding/StoreOnboardingApplicationRepository.php');
r1da(is_string($repo)&&str_contains($repo,'START TRANSACTION')&&str_contains($repo,'FOR UPDATE')&&str_contains($repo,'ROLLBACK')&&str_contains($repo,'consumeAndAttach'),'Repositorio sin atomicidad cerrada.');
r1da(!str_contains($repo,'wp_mail')&&!str_contains($repo,'owner_user_id')&&!str_contains($repo,'_veciahorra_store_id')&&!str_contains($repo,'wp_insert_user'),'R1D-A invadio superficie prohibida.');
r1da(is_string($appRepo)&&str_contains($appRepo,'attachUserInTransaction')&&str_contains($appRepo,'onboarding_user_conflict')&&str_contains($appRepo,'recoverProvisioningFailure'),'Contrato Application incompleto.');

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

        $b=$create('rotate',$t0);$bid=(int)$b->data['id'];$v=$verifications->create($bid,V::PURPOSE,null,$email,str_repeat('b',32),'2026-09-01 01:00:00',$t0);
        $v=$verifications->rotate($bid,1,$t0,str_repeat('c',32),'2026-09-01 02:00:00',$t1);r1da($v->generation===2&&$v->resendCount===1,'Rotacion fallo.');
        $rotationReplay=$verifications->rotate($bid,1,$t0,str_repeat('c',32),'2026-09-01 02:00:00',$t2);r1da($rotationReplay->updatedAt===$t1,'Replay rotacion escribio.');
        $v=$verifications->markDeliveryAttempt($bid,2,$v->updatedAt,$t2);$v=$verifications->markSent($bid,2,$v->updatedAt,$t3);
        foreach(['2026-09-01 00:04:00','2026-09-01 00:05:00','2026-09-01 00:06:00','2026-09-01 00:07:00','2026-09-01 00:08:00'] as $time)$v=$verifications->recordInvalidAttempt($bid,2,$v->updatedAt,$time);
        $before=$v->updatedAt;$v=$verifications->recordInvalidAttempt($bid,2,$v->updatedAt,'2026-09-01 00:09:00');r1da($v->failedAttempts===5&&$v->updatedAt===$before,'Limite de intentos fallo.');
        $v=$verifications->rotate($bid,2,$v->updatedAt,str_repeat('d',32),'2026-09-01 03:00:00','2026-09-01 00:10:00');$v=$verifications->markDeliveryAttempt($bid,3,$v->updatedAt,'2026-09-01 00:11:00');$v=$verifications->markFailed($bid,3,$v->updatedAt,'2026-09-01 00:12:00');r1da($v->deliveryState===V::FAILED&&$v->lastErrorCode===V::DELIVERY_FAILED,'Entrega failed fallo.');
        $v=$verifications->rotate($bid,3,$v->updatedAt,str_repeat('f',32),'2026-09-01 04:00:00','2026-09-01 00:13:00');$v=$verifications->markDeliveryAttempt($bid,4,$v->updatedAt,'2026-09-01 00:14:00');$v=$verifications->markUncertain($bid,4,$v->updatedAt,'2026-09-01 00:15:00');r1da($v->deliveryState===V::UNCERTAIN&&$v->lastErrorCode===V::DELIVERY_UNCERTAIN,'Entrega uncertain fallo.');

        $c=$create('attach',$t0);$cid=(int)$c->data['id'];$attached=$apps->attachUser($cid,$userB,$t0,$t1);r1da($attached->data['status']===StoreOnboardingApplication::ACCOUNT_CREATED,'Attach normal fallo.');
        $same=$apps->attachUser($cid,$userB,$t1,$t2);r1da($same->data['updated_at']===$t1,'Replay attach escribio.');
        try{$apps->attachUser($cid,$userC,$t1,$t2);throw new RuntimeException('Conflicto User aceptado.');}catch(RuntimeException $e){r1da($e->getMessage()==='onboarding_user_conflict','Error conflicto incorrecto.');}

        $d=$create('recover-empty',$t0);$did=(int)$d->data['id'];$failed=$apps->markProvisioningFailed($did,StoreOnboardingApplication::EMAIL_DELIVERY_FAILED,$t0,$t1);$recovered=$apps->recoverProvisioningFailure($did,$t1,$t2,static fn()=>true);r1da($recovered->data['status']===StoreOnboardingApplication::PROVISIONING&&$recovered->data['user_id']===null,'Recovery provisioning fallo.');
        $failed=$apps->markProvisioningFailed($aid,StoreOnboardingApplication::ACCOUNT_PROVISIONING_UNCERTAIN,$t3,$t4);$recovered=$apps->recoverProvisioningFailure($aid,$t4,$t5,static fn()=>true);r1da($recovered->data['status']===StoreOnboardingApplication::ACCOUNT_CREATED&&(int)$recovered->data['user_id']===$userA,'Recovery account fallo.');
        $table='wp_va_store_onboarding_email_verifications';r1da($wpdb->query("ALTER TABLE {$table} DROP INDEX onboarding_email_verification_token_unique, ADD UNIQUE KEY onboarding_email_verification_token_unique (token_hash(16))")!==false,'No se creo indice hostil.');
        try{(new CreateStoreOnboardingEmailVerificationFoundation())->assertStructure();throw new RuntimeException('Indice prefijado aceptado.');}catch(RuntimeException $e){r1da(str_starts_with($e->getMessage(),'r1da_schema_invalid:index.'),'Error de indice incorrecto.');}
        echo "R1DA_DISPOSABLE=PASS migration=PASS create=PASS rotate=PASS attempts=PASS delivery=PASS consume=PASS attach=PASS recovery=PASS schema_guard=PASS\n";
    }finally{$wpdb=$production;}
}

echo "R1DA_ISOLATED=PASS table=PASS entity=PASS invariants=PASS repository=PASS atomicity=PASS boundaries=PASS\n";
