<?php
declare(strict_types=1);
/** Native wpdb, production schemas/services and local InnoDB; no wp-load or provider network. */
if(PHP_SAPI!=='cli'||getenv('VA_REFUND_TEST')!=='1')throw new RuntimeException('explicit_disposable_test_required');
$plugin=getenv('VA_PROOF_PLUGIN_ROOT')?:dirname(__DIR__,2);
define('ABSPATH','C:/xampp/htdocs/Minimarket/');define('WPINC','wp-includes');
define('WP_DEBUG',false);define('WP_DEBUG_DISPLAY',false);define('DB_CHARSET','utf8mb4');define('DB_COLLATE','');
define('VA_PLUGIN_URL','http://127.0.0.1/test-plugin/');
function get_current_user_id(){return $GLOBALS['user']??9001;}
$GLOBALS['blog_id']=1;
function current_user_can($cap){return $cap==='manage_options'&&in_array(get_current_user_id(),[9001,9002],true);}
function wp_cache_get(...$args){return false;}function wp_cache_set(...$args){return true;}
function wp_enqueue_script(...$args){$GLOBALS['assets'][]=$args;}
function rest_url($path){return 'http://127.0.0.1/wp-json/'.$path;}
function wp_create_nonce($action){return 'test-nonce';}
foreach(['compat.php','plugin.php','load.php','class-wp-error.php','functions.php','formatting.php','kses.php','utf8.php','class-wpdb.php','class-wp-http-response.php','rest-api/class-wp-rest-response.php','rest-api/class-wp-rest-request.php'] as $file)require ABSPATH.WPINC.'/'.$file;
add_filter('pre_option_blog_charset',static fn()=> 'UTF-8');
require $plugin.'/vendor/autoload.php';
use VeciAhorra\Modules\Couriers\Returns\ReturnRefundService;
use VeciAhorra\Modules\Payments\Gateway\RefundGatewayInterface;
use VeciAhorra\Modules\Payments\Gateway\RefundResult;
$worker=($argv[1]??'')==='--race';
$database=$worker?$argv[2]:'va_refund_test_'.bin2hex(random_bytes(8));
if(!preg_match('/^va_refund_test_[a-f0-9]{16}$/D',$database))throw new RuntimeException('unsafe_database');
$admin=new mysqli('127.0.0.1','root','','',3306);
if(!$worker)$admin->query("CREATE DATABASE {$database} CHARACTER SET utf8mb4");
if(!$worker)register_shutdown_function(static function()use($admin,$database){$admin->query("DROP DATABASE IF EXISTS {$database}");});
$wpdb=new wpdb('root','',$database,'127.0.0.1:3306');$wpdb->set_prefix('t_');$wpdb->suppress_errors(true);
$GLOBALS['assertions']=0;
function check(bool $ok,string $label):void{++$GLOBALS['assertions'];if(!$ok)throw new RuntimeException('FAIL '.$label);}
function sql(string $query):void{global $wpdb;if($wpdb->query($query)===false)throw new RuntimeException('fixture_sql_failed:'.$wpdb->last_error);}
function put(string $table,array $data):int{global $wpdb;if($wpdb->insert('t_va_'.$table,$data)!==1)throw new RuntimeException('fixture_insert_failed:'.$table.':'.$wpdb->last_error);return (int)$wpdb->insert_id;}
function row(string $table,int $id,string $key='id'):array{global $wpdb;return $wpdb->get_row("SELECT * FROM t_va_{$table} WHERE {$key}={$id}",ARRAY_A)??[];}
function countCalls():int{global $wpdb;return (int)$wpdb->get_var('SELECT COUNT(*) FROM t_va_refund_test_calls');}
function reject(callable $callback,string $label):void{try{$callback();}catch(DomainException|InvalidArgumentException|VeciAhorra\Exceptions\ConflictException){check(true,$label);return;}check(false,$label);}
function failure(callable $callback,string $label):void{try{$callback();}catch(Throwable){check(true,$label);return;}check(false,$label);}
final class FakeRefundGateway implements RefundGatewayInterface
{
    public function __construct(public int $delivery,public string $mode='refunded'){}
    public function refund(#[SensitiveParameter] string $token,int $amount):RefundResult
    {
        global $wpdb,$database;
        check((int)$wpdb->get_var('SELECT @@in_transaction')===0,'REMOTE_OUTSIDE_TRANSACTION');
        $remote=new mysqli('127.0.0.1','root','',$database,3306);
        $r=$remote->query('SELECT * FROM t_va_return_refunds WHERE delivery_id='.$this->delivery)->fetch_assoc();
        check($r && $r['status']==='refund_pending' && $r['remote_started_at']!==null,'INTENT_VISIBLE_BEFORE_REMOTE');
        check(row('orders',(int)$r['order_id'])['status']==='incident_review' && row('deliveries',$this->delivery)['status']==='returned_to_store','NO_EARLY_SUCCESS');
        check(preg_match('/^localtoken[a-f0-9]{32}$/D',$token)===1,'OPERATIONAL_TOKEN');
        check($amount===(int)$r['total_refund'] && $amount>0,'INTEGER_REMOTE_AMOUNT');
        $remote->query('INSERT INTO t_va_refund_test_calls (delivery_id,amount) VALUES ('.$this->delivery.','.$amount.')');$remote->close();
        if($this->mode==='throw')throw new RuntimeException('sensitive-'.$token);
        if($this->mode==='reversed')return new RefundResult('refunded',true);
        return new RefundResult($this->mode);
    }
}
function executeRefund(array $fixture,int $index=0,string $mode='refunded',?string $key=null,int $retry=0,string $note='Admin review'):array{
    $id=$fixture['deliveries'][$index];
    return (new ReturnRefundService(new FakeRefundGateway($id,$mode)))->cancelAndRefund($id,4,$key??str_repeat('k',16).$id,$note,true,$retry);
}
function fixture(array $products=[8000],string $method='delivery'):array{
    $now=current_time('mysql',true);$total=array_sum($products)+1700;$token='localtoken'.bin2hex(random_bytes(16));
    $checkout=put('checkouts',['public_id'=>'chk_'.bin2hex(random_bytes(20)),'owner_type'=>'user','user_id'=>1001,'status'=>'payment_started','fulfillment_method'=>$method,'currency'=>'CLP','product_subtotal'=>array_sum($products).'.00','platform_fee'=>'700.00','delivery_fee'=>'1000.00','fee_policy_version'=>'test','total_amount'=>$total.'.00','created_at'=>$now,'updated_at'=>$now]);
    $orders=[];$deliveries=[];
    foreach($products as $i=>$amount){
        $order=put('orders',['customer_id'=>1001,'minimarket_id'=>1001+$i,'total'=>$amount.'.00','status'=>'incident_review','created_at'=>$now,'updated_at'=>$now]);
        $orders[]=$order;put('checkout_orders',['checkout_id'=>$checkout,'order_id'=>$order,'created_at'=>$now]);
        $delivery=put('deliveries',['order_id'=>$order,'customer_id'=>1001,'minimarket_id'=>1001+$i,'courier_id'=>2001,'status'=>'returned_to_store','transition_version'=>4,'created_at'=>$now,'updated_at'=>$now]);$deliveries[]=$delivery;
        put('delivery_returns',['delivery_id'=>$delivery,'order_id'=>$order,'store_id'=>1001+$i,'courier_id'=>2001,'opened_version'=>2,'opened_by'=>2001,'opened_code'=>'recipient_absent','opened_note'=>'Return note','opened_at'=>$now,'received_version'=>3,'received_by'=>3001+$i,'received_code'=>'intact','received_note'=>'Received','received_at'=>$now]);
    }
    $session=put('payment_sessions',['public_id'=>'ps_'.bin2hex(random_bytes(20)),'checkout_id'=>$checkout,'idempotency_key'=>bin2hex(random_bytes(16)),'request_fingerprint'=>str_repeat('a',64),'status'=>'confirmed','provider'=>'webpay_plus','provider_session_id'=>$token,'currency'=>'CLP','amount'=>$total.'.00','confirmed_at'=>$now,'created_at'=>$now,'updated_at'=>$now,'expires_at'=>$now]);
    $payment=put('payments',['payment_reference'=>'pay_'.bin2hex(random_bytes(20)),'checkout_id'=>$checkout,'payment_session_id'=>$session,'customer_id'=>1001,'amount'=>$total.'.00','status'=>'paid','provider'=>'webpay_plus','paid_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
    sql("UPDATE t_va_payment_sessions SET payment_id={$payment} WHERE id={$session}");
    foreach($orders as $order)put('payment_orders',['payment_id'=>$payment,'order_id'=>$order,'created_at'=>$now]);
    $origin=put('payment_origin_contexts',['public_id'=>'poc_'.bin2hex(random_bytes(20)),'site_scope'=>'wp-blog:1','origin'=>'veciahorra_checkout','origin_resource_id'=>row('checkouts',$checkout)['public_id'],'gateway_id'=>'webpay_plus','payment_attempt_id'=>row('payment_sessions',$session)['public_id'],'origin_key'=>bin2hex(random_bytes(32)),'amount_clp'=>$total,'currency'=>'CLP','environment'=>'integration','merchant_identity_hash'=>hash('sha256','597055555532'),'buy_order'=>'VA'.str_repeat('A',24),'financial_session_id'=>'VA-'.str_repeat('A',58),'token_hash'=>hash('sha256',$token),'context_version'=>1,'created_at'=>$now,'updated_at'=>$now,'expires_at'=>$now]);
    return compact('checkout','orders','deliveries','payment','session','origin');
}
if($worker){
    $GLOBALS['user']=(int)$argv[5];$id=(int)$argv[3];
    echo "STARTED\n";flush();
    try{$r=(new ReturnRefundService(new FakeRefundGateway($id)))->cancelAndRefund($id,4,$argv[4],'Race',true);echo 'RESULT='.$r['status']."\n";}
    catch(DomainException){echo "RESULT=conflict\n";}
    exit;
}
try{
    foreach(['CheckoutSchema','OrderSchema','DeliverySchema','CheckoutOrderSchema','DeliveryTrackingSchema','PaymentSchema','PaymentOrderSchema','PaymentSessionSchema','PaymentOriginContextSchema','CheckoutRefundSchema','InventorySchema','ReservationSchema'] as $suffix){
        $class='VeciAhorra\\Database\\Schemas\\'.$suffix;$schema=new $class();$builder=VeciAhorra\Database\Builder\TableBuilder::make('t_va_'.$schema->name());$schema->define($builder);sql($builder->build($wpdb->get_charset_collate()));sql('ALTER TABLE t_va_'.$schema->name().' AUTO_INCREMENT=1001');
    }
    sql('CREATE TABLE t_va_delivery_otps (delivery_id BIGINT PRIMARY KEY) ENGINE=InnoDB');
    (new VeciAhorra\Database\Migrations\EnsureUniqueDeliveryOrder())->up();
    (new VeciAhorra\Database\Migrations\CreateDeliveryReturns())->up();
    (new VeciAhorra\Database\Migrations\CreateReturnRefunds())->up();(new VeciAhorra\Database\Migrations\CreateReturnRefunds())->up();
    sql('CREATE TABLE t_va_refund_test_calls (id BIGINT AUTO_INCREMENT PRIMARY KEY,delivery_id BIGINT,amount BIGINT) ENGINE=InnoDB');
    $now=current_time('mysql',true);
    $inventory=put('inventory',['product_id'=>1001,'minimarket_id'=>1001,'price'=>'1000.00','stock'=>7,'created_at'=>$now,'updated_at'=>$now]);
    put('reservations',['inventory_id'=>$inventory,'product_id'=>1001,'minimarket_id'=>1001,'quantity'=>2,'status'=>'consumed','reserved_at'=>$now,'expires_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
    $inventoryBefore=$wpdb->get_results('SELECT * FROM t_va_inventory',ARRAY_A);$reservationsBefore=$wpdb->get_results('SELECT * FROM t_va_reservations',ARRAY_A);
    $f=fixture([3000,5000]);$other=$f['orders'][1];$otherDelivery=$f['deliveries'][1];sql("UPDATE t_va_orders SET status='delivered' WHERE id={$other}");sql("UPDATE t_va_deliveries SET status='delivered' WHERE id={$otherDelivery}");
    $otherBefore=row('orders',$other);$otherDeliveryBefore=row('deliveries',$otherDelivery);$paymentBefore=row('payments',$f['payment']);
    $r=executeRefund($f);
    check($r['status']==='refunded'&&$r['total_refund']===3000&&$r['platform_fee_refund']===0&&$r['delivery_fee_refund']===0,'PARTIAL_ONLY_PRODUCTS');
    check(row('orders',$other)===$otherBefore&&row('deliveries',$otherDelivery)===$otherDeliveryBefore,'OTHER_ORDER_UNCHANGED');
    check(row('payments',$f['payment'])===$paymentBefore,'PAYMENT_HISTORY_UNCHANGED');
    check(row('checkouts',$f['checkout'])['refund_status']==='partially_refunded','CHECKOUT_PARTIAL');
    check(row('orders',$f['orders'][0])['status']==='cancelled'&&row('deliveries',$f['deliveries'][0])['status']==='return_closed','FINAL_STATES');
    check((int)$wpdb->get_var('SELECT COUNT(*) FROM t_va_orders WHERE id='.$f['orders'][0]." AND status='delivered'")===0 && row('orders',$other)['status']==='delivered','REFUNDED_EXCLUDED_DELIVERED_PRESERVED');
    $calls=countCalls();check(executeRefund($f)===$r&&countCalls()===$calls,'REPLAY_IDEMPOTENT');
    reject(fn()=>executeRefund($f,0,'refunded',null,0,'Different note'),'CONTRADICTORY_REPLAY');
    reject(fn()=>executeRefund($f,0,'refunded','another-key-000000'),'CONFIRMED_NOT_RETRIED');
    $f=fixture([3000,2000,3000]);$a=executeRefund($f,0);$b=executeRefund($f,1);$c=executeRefund($f,2);
    check($a['total_refund']===3000&&$b['total_refund']===2000&&$c['total_refund']===4700,'SUCCESSIVE_FEES_ONLY_LAST');
    check($a['total_refund']+$b['total_refund']+$c['total_refund']===9700&&row('checkouts',$f['checkout'])['refund_status']==='refunded','FULL_CHECKOUT_EXACT');
    $f=fixture();$r=executeRefund($f);check($r['total_refund']===9700,'SINGLE_FULL_TOTAL');
    $history=$wpdb->get_results('SELECT * FROM t_va_return_refund_attempts WHERE delivery_id='.$f['deliveries'][0].' ORDER BY id',ARRAY_A);
    check(array_column($history,'event')===['refund_pending','remote_started','refunded'],'APPEND_HISTORY');
    check((int)$wpdb->get_var('SELECT COUNT(*) FROM t_va_delivery_tracking WHERE delivery_id='.$f['deliveries'][0]." AND event='cancel_and_refund' AND actor_id=9001 AND transition_version=5")===1,'TRACKING_REQUIRED');
    $calls=countCalls();executeRefund($f);check($history===$wpdb->get_results('SELECT * FROM t_va_return_refund_attempts WHERE delivery_id='.$f['deliveries'][0].' ORDER BY id',ARRAY_A),'HISTORY_NOT_OVERWRITTEN');
    check((string)$wpdb->get_var('SELECT SUM(total_refund) FROM t_va_checkout_refunds WHERE checkout_id='.$f['checkout'])==='9700.00','FEES_NOT_DOUBLED_ON_REPLAY');
    $f=fixture();$id=$f['deliveries'][0];$service=new ReturnRefundService(new FakeRefundGateway($id));
    foreach([0,1001,2001,3001] as $user){$GLOBALS['user']=$user;reject(fn()=>$service->cancelAndRefund($id,4,'valid-key-00000001','Note',true),'ADMIN_ONLY');}$GLOBALS['user']=9001;
    reject(fn()=>$service->cancelAndRefund($id,null,'valid-key-00000001','Note',true),'VERSION_REQUIRED');
    reject(fn()=>$service->cancelAndRefund($id,3,'valid-key-00000001','Note',true),'STALE_VERSION');
    reject(fn()=>$service->cancelAndRefund($id,4,'valid-key-00000001','Note',false),'CONFIRMATION_REQUIRED');
    foreach(['',str_repeat('x',501),"bad\nline"] as $note)reject(fn()=>$service->cancelAndRefund($id,4,'valid-key-00000001',$note,true),'NOTE_REQUIRED');
    check(countCalls()===$calls,'REJECTED_NO_REMOTE');
    foreach(['picked_up','return_pending','delivered'] as $status){sql("UPDATE t_va_deliveries SET status='{$status}' WHERE id={$id}");reject(fn()=>executeRefund($f),'DELIVERY_STATE_REQUIRED');}sql("UPDATE t_va_deliveries SET status='returned_to_store' WHERE id={$id}");
    sql('UPDATE t_va_delivery_returns SET received_at=NULL WHERE delivery_id='.$id);reject(fn()=>executeRefund($f),'RECEIPT_REQUIRED');sql("UPDATE t_va_delivery_returns SET received_at='{$now}' WHERE delivery_id={$id}");
    sql('UPDATE t_va_orders SET status=\'paid\' WHERE id='.$f['orders'][0]);reject(fn()=>executeRefund($f),'ORDER_STATE_REQUIRED');
    $pickup=fixture([8000],'pickup');reject(fn()=>executeRefund($pickup),'PICKUP_EXCLUDED');
    $failed=fixture();$key='failed-key-000001';$r=executeRefund($failed,0,'refund_failed',$key);check($r['status']==='refund_failed','DEFINITIVE_REJECTION');
    $calls=countCalls();check(executeRefund($failed,0,'refunded',$key)===$r&&countCalls()===$calls,'FAILED_REPLAY_NO_RETRY');
    reject(fn()=>executeRefund($failed,0,'refunded','failed-key-000002'),'EXPLICIT_RETRY_REQUIRED');
    check(executeRefund($failed,0,'refunded','failed-key-000002',1)['status']==='refunded','EXPLICIT_FAILED_RETRY');
    check(executeRefund($failed,0,'refunded',$key)===$r,'OLD_ATTEMPT_REPLAY');
    foreach(['refund_uncertain','throw'] as $mode){
        $f=fixture([3000,5000]);$r=executeRefund($f,0,$mode);check($r['status']==='refund_uncertain','UNCERTAIN_RESULT');$calls=countCalls();
        check(executeRefund($f)===$r&&countCalls()===$calls,'UNCERTAIN_REPLAY_NO_CALL');
        reject(fn()=>executeRefund($f,0,'refunded','uncertain-key-00001',1),'NO_UNCERTAIN_RETRY');
        reject(fn()=>executeRefund($f,1),'UNCERTAIN_BLOCKS_CHECKOUT');
        check(row('orders',$f['orders'][0])['status']==='incident_review','UNCERTAIN_NOT_SUCCESS');
    }
    $f=fixture();sql("CREATE TRIGGER fail_intent BEFORE INSERT ON t_va_return_refund_attempts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture'");$calls=countCalls();
    failure(fn()=>executeRefund($f),'FAIL_BEFORE_INTENT');sql('DROP TRIGGER fail_intent');check(countCalls()===$calls&&row('return_refunds',$f['deliveries'][0],'delivery_id')===[],'NO_INTENT_NO_CALL');
    $f=fixture();sql("CREATE TRIGGER fail_claim BEFORE INSERT ON t_va_return_refund_attempts FOR EACH ROW BEGIN IF NEW.event='remote_started' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture'; END IF; END");
    failure(fn()=>executeRefund($f),'FAIL_BEFORE_CALL');sql('DROP TRIGGER fail_claim');check(countCalls()===$calls,'CLAIM_FAILURE_NO_CALL');
    sql("UPDATE t_va_return_refunds SET lease_expires_at='2000-01-01' WHERE delivery_id=".$f['deliveries'][0]);check(executeRefund($f)['status']==='refund_failed'&&countCalls()===$calls,'EXPIRED_UNSENT_FAILED');
    $f=fixture();sql("CREATE TRIGGER fail_after_remote BEFORE UPDATE ON t_va_return_refunds FOR EACH ROW BEGIN IF NEW.status='refunded' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture'; END IF; END");
    $r=executeRefund($f);sql('DROP TRIGGER fail_after_remote');check($r['status']==='refund_uncertain','FAIL_AFTER_REMOTE_UNCERTAIN');
    check(row('orders',$f['orders'][0])['status']==='incident_review'&&row('deliveries',$f['deliveries'][0])['status']==='returned_to_store','FINALIZATION_ROLLBACK');
    check((int)$wpdb->get_var('SELECT COUNT(*) FROM t_va_checkout_refunds WHERE checkout_id='.$f['checkout'])===0&&(int)$wpdb->get_var('SELECT COUNT(*) FROM t_va_delivery_tracking WHERE delivery_id='.$f['deliveries'][0])===0,'LEDGER_TRACKING_ROLLBACK');
    $calls=countCalls();executeRefund($f);check(countCalls()===$calls,'AFTER_REMOTE_NO_REPEAT');
    $f=fixture();sql("CREATE TRIGGER fail_all_terminal BEFORE UPDATE ON t_va_return_refunds FOR EACH ROW BEGIN IF NEW.status<>'refund_pending' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture'; END IF; END");
    failure(fn()=>executeRefund($f),'LOST_DURABLE_RESULT');sql('DROP TRIGGER fail_all_terminal');$calls=countCalls();
    check(row('return_refunds',$f['deliveries'][0],'delivery_id')['remote_started_at']!==null,'REMOTE_MARKER_SURVIVES_FAILURE');
    sql("UPDATE t_va_return_refunds SET lease_expires_at='2000-01-01' WHERE delivery_id=".$f['deliveries'][0]);
    check(executeRefund($f)['status']==='refund_uncertain'&&countCalls()===$calls,'EXPIRED_SENT_NEVER_REEXECUTED');
    $f=fixture([3000,5000]);check(executeRefund($f,0,'reversed')['status']==='refund_uncertain','PARTIAL_REVERSAL_AMBIGUOUS');
    $f=fixture();check(executeRefund($f,0,'reversed')['status']==='refunded','FULL_REVERSAL_SUPPORTED');
    $legacy=fixture();put('checkout_refunds',['checkout_id'=>$legacy['checkout'],'idempotency_key'=>'legacy-record-00001','product_refund'=>'1000.00','platform_fee_refund'=>'0.00','delivery_fee_refund'=>'0.00','total_refund'=>'1000.00','status'=>'recorded','created_at'=>$now]);reject(fn()=>executeRefund($legacy),'UNATTRIBUTED_LEDGER_BLOCKED');
    $f=fixture([3000,5000]);executeRefund($f);sql('UPDATE t_va_checkout_refunds SET platform_fee_refund=701.00,total_refund=3701.00 WHERE checkout_id='.$f['checkout']);reject(fn()=>executeRefund($f,1),'COMPONENT_LIMIT');
    $f=fixture();sql('UPDATE t_va_checkouts SET total_amount=9699.00 WHERE id='.$f['checkout']);reject(fn()=>executeRefund($f),'ORIGINAL_TOTAL_LIMIT');
    $f=fixture();sql('UPDATE t_va_payment_sessions SET provider_session_id=NULL WHERE id='.$f['session']);reject(fn()=>executeRefund($f),'MISSING_TOKEN');
    $f=fixture();sql("UPDATE t_va_payment_origin_contexts SET token_hash='wrong' WHERE id=".$f['origin']);reject(fn()=>executeRefund($f),'TOKEN_BINDING_REQUIRED');
    $f=fixture();executeRefund($f);$GLOBALS['user']=1001;
    $safe=(new VeciAhorra\Modules\Couriers\Returns\ReturnRefundCustomer())->forCheckout($f['checkout']);
    check(count($safe)===1&&$safe[0]['total_refund']===9700&&$safe[0]['confirmed_at']!==null,'CUSTOMER_BREAKDOWN');
    foreach(['provider','token','owner','lease','note','fingerprint','idempotency','actor'] as $secret)check(!str_contains(json_encode($safe),$secret),'CUSTOMER_SAFE_'.$secret);
    foreach([1002,2001,3001,9001,0] as $user){$GLOBALS['user']=$user;check((new VeciAhorra\Modules\Couriers\Returns\ReturnRefundCustomer())->forCheckout($f['checkout'])===[],'OTHER_IDENTITY_PRIVATE');}$GLOBALS['user']=9001;
    $f=fixture();$routes=new VeciAhorra\Modules\Couriers\Returns\ReturnRefundRoutes(new ReturnRefundService(new FakeRefundGateway($f['deliveries'][0])));
    $body=['expected_version'=>4,'idempotency_key'=>'http-key-00000001','note'=>'HTTP note','confirmed'=>true];
    foreach([['expected_version',null],['expected_version','4'],['expected_version',true],['confirmed',null],['confirmed','true'],['confirmed',1],['retry_of','0']] as [$field,$value]){
        $bad=$body;$bad[$field]=$value;$request=new WP_REST_Request('POST','/');$request['id']=$f['deliveries'][0];$request->set_header('Content-Type','application/json');$request->set_body(json_encode($bad));check($routes->cancel($request)->get_status()===409,'HTTP_STRICT_VALIDATION');
    }
    $request=new WP_REST_Request('POST','/');$request['id']=$f['deliveries'][0];$request->set_header('Content-Type','application/json');$request->set_body(json_encode($body));check($routes->cancel($request)->get_status()===200,'HTTP_PRODUCTIVE_OPERATION');
    $GLOBALS['user']=1001;check($routes->cancel($request)->get_status()===409,'HTTP_ADMIN_REQUIRED');$GLOBALS['user']=9001;
    foreach(['expected_version','confirmed'] as $field){$omitted=$body;unset($omitted[$field]);$request->set_body(json_encode($omitted));check($routes->cancel($request)->get_status()===409,'HTTP_OMISSION_REJECTED');}
    ob_start();(new VeciAhorra\Modules\Couriers\Returns\ReturnAdmin())->render();$html=ob_get_clean();
    check(str_contains($html,'data-return-refund')&&str_contains($html,'Historial de intentos')&&str_contains($html,'regularización física'),'ADMIN_UI');
    check(!str_contains($html,'localtoken')&&!str_contains($html,'merchant_identity_hash'),'ADMIN_NO_TOKEN');
    $GLOBALS['user']=2001;ob_start();(new VeciAhorra\Modules\Couriers\Returns\ReturnAdmin())->render();check(ob_get_clean()==='','ADMIN_VIEW_AUTHORITY');$GLOBALS['user']=9001;
    // Two real connections queued behind the same held checkout row; exactly one remote execution.
    $f=fixture();$id=$f['deliveries'][0];sql('START TRANSACTION');$wpdb->get_row('SELECT * FROM t_va_checkouts WHERE id='.$f['checkout'].' FOR UPDATE');
    $workers=[];
    foreach([9001,9002] as $actor){$pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--race',$database,(string)$id,'race-key-00000'.$actor,(string)$actor],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);check(trim((string)fgets($pipes[1]))==='STARTED','RACE_STARTED');$workers[]=[$process,$pipes];}
    $deadline=microtime(true)+20;
    do{$waiting=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB=%s AND INFO=%s",$database,'SELECT * FROM t_va_checkouts WHERE id='.$f['checkout'].' FOR UPDATE'));if($waiting===2)break;usleep(50000);}while(microtime(true)<$deadline);
    check($waiting===2,'RACE_BOTH_WAITING');$calls=countCalls();sql('COMMIT');$results=[];
    foreach($workers as [$process,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($process)===0,'RACE_WORKER:'.$out.$err);$results[]=trim($out);}
    sort($results);check($results===['RESULT=conflict','RESULT=refunded']&&countCalls()===$calls+1,'CONCURRENCY_ONE_WINNER');
    check($wpdb->get_results('SELECT * FROM t_va_inventory',ARRAY_A)===$inventoryBefore,'INVENTORY_UNCHANGED');
    check($wpdb->get_results('SELECT * FROM t_va_reservations',ARRAY_A)===$reservationsBefore,'RESERVATIONS_UNCHANGED');
    check((int)$wpdb->get_var('SELECT COUNT(*) FROM t_va_orders WHERE id BETWEEN 18 AND 22')===0,'HISTORICAL_ORDERS_ABSENT');
    echo 'PASS return-refund-mysql assertions='.$GLOBALS['assertions']." external_calls=0\n";
} finally {$wpdb->query('ROLLBACK');}
