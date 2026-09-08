<?php
declare(strict_types=1);
// Included only by the disposable MariaDB runner, after production schemas and fixtures.
use VeciAhorra\Modules\Cart\Proximity\GeoPoint;
use VeciAhorra\Modules\Cart\Proximity\PickupModule;
use VeciAhorra\Modules\Cart\Proximity\PickupProposalService;
use VeciAhorra\Modules\Cart\Proximity\PickupSettings;
use VeciAhorra\Modules\Cart\Service\CartAggregate;

$pickup = new PickupProposalService();
$point = ['latitude'=>-33.45,'longitude'=>-70.65,'confirmed'=>true];
$makeStore = static function(string $name,int $zoneId,?float $lat,?float $lon)use($now):int{
    $id=put('stores',['business_name'=>$name,'legal_name'=>$name,'owner_name'=>'Test','rut'=>substr(hash('sha256',$name),0,12),'email'=>'test@example.invalid','phone'=>'123456789','status'=>'active','onboarding_status'=>'complete','approved_at'=>$now,'created_at'=>$now,'updated_at'=>$now,'pickup_latitude'=>$lat,'pickup_longitude'=>$lon]);
    put('store_service_zones',['store_id'=>$id,'zone_id'=>$zoneId,'assigned_by'=>9001,'assigned_at'=>$now]);return $id;
};
$makeInventory=static fn(int $s,int $p,int $price,int $stock=100):int=>put('inventory',['product_id'=>$p,'minimarket_id'=>$s,'stock'=>$stock,'price'=>$price.'.00','status'=>'active','created_at'=>$now,'updated_at'=>$now]);
$product2=put('products',['name'=>'Other product','slug'=>'other-product','status'=>'active','created_at'=>$now,'updated_at'=>$now]);
$far=$makeStore('Far cheap',$zone,-33.55,-70.65);
$near=$makeStore('Near expensive',$zone,-33.451,-70.65);
$origin2=$makeStore('Original second store',$zone,null,null);
$otherInventory=$makeInventory($origin2,$product2,1000);
$farOffers=[$makeInventory($far,$product,1000),$makeInventory($far,$product2,1000)];
$nearOffers=[$makeInventory($near,$product,4000),$makeInventory($near,$product2,4000)];
$fresh=static function()use($service,$inventory,$otherInventory):array{
    $GLOBALS['user']=($GLOBALS['pickup_user']??9200)+1;$GLOBALS['pickup_user']=$GLOBALS['user'];
    $cart=$service->getPublicCart(['user_id'=>get_current_user_id()]);
    $cart=$service->addItem(command($cart),$inventory,2)['cart'];
    $cart=$service->addItem(command($cart),$otherInventory,1)['cart'];
    return $service->setMethod(command($cart),'delivery')['cart'];
};
$dbState=static function()use($wpdb):array{
    $result=[];foreach(['carts','cart_items','reservations','orders','order_items','checkouts','checkout_orders','payment_sessions','inventory'] as $table)$result[$table]=$wpdb->get_results('SELECT * FROM t_va_'.$table.' ORDER BY id',ARRAY_A);return $result;
};
$accept=static fn(array $cart,array $proposal,string $key='pickup-accept-test')=>$pickup->accept(command($cart),['proposal_id'=>$proposal['proposal_id'],'accepted'=>true,'idempotency_key'=>$key]);
$rejectUnchanged=static function(callable $action,string $label)use($dbState):void{
    $before=$dbState();reject($action,$label);check($dbState()===$before,$label.'_NO_PARTIAL_CHANGE');
};

