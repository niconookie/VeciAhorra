<?php
declare(strict_types=1);
/** Disposable MariaDB + native WP image editor + real localhost HTTP. No wp-load/wp-config. */
if(PHP_SAPI!=='cli'||getenv('VA_PROOF_TEST')!=='1')throw new RuntimeException('explicit_disposable_test_required');
$wpRoot=getenv('VA_TERRITORY_WP_ROOT')?:'C:/xampp/htdocs/Minimarket';
$plugin=getenv('VA_PROOF_PLUGIN_ROOT')?:dirname(__DIR__,2);
$race=($argv[1]??'')==='--return-race';
$run=$race?(string)$argv[3]:dirname(__DIR__,3).'/proof-runtime-'.bin2hex(random_bytes(8));
if(!str_starts_with(str_replace('\\','/',realpath(dirname($run))), 'C:/xampp/htdocs/Minimarket/wp-content/plugins/veciahorra/artifacts'))throw new RuntimeException('local_disposable_root_required');
if(!$race)mkdir($run,0700);
define('ABSPATH',rtrim($wpRoot,'/\\').'/');define('WPINC','wp-includes');define('WP_CONTENT_DIR',$run);
define('WP_DEBUG',false);define('WP_DEBUG_DISPLAY',false);define('WP_MEMORY_LIMIT','128M');define('WP_MAX_MEMORY_LIMIT','256M');
define('DAY_IN_SECONDS',86400);define('KB_IN_BYTES',1024);define('MB_IN_BYTES',1048576);define('GB_IN_BYTES',1073741824);
define('DB_CHARSET','utf8mb4');define('DB_COLLATE','');
function get_userdata($id){return $id>0?(object)['ID'=>$id]:false;}
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
    $id=(int)$argv[4];$action=$argv[5];$returns=new \VeciAhorra\Modules\Couriers\Returns\ReturnService();
    identity($action==='receive'?'store':'courier',$action==='receive'?3001:2001);
    $nativeProof=new DeliveryProofService(new DeliveryProofRepository(),new \VeciAhorra\Modules\Couriers\Repository\CourierDeliveryRepository(),new class extends PrivateDeliveryStorage{public function prepare(array $upload):array{$file=parent::prepare($upload);echo "PREPARED=1\n";flush();return$file;}});
    echo "STARTED=1\n";flush();
    try{
        $result=match($action){
            'open'=>$returns->open($id,2,$argv[6],'race'),
            'receive'=>$returns->receive($id,3,$argv[6],'race'),
            'normal'=>$nativeProof->confirm($id,2,$argv[6],['tmp_name'=>$run.'/input.jpg','error'=>0],false,false,true),
            'abandon'=>(new CourierDeliveryService())->abandon($id,1001,'race',3),
        };
        echo "RESULT=success\n";
    }catch(DomainException $e){echo "RESULT=conflict CODE=".$e->getMessage()."\n";}
    exit;
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
    (new \VeciAhorra\Database\Migrations\CreateDeliveryReturns())->up();
    (new \VeciAhorra\Database\Migrations\CreateDeliveryReturns())->up();
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
    sql('CREATE TABLE t_usermeta (umeta_id BIGINT AUTO_INCREMENT PRIMARY KEY,user_id BIGINT,meta_key VARCHAR(255),meta_value TEXT) ENGINE=InnoDB');
    sql("UPDATE t_va_stores SET owner_user_id=3001,onboarding_status='complete',approved_at=created_at WHERE id=1001");
    insertRow('stores',['id'=>1002,'owner_user_id'=>3002,'business_name'=>'Other Store','legal_name'=>'Other Store','owner_name'=>'Other','rut'=>'test2','email'=>'other@example.test','phone'=>'123','address'=>'Other','commune'=>'Other','status'=>'active','onboarding_status'=>'complete','approved_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
    $returns=new \VeciAhorra\Modules\Couriers\Returns\ReturnService();
    foreach(\VeciAhorra\Modules\Couriers\Returns\ReturnService::REASONS as $reason){
        $f=picked();$id=$f['delivery'];$code=ownerCode($id);$before=$repo->otp($id);
        foreach([['',2,'note'],['arbitrary',2,'note'],[$reason,null,'note'],[$reason,2,''],[$reason,2,str_repeat('x',501)]] as [$bad,$version,$note])rejected(fn()=>$returns->open($id,$version,$bad,$note),'invalid_open_input');
        foreach([['courier',2002,1002],['customer',1001,1001],['store',3001,1001],['admin',4001,1001],['anonymous',0,1001]] as [$role,$user,$driver]){identity($role,$user,$driver);rejected(fn()=>$returns->open($id,2,$reason,'note'),'OPEN_AUTHORITY_'.$role);}
        identity('courier',2001);
        $result=$returns->open($id,2,$reason,'note');
        check($result['status']==='return_pending'&&row('orders',$f['order'])['status']==='return_pending','OPEN_DELIVERY_ORDER');
        check((int)row('deliveries',$id)['courier_id']===1001,'CUSTODY_OWNER');
        check(countEvents($id)===3,'TRACKING_OPEN');
        $otp=$repo->otp($id);check($otp['invalidated_at']!==null&&$otp['code_hash']===''&&$otp['generation_context']===''&&$otp['attempts']===$before['attempts'],'OTP_INVALIDATED');
        check($returns->open($id,2,$reason,'note')===$result&&countEvents($id)===3,'OPEN_REPLAY');
        rejected(fn()=>$returns->open($id,2,$reason,'different'),'OPEN_CONTRADICTORY');
        rejected(fn()=>$proof->confirm($id,3,$code,$upload,false,false,true),'DELIVERY_AFTER_INCIDENT');
        rejected(fn()=>$courier->abandon($id,1001,'reason',3),'ABANDON_CUSTODY');
        $available=fixture();rejected(fn()=>$courier->accept($available['delivery'],1001,0),'NEW_ACCEPTANCE_CUSTODY');
        check($courier->available(1001)===[],'AVAILABLE_HIDDEN_CUSTODY');
        identity('admin',4001);rejected(fn()=>$courier->adminChange($id,1002,'reason',3),'REASSIGN_CUSTODY');
        $courier->suspend(1001,$now);check((int)row('deliveries',$id)['courier_id']===1001&&row('deliveries',$id)['status']==='return_pending','SUSPENSION_CUSTODY');
        sql("UPDATE t_va_couriers SET status='approved' WHERE id=1001");
        foreach([['store',3002],['courier',2001],['customer',1001],['anonymous',0],['admin',4001]] as [$role,$user]){identity($role,$user);rejected(fn()=>$returns->receive($id,3,'intact','received'),'RECEIVE_AUTHORITY_'.$role);}
        identity('store',3001);
        foreach([['arbitrary',3,'ok'],['intact',null,'ok'],['intact',3,''],['intact',3,str_repeat('x',501)]] as [$condition,$version,$note])rejected(fn()=>$returns->receive($id,$version,$condition,$note),'invalid_receipt');
        $received=$returns->receive($id,3,'intact','received');
        check($received['status']==='returned_to_store'&&row('orders',$f['order'])['status']==='incident_review','RECEIPT_DELIVERY_ORDER');
        check($returns->receive($id,3,'intact','received')===$received&&countEvents($id)===4,'RECEIPT_REPLAY');
        rejected(fn()=>$returns->receive($id,3,'damaged','received'),'RECEIPT_CONTRADICTORY');
        $events=$wpdb->get_results('SELECT * FROM t_va_delivery_tracking WHERE delivery_id='.$id.' ORDER BY transition_version',ARRAY_A);
        check($events[2]['reason_code']===$reason&&$events[2]['reason']==='note'&&(int)$events[2]['actor_id']===2001&&$events[3]['reason_code']==='intact'&&(int)$events[3]['actor_id']===3001&&!empty($events[3]['created_at']),'TRACKING_ACTORS_APPEND');
        identity('courier',2001);check($courier->accept($available['delivery'],1001,0)['status']==='assigned','CUSTODY_ENDED_ACCEPTANCE');
        $courier->abandon($available['delivery'],1001,'test cleanup',1);
    }
    foreach(['damaged','incomplete'] as $condition){$f=picked();$returns->open($f['delivery'],2,'recipient_rejected','note');identity('store',3001);check($returns->receive($f['delivery'],3,$condition,'observed')['status']==='returned_to_store','RECEIPT_CONDITION_'.$condition);}
    $f=picked();sql("UPDATE t_va_deliveries SET status='assigned' WHERE id={$f['delivery']}");rejected(fn()=>$returns->open($f['delivery'],2,'recipient_absent','note'),'OPEN_STATE_REQUIRED');sql("UPDATE t_va_deliveries SET status='picked_up' WHERE id={$f['delivery']}");identity('store',3001);rejected(fn()=>$returns->receive($f['delivery'],2,'intact','too soon'),'RECEIPT_BEFORE_RETURN');
    identity('courier',2001);$pending=fixture();rejected(fn()=>$returns->open($pending['delivery'],0,'recipient_absent','note'),'OPEN_REQUIRES_PICKED_UP');
    $pickup=fixture(1,'pickup');sql("UPDATE t_va_deliveries SET status='picked_up',courier_id=1001,transition_version=2 WHERE id={$pickup['delivery']}");rejected(fn()=>$returns->open($pickup['delivery'],2,'recipient_absent','note'),'PICKUP_EXCLUDED');
    foreach(['delivery_tracking'=>'INSERT','orders'=>'UPDATE','deliveries'=>'UPDATE','delivery_returns'=>'INSERT','delivery_otps'=>'UPDATE'] as $table=>$operation){
        sql("CREATE TRIGGER return_fail BEFORE {$operation} ON t_va_{$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected failure'");
        failWrite(fn()=>$returns->open($f['delivery'],2,'photo_unavailable','note'),'OPEN_FAIL_'.$table);
        check(row('deliveries',$f['delivery'])['status']==='picked_up'&&row('orders',$f['order'])['status']==='paid'&&$repo->otp($f['delivery'])['invalidated_at']===null&&countEvents($f['delivery'])===2,'ATOMIC_OPEN_'.$table);
        sql('DROP TRIGGER return_fail');
    }
    $returns->open($f['delivery'],2,'photo_unavailable','note');identity('store',3001);
    foreach(['delivery_tracking'=>'INSERT','orders'=>'UPDATE','deliveries'=>'UPDATE','delivery_returns'=>'UPDATE'] as $table=>$operation){
        sql("CREATE TRIGGER return_fail BEFORE {$operation} ON t_va_{$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected failure'");
        failWrite(fn()=>$returns->receive($f['delivery'],3,'incomplete','note'),'RECEIPT_FAIL_'.$table);
        check(row('deliveries',$f['delivery'])['status']==='return_pending'&&row('orders',$f['order'])['status']==='return_pending'&&(int)row('deliveries',$f['delivery'])['courier_id']===1001&&countEvents($f['delivery'])===3,'ATOMIC_RECEIPT_'.$table);
        sql('DROP TRIGGER return_fail');
    }
    $returns->receive($f['delivery'],3,'incomplete','note');
    check((int)$wpdb->get_var('SELECT COUNT(*) FROM t_va_orders WHERE id BETWEEN 18 AND 22')===0,'PROTECTED_ORDERS_ABSENT');
    function raceReturn(array $f,array $first,array $second):array{
        global $admin,$database,$run;
        $admin->select_db($database);$admin->query('START TRANSACTION');
        $admin->query('SELECT id FROM t_va_couriers WHERE id=1001 FOR UPDATE');
        $workers=[];$descriptors=[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
        try{
            foreach([$first,$second] as [$action,$code]){
                $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--return-race',$database,$run,(string)$f['delivery'],$action,$code],$descriptors,$pipes);
                if(!is_resource($process))throw new RuntimeException('worker_unavailable');
                $workers[]=[$process,$pipes];check(trim((string)fgets($pipes[1]))==='STARTED=1','RACE_WORKER_STARTED');
                if($action==='normal')check(trim((string)fgets($pipes[1]))==='PREPARED=1','RACE_NATIVE_PHOTO_PREPARED');
            }
            $deadline=microtime(true)+20;$blocked=0;
            // Observe both executing lock requests while this connection owns the InnoDB row lock.
            // PROCESSLIST is live; INNODB_LOCK_WAITS snapshots can lag on this MariaDB release.
            do{$result=$admin->query("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB='{$database}' AND INFO='SELECT id FROM t_va_couriers WHERE id=1001 FOR UPDATE'");$blocked=(int)$result->fetch_row()[0];if($blocked===2)break;usleep(250000);}while(microtime(true)<$deadline);
            $overlapped=$blocked>=2;
        }finally{$admin->query('COMMIT');}
        $results=[];
        foreach($workers as [$process,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);foreach($pipes as $pipe)fclose($pipe);if(!$overlapped)echo 'RACE_DIAGNOSTIC='.$out.$err;check(proc_close($process)===0&&$err==='','RACE_WORKER_CLEAN');$results[]=str_contains($out,'RESULT=success')?'success':'conflict';}
        check($overlapped,'RACE_REAL_OVERLAPPING_LOCK_WAITS');
        return $results;
    }
    $f=picked();$out=raceReturn($f,['open','recipient_absent'],['open','recipient_rejected']);check(count(array_filter($out,fn($v)=>$v==='success'))===1&&countEvents($f['delivery'])===3,'RACE_OPEN_ONE_WINNER');
    identity('store',3001);$returns->receive($f['delivery'],3,'intact','cleanup');
    $f=picked();$code=ownerCode($f['delivery']);$out=raceReturn($f,['open','recipient_absent'],['normal',$code]);
    check(count(array_filter($out,fn($v)=>$v==='success'))===1&&countEvents($f['delivery'])===3,'RACE_NORMAL_INCIDENT_ONE_WINNER');
    $status=row('deliveries',$f['delivery'])['status'];check(($status==='delivered'&&row('orders',$f['order'])['status']==='delivered'&&$repo->evidence($f['delivery'])!==null)||($status==='return_pending'&&row('orders',$f['order'])['status']==='return_pending'&&$repo->evidence($f['delivery'])===null),'RACE_NORMAL_INCIDENT_ATOMIC');
    if($status==='return_pending'){identity('store',3001);$returns->receive($f['delivery'],3,'intact','cleanup');}
    $f=picked();$returns->open($f['delivery'],2,'otp_unavailable','race');$out=raceReturn($f,['receive','intact'],['receive','damaged']);check(count(array_filter($out,fn($v)=>$v==='success'))===1&&countEvents($f['delivery'])===4&&row('orders',$f['order'])['status']==='incident_review','RACE_RECEIPT_ONE_WINNER');
    $f=picked();$returns->open($f['delivery'],2,'otp_unavailable','race');$out=raceReturn($f,['receive','intact'],['abandon','']);check($out===['success','conflict']&&row('deliveries',$f['delivery'])['status']==='returned_to_store'&&countEvents($f['delivery'])===4,'RACE_RECEIPT_CONTRADICTORY');
    $missing=picked();sql('DELETE FROM t_va_delivery_otps WHERE delivery_id='.$missing['delivery']);check($returns->open($missing['delivery'],2,'otp_unavailable',str_repeat('á',500))['status']==='return_pending'&&$repo->otp($missing['delivery'])===null,'MISSING_OTP_NO_REGENERATION_500_UNICODE');identity('store',3001);$returns->receive($missing['delivery'],3,'intact',str_repeat('á',500));
    foreach(['class-wp-http-response.php','rest-api/class-wp-rest-response.php','rest-api/class-wp-rest-request.php','rest-api.php'] as $file)require ABSPATH.WPINC.'/'.$file;
    $routes=new \VeciAhorra\Modules\Couriers\Returns\ReturnRoutes();
    $f=picked();$request=new WP_REST_Request('POST');$request->set_body_params(['id'=>$f['delivery'],'reason'=>'recipient_absent','observation'=>'private internal note','confirmed'=>true]);
    check($routes->open($request)->get_data()['error']['code']==='expected_version_required','ROUTE_VERSION_REQUIRED');
    $request->set_body_params(['id'=>$f['delivery'],'expected_version'=>2,'reason'=>'recipient_absent','observation'=>'private internal note','confirmed'=>'true']);
    check($routes->open($request)->get_data()['error']['code']==='return_confirmation_required','ROUTE_CONFIRMATION_STRICT');
    $request->set_param('confirmed',true);check($routes->open($request)->get_status()===200,'ROUTE_OPEN_CONNECTED');
    identity('courier',2002,1002);check($routes->owned()->get_data()['data']===[],'COURIER_OTHER_RETURN_HIDDEN');
    identity('courier',2001);$view=$routes->owned()->get_data()['data'];check(count($view)>0&&!str_contains(json_encode($view),'private internal note')&&!str_contains(json_encode($view),'code_hash'),'COURIER_SAFE_RETURN_VIEW');
    identity('store',3002);check($routes->pending()->get_data()['data']===[],'STORE_OTHER_RETURN_HIDDEN');
    identity('store',3001);$view=$routes->pending()->get_data()['data'];check(count($view)===1&&(int)$view[0]['id']===$f['delivery']&&!str_contains(json_encode($view),'private internal note'),'STORE_OWN_RETURN_VIEW');
    identity('anonymous');check($routes->pending()->get_status()===409&&$routes->owned()->get_status()===409,'ANONYMOUS_RETURN_HIDDEN');
    foreach(['return_pending','returned_to_store'] as $state){
        $context=['checkout_status'=>'payment_started','fulfillment_method'=>'delivery','payment'=>['status'=>'paid'],'attempt'=>['reconciliation_status'=>'completed','financial_status'=>'approved','business_status'=>'completed'],'deliveries'=>[['status'=>$state]]];
        $status=(new \VeciAhorra\Modules\CustomerPanel\Service\CustomerPurchaseStatusResolver())->resolve($context);
        check($status->code===$state&&!str_contains(json_encode($status),'private internal note'),'CUSTOMER_SAFE_STATUS_'.$state);
    }
    require ABSPATH.WPINC.'/utf8.php';
    add_filter('pre_option_blog_charset',static fn()=>'UTF-8');
    identity('admin',4001);ob_start();(new \VeciAhorra\Modules\Couriers\Returns\ReturnAdmin())->render();$html=ob_get_clean();check(str_contains($html,'private internal note')&&!str_contains($html,'<button')&&!str_contains($html,'code_hash'),'ADMIN_READ_ONLY_INCIDENT');
    identity('customer');ob_start();(new \VeciAhorra\Modules\Couriers\Returns\ReturnAdmin())->render();check(ob_get_clean()==='','ADMIN_VIEW_AUTHORIZED_ONLY');
    echo "RETURN_FLOW=PASS ASSERTIONS={$assertions}\n";
} finally {
    $wpdb->close();$admin->query("DROP DATABASE {$database}");$GLOBALS['cleaned']=true;removeRun($run);$admin->close();
    echo "DISPOSABLE_DATABASE_REMOVED=yes TEMP_FILES_REMOVED=yes\n";
}
