<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';

use VeciAhorra\Database\Migrations\CreateStoreOnboardingFoundation;
use VeciAhorra\Modules\Minimarket\Identity\MinimarketRole;
use VeciAhorra\Modules\Minimarket\Onboarding\StoreOnboardingApplication as Application;
use VeciAhorra\Modules\Minimarket\Onboarding\StoreOnboardingApplicationRepository;
use VeciAhorra\Modules\Minimarket\Ownership\StoreOwnershipRepository;

function r1aAssert(bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); }
function r1aThrows(callable $callback, string $code): void {
    try { $callback(); } catch (Throwable $exception) { r1aAssert($exception->getMessage() === $code, "Esperaba {$code}, obtuvo {$exception->getMessage()}"); return; }
    throw new RuntimeException("No rechazo {$code}");
}

global $wpdb;
$prefix = $wpdb->prefix . 'va_';
$stores = $prefix . 'stores';
$applications = $prefix . 'store_onboarding_applications';
$before = [
    'stores'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$stores}"),
    'applications'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$applications}"),
    'users'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}"),
    'meta'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key=%s", MinimarketRole::STORE_META_KEY)),
];
$userIds=[]; $storeIds=[]; $applicationIds=[];
$now = gmdate('Y-m-d H:i:s');
$later = gmdate('Y-m-d H:i:s', time()+1);
$later2 = gmdate('Y-m-d H:i:s', time()+2);

$newUser = static function(string $label) use (&$userIds): int {
    $token = strtolower(wp_generate_password(10, false));
    $id = wp_create_user("r1a_{$label}_{$token}", wp_generate_password(32, true, true), "r1a_{$label}_{$token}@example.test");
    r1aAssert(is_int($id), "No creo usuario {$label}"); $userIds[]=$id; return $id;
};
$newStore = static function(string $label, ?int $owner=null) use (&$storeIds,$wpdb,$stores,$now): int {
    $token = wp_generate_password(10, false);
    r1aAssert($wpdb->insert($stores, [
        'owner_user_id'=>$owner,'business_name'=>"R1A {$label} {$token}",'legal_name'=>"R1A {$label}",
        'owner_name'=>'Fixture R1A','rut'=>"R1A-{$token}",'email'=>"r1a-{$token}@example.test",'phone'=>'0',
        'mobile'=>null,'address'=>null,'commune'=>null,'city'=>null,'region'=>null,
        'status'=>'pending','onboarding_status'=>'draft','approved_at'=>null,'created_at'=>$now,'updated_at'=>$now,
    ])===1,"No creo Store {$label}"); $storeIds[]=(int)$wpdb->insert_id; return (int)$wpdb->insert_id;
};