++ $tests;
check(abs(GeoPoint::distance(['latitude'=>0.0,'longitude'=>0.0],['latitude'=>0.0,'longitude'=>1.0])-111195.08)<1,'HAVERSINE_KNOWN_DISTANCE');
check(GeoPoint::distance(['latitude'=>90.0,'longitude'=>180.0],['latitude'=>90.0,'longitude'=>180.0])===0.0,'HAVERSINE_IDENTICAL');
foreach([[null,0],['',0],['abc',0],[91,0],[0,181],[false,0],[INF,0]] as [$lat,$lon])reject(fn()=>GeoPoint::validate($lat,$lon),'INVALID_COORDINATES');
$cart=$fresh();$before=$dbState();$proposal=$pickup->search(command($cart),$point)['proposal'];
check($proposal['store_name']==='Near expensive','DISTANCE_NOT_PRICE_RANKING');
check($proposal['distance_metres']>100&&$proposal['distance_metres']<120,'SERVER_DISTANCE');
check($dbState()===$before,'SEARCH_NO_CART_OR_COMMERCE_MUTATION');
check(count($proposal['items'])===2&&array_column($proposal['items'],'quantity')===[2,1],'SAME_PRODUCTS_QUANTITIES');
check($proposal['summary']['product_subtotal']==='12000.00'&&$proposal['summary']['platform_fee']==='700.00'&&$proposal['summary']['delivery_fee']==='0.00'&&$proposal['summary']['total']==='12700.00','PROPOSAL_FEES');
check(!str_contains(json_encode($proposal),'inventory_id')&&!str_contains(json_encode($proposal),'stock'),'PUBLIC_MINIMUM_DATA');
check($service->getPublicCart(['user_id'=>get_current_user_id()])===$cart,'DECLINE_LEAVES_CART_INTACT');
$stored=$wpdb->get_results('SELECT * FROM t_va_pickup_proposals',ARRAY_A);
check(!str_contains(json_encode($stored),'-33.45')&&!str_contains(json_encode($stored),'-70.65'),'NO_SEARCH_LOCATION_PERSISTED');

++ $tests;
foreach([7999,8000,8001] as $amount){
    $GLOBALS['user']=($GLOBALS['pickup_user']+=1);$c=$service->getPublicCart(['user_id'=>get_current_user_id()]);
    sql("UPDATE t_va_inventory SET price='{$amount}.00' WHERE id={$inventory}");
    $c=$service->addItem(command($c),$inventory,1)['cart'];$c=$service->setMethod(command($c),'delivery')['cart'];
    check($c['summary']['pickup_search_eligible']===($amount<8000),'THRESHOLD_VISIBILITY_'.$amount);
    if($amount<8000)check($pickup->search(command($c),$point)['proposal']!==null,'BELOW_MINIMUM_SEARCH');
    else $rejectUnchanged(fn()=>$pickup->search(command($c),$point),'AT_OR_ABOVE_MINIMUM_REJECTED_'.$amount);
    if($amount===8000)check((new VeciAhorra\Modules\Checkout\Service\FulfillmentPolicy())->authorize('delivery','8000.00')==='delivery','EXACT_MINIMUM_DELIVERY_AUTHORIZED');
}
sql("UPDATE t_va_inventory SET price='1000.00' WHERE id={$inventory}");

++ $tests;
$cart=$fresh();
foreach(['distance'=>0,'store_id'=>$far,'inventory_id'=>$farOffers[0],'service_zone_id'=>$otherZone,'address'=>'<script>bad</script>','total'=>1] as $field=>$value)$rejectUnchanged(fn()=>$pickup->search(command($cart),[...$point,$field=>$value]),'PAYLOAD_TAMPERING_'.$field);
$rejectUnchanged(fn()=>$pickup->search(command($cart),[...$point,'confirmed'=>false]),'POINT_CONFIRMATION');
foreach([null,0,-1,$cart['version']-1,$cart['version']+1,(string)$cart['version']] as $version)$rejectUnchanged(fn()=>$pickup->search([...command($cart),'expected_version'=>$version],$point),'SEARCH_VERSION');
$owner=command($cart);$GLOBALS['user']=1;$rejectUnchanged(fn()=>$pickup->search($owner,$point),'OWNER_REQUIRED');$GLOBALS['user']=$owner['user_id'];
$module=new PickupModule();$request=new WP_REST_Request('POST','/veciahorra/v1/cart/proximity/search');$request->set_header('Content-Type','application/json');$request->set_body(json_encode([...command($cart),...$point]));
check($module->handle($request,'search')->get_status()===403,'REST_NONCE_REQUIRED');
$request->set_header('X-WP-Nonce','test-nonce');$request->set_body(json_encode(['cart_id'=>$cart['cart_id'],'expected_version'=>$cart['version'],...$point]));
$before=$dbState();$response=$module->handle($request,'search');
check($response->get_status()===200&&$response->get_data()['data']['proposal']['store_name']==='Near expensive','REAL_PICKUP_SEARCH_ROUTE');
check($dbState()===$before,'REST_SEARCH_NO_SIDE_EFFECTS');

