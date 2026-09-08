<?php
declare(strict_types=1);
/** Native wpdb, production schemas/services and local InnoDB; no wp-load or provider network. */
if(PHP_SAPI!=='cli'||getenv('VA_CART_TEST')!=='1')throw new RuntimeException('explicit_disposable_test_required');
$plugin=getenv('VA_PROOF_PLUGIN_ROOT')?:dirname(__DIR__,2);
define('ABSPATH','C:/xampp/htdocs/Minimarket/');define('WPINC','wp-includes');
define('WP_ENVIRONMENT_TYPE','local');define('VECIAHORRA_COMMERCE_ENABLED',true);define('VECIAHORRA_PAYMENT_GATEWAY','mock');
define('WP_DEBUG',false);define('WP_DEBUG_DISPLAY',false);define('DB_CHARSET','utf8mb4');define('DB_COLLATE','');
define('VA_PLUGIN_URL','http://127.0.0.1/test-plugin/');
function get_current_user_id(){return $GLOBALS['user']??9001;}
function is_user_logged_in(){return get_current_user_id()>0;}
function get_user_meta(...$args){return 1001;}
function wp_salt(...$args){return 'isolated-cart-test-secret-at-least-32-characters';}
function home_url($path=''){return 'http://127.0.0.1/isolated'.$path;}
$GLOBALS['blog_id']=1;
function current_user_can($cap){return $cap==='manage_options'&&in_array(get_current_user_id(),[9001,9002],true);}
function wp_cache_get(...$args){return false;}function wp_cache_set(...$args){return true;}
function wp_enqueue_script(...$args){$GLOBALS['assets'][]=$args;}
function rest_url($path){return 'http://127.0.0.1/wp-json/'.$path;}
function wp_create_nonce($action){return 'test-nonce';}
function wp_verify_nonce($nonce,$action){return $nonce==='test-nonce';}
foreach(['compat.php','plugin.php','load.php','class-wp-error.php','functions.php','formatting.php','kses.php','utf8.php','class-wpdb.php','class-wp-http-response.php','rest-api/class-wp-rest-response.php','rest-api/class-wp-rest-request.php'] as $file)require ABSPATH.WPINC.'/'.$file;
add_filter('pre_option_blog_charset',static fn()=> 'UTF-8');
add_filter('pre_option',static fn($pre,$key,$default)=>$pre!==false?$pre:($default===false?null:$default),10,3);
require $plugin.'/vendor/autoload.php';

use VeciAhorra\Modules\Cart\Service\CartAggregate;
use VeciAhorra\Modules\Cart\Service\CartService;
use VeciAhorra\Modules\Cart\Repository\CartRepository;
use VeciAhorra\Modules\Sectorization\CurrentSector;
$worker=($argv[1]??'')==='--race';
$database=$worker?$argv[2]:'va_cart_test_'.bin2hex(random_bytes(8));
if(!preg_match('/^va_cart_test_[a-f0-9]{16}$/D',$database))throw new RuntimeException('unsafe_database');
$port=(int)(getenv('VA_CART_TEST_PORT')?:3306);
$admin=new mysqli('127.0.0.1','root','','',$port);
if(!$worker)$admin->query("CREATE DATABASE {$database} CHARACTER SET utf8mb4");
if(!$worker)register_shutdown_function(static function()use($admin,$database){
    if(!preg_match('/^va_cart_test_[a-f0-9]{16}$/D',$database))throw new RuntimeException('unsafe_database');
    $admin->query("DROP DATABASE IF EXISTS {$database}");
    echo "DISPOSABLE_DATABASE_REMOVED=yes\n";
});
$wpdb=new wpdb('root','',$database,'127.0.0.1:'.$port);$wpdb->set_prefix('t_');$wpdb->suppress_errors(true);
$assertions=0;$tests=0;
function check(bool $ok,string $label):void{global $assertions;++$assertions;if(!$ok)throw new RuntimeException('FAIL '.$label);}
function sql(string $query):void{global $wpdb;if($wpdb->query($query)===false)throw new RuntimeException('fixture_sql_failed:'.$wpdb->last_error);}
function put(string $table,array $data):int{global $wpdb;if($wpdb->insert('t_va_'.$table,$data)!==1)throw new RuntimeException('fixture_insert_failed:'.$table.':'.$wpdb->last_error);return (int)$wpdb->insert_id;}
function reject(callable $callback,string $label):void{try{$callback();}catch(DomainException|InvalidArgumentException|VeciAhorra\Exceptions\ConflictException|VeciAhorra\Exceptions\RecordNotFoundException){check(true,$label);return;}check(false,$label);}
function command(array $snapshot,array $extra=[]):array{return [...$extra,'user_id'=>get_current_user_id(),'cart_id'=>$snapshot['cart_id'],'expected_version'=>$snapshot['version']];}