try {
    $columns=array_column($wpdb->get_results("SHOW COLUMNS FROM {$stores}",ARRAY_A),'Field');
    r1aAssert(in_array('owner_user_id',$columns,true),'Falta owner_user_id.');
    $indexes=$wpdb->get_results("SHOW INDEX FROM {$stores}",ARRAY_A);
    $ownerIndex=array_values(array_filter($indexes,static fn(array $i):bool=>$i['Key_name']==='stores_owner_user_unique'));
    r1aAssert(count($ownerIndex)===1&&(int)$ownerIndex[0]['Non_unique']===0&&$ownerIndex[0]['Column_name']==='owner_user_id','Indice owner invalido.');
    $expectedColumns=['id','public_id','user_id','account_email','owner_rut_normalized','status','idempotency_key_hash','terms_version','terms_accepted_at','store_id','failure_code','attempt_count','last_attempt_at','created_at','updated_at','abandoned_at'];
    r1aAssert(array_column($wpdb->get_results("SHOW COLUMNS FROM {$applications}",ARRAY_A),'Field')===$expectedColumns,'Columnas onboarding invalidas.');
    $expectedIndexes=['PRIMARY','onboarding_account_email','onboarding_idempotency_unique','onboarding_owner_rut','onboarding_public_id_unique','onboarding_status_updated','onboarding_store_unique','onboarding_user_unique'];
    $actualIndexes=array_values(array_unique(array_column($wpdb->get_results("SHOW INDEX FROM {$applications}",ARRAY_A),'Key_name'))); sort($actualIndexes); sort($expectedIndexes);
    r1aAssert($actualIndexes===$expectedIndexes,'Indices onboarding invalidos.');
    r1aAssert(Application::statuses()===['provisioning','account_created','profile_incomplete','ready_to_materialize','provisioning_failed','store_materialized','abandoned'],'Estados cerrados invalidos.');
    r1aAssert(Application::failureCodes()===['account_provisioning_failed','application_persistence_failed','store_materialization_failed','technical_outcome_uncertain'],'Codigos de fallo cerrados invalidos.');
    r1aThrows(static fn()=>Application::assertStatus('admin'),'onboarding_invalid_status');
    foreach(['',' account_provisioning_failed','ACCOUNT_PROVISIONING_FAILED','future_failure'] as $invalidFailureCode) {
        r1aThrows(static fn()=>Application::assertFailureCode($invalidFailureCode),'onboarding_invalid_failure_code');
    }
    r1aThrows(static fn()=>new Application(['status'=>Application::PROVISIONING,'failure_code'=>'account_provisioning_failed']),'onboarding_invalid_status_failure_code');
    r1aThrows(static fn()=>new Application(['status'=>Application::PROVISIONING_FAILED,'failure_code'=>null]),'onboarding_invalid_failure_code');
    r1aThrows(static fn()=>Application::assertTransition(Application::PROVISIONING,Application::STORE_MATERIALIZED),'onboarding_invalid_transition');
    foreach([
        [Application::PROVISIONING,Application::ACCOUNT_CREATED],
        [Application::PROVISIONING,Application::PROVISIONING_FAILED],
        [Application::PROVISIONING_FAILED,Application::PROVISIONING],
        [Application::ACCOUNT_CREATED,Application::PROFILE_INCOMPLETE],
        [Application::PROFILE_INCOMPLETE,Application::READY_TO_MATERIALIZE],
        [Application::READY_TO_MATERIALIZE,Application::STORE_MATERIALIZED],
        [Application::PROFILE_INCOMPLETE,Application::ABANDONED],
    ] as [$from,$to]) Application::assertTransition($from,$to);

    $ownerRepo=new StoreOwnershipRepository();
    $historicalUser=$newUser('historical'); $historicalStore=$newStore('historical');
    add_user_meta($historicalUser,MinimarketRole::STORE_META_KEY,$historicalStore);
    r1aAssert($ownerRepo->resolveStoreIdForOwnerUser($historicalUser)===$historicalStore,'Fallback historico fallo.');
    $ownerRepo->assignOwner($historicalStore,$historicalUser);
    delete_user_meta($historicalUser,MinimarketRole::STORE_META_KEY);
    r1aAssert($ownerRepo->resolveStoreIdForOwnerUser($historicalUser)===$historicalStore,'Resolucion canonica fallo.');
    r1aAssert((int)get_user_meta($historicalUser,MinimarketRole::STORE_META_KEY,true)===$historicalStore,'No reparo proyeccion ausente.');
    $otherStore=$newStore('other'); update_user_meta($historicalUser,MinimarketRole::STORE_META_KEY,$otherStore);
    r1aThrows(static fn()=>$ownerRepo->resolveStoreIdForOwnerUser($historicalUser),'store_owner_projection_conflict');
    update_user_meta($historicalUser,MinimarketRole::STORE_META_KEY,$historicalStore);
    $foreignOwner=$newUser('foreignowner'); $foreignStore=$newStore('foreignowner',$foreignOwner);
    $foreignProjected=$newUser('foreignprojected'); add_user_meta($foreignProjected,MinimarketRole::STORE_META_KEY,$foreignStore);
    r1aThrows(static fn()=>$ownerRepo->resolveStoreIdForOwnerUser($foreignProjected),'store_owner_projection_conflict');
    r1aAssert((int)$wpdb->get_var($wpdb->prepare("SELECT owner_user_id FROM {$stores} WHERE id=%d",$foreignStore))===$foreignOwner,'Fallback altero owner canonico.');
    r1aAssert((int)get_user_meta($foreignProjected,MinimarketRole::STORE_META_KEY,true)===$foreignStore,'Fallback altero proyeccion conflictiva.');
    delete_user_meta($foreignProjected,MinimarketRole::STORE_META_KEY,$foreignStore);

    $adminUser=$newUser('adminassignment'); $adminStoreA=$newStore('admina'); $adminStoreB=$newStore('adminb');
    $ownerRepo->setOwnerStoreForUser($adminUser,$adminStoreA);
    r1aAssert($ownerRepo->resolveStoreIdForOwnerUser($adminUser)===$adminStoreA,'Asignacion administrativa fallo.');
    $ownerRepo->setOwnerStoreForUser($adminUser,$adminStoreB);
    r1aAssert($ownerRepo->resolveStoreIdForOwnerUser($adminUser)===$adminStoreB,'Reasignacion administrativa fallo.');
    r1aAssert($wpdb->get_var($wpdb->prepare("SELECT owner_user_id FROM {$stores} WHERE id=%d",$adminStoreA))===null,'Reasignacion no libero Store anterior.');
    $ownerRepo->unassignOwner($adminUser);
    r1aAssert($ownerRepo->resolveStoreIdForOwnerUser($adminUser)===null,'Desasignacion administrativa fallo.');
    r1aThrows(static fn()=>$ownerRepo->setOwnerStoreForUser($adminUser,PHP_INT_MAX),'store_owner_store_missing');
    r1aThrows(static fn()=>$ownerRepo->setOwnerStoreForUser($adminUser,$foreignStore),'store_owner_store_already_owned');
    add_user_meta($foreignProjected,MinimarketRole::STORE_META_KEY,$otherStore);
    r1aThrows(static fn()=>$ownerRepo->setOwnerStoreForUser($adminUser,$otherStore),'store_owner_historical_store_ambiguous');
    delete_user_meta($foreignProjected,MinimarketRole::STORE_META_KEY,$otherStore);
    $wpdb->suppress_errors(true);
    r1aAssert($wpdb->update($stores,['owner_user_id'=>$historicalUser],['id'=>$otherStore])===false,'Unicidad owner_user_id no rechazo duplicado.'); $wpdb->last_error='';
    $wpdb->suppress_errors(false);

    $migration=new CreateStoreOnboardingFoundation();
    $migration->assertStructure();
    $migration->validatedBackfillCandidates();
    add_user_meta($historicalUser,MinimarketRole::STORE_META_KEY,$otherStore);
    r1aThrows(static fn()=>$migration->validatedBackfillCandidates(),'store_owner_backfill_user_ambiguous');
    delete_user_meta($historicalUser,MinimarketRole::STORE_META_KEY,$otherStore);
    $sharedStore=$newStore('shared');
    $sharedUser=$newUser('shared'); $sharedUser2=$newUser('shared2');
    add_user_meta($sharedUser,MinimarketRole::STORE_META_KEY,$sharedStore); add_user_meta($sharedUser2,MinimarketRole::STORE_META_KEY,$sharedStore);
    r1aThrows(static fn()=>$migration->validatedBackfillCandidates(),'store_owner_backfill_store_ambiguous');
    delete_user_meta($sharedUser,MinimarketRole::STORE_META_KEY,$sharedStore); delete_user_meta($sharedUser2,MinimarketRole::STORE_META_KEY,$sharedStore);
    $wpdb->insert($wpdb->usermeta,['user_id'=>PHP_INT_MAX,'meta_key'=>MinimarketRole::STORE_META_KEY,'meta_value'=>$otherStore]);
    r1aThrows(static fn()=>$migration->validatedBackfillCandidates(),'store_owner_backfill_user_missing');
    $wpdb->delete($wpdb->usermeta,['user_id'=>PHP_INT_MAX,'meta_key'=>MinimarketRole::STORE_META_KEY]);
    $missingStoreUser=$newUser('missingstore'); add_user_meta($missingStoreUser,MinimarketRole::STORE_META_KEY,PHP_INT_MAX);
    r1aThrows(static fn()=>$migration->validatedBackfillCandidates(),'store_owner_backfill_store_missing');
    delete_user_meta($missingStoreUser,MinimarketRole::STORE_META_KEY,PHP_INT_MAX);
    $conflictUser=$newUser('conflict'); add_user_meta($conflictUser,MinimarketRole::STORE_META_KEY,$historicalStore);
    r1aThrows(static fn()=>$migration->validatedBackfillCandidates(),'store_owner_backfill_owner_conflict');
    delete_user_meta($conflictUser,MinimarketRole::STORE_META_KEY,$historicalStore);
    r1aAssert($migration->backfillValidatedOwners()>=3,'Backfill idempotente no proceso candidatos.');

    $repo=new StoreOnboardingApplicationRepository();
    $hash=hash('sha256','r1a-'.wp_generate_password(24,true,true));
    $input=['public_id'=>'onb_'.wp_generate_password(24,false),'account_email'=>'owner@example.test','owner_rut_normalized'=>'123456785','idempotency_key_hash'=>$hash,'terms_version'=>'2026-08','terms_accepted_at'=>$now,'created_at'=>$now,'updated_at'=>$now];
    foreach(['2026-02-29 00:00:00','2026-08-19T13:45:07','2026-08-19 13:45:07Z','2026-08-19 13:45:07.1',' 2026-08-19 13:45:07','0999-12-31 23:59:59','0000-00-00 00:00:00'] as $invalidTimestamp) {
        $invalidInput=$input; $invalidInput['created_at']=$invalidTimestamp;
        try { $repo->createProvisioning($invalidInput); throw new RuntimeException('No rechazo timestamp UTC invalido.'); }
        catch (InvalidArgumentException $exception) { r1aAssert(str_contains($exception->getMessage(),'canonical UTC timestamp'),'Clasificacion timestamp invalida.'); }
    }
    $invalidOrder=$input; $invalidOrder['terms_accepted_at']=$later;
    r1aThrows(static fn()=>$repo->createProvisioning($invalidOrder),'onboarding_invalid_timestamp_order');
    $app=$repo->createProvisioning($input); $applicationIds[]=(int)$app->data['id'];
    r1aAssert($repo->createProvisioning($input)->data['id']===$app->data['id'],'Replay idempotente fallo.');
    $conflicting=$input; $conflicting['public_id']='onb_'.wp_generate_password(24,false);
    r1aThrows(static fn()=>$repo->createProvisioning($conflicting),'onboarding_idempotency_conflict'); $wpdb->last_error='';
    r1aThrows(static fn()=>$repo->attachUser((int)$app->data['id'],$historicalUser,$now,$now),'onboarding_updated_at_must_advance');
    r1aThrows(static fn()=>$repo->attachUser((int)$app->data['id'],PHP_INT_MAX,$now,$later),'onboarding_user_missing');
    $app=$repo->attachUser((int)$app->data['id'],$historicalUser,$now,$later);
    r1aAssert($repo->findByUserId($historicalUser)?->data['status']===Application::ACCOUNT_CREATED,'Attach user fallo.');
    r1aThrows(static fn()=>$repo->markProfileIncomplete((int)$app->data['id'],$now,$later2),'onboarding_concurrent_modification');
    $app=$repo->markProfileIncomplete((int)$app->data['id'],$later,$later2);
    $later3=gmdate('Y-m-d H:i:s',time()+3); $app=$repo->markReadyToMaterialize((int)$app->data['id'],$later2,$later3);
    $later4=gmdate('Y-m-d H:i:s',time()+4); $app=$repo->incrementAttempt((int)$app->data['id'],$later3,$later4);
    r1aAssert((int)$app->data['attempt_count']===1,'Increment attempt fallo.');
    $later5=gmdate('Y-m-d H:i:s',time()+5);
    r1aThrows(static fn()=>$repo->attachMaterializedStore((int)$app->data['id'],PHP_INT_MAX,$later4,$later5),'onboarding_store_missing');
    r1aThrows(static fn()=>$repo->attachMaterializedStore((int)$app->data['id'],$otherStore,$later4,$later5),'onboarding_store_owner_missing');
    r1aThrows(static fn()=>$repo->attachMaterializedStore((int)$app->data['id'],$foreignStore,$later4,$later5),'onboarding_store_owner_conflict');
    $app=$repo->attachMaterializedStore((int)$app->data['id'],$historicalStore,$later4,$later5);
    r1aAssert($app->data['status']===Application::STORE_MATERIALIZED,'Materializacion fallo.');
    $failureInput=$input;
    $failureInput['public_id']='onb_'.wp_generate_password(24,false);
    $failureInput['idempotency_key_hash']=hash('sha256','r1a-failure-'.wp_generate_password(24,true,true));
    $failed=$repo->createProvisioning($failureInput); $applicationIds[]=(int)$failed->data['id'];
    r1aThrows(static fn()=>$repo->markProvisioningFailed((int)$failed->data['id'],'future_failure',$now,$later),'onboarding_invalid_failure_code');
    $failed=$repo->markProvisioningFailed((int)$failed->data['id'],Application::ACCOUNT_PROVISIONING_FAILED,$now,$later);
    r1aAssert($failed->data['failure_code']===Application::ACCOUNT_PROVISIONING_FAILED,'No persistio failure_code cerrado.');
    $failed=$repo->markAbandoned((int)$failed->data['id'],$later,$later2);
    r1aAssert($failed->data['failure_code']===null,'No limpio failure_code al abandonar.');
    foreach(['password','nonce','captcha','payload','idempotency_key'] as $secret) r1aAssert(!in_array($secret,$expectedColumns,true),"Columna secreta {$secret}.");

    $migration->up();
    r1aAssert((string)get_option('veciahorra_db_version')==='0.30.0','Version instalada invalida.');
    echo "R1A_SCHEMA=PASS\nR1A_OWNERSHIP=PASS\nR1A_BACKFILL_ADVERSARIAL=6/6\nR1A_ONBOARDING=PASS\nR1A_IDEMPOTENCY=PASS\nR1A_CAS=PASS\n";
} finally {
    foreach($applicationIds as $id)$wpdb->delete($applications,['id'=>$id]);
    foreach($userIds as $id)delete_user_meta($id,MinimarketRole::STORE_META_KEY);
    foreach(array_reverse($storeIds) as $id)$wpdb->delete($stores,['id'=>$id]);
    require_once ABSPATH.'wp-admin/includes/user.php'; foreach(array_reverse($userIds) as $id)wp_delete_user($id);
    $after=['stores'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$stores}"),'applications'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$applications}"),'users'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}"),'meta'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key=%s",MinimarketRole::STORE_META_KEY))];
    r1aAssert($after===$before,'Cleanup R1A incompleto: '.wp_json_encode(['before'=>$before,'after'=>$after]));
    echo "R1A_FIXTURE_CLEANUP=PASS\n";
}