++ $tests;
// Each invalid nearest store must be excluded completely, leaving the farther complete one.
$invalidations=[
    ["UPDATE t_va_stores SET pickup_latitude=NULL WHERE id={$near}","UPDATE t_va_stores SET pickup_latitude=-33.451 WHERE id={$near}",'MISSING_COORDINATES'],
    ["UPDATE t_va_store_service_zones SET zone_id={$otherZone} WHERE store_id={$near}","UPDATE t_va_store_service_zones SET zone_id={$zone} WHERE store_id={$near}",'OUT_OF_ZONE'],
    ["UPDATE t_va_stores SET status='inactive' WHERE id={$near}","UPDATE t_va_stores SET status='active' WHERE id={$near}",'INACTIVE_STORE'],
    ["UPDATE t_va_stores SET approved_at=NULL WHERE id={$near}","UPDATE t_va_stores SET approved_at='{$now}' WHERE id={$near}",'UNAPPROVED_STORE'],
    ["UPDATE t_va_inventory SET status='inactive' WHERE id={$nearOffers[0]}","UPDATE t_va_inventory SET status='active' WHERE id={$nearOffers[0]}",'INACTIVE_INVENTORY'],
    ["UPDATE t_va_inventory SET stock=1 WHERE id={$nearOffers[0]}","UPDATE t_va_inventory SET stock=100 WHERE id={$nearOffers[0]}",'INSUFFICIENT_STOCK'],
    ["UPDATE t_va_inventory SET price='0.00' WHERE id={$nearOffers[0]}","UPDATE t_va_inventory SET price='4000.00' WHERE id={$nearOffers[0]}",'INVALID_PRICE'],
    ["UPDATE t_va_inventory SET product_id=99999 WHERE id={$nearOffers[1]}","UPDATE t_va_inventory SET product_id={$product2} WHERE id={$nearOffers[1]}",'ONE_PRODUCT_MISSING'],
];
foreach($invalidations as [$invalidate,$restore,$label]){
    sql($invalidate);check($pickup->search(command($cart),$point)['proposal']['store_name']==='Far cheap',$label.'_EXCLUDED');sql($restore);
}
sql("UPDATE t_va_inventory SET stock=0 WHERE id IN ({$nearOffers[0]},{$farOffers[1]})");
check($pickup->search(command($cart),$point)['proposal']===null,'NO_MULTISTORE_COMBINATION');
sql("UPDATE t_va_inventory SET stock=100 WHERE id IN ({$nearOffers[0]},{$farOffers[1]})");
sql("UPDATE t_va_stores SET pickup_latitude=-33.451 WHERE id={$far}");
check($pickup->search(command($cart),$point)['proposal']['store_name']==='Far cheap','DISTANCE_TIE_STORE_ID');
sql("UPDATE t_va_stores SET pickup_latitude=-33.55 WHERE id={$far}");
sql("UPDATE t_va_stores SET pickup_latitude=NULL WHERE id IN ({$far},{$near})");
check($pickup->search(command($cart),$point)['proposal']===null,'NO_COORDINATES_NO_CANDIDATE');
sql("UPDATE t_va_stores SET pickup_latitude=-33.55 WHERE id={$far}");sql("UPDATE t_va_stores SET pickup_latitude=-33.451 WHERE id={$near}");