function checkoutService(CartService $service):VeciAhorra\Modules\Checkout\Service\CheckoutService{return new VeciAhorra\Modules\Checkout\Service\CheckoutService(new VeciAhorra\Modules\Checkout\Service\CheckoutValidationService($service,new VeciAhorra\Modules\Inventory\Services\InventoryService(),new VeciAhorra\Modules\Products\Services\ProductService(),new VeciAhorra\Modules\Stores\Repositories\StoreRepository()),new VeciAhorra\Modules\Reservations\Service\ReservationService(),new VeciAhorra\Modules\Orders\Services\OrderService(),$service);}
if($worker){
    $input=json_decode(base64_decode($argv[3]),true,512,JSON_THROW_ON_ERROR);$GLOBALS['user']=$input['owner']['user_id'];
    $service=new CartService(new CartRepository());$owner=$input['owner'];echo "STARTED\n";flush();
    try{
        $result=match($input['operation']){
            'quantity'=>$service->updateQuantity($owner,$input['line'],3),
            'add'=>$service->addItem($owner,$input['inventory'],1),
            'delete'=>$service->removeItem($owner,$input['line']),
            'clear'=>$service->clearCart($owner),
            'method'=>$service->setMethod($owner,'delivery'),
            'checkout'=>checkoutService($service)->initialize([...$owner,'expected_cart_version'=>$owner['expected_version'],'fulfillment_method'=>'pickup','idempotency_key'=>$input['key']]),
            'accept'=>(new VeciAhorra\Modules\Cart\Proximity\PickupProposalService())->accept($owner,['proposal_id'=>$input['proposal'],'accepted'=>true,'idempotency_key'=>$input['key']]),
        };
        echo "RESULT=winner\n";
    }catch(Throwable $e){echo 'RESULT=conflict:'.get_class($e)."\n";}
    exit;
}
function race(array $snapshot,string $a,string $b,int $inventory,?string $proposal=null):void{
    global $wpdb,$database,$tests;
    ++$tests;$owner=command($snapshot);$line=(int)$snapshot['items'][0]['id'];
    sql('START TRANSACTION');$wpdb->get_row($wpdb->prepare('SELECT * FROM t_va_carts WHERE public_id=%s FOR UPDATE',$snapshot['cart_id']),ARRAY_A);
    $jobs=[];
    try{
        foreach([$a,$b] as $i=>$operation){
            $payload=base64_encode(json_encode(['owner'=>$owner,'line'=>$line,'inventory'=>$inventory,'operation'=>$operation,'proposal'=>$proposal,'key'=>'race-checkout-'.$i.'-'.$snapshot['cart_id']],JSON_THROW_ON_ERROR));
            $pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'--race',$database,$payload],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($proc))throw new RuntimeException('worker_start_failed');fclose($pipes[0]);$started=fgets($pipes[1]);$jobs[]=['proc'=>$proc,'pipes'=>$pipes,'output'=>$started];check(trim($started)==='STARTED','WORKER_STARTED');
        }
        $deadline=microtime(true)+8;$blocked=0;
        while(microtime(true)<$deadline){
            $blocked=0;foreach($wpdb->get_results('SHOW FULL PROCESSLIST',ARRAY_A) as $process){
                if(($process['db']??'')===$database&&str_contains($process['Info']??'',"carts WHERE public_id=")&&str_contains($process['Info']??'','FOR UPDATE')&&($process['Command']??'')==='Query')++$blocked;
            }
            if($blocked===2)break;usleep(20000);
        }
        check($blocked===2,'SHARED_ROW_LOCK_'.$a.'_'.$b);sql('COMMIT');
        $wins=0;$conflicts=0;
        foreach($jobs as &$job){stream_set_blocking($job['pipes'][1],true);$output=$job['output'].stream_get_contents($job['pipes'][1]);$errors=stream_get_contents($job['pipes'][2]);fclose($job['pipes'][1]);fclose($job['pipes'][2]);$exit=proc_close($job['proc']);$job['proc']=null;check($exit===0&&$errors==='','WORKER_RUNTIME');$wins+=str_contains($output,'RESULT=winner')?1:0;$conflicts+=str_contains($output,'RESULT=conflict')?1:0;}unset($job);
        check($wins===1&&$conflicts===1,'SINGLE_WINNER_'.$a.'_'.$b);
        $stored=$wpdb->get_row($wpdb->prepare('SELECT * FROM t_va_carts WHERE public_id=%s',$snapshot['cart_id']),ARRAY_A);
        check((int)$stored['version']===$snapshot['version']+1,'RACE_VERSION_ONCE');
        check((int)$wpdb->get_var('SELECT COUNT(*) FROM t_va_cart_items ci LEFT JOIN t_va_carts c ON c.id=ci.cart_id WHERE ci.cart_id IS NOT NULL AND c.id IS NULL')===0,'NO_ORPHAN_LINES');
    }finally{
        $wpdb->query('ROLLBACK');foreach($jobs as $job)if(is_resource($job['proc'])){proc_terminate($job['proc']);foreach($job['pipes'] as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($job['proc']);}
    }
}
try{
    foreach(['Schemas\\CartItemSchema','Schemas\\CheckoutSchema','Schemas\\OrderSchema','Schemas\\OrderItemSchema','Schemas\\ReservationSchema','Schemas\\InventorySchema','Schemas\\CheckoutOrderSchema','Schemas\\PaymentSessionSchema','Tables\\ProductsTable','Tables\\StoresTable','Tables\\ServiceZonesTable','Tables\\StoreServiceZonesTable'] as $suffix){
        $class='VeciAhorra\\Database\\'.$suffix;$schema=new $class();$builder=VeciAhorra\Database\Builder\TableBuilder::make('t_va_'.$schema->name());$schema->define($builder);sql($builder->build($wpdb->get_charset_collate()));sql('ALTER TABLE t_va_'.$schema->name().' AUTO_INCREMENT=1001');
    }
    (new VeciAhorra\Database\Migrations\CreateCartAggregates())->up();
    (new VeciAhorra\Database\Migrations\CreateCartAggregates())->up();
    $now=current_time('mysql',true);
    $zone=put('service_zones',['commune'=>'Test','name'=>'A','status'=>'active','created_at'=>$now,'updated_at'=>$now]);
    $otherZone=put('service_zones',['commune'=>'Test','name'=>'B','status'=>'active','created_at'=>$now,'updated_at'=>$now]);
    $store=put('stores',['business_name'=>'Test','legal_name'=>'Test','owner_name'=>'Test','rut'=>'TEST','email'=>'test@example.invalid','phone'=>'123456789','status'=>'active','onboarding_status'=>'complete','approved_at'=>$now,'delivery_enabled'=>1,'created_at'=>$now,'updated_at'=>$now]);
    put('store_service_zones',['store_id'=>$store,'zone_id'=>$zone,'assigned_by'=>9001,'assigned_at'=>$now]);
    $product=put('products',['name'=>'Test','slug'=>'test','status'=>'active','delivery_enabled'=>1,'created_at'=>$now,'updated_at'=>$now]);
    $inventory=put('inventory',['product_id'=>$product,'minimarket_id'=>$store,'stock'=>100,'price'=>'1000.00','status'=>'active','delivery_enabled'=>1,'created_at'=>$now,'updated_at'=>$now]);
    $service=new CartService(new CartRepository());$aggregate=new CartAggregate();
    if(getenv('VA_PICKUP_TEST')==='1'){
        (new VeciAhorra\Database\Migrations\CreatePickupProposals())->up();
        (new VeciAhorra\Database\Migrations\CreatePickupProposals())->up();
        require __DIR__.'/support/pickup-proposal-cases.php';
        echo "TESTS_RUN={$tests}\nASSERTIONS={$assertions}\nTESTS_FAILED=0\n";exit;
    }
    // Exercise the production REST callback, request validation, controller and service.
    $routes=new VeciAhorra\Modules\Checkout\Routes\CheckoutRoutes(new VeciAhorra\Modules\Checkout\Controller\CheckoutController(checkoutService($service)));
    $requestFor=static function(array $cart,string $method,string $key):WP_REST_Request{
        $body=['cart_id'=>$cart['cart_id'],'expected_cart_version'=>$cart['version'],'fulfillment_method'=>$method];
        if($method==='delivery')$body['delivery']=['recipient_name'=>'Test Customer','contact_phone'=>'+56912345678','address_line1'=>'Test 123','commune'=>'Test','reference'=>'','notes'=>''];
        $request=new WP_REST_Request('POST','/veciahorra/v1/checkout');
        $request->set_header('Content-Type','application/json');$request->set_header('Idempotency-Key',$key);
        $request->set_body(json_encode($body,JSON_THROW_ON_ERROR));return $request;
    };
    $state=static function()use($wpdb):array{
        $rows=[];foreach(['carts','cart_items','checkouts','orders','order_items','reservations','checkout_orders','payment_sessions','inventory'] as $table)$rows[$table]=$wpdb->get_results('SELECT * FROM t_va_'.$table.' ORDER BY id',ARRAY_A);
        return $rows;
    };
    foreach(['pickup'=>'delivery','delivery'=>'pickup'] as $persisted=>$sent){
        ++$tests;$GLOBALS['user']=$persisted==='pickup'?9101:9102;
        $cart=$service->getPublicCart(['user_id'=>get_current_user_id()]);
        $cart=$service->addItem(command($cart),$inventory,10)['cart'];
        if($persisted==='delivery')$cart=$service->setMethod(command($cart),'delivery')['cart'];
        check($cart['status']==='active'&&$cart['service_zone_id']===$zone&&$cart['total']==='10000.00','MODALITY_VALID_PRECONDITIONS_'.$persisted);
        $before=$state();$request=$requestFor($cart,$sent,'modality-conflict-'.$persisted);
        $response=$routes->initialize($request);$data=$response->get_data();
        check(!($data['success']??false),'MODALITY_CONTRADICTION_ACCEPTED_'.$persisted.'_VS_'.$sent);
        check($response->get_status()===409&&($data['error']['code']??'')==='cart_conflict','MODALITY_REST_CONFLICT_'.$persisted);
        $reason=null;
        try{checkoutService($service)->initialize([...(new VeciAhorra\Modules\Checkout\Requests\CheckoutRequest($request->get_json_params()))->validated(),'user_id'=>get_current_user_id(),'idempotency_key'=>'modality-reason-'.$persisted]);}catch(DomainException $e){$reason=$e->getMessage();}
        check($reason==='cart_modality_conflict','MODALITY_SPECIFIC_REASON_'.$persisted);
        check($state()===$before,'MODALITY_REJECTION_NO_SIDE_EFFECTS_'.$persisted);
        $matched=$routes->initialize($requestFor($cart,$persisted,'modality-match-'.$persisted));$data=$matched->get_data();
        check($matched->get_status()===201&&($data['success']??false)&&($data['data']['valid']??false),'MODALITY_MATCH_ACCEPTED_'.$persisted);
        echo 'MODALITY_'.$persisted.'_VS_'.$sent.'_REJECTED=yes'."\n";
    }
    echo "MODALITY_MATCH_ACCEPTED=yes\nMODALITY_REJECTION_NO_SIDE_EFFECTS=yes\n";
    $GLOBALS['user']=9001;
    if(getenv('VA_CART_MODALITY_ONLY')==='1'){
        echo "TESTS_RUN={$tests}\nASSERTIONS={$assertions}\nTESTS_FAILED=0\n";exit;
    }
    ++$tests;$GLOBALS['user']=9103;
    $tokens=new VeciAhorra\Modules\Catalog\Security\PublicOfferToken();
    $cartRoutes=new VeciAhorra\Modules\Cart\Routes\CartRoutes(new VeciAhorra\Modules\Cart\Controller\CartController($service,$tokens,new CurrentSector()));
    $cart=$service->getPublicCart(['user_id'=>get_current_user_id()]);
    $cart=$service->addItem(command($cart),$inventory,2)['cart'];
    $token=$tokens->issue($inventory,$product,$zone,['user_id'=>get_current_user_id()]);
    $cartRequest=static function(array $body,int $id=0):WP_REST_Request{
        $request=new WP_REST_Request('POST','/veciahorra/v1/cart');$request->set_header('Content-Type','application/json');
        $request->set_body(json_encode($body,JSON_THROW_ON_ERROR));$request->set_url_params(['id'=>$id]);return $request;
    };
    foreach(['store','updateQuantity','delete','clear','method'] as $operation){
        foreach([null,0,-1,$cart['version']-1,$cart['version']+1,(string)$cart['version']] as $version){
            $body=['cart_id'=>$cart['cart_id'],'offer_token'=>$token,'quantity'=>3,'fulfillment_method'=>'delivery'];
            if($version!==null)$body['expected_version']=$version;
            $before=$state();$response=$cartRoutes->$operation($cartRequest($body,(int)$cart['items'][0]['id']));
            check($response->get_status()===409,'REST_EXPECTED_VERSION_'.$operation);
            check($state()===$before,'REST_VERSION_REJECTION_INTACT_'.$operation);
        }
    }
    foreach([null,0,-1,$cart['version']-1,$cart['version']+1,(string)$cart['version']] as $version){
        $before=$state();$reason=null;
        try{(new CurrentSector())->set($zone,[...command($cart),'expected_version'=>$version]);}catch(DomainException $e){$reason=$e->getMessage();}
        check(in_array($reason,['expected_cart_version_required','cart_version_conflict'],true),'ZONE_EXPECTED_VERSION');
        check($state()===$before,'ZONE_VERSION_REJECTION_INTACT');
        $request=$requestFor($cart,'pickup','missing-checkout-version');$body=$request->get_json_params();
        if($version===null)unset($body['expected_cart_version']);else $body['expected_cart_version']=$version;
        $request->set_body(json_encode($body,JSON_THROW_ON_ERROR));$response=$routes->initialize($request);
        check(in_array($response->get_status(),[409,422],true),'CHECKOUT_EXPECTED_CART_VERSION');
        check($state()===$before,'CHECKOUT_VERSION_REJECTION_INTACT');
    }
    $response=$cartRoutes->updateQuantity($cartRequest([...command($cart),'quantity'=>3],(int)$cart['items'][0]['id']));
    check($response->get_status()===200&&($response->get_data()['success']??false),'REST_QUANTITY_SUCCESS');
    $cart=$service->getPublicCart(['user_id'=>get_current_user_id()]);
    $response=$cartRoutes->delete($cartRequest(command($cart),(int)$cart['items'][0]['id']));
    check($response->get_status()===200&&($response->get_data()['success']??false),'REST_DELETE_SUCCESS');
    $cart=$service->getPublicCart(['user_id'=>get_current_user_id()]);
    $response=$cartRoutes->store($cartRequest([...command($cart),'offer_token'=>$token,'quantity'=>2]));
    check($response->get_status()===201&&($response->get_data()['success']??false),'REST_CREATE_SUCCESS_201');
    $cart=$service->getPublicCart(['user_id'=>get_current_user_id()]);
    $response=$cartRoutes->clear($cartRequest(command($cart)));
    check($response->get_status()===200&&($response->get_data()['success']??false),'REST_CLEAR_SUCCESS');
    $GLOBALS['user']=9001;
    ++$tests;$empty=$service->getPublicCart(['user_id'=>9001]);
    check($empty['version']===1&&$empty['status']==='active'&&$empty['fulfillment_method']==='pickup'&&$empty['service_zone_id']===$zone,'INITIAL_AGGREGATE');
    check(preg_match('/^[a-f0-9]{48}$/D',$empty['cart_id'])===1,'PUBLIC_ID');
    check($service->getPublicCart(['user_id'=>9001])===$empty,'READ_IDEMPOTENT');
    ++$tests;
    foreach([null,0,-1,2,'1',1.5,true] as $version)reject(fn()=>$service->addItem([...command($empty),'expected_version'=>$version],$inventory,1),'STRICT_VERSION');
    check($service->getPublicCart(['user_id'=>9001])===$empty,'REJECTION_NO_MUTATION');
    ++$tests;$added=$service->addItem(command($empty),$inventory,2);$snapshot=$added['cart'];
    check($snapshot['version']===2&&count($snapshot['items'])===1&&$snapshot['total']==='2000.00','ADD_ONCE');
    $line=(int)$snapshot['items'][0]['id'];
    reject(fn()=>$service->addItem(command($empty),$inventory,1),'STALE_ADD');
    ++$tests;$added=$service->addItem(command($snapshot),$inventory,1);$snapshot=$added['cart'];
    check($snapshot['version']===3&&count($snapshot['items'])===1&&(int)$snapshot['items'][0]['quantity']===3,'DEDUPLICATION');
    ++$tests;$same=$service->updateQuantity(command($snapshot),$line,3)['cart'];check($same['version']===3,'SEMANTIC_NOOP');
    $snapshot=$service->updateQuantity(command($snapshot),$line,4)['cart'];check($snapshot['version']===4&&$snapshot['total']==='4000.00','QUANTITY_ONCE');
    ++$tests;reject(fn()=>(new CurrentSector())->set($otherZone,command($snapshot)),'INCOMPATIBLE_ZONE_BLOCKED');
    check((new CurrentSector())->id()===$zone&&$service->getPublicCart(['user_id'=>9001])===$snapshot,'ZONE_FAILURE_INTACT');
    ++$tests;$snapshot=$service->setMethod(command($snapshot),'delivery')['cart'];check($snapshot['version']===5&&$snapshot['fulfillment_method']==='delivery','METHOD_VERSION');
    reject(fn()=>$aggregate->materialize([...command($snapshot),'expected_cart_version'=>5,'idempotency_key'=>'checkout-test-key','fulfillment_method'=>'pickup'],fn()=>[]),'CHECKOUT_CANNOT_OVERRIDE_METHOD');
    ++$tests;$snapshot=$service->clearCart(command($snapshot))['cart'];check($snapshot['version']===6&&$snapshot['items']===[],'CLEAR_ONCE');
    $snapshot=$service->clearCart(command($snapshot))['cart'];check($snapshot['version']===6,'EMPTY_CLEAR_NOOP');
    ++$tests;
    foreach(['materializing','consumed'] as $status){
        sql("UPDATE t_va_carts SET status='{$status}' WHERE public_id='{$snapshot['cart_id']}'");
        reject(fn()=>$service->addItem(command($snapshot),$inventory,1),'IMMUTABLE_'.$status);
    }
    sql("UPDATE t_va_carts SET status='active' WHERE public_id='{$snapshot['cart_id']}'");
    ++$tests;$GLOBALS['user']=9002;
    $legacy=put('cart_items',['user_id'=>9002,'inventory_id'=>$inventory,'product_id'=>$product,'minimarket_id'=>$store,'quantity'=>7,'unit_price_snapshot'=>'900.00','created_at'=>$now,'updated_at'=>$now]);
    $before=$wpdb->get_row('SELECT * FROM t_va_cart_items WHERE id='.$legacy,ARRAY_A);
    $adopted=$service->getPublicCart(['user_id'=>9002]);$after=$wpdb->get_row('SELECT * FROM t_va_cart_items WHERE id='.$legacy,ARRAY_A);
    check($after['cart_id']!==null,'LAZY_ADOPTED');unset($after['cart_id'],$before['cart_id']);check($after===$before,'LAZY_NO_DATA_LOSS');
    check($service->getPublicCart(['user_id'=>9002])===$adopted,'LAZY_IDEMPOTENT');
    ++$tests;$GLOBALS['user']=9001;
    reject(fn()=>$service->removeItem(command($snapshot),$legacy),'FOREIGN_OWNER_LINE');

    ++$tests;
    $checkout=new VeciAhorra\Modules\Checkout\Service\CheckoutService(
        new VeciAhorra\Modules\Checkout\Service\CheckoutValidationService($service,new VeciAhorra\Modules\Inventory\Services\InventoryService(),new VeciAhorra\Modules\Products\Services\ProductService(),new VeciAhorra\Modules\Stores\Repositories\StoreRepository()),
        new VeciAhorra\Modules\Reservations\Service\ReservationService(),new VeciAhorra\Modules\Orders\Services\OrderService(),$service
    );
    $snapshot=$service->setMethod(command($snapshot),'pickup')['cart'];
    $snapshot=$service->addItem(command($snapshot),$inventory,2)['cart'];
    $payload=[...command($snapshot),'expected_cart_version'=>$snapshot['version'],'fulfillment_method'=>'pickup','idempotency_key'=>'native-checkout-0001'];
    $result=$checkout->initialize($payload);
    check($result['valid']===true&&count($result['orders'])===1&&count($result['reservations'])===1,'REAL_CHECKOUT_ORDERS_RESERVATIONS');
    sql("UPDATE t_va_inventory SET price='1001.00' WHERE id={$inventory}");
    check($checkout->initialize($payload)===$result,'CONSUMED_CHECKOUT_PRICE_FROZEN_ON_REPLAY');
    check($wpdb->get_var('SELECT total FROM t_va_orders WHERE id='.(int)$result['orders'][0]['id'])==='2000.00','ORDER_PRICE_FROZEN');
    sql("UPDATE t_va_inventory SET price='1000.00' WHERE id={$inventory}");
    $consumed=$wpdb->get_row("SELECT * FROM t_va_carts WHERE public_id='{$snapshot['cart_id']}'",ARRAY_A);
    check($consumed['status']==='consumed'&&$consumed['active_owner']===null&&(int)$consumed['version']===$snapshot['version']+1,'CHECKOUT_CONSUMES_ONCE');
    check((int)$wpdb->get_var('SELECT COUNT(*) FROM t_va_cart_items WHERE cart_id='.(int)$consumed['id'])===0,'CONSUMED_LINES_EMPTY');
    check((int)$wpdb->get_var('SELECT source_cart_id FROM t_va_checkouts WHERE id='.(int)$result['orders'][0]['id'])===(int)$consumed['id'],'DURABLE_ORIGIN_LINK');
    check($checkout->initialize($payload)===$result,'CHECKOUT_REPLAY');
    reject(fn()=>$checkout->initialize([...$payload,'fulfillment_method'=>'delivery']),'CHECKOUT_CONTRADICTORY_REPLAY');
    reject(fn()=>$service->addItem(command($snapshot),$inventory,1),'CONSUMED_NOT_EDITABLE');
    $new=$service->getPublicCart(['user_id'=>9001]);
    check($new['cart_id']!==$snapshot['cart_id']&&$new['version']===1&&$new['status']==='active','NEW_CART_AFTER_CONSUMPTION');
    check((int)$wpdb->get_var("SELECT COUNT(*) FROM t_va_carts WHERE owner_key='".CartAggregate::ownerKey(['user_id'=>9001])."' AND status='active'")===1,'ONE_ACTIVE');
    ++$tests;$snapshot=$service->addItem(command($new),$inventory,1)['cart'];
    $beforeCounts=[];foreach(['orders','order_items','reservations','checkouts','checkout_orders'] as $table)$beforeCounts[$table]=(int)$wpdb->get_var('SELECT COUNT(*) FROM t_va_'.$table);
    $stockBefore=$wpdb->get_var('SELECT stock FROM t_va_inventory WHERE id='.$inventory);
    sql("CREATE TRIGGER cart_failure BEFORE INSERT ON t_va_checkouts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated failure'");
    $failure=null;
    try{$checkout->initialize([...command($snapshot),'expected_cart_version'=>$snapshot['version'],'fulfillment_method'=>'pickup','idempotency_key'=>'native-checkout-failure']);}catch(Throwable $e){$failure=$e;}
    sql('DROP TRIGGER cart_failure');check($failure!==null,'SQL_FAILURE_PROPAGATES');
    check($service->getPublicCart(['user_id'=>9001])===$snapshot,'SQL_FAILURE_CART_LINES_VERSION_INTACT');
    foreach($beforeCounts as $table=>$count)check((int)$wpdb->get_var('SELECT COUNT(*) FROM t_va_'.$table)===$count,'SQL_FAILURE_NO_PARTIAL_'.$table);
    check($wpdb->get_var('SELECT stock FROM t_va_inventory WHERE id='.$inventory)===$stockBefore,'SQL_FAILURE_STOCK_INTACT');

    foreach([['quantity','quantity'],['add','delete'],['clear','quantity'],['method','quantity'],['checkout','quantity'],['checkout','checkout']] as [$a,$b]){
        ++$GLOBALS['user'];$base=$service->getPublicCart(['user_id'=>get_current_user_id()]);$base=$service->addItem(command($base),$inventory,2)['cart'];race($base,$a,$b,$inventory);
    }

    ++$tests;$GLOBALS['user']=9020;$empty=$service->getPublicCart(['user_id'=>9020]);$key=CartAggregate::ownerKey(['user_id'=>9020]);
    $duplicate=$wpdb->query($wpdb->prepare("INSERT INTO t_va_carts(public_id,owner_key,active_owner,user_id,status,version,fulfillment_method,service_zone_id,created_at,updated_at) VALUES(%s,%s,%s,9020,'active',1,'pickup',%d,%s,%s)",bin2hex(random_bytes(24)),$key,$key,$zone,$now,$now));
    check($duplicate===false,'DATABASE_SINGLE_ACTIVE_OWNER');
    $snapshot=$service->addItem(command($empty),$inventory,2)['cart'];
    $id=(int)$snapshot['items'][0]['id'];sql('UPDATE t_va_cart_items SET user_id=9021 WHERE id='.$id);
    reject(fn()=>$service->getPublicCart(['user_id'=>9020]),'BOUND_FOREIGN_OWNER_BLOCKED');
    sql('UPDATE t_va_cart_items SET user_id=9020 WHERE id='.$id);
    ++$tests;$GLOBALS['user']=9022;
    $invalid=put('cart_items',['user_id'=>9022,'inventory_id'=>$inventory,'product_id'=>$product+1,'minimarket_id'=>$store,'quantity'=>1,'unit_price_snapshot'=>'1000.00','created_at'=>$now,'updated_at'=>$now]);
    $legacyBefore=$wpdb->get_row('SELECT * FROM t_va_cart_items WHERE id='.$invalid,ARRAY_A);
    reject(fn()=>$service->getPublicCart(['user_id'=>9022]),'LEGACY_INCONSISTENT_BLOCKED');
    check($wpdb->get_row('SELECT * FROM t_va_cart_items WHERE id='.$invalid,ARRAY_A)===$legacyBefore,'LEGACY_FAILURE_NO_LOSS');
    $GLOBALS['user']=9020;$service->getPublicCart(['user_id'=>9020]);
    check($wpdb->get_row('SELECT * FROM t_va_cart_items WHERE id='.$invalid,ARRAY_A)===$legacyBefore,'NO_GLOBAL_BACKFILL');
    ++$tests;sql("UPDATE t_va_service_zones SET status='inactive' WHERE id={$zone}");$called=false;
    try{$aggregate->materialize([...command($snapshot),'expected_cart_version'=>$snapshot['version'],'fulfillment_method'=>'pickup','idempotency_key'=>'zone-guard-0001'],function()use(&$called){$called=true;throw new DomainException('test operation');});}catch(DomainException){}
    check(!$called,'ZONE_BEFORE_MATERIALIZATION_CALLBACK');
    sql("UPDATE t_va_service_zones SET status='active' WHERE id={$zone}");
    echo "TESTS_RUN={$tests}\nASSERTIONS={$assertions}\nTESTS_FAILED=0\n";
}catch(Throwable $e){echo 'FIRST_FAILURE='.get_class($e).':'.$e->getMessage()."\nTESTS_RUN={$tests}\nASSERTIONS={$assertions}\nTESTS_FAILED=1\n";exit(1);}
