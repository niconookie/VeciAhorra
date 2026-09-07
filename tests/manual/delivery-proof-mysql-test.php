<?php
declare(strict_types=1);
/** Disposable MariaDB + native WP image editor + real localhost HTTP. No wp-load/wp-config. */
if(PHP_SAPI!=='cli'||getenv('VA_PROOF_TEST')!=='1')throw new RuntimeException('explicit_disposable_test_required');
$wpRoot=getenv('VA_TERRITORY_WP_ROOT')?:'C:/xampp/htdocs/Minimarket';
$plugin=getenv('VA_PROOF_PLUGIN_ROOT')?:dirname(__DIR__,2);
$race=($argv[1]??'')==='--race';
$run=$race?(string)$argv[3]:dirname(__DIR__,3).'/proof-runtime-'.bin2hex(random_bytes(8));
if(!str_starts_with(str_replace('\\','/',realpath(dirname($run))), 'C:/xampp/htdocs/Minimarket/wp-content/plugins/veciahorra/artifacts'))throw new RuntimeException('local_disposable_root_required');
if(!$race)mkdir($run,0700);
define('ABSPATH',rtrim($wpRoot,'/\\').'/');define('WPINC','wp-includes');define('WP_CONTENT_DIR',$run);
define('WP_DEBUG',false);define('WP_DEBUG_DISPLAY',false);define('WP_MEMORY_LIMIT','128M');define('WP_MAX_MEMORY_LIMIT','256M');
define('DAY_IN_SECONDS',86400);define('KB_IN_BYTES',1024);define('MB_IN_BYTES',1048576);define('GB_IN_BYTES',1073741824);
define('DB_CHARSET','utf8mb4');define('DB_COLLATE','');
function wp_cache_get(...$args){return false;}function wp_cache_set(...$args){return true;}
function is_user_logged_in():bool{return ($GLOBALS['identity']['id']??0)>0;}
function get_current_user_id():int{return $GLOBALS['identity']['id']??0;}
function current_user_can($cap):bool{return in_array($cap,$GLOBALS['identity']['caps']??[],true);}
function get_user_meta($id,$key,$single=false){return $key==='_veciahorra_courier_id'?($GLOBALS['identity']['courier']??0):'';}
function wp_salt($scheme='auth'):string{return hash_hmac('sha256',$scheme,$GLOBALS['proofSecret']);}
function admin_url($path=''):string{return 'http://127.0.0.1/Minimarket/wp-admin/'.$path;}
function content_url($path=''):string{return 'http://127.0.0.1/'.str_replace('C:/xampp/htdocs/','',str_replace('\\','/',WP_CONTENT_DIR)).$path;}
function wp_remote_get($url,$args=[]){
    if(!str_starts_with($url,'http://127.0.0.1/'))throw new RuntimeException('nonlocal_http_forbidden');
    $curl=curl_init($url);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_TIMEOUT=>10]);
    $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
    return ['body'=>$body===false?'':$body,'response'=>['code'=>$status]];
}
function wp_remote_retrieve_response_code($r):int{return (int)$r['response']['code'];}
function wp_remote_retrieve_body($r):string{return $r['body'];}
foreach(['compat.php','plugin.php','load.php','class-wp-error.php','functions.php','formatting.php','shortcodes.php','media.php','class-wpdb.php'] as $f)require ABSPATH.WPINC.'/'.$f;
if(getenv('VA_PROOF_COMPOSER')==='1')require $plugin.'/vendor/autoload.php';
else spl_autoload_register(static function($class)use($plugin){if(str_starts_with($class,'VeciAhorra\\')){$path=$plugin.'/app/'.str_replace('\\','/',substr($class,11)).'.php';if(is_file($path))require $path;}});
use VeciAhorra\Modules\Couriers\Service\CourierDeliveryService;
use VeciAhorra\Modules\Couriers\Evidence\DeliveryProofService;
use VeciAhorra\Modules\Couriers\Evidence\DeliveryProofRepository;
use VeciAhorra\Modules\Couriers\Evidence\PrivateDeliveryStorage;
use VeciAhorra\Modules\Couriers\Evidence\DeliveryOtp;
$GLOBALS['proofSecret']=$race?(string)getenv('VA_PROOF_SECRET'):bin2hex(random_bytes(32));
if(!$race)putenv('VA_PROOF_SECRET='.$GLOBALS['proofSecret']);
$database=$race?(string)$argv[2]:'va_proof_test_'.bin2hex(random_bytes(8));
if(!preg_match('/^va_proof_test_[a-f0-9]{16}$/D',$database))throw new RuntimeException('disposable_db_required');
$admin=new mysqli('127.0.0.1',getenv('VA_TERRITORY_DB_USER')?:'root',getenv('VA_TERRITORY_DB_PASSWORD')?:'','',3306);
if(!$race)$admin->query("CREATE DATABASE {$database} CHARACTER SET utf8mb4");
function removeRun(string $path):void{
    foreach(new DirectoryIterator($path) as $entry){if($entry->isDot())continue;$p=$entry->getPathname();if($entry->isDir()&&!$entry->isLink())removeRun($p);else unlink($p);}rmdir($path);
}
if(!$race)register_shutdown_function(function()use($admin,$database,$run){if(!($GLOBALS['cleaned']??false)){$admin->query("DROP DATABASE IF EXISTS {$database}");if(is_dir($run))removeRun($run);}});
$wpdb=new wpdb(getenv('VA_TERRITORY_DB_USER')?:'root',getenv('VA_TERRITORY_DB_PASSWORD')?:'',$database,'127.0.0.1:3306');
$wpdb->set_prefix('t_');$wpdb->suppress_errors(true);
function identity(string $role,int $id=1001,int $courier=1001):void{$GLOBALS['identity']=['id'=>$role==='anonymous'?0:$id,'courier'=>$courier,'caps'=>match($role){'courier'=>['veciahorra_manage_deliveries'],'admin'=>['manage_options','veciahorra_manage_store'],'store'=>['veciahorra_manage_store'],default=>[]}];}
if($race){
    identity('customer');$s=new DeliveryProofService();$id=(int)$argv[4];$code=$s->customer($id)['otp'];identity('courier',2001);
    if(($argv[5]??'')==='hold'){
        $slow=new class extends PrivateDeliveryStorage {public function move(array $file):void{parent::move($file);echo "LOCKED=1\n";flush();usleep(1200000);}};
        $s=new DeliveryProofService(new DeliveryProofRepository(),new \VeciAhorra\Modules\Couriers\Repository\CourierDeliveryRepository(),$slow);
    }else{echo "STARTED=1\n";flush();}
    $s->confirm($id,2,(string)$code,['tmp_name'=>$run.'/input.jpg','error'=>0],false,false,true);
    echo "RESULT=delivered\n";exit;
}
$assertions=0;
function check(bool $ok, string $label): void { $GLOBALS['assertions']++; if (!$ok) throw new RuntimeException('FAIL '.$label); }
function sql(string $query): void { global $wpdb; if ($wpdb->query($query) === false) throw new RuntimeException($wpdb->last_error); }
function insertRow(string $table, array $data): int { global $wpdb; if ($wpdb->insert('t_va_'.$table,$data) !== 1) throw new RuntimeException($wpdb->last_error); return (int)$wpdb->insert_id; }
function rejected(callable $call, string $label): void { try { $call(); } catch (DomainException|InvalidArgumentException $e) { if($label==='OTP_WRONG_REJECTED' && $e->getMessage()!=='otp_invalid')throw new RuntimeException('unexpected:'.$e->getMessage()); check(true,$label); return; } throw new RuntimeException('FAIL '.$label); }
function row(string $table, int $id): array { global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM t_va_'.$table.' WHERE id=%d',$id),ARRAY_A); }
function fixture(int $zone=1, string $method='delivery', string $preparation='ready_for_pickup', bool $withDelivery=true): array {
    $now=current_time('mysql',true);
    $order=insertRow('orders',['customer_id'=>1001,'minimarket_id'=>1001,'total'=>'10000.00','status'=>'paid','service_zone_id'=>$zone?:null,'store_fulfillment_status'=>$preparation,'created_at'=>$now,'updated_at'=>$now]);
    $snapshot=['delivery_recipient_name'=>'Recipient','delivery_contact_phone'=>'+56911111111','delivery_address_line1'=>'Destination 1','delivery_commune'=>'Commune'];
    $checkout=insertRow('checkouts',[...$snapshot,'public_id'=>'chk_'.bin2hex(random_bytes(20)),'owner_type'=>'user','user_id'=>1001,'status'=>'payment_completed','fulfillment_method'=>$method,'service_zone_id'=>$zone?:null,'total_amount'=>'10000.00','created_at'=>$now,'updated_at'=>$now]);
    insertRow('checkout_orders',['checkout_id'=>$checkout,'order_id'=>$order,'created_at'=>$now]);
    $delivery=$withDelivery?insertRow('deliveries',[...$snapshot,'order_id'=>$order,'customer_id'=>1001,'minimarket_id'=>1001,'service_zone_id'=>$zone?:null,'status'=>'pending','created_at'=>$now,'updated_at'=>$now]):0;
    return compact('order','checkout','delivery');
}


try {
    $schemaClasses = [
        'Tables\\CouriersTable','Tables\\StoresTable','Tables\\ServiceZonesTable','Tables\\StoreServiceZonesTable',
        'Schemas\\CheckoutSchema','Schemas\\OrderSchema','Schemas\\DeliverySchema','Schemas\\CheckoutOrderSchema',
        'Schemas\\DeliveryTrackingSchema',
    ];
    foreach ($schemaClasses as $suffix) {
        $class='VeciAhorra\\Database\\'.$suffix; $schema=new $class();
        $builder=VeciAhorra\Database\Builder\TableBuilder::make('t_va_'.$schema->name());
        $schema->define($builder); sql($builder->build($wpdb->get_charset_collate()));
        sql('ALTER TABLE t_va_'.$schema->name().' AUTO_INCREMENT=1001');
    }

    (new \VeciAhorra\Database\Migrations\CreateDeliveryProof())->up();
    (new \VeciAhorra\Database\Migrations\AddDeliveryEvidenceConfirmation())->up();
    (new \VeciAhorra\Database\Migrations\AddDeliveryEvidenceConfirmation())->up();
    (new \VeciAhorra\Database\Migrations\CreateDeliveryProof())->up();
    $now=current_time('mysql',true);
    foreach ([1,2,3] as $zone) insertRow('service_zones',['id'=>$zone,'commune'=>'Commune','name'=>'Zone '.$zone,'status'=>$zone===3?'inactive':'active','created_at'=>$now,'updated_at'=>$now]);
    insertRow('stores',['id'=>1001,'business_name'=>'Store','legal_name'=>'Store','owner_name'=>'Owner','rut'=>'test','email'=>'store@example.test','phone'=>'+56922222222','address'=>'Pickup 1','commune'=>'Commune','status'=>'active','created_at'=>$now,'updated_at'=>$now]);
    foreach([1,2,3] as $zone) insertRow('store_service_zones',['store_id'=>1001,'zone_id'=>$zone,'assigned_by'=>1001,'assigned_at'=>$now]);
    foreach([1001=>1,1002=>1,1003=>2,1004=>null,1005=>3] as $id=>$zone) insertRow('couriers',['id'=>$id,'display_name'=>'Courier '.$id,'phone'=>'+56933333333','status'=>'approved','service_zone_id'=>$zone,'created_at'=>$now,'updated_at'=>$now]);

    $proof=new DeliveryProofService();$repo=new DeliveryProofRepository();$courier=new CourierDeliveryService();$storage=new PrivateDeliveryStorage();
    function nfiles():int{return count(glob(WP_CONTENT_DIR.'/veciahorra-private-delivery/*.jpg')?:[]);}
    function countEvents(int $id):int{global $wpdb;return (int)$wpdb->get_var('SELECT COUNT(*) FROM t_va_delivery_tracking WHERE delivery_id='.$id);}
    function failWrite(callable $cb,string $label):void{try{$cb();}catch(Throwable){check(true,$label);return;}throw new RuntimeException('FAIL '.$label);}
    function picked():array{identity('courier',2001);$f=fixture();$s=new CourierDeliveryService();$s->accept($f['delivery'],1001,0);$s->transition($f['delivery'],1001,'picked_up',1);return $f;}
    function ownerCode(int $id):string{identity('customer');$code=(new DeliveryProofService())->customer($id)['otp'];identity('courier',2001);return $code;}
    function assertUnchanged(array $f,int $files,string $label):void{
        check(row('deliveries',$f['delivery'])['status']==='picked_up'&&row('orders',$f['order'])['status']==='paid'
          &&(new DeliveryProofRepository())->evidence($f['delivery'])===null&&(new DeliveryProofRepository())->otp($f['delivery'])['consumed_at']===null
          &&countEvents($f['delivery'])===2&&nfiles()===$files,$label);
    }
    $im=imagecreatetruecolor(2000,1000);imagejpeg($im,$run.'/input.jpg',90);imagepng($im,$run.'/input.png');imagewebp($im,$run.'/input.webp');imagedestroy($im);
    $bytes=file_get_contents($run.'/input.jpg');$exif="Exif\0\0II".pack('vVv',42,8,1).pack('vvVv',0x112,3,1,6)."\0\0".pack('V',0);
    file_put_contents($run.'/input.jpg',substr($bytes,0,2)."\xff\xe1".pack('n',strlen($exif)+2).$exif.substr($bytes,2));
    $upload=['tmp_name'=>$run.'/input.jpg','error'=>UPLOAD_ERR_OK,'name'=>'untrusted.jpg','type'=>'ignored'];
    // Native multipart HTTP exercises is_uploaded_file and the productive route/service.
    $httpConfig=[ABSPATH,$plugin,$run,$database,$GLOBALS['proofSecret'],getenv('VA_TERRITORY_DB_USER')?:'root',getenv('VA_TERRITORY_DB_PASSWORD')?:''];
    file_put_contents($run.'/upload.php','<?php $proofTestConfig='.var_export($httpConfig,true).';require '.var_export(__DIR__.'/delivery-choice-http-fixture.php',true).';');
    function postPhoto(int $id,array $fields,string $query=''):array{
        $curl=curl_init(content_url('/upload.php?id=').$id.$query);
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_PROXY=>'',CURLOPT_TIMEOUT=>20,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$fields]);
        $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
        return [$status,json_decode((string)$body,true)];
    }
    $httpFixture=picked();$httpId=$httpFixture['delivery'];$httpCode=ownerCode($httpId);
    $fields=['expected_version'=>'2','otp'=>$httpCode,'photo'=>new CURLFile($run.'/input.jpg','image/jpeg','camera.jpg')];
    foreach([null,'0','false','true','2','1x',' 1'] as $value){
        $attempt=$fields;if($value!==null)$attempt['courier_confirmation']=$value;
        [$status,$data]=postPhoto($httpId,$attempt);
        check($status===409&&($data['error']['code']??'')==='courier_confirmation_required','HTTP_CONFIRMATION_REJECTED');
    }
    foreach([[$fields,'&courier_confirmation=1'],[array_merge($fields,['courier_confirmation[]'=>'1']),'']] as [$attempt,$query]){
        [$status,$data]=postPhoto($httpId,$attempt,$query);
        check($status===409&&($data['error']['code']??'')==='courier_confirmation_required','HTTP_CONFIRMATION_QUERY_ARRAY_REJECTED');
    }
    assertUnchanged($httpFixture,0,'http_confirmation_no_writes');
    [$status,$data]=postPhoto($httpId,['expected_version'=>'2','otp'=>$httpCode,'courier_confirmation'=>'1']);
    check($status===409&&($data['error']['code']??'')==='photo_required','HTTP_PHOTO_REQUIRED');
    [$status,$data]=postPhoto($httpId,array_merge($fields,['courier_confirmation'=>'1']));
    check($status===200&&($data['data']['status']??'')==='delivered','HTTP_CAMERA_ACCEPTED');
    $savedFixture=picked();$savedId=$savedFixture['delivery'];$savedCode=ownerCode($savedId);
    [$status,$data]=postPhoto($savedId,['expected_version'=>'2','otp'=>$savedCode,'courier_confirmation'=>'1','photo'=>new CURLFile($run.'/input.png','image/png','previously-saved.png')]);
    check($status===200&&($data['data']['status']??'')==='delivered','HTTP_SAVED_ACCEPTED_SAME_ROUTE');
    check($repo->evidence($savedId)['courier_confirmed_at']!==null&&$repo->evidence($httpId)['courier_confirmed_at']!==null,'HTTP_CONFIRMATION_PERSISTED');
    unlink($run.'/upload.php');
    // Remove only the two disposable completed fixtures to keep baseline file counts.
    foreach([$httpId,$savedId] as $completedId){unlink($storage->path($repo->evidence($completedId)['storage_key']));}
    // Additive upgrade preserves historical NULL without fabricating an attestation.
    sql('UPDATE t_va_delivery_evidence SET courier_confirmed_at=NULL WHERE delivery_id='.$httpId);
    sql('ALTER TABLE t_va_delivery_evidence DROP COLUMN courier_confirmed_at');
    (new \VeciAhorra\Database\Migrations\AddDeliveryEvidenceConfirmation())->up();
    (new \VeciAhorra\Database\Migrations\AddDeliveryEvidenceConfirmation())->up();
    check($repo->evidence($httpId)['courier_confirmed_at']===null,'historical_confirmation_not_backfilled');
    check((new VeciAhorra\Database\Migrations\CreateDeliveryProof())->up()===null,'migration_idempotent');
    $a=picked();$id=$a['delivery'];$code=ownerCode($id);$otp=$repo->otp($id);
    check(preg_match('/^[0-9]{6}$/D',$code)===1,'six_digits');
    check(strlen($otp['code_hash'])===64&&!in_array($code,array_values($otp),true),'hash_only_no_plaintext');
    $courier->transition($id,1001,'picked_up',1);check($repo->otp($id)===$otp&&countEvents($id)===2,'pickup_replay_preserves_otp');
    check(strtotime($otp['expires_at'].' UTC')-strtotime($otp['created_at'].' UTC')===7200,'two_hour_expiry');
    check($proof->customer($id)===null,'courier_cannot_read_otp');
    $projected=(new \VeciAhorra\Modules\CustomerPanel\Query\CustomerPurchaseQuery())->deliveries([$a['order']]);
    check((int)$projected[0]['id']===$id,'customer_runtime_query_delivery_identity');
    rejected(fn()=>$proof->confirm($id,2,$code,$upload,false,false),'CONFIRMATION_OMITTED');
    rejected(fn()=>$proof->confirm($id,2,$code,$upload,false,false,false),'CONFIRMATION_FALSE');
    assertUnchanged($a,0,'confirmation_no_writes');
    rejected(fn()=>$proof->confirm($id,2,'',$upload,false,false,true),'OTP_MISSING');
    check((int)$repo->otp($id)['attempts']===1,'missing_otp_counted');
    sql("UPDATE t_va_delivery_otps SET attempts=0 WHERE delivery_id={$id}");
    rejected(fn()=>$proof->confirm($id,2,$code,[],false,false,true),'PHOTO_REQUIRED');
    rejected(fn()=>$proof->confirm($id,2,$code,$upload,true,false,true),'consent_required');
    $wrong=$code==='000000'?'000001':'000000';
    rejected(fn()=>$proof->confirm($id,2,$wrong,$upload,false,false,true),'OTP_WRONG_REJECTED');
    check((int)$repo->otp($id)['attempts']===1&&nfiles()===0,'wrong_otp_atomic_attempt_no_file');
    for($i=0;$i<4;$i++)rejected(fn()=>$proof->confirm($id,2,$wrong,$upload,false,false,true),'wrong_attempt');
    rejected(fn()=>$proof->confirm($id,2,$code,$upload,false,false,true),'fifth_attempt_locked');
    check((int)$repo->otp($id)['attempts']===5,'attempt_cap');
    identity('customer');check($proof->customer($id)['otp']===null,'locked_otp_hidden');identity('courier',2001);
    assertUnchanged($a,0,'locked_no_close');
    $a=picked();$id=$a['delivery'];$code=ownerCode($id);
    sql("UPDATE t_va_delivery_otps SET expires_at='2000-01-01 00:00:00' WHERE delivery_id={$id}");
    rejected(fn()=>$proof->confirm($id,2,$code,$upload,false,false,true),'expired_rejected');
    identity('customer');check($proof->customer($id)['otp']===null,'expired_otp_hidden');identity('courier',2001);
    $a=picked();$id=$a['delivery'];$code=ownerCode($id);
    sql("UPDATE t_va_delivery_otps SET consumed_at=created_at WHERE delivery_id={$id}");
    rejected(fn()=>$proof->confirm($id,2,$code,$upload,false,false,true),'consumed_not_delivered_rejected');
    $a=picked();$id=$a['delivery'];$code=ownerCode($id);
    identity('courier',2002,1002);rejected(fn()=>$proof->confirm($id,2,$code,$upload,false,false,true),'other_courier');
    identity('courier',2001);sql("UPDATE t_va_couriers SET status='inactive' WHERE id=1001");
    rejected(fn()=>$proof->confirm($id,2,$code,$upload,false,false,true),'suspended_courier');
    sql("UPDATE t_va_couriers SET status='approved' WHERE id=1001");
    rejected(fn()=>$proof->confirm($id,1,$code,$upload,false,false,true),'obsolete_version');
    file_put_contents($run.'/fake.jpg','this is not an image');
    rejected(fn()=>$proof->confirm($id,2,$code,['tmp_name'=>$run.'/fake.jpg','error'=>0],false,false,true),'renamed_fake_rejected');
    $large=fopen($run.'/large.jpg','w');ftruncate($large,8*1024*1024+1);fclose($large);
    rejected(fn()=>$proof->confirm($id,2,$code,['tmp_name'=>$run.'/large.jpg','error'=>0],false,false,true),'over_8mb_rejected');
    rejected(fn()=>$storage->path('../input.jpg'),'PATH_TRAVERSAL');
    rejected(fn()=>$storage->path(str_repeat('a',64).'.jpg/../input.jpg'),'path_suffix_rejected');
    sql('START TRANSACTION');rejected(fn()=>$proof->confirm($id,2,$code,$upload,false,false,true),'nested_transaction_closed');sql('ROLLBACK');
    $files=nfiles();
    foreach(['tracking'=>'delivery_tracking','order'=>'orders','otp'=>'delivery_otps','evidence'=>'delivery_evidence'] as $kind=>$table){
        $operation=$kind==='tracking'?'INSERT':'UPDATE';
        sql("CREATE TRIGGER proof_fail BEFORE {$operation} ON t_va_{$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected write failure'");
        failWrite(fn()=>$proof->confirm($id,2,$code,$upload,false,false,true),'injected_'.$kind);
        assertUnchanged($a,$files,'ATOMIC_ROLLBACK_'.$kind);
        sql('DROP TRIGGER proof_fail');
    }
    $brokenStorage=new class extends PrivateDeliveryStorage {public function move(array $file):void{unlink($this->path($file['temp']));parent::move($file);}};
    $broken=new DeliveryProofService(new DeliveryProofRepository(),new VeciAhorra\Modules\Couriers\Repository\CourierDeliveryRepository(),$brokenStorage);
    failWrite(fn()=>$broken->confirm($id,2,$code,$upload,false,false,true),'native_move_failure');
    assertUnchanged($a,$files,'move_rollback');
    sql("UPDATE t_va_orders SET status='cancelled' WHERE id={$a['order']}");
    failWrite(fn()=>$proof->confirm($id,2,$code,$upload,false,false,true),'order_zero');
    check(row('deliveries',$id)['status']==='picked_up'&&$repo->evidence($id)===null&&nfiles()===$files,'order_zero_compensates');
    sql("UPDATE t_va_orders SET status='paid' WHERE id={$a['order']}");
    check($proof->confirm($id,2,$code,$upload,false,false,true)['status']==='delivered','valid_photo_otp_delivery');
    $e=$repo->evidence($id);$path=$storage->path($e['storage_key']);$size=getimagesize($path);
    check($e['status']==='completed'&&$repo->otp($id)['consumed_at']!==null&&row('orders',$a['order'])['status']==='delivered'&&countEvents($id)===3,'delivery_order_otp_evidence_tracking_committed');
    check($e['courier_confirmed_at']===$e['created_at']&&$e['courier_confirmed_at']!==null,'confirmation_persisted');
    check($e['consented_at']===null&&(int)$e['recipient_visible']===0,'recipient_optional');
    check($size[0]===800&&$size[1]===1600&&$size['mime']==='image/jpeg','orientation_and_1600_limit');
    check(!str_contains(file_get_contents($path),"Exif\0\0")&&!str_contains(file_get_contents($path),'http://ns.adobe.com'),'metadata_removed');
    check(hash_file('sha256',$path)===$e['sha256']&&preg_match('/^[a-f0-9]{64}\.jpg$/D',$e['storage_key'])===1,'random_name_sha256');
    $http=wp_remote_get(content_url('/veciahorra-private-delivery/').$e['storage_key']);
    check(wp_remote_retrieve_response_code($http)===403&&!str_contains(wp_remote_retrieve_body($http),substr(file_get_contents($path),0,12)),'DIRECT_HTTP_BLOCKED');
    $count=nfiles();check($proof->confirm($id,2,'',[],false,false,true)['status']==='delivered','same_identity_replay');
    check(nfiles()===$count&&countEvents($id)===3,'replay_no_duplicate_file_evidence_tracking');
    rejected(fn()=>$proof->confirm($id,3,'',[],false,false,true),'replay_wrong_version');
    rejected(fn()=>$courier->transition($id,1001,'delivered',2),'legacy_courier_delivery_closed');
    identity('admin');rejected(fn()=>$courier->adminTransition($id,'delivered',2),'admin_delivery_bypass_closed');
    check(is_file($proof->download($id)['path']),'admin_read');
    identity('customer');$view=$proof->customer($id);
    check($view['otp']===null&&$view['evidence_url']!==null,'owner_detail_evidence_no_consumed_otp');
    $read=$proof->download($id);
    check(is_file($read['path'])&&$read['headers']['Cache-Control']==='private, no-store'&&$read['headers']['X-Content-Type-Options']==='nosniff'&&str_starts_with($read['headers']['Content-Disposition'],'inline;'),'owner_read_headers');
    foreach([['customer',1002],['courier',2001],['store',1001],['anonymous',0]] as [$role,$user]){
        identity($role,$user);rejected(fn()=>$proof->download($id),'READ_AUTHORIZATION_'.$role);
        check($proof->customer($id)===null,'no_customer_projection_'.$role);
        try{$proof->download($id);}catch(DomainException $first){try{$proof->download(999999);}catch(DomainException $second){check($first->getMessage()===$second->getMessage(),'no_foreign_existence_leak_'.$role);}}
    }
    // Real HTTP streaming controller, with explicitly simulated identities on localhost only.
    $controllerPhp='<?php if(!in_array($_SERVER["REMOTE_ADDR"]??"",["127.0.0.1","::1"],true)){http_response_code(404);exit;}'."\n";
    $controllerPhp.='define("ABSPATH",'.var_export(ABSPATH,true).');define("WP_CONTENT_DIR",'.var_export(WP_CONTENT_DIR,true).');define("DB_CHARSET","utf8mb4");define("DB_COLLATE","");define("WP_DEBUG",false);'."\n";
    $controllerPhp.='function is_user_logged_in(){return ($_SERVER["HTTP_X_TEST_ROLE"]??"")!=="anonymous";} function get_current_user_id(){return ($_SERVER["HTTP_X_TEST_ROLE"]??"")==="other"?1002:1001;}'."\n";
    $controllerPhp.='function current_user_can($c){$r=$_SERVER["HTTP_X_TEST_ROLE"]??"anonymous";return ($r==="admin"&&$c==="manage_options")||($r==="courier"&&$c==="veciahorra_manage_deliveries")||($r==="store"&&$c==="veciahorra_manage_store");} function content_url($p=""){return "http://127.0.0.1/".$p;} function status_header($s){http_response_code($s);}'."\n";
    $controllerPhp.='function absint($v){return abs((int)$v);} function is_multisite(){return false;} function apply_filters($tag,$v,...$args){return $v;} function has_filter(...$args){return false;} function add_filter(...$args){} function do_action(...$args){}';
    $controllerPhp.='require ABSPATH."wp-includes/class-wpdb.php";spl_autoload_register(function($c){if(str_starts_with($c,"VeciAhorra".chr(92))){$p='.var_export($plugin.'/app/',true).'.str_replace(chr(92),"/",substr($c,11)).".php";if(is_file($p))require $p;}});'."\n";
    $controllerPhp.='$wpdb=new wpdb('.var_export(getenv('VA_TERRITORY_DB_USER')?:'root',true).','.var_export(getenv('VA_TERRITORY_DB_PASSWORD')?:'',true).','.var_export($database,true).',"127.0.0.1:3306");$wpdb->set_prefix("t_");$wpdb->suppress_errors(true);$class=implode(chr(92),["VeciAhorra","Modules","Couriers","Evidence","DeliveryProofController"]);(new $class())->download();';
    file_put_contents($run.'/download.php',$controllerPhp);
    foreach(['owner'=>200,'admin'=>200,'other'=>404,'courier'=>404,'store'=>404,'anonymous'=>404] as $role=>$expected){
        $curl=curl_init(content_url('/download.php?delivery_id=').$id);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_PROXY=>'',CURLOPT_HTTPHEADER=>['X-Test-Role: '.$role],CURLOPT_TIMEOUT=>10]);
        $response=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
        check($status===$expected,'HTTP_CONTROLLER_'.$role);
        if($expected===200)check(str_contains($response,'Cache-Control: private, no-store')&&str_contains($response,'X-Content-Type-Options: nosniff')&&str_contains($response,'Content-Disposition: inline; filename="delivery-proof.jpg"'),'http_private_headers_'.$role);
    }
    unlink($run.'/download.php');
    foreach(['png','webp'] as $type){
        $b=picked();$bcode=ownerCode($b['delivery']);
        check($proof->confirm($b['delivery'],2,$bcode,['tmp_name'=>$run.'/input.'.$type,'error'=>0],true,true,true)['status']==='delivered','decode_'.$type);
        check($repo->evidence($b['delivery'])['consented_at']!==null,'visible_consent_timestamp_'.$type);
    }
    $bare=picked();rejected(fn()=>$courier->transition($bare['delivery'],1001,'delivered',2),'PHOTO_BYPASS_CLOSED');
    $pickup=fixture(1,'pickup');identity('courier',2001);
    rejected(fn()=>$courier->accept($pickup['delivery'],1001,0),'pickup_accept_closed');
    check($repo->otp($pickup['delivery'])===null&&$repo->evidence($pickup['delivery'])===null,'PICKUP_NO_OTP_EVIDENCE');
    $raceFixture=picked();$beforeFiles=nfiles();
    $descriptors=[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];$pipes1=[];$pipes2=[];
    $p1=proc_open([PHP_BINARY,__FILE__,'--race',$database,$run,(string)$raceFixture['delivery'],'hold'],$descriptors,$pipes1);
    check(is_resource($p1)&&trim((string)fgets($pipes1[1]))==='LOCKED=1','first_confirmation_uncommitted');
    $p2=proc_open([PHP_BINARY,__FILE__,'--race',$database,$run,(string)$raceFixture['delivery']],$descriptors,$pipes2);
    check(is_resource($p2)&&trim((string)fgets($pipes2[1]))==='STARTED=1','second_confirmation_overlaps');
    $out2=stream_get_contents($pipes2[1]);$err2=stream_get_contents($pipes2[2]);$out1=stream_get_contents($pipes1[1]);$err1=stream_get_contents($pipes1[2]);
    foreach([$pipes1,$pipes2] as $pipes)foreach($pipes as $pipe)fclose($pipe);
    check(proc_close($p1)===0&&proc_close($p2)===0&&$err1===''&&$err2==='','concurrent_workers_clean');
    check(str_contains($out1,'RESULT=delivered')&&str_contains($out2,'RESULT=delivered')&&nfiles()===$beforeFiles+1&&countEvents($raceFixture['delivery'])===3,'concurrent_replay_one_evidence_file');
    // Fail closed if a server stops enforcing deny-all, before processing any image.
    file_put_contents(WP_CONTENT_DIR.'/control.php','<?php http_response_code(200); echo "synthetic public probe";');
    $unsafe=new PrivateDeliveryStorage(WP_CONTENT_DIR.'/unsafe',content_url('/control.php?probe='));
    $before=nfiles();failWrite(fn()=>$unsafe->prepare($upload),'PRIVACY_FAIL_CLOSED');
    check(count(glob(WP_CONTENT_DIR.'/unsafe/*.jpg')?:[])===0&&nfiles()===$before,'privacy_probe_compensated');
    check((int)$wpdb->get_var('SELECT COUNT(*) FROM t_va_orders WHERE id BETWEEN 18 AND 22')===0,'protected_orders_absent');
    check((int)$wpdb->get_var("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND ENGINE<>'InnoDB'")===0,'innodb_real');
    echo "DELIVERY_PROOF=PASS ASSERTIONS={$assertions} IMAGE_EDITOR=WP_Image_Editor_GD HTTP_DIRECT=403\n";
} finally {
    $wpdb->close();$admin->query("DROP DATABASE {$database}");$GLOBALS['cleaned']=true;removeRun($run);$admin->close();
    echo "DISPOSABLE_DATABASE_REMOVED=yes TEMP_FILES_REMOVED=yes\n";
}