++ $tests;
$proposal=$pickup->search(command($cart),$point)['proposal'];
$rejectUnchanged(fn()=>$pickup->accept(command($cart),['proposal_id'=>$proposal['proposal_id'],'accepted'=>false,'idempotency_key'=>'explicit-acceptance']),'EXPLICIT_ACCEPTANCE_REQUIRED');
foreach([null,0,-1,$cart['version']-1,$cart['version']+1,(string)$cart['version']] as $version)$rejectUnchanged(fn()=>$pickup->accept([...command($cart),'expected_version'=>$version],['proposal_id'=>$proposal['proposal_id'],'accepted'=>true,'idempotency_key'=>'version-acceptance']),'ACCEPT_VERSION');
$rejectUnchanged(fn()=>$pickup->accept(command($cart),['proposal_id'=>$proposal['proposal_id'],'accepted'=>true,'idempotency_key'=>'']),'ACCEPT_IDEMPOTENCY_REQUIRED');
foreach($invalidations as [$invalidate,$restore,$label]){
    sql($invalidate);$rejectUnchanged(fn()=>$accept($cart,$proposal),'REVALIDATE_'.$label);sql($restore);
}
sql("UPDATE t_va_inventory SET price='4001.00' WHERE id={$nearOffers[0]}");$rejectUnchanged(fn()=>$accept($cart,$proposal),'REVALIDATE_PRICE');sql("UPDATE t_va_inventory SET price='4000.00' WHERE id={$nearOffers[0]}");
sql("UPDATE t_va_stores SET pickup_latitude=-33.452 WHERE id={$near}");$rejectUnchanged(fn()=>$accept($cart,$proposal),'REVALIDATE_COORDINATES');sql("UPDATE t_va_stores SET pickup_latitude=-33.451 WHERE id={$near}");
sql("UPDATE t_va_pickup_proposals SET expires_at='2000-01-01 00:00:00' WHERE public_id='{$proposal['proposal_id']}'");
$rejectUnchanged(fn()=>$accept($cart,$proposal),'EXPIRED_PROPOSAL');
$proposal=$pickup->search(command($cart),$point)['proposal'];
$before=$dbState();
sql("CREATE TRIGGER pickup_failure BEFORE INSERT ON t_va_cart_items FOR EACH ROW BEGIN IF NEW.product_id={$product2} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='pickup fixture failure'; END IF; END");
$error=null;try{$accept($cart,$proposal);}catch(Throwable $e){$error=$e;}sql('DROP TRIGGER pickup_failure');
check($error!==null,'SECOND_LINE_SQL_FAILURE');check($dbState()===$before,'REPLACEMENT_ROLLBACK_ALL_LINES');

++ $tests;
$before=$dbState();$accepted=$accept($cart,$proposal);$changed=$accepted['cart'];
check($changed['version']===$cart['version']+1&&$changed['cart_id']===$cart['cart_id'],'ACCEPT_VERSION_ONCE');
check($changed['fulfillment_method']==='pickup'&&$changed['summary']['delivery_fee']==='0.00','FINAL_PICKUP_ZERO_DELIVERY');
check($changed['summary']['product_subtotal']==='12000.00'&&$changed['summary']['total']==='12700.00','FINAL_RECALCULATED_TOTAL');
$lines=$wpdb->get_results('SELECT * FROM t_va_cart_items WHERE user_id='.get_current_user_id().' ORDER BY product_id',ARRAY_A);
check(array_map('intval',array_column($lines,'product_id'))===[$product,$product2]&&array_map('intval',array_column($lines,'quantity'))===[2,1],'ACCEPT_EXACT_PRODUCTS_QUANTITIES');
check(array_unique(array_map('intval',array_column($lines,'minimarket_id')))===[$near],'ACCEPT_SINGLE_STORE');
$after=$dbState();foreach(['reservations','orders','order_items','checkouts','checkout_orders','payment_sessions','inventory'] as $table)check($after[$table]===$before[$table],'ACCEPT_NO_COMMERCE_SIDE_EFFECT_'.$table);
check($accept($cart,$proposal)===$accepted,'ACCEPT_IDEMPOTENT_REPLAY');check($dbState()===$after,'REPLAY_NO_MUTATION');
$request->set_header('Idempotency-Key','pickup-accept-test');$request->set_body(json_encode(['cart_id'=>$cart['cart_id'],'expected_version'=>$cart['version'],'proposal_id'=>$proposal['proposal_id'],'accepted'=>true]));
$response=$module->handle($request,'accept');check($response->get_status()===200&&$response->get_data()['data']===$accepted,'REAL_PICKUP_ACCEPT_ROUTE_REPLAY');
$rejectUnchanged(fn()=>$accept($cart,$proposal,'different-key'),'CONTRADICTORY_REPLAY');
$rejectUnchanged(fn()=>$pickup->search(command($changed),$point),'PICKUP_CART_NOT_ELIGIBLE');

foreach([['accept','accept'],['accept','quantity'],['accept','checkout']] as [$a,$b]){
    $cart=$fresh();$proposal=$pickup->search(command($cart),$point)['proposal'];$before=$dbState();
    race($cart,$a,$b,$inventory,$proposal['proposal_id']);
    $after=$dbState();foreach(['orders','reservations','checkouts','payment_sessions'] as $table)check($after[$table]===$before[$table],'ACCEPT_RACE_NO_COMMERCE_'.$a.'_'.$b);
}

++ $tests;
// Inventory reduction owns its row before acceptance; acceptance must wait and revalidate.
$cart=$fresh();$proposal=$pickup->search(command($cart),$point)['proposal'];$before=$dbState();
sql('START TRANSACTION');sql("UPDATE t_va_inventory SET stock=0 WHERE id={$nearOffers[0]}");
$payload=base64_encode(json_encode(['owner'=>command($cart),'operation'=>'accept','proposal'=>$proposal['proposal_id'],'key'=>'stock-race-acceptance']));
$pipes=[];$job=proc_open([PHP_BINARY,__DIR__.'/../cart-aggregate-mysql-test.php','--race',$database,$payload],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);
try{
    check(trim(fgets($pipes[1]))==='STARTED','STOCK_WORKER_STARTED');$blocked=false;$deadline=microtime(true)+8;
    while(microtime(true)<$deadline){foreach($wpdb->get_results('SHOW FULL PROCESSLIST',ARRAY_A) as $process){if(($process['db']??'')===$database&&str_contains($process['Info']??'','inventory WHERE minimarket_id=')&&str_contains($process['Info']??'','FOR UPDATE'))$blocked=true;}if($blocked)break;usleep(20000);}
    check($blocked,'ACCEPT_LOCKS_INVENTORY');sql('COMMIT');
    $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($job);$job=null;
    check($exit===0&&$errors===''&&str_contains($output,'RESULT=conflict'),'STOCK_REDUCTION_REVALIDATED');
}finally{sql('ROLLBACK');if(is_resource($job)){proc_terminate($job);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($job);}}
$after=$dbState();foreach(['carts','cart_items','orders','reservations','checkouts','payment_sessions'] as $table)check($after[$table]===$before[$table],'STOCK_RACE_NO_PARTIAL_'.$table);
sql("UPDATE t_va_inventory SET stock=100 WHERE id={$nearOffers[0]}");
// Coordinate administration changes only the location fields and respects existing zones.
++ $tests;$GLOBALS['user']=9001;$settings=new PickupSettings();
$beforeStore=$wpdb->get_row('SELECT * FROM t_va_stores WHERE id='.$near,ARRAY_A);
$beforeZones=$wpdb->get_results('SELECT * FROM t_va_store_service_zones ORDER BY id',ARRAY_A);
check($settings->browserKey()==='','GOOGLE_KEY_DEFAULT_EMPTY');
$settings->saveCoordinates($near,'-34.0','-71.0',false);
$afterStore=$wpdb->get_row('SELECT * FROM t_va_stores WHERE id='.$near,ARRAY_A);
check($afterStore['pickup_latitude']==='-34.0000000'&&$afterStore['pickup_longitude']==='-71.0000000','ADMIN_COORDINATES_SAVED');
unset($beforeStore['pickup_latitude'],$beforeStore['pickup_longitude'],$afterStore['pickup_latitude'],$afterStore['pickup_longitude']);
check($beforeStore===$afterStore&&$beforeZones===$wpdb->get_results('SELECT * FROM t_va_store_service_zones ORDER BY id',ARRAY_A),'ADMIN_NO_ZONE_OR_LIFECYCLE_CHANGES');
reject(fn()=>$settings->saveCoordinates($near,'','',false),'ADMIN_EMPTY_COORDINATES_INVALID');
$settings->saveCoordinates($near,null,null,true);check($wpdb->get_var('SELECT pickup_latitude FROM t_va_stores WHERE id='.$near)===null,'ADMIN_LOCATION_PENDING');
$GLOBALS['user']=9201;reject(fn()=>$settings->saveCoordinates($near,0,0,false),'ADMIN_PERMISSION_REQUIRED');
echo "PICKUP_CONCURRENCY=4/4\nPICKUP_EXTERNAL_CALLS=0\n";
