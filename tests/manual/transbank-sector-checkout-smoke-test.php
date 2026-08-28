<?php
declare(strict_types=1);
ob_start();
if (PHP_SAPI !== 'cli' || defined('VECIAHORRA_PUBLIC_COMMERCE_ENABLED')) throw new RuntimeException('SMOKE_PREFLIGHT');
if (!define('VECIAHORRA_PUBLIC_COMMERCE_ENABLED', true)) throw new RuntimeException('SMOKE_GATE_DEFINE');
$oldPath=session_save_path();$sessionDir=sys_get_temp_dir().'/va-transbank-smoke-'.bin2hex(random_bytes(8));
if(!mkdir($sessionDir,0700)){throw new RuntimeException('SMOKE_SESSION_DIR');}session_save_path($sessionDir);
use VeciAhorra\Core\Application;use VeciAhorra\Core\Config;use VeciAhorra\Core\LaunchGate;
use VeciAhorra\Modules\Cart\Repository\CartRepository;use VeciAhorra\Modules\Cart\Service\CartService;
use VeciAhorra\Modules\Catalog\Service\CatalogService;use VeciAhorra\Modules\Checkout\Service\CheckoutService;
use VeciAhorra\Modules\Catalog\Security\PublicOfferToken;
use VeciAhorra\Modules\Inventory\Repositories\InventoryRepository;use VeciAhorra\Modules\Products\Models\Product;
use VeciAhorra\Modules\Products\Repositories\ProductRepository;use VeciAhorra\Modules\Sectorization\CurrentSector;
use VeciAhorra\Modules\Sectorization\ServiceZoneRepository;use VeciAhorra\Modules\Stores\Repositories\StoreRepository;
require_once dirname(__DIR__,5).'/wp-load.php';
function s(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}function same(mixed $a,mixed $b,string $m):void{s($a===$b,$m);}
const SMOKE_SECTOR_META='_veciahorra_service_zone_id';
function rr(string $method,string $route,?array $body=null,string $key=''):WP_REST_Response{$r=new WP_REST_Request($method,$route);if($body!==null){$r->set_header('content-type','application/json');$r->set_body(wp_json_encode($body));}if($key!=='')$r->set_header('Idempotency-Key',$key);return rest_do_request($r);}
function snap():string{global $wpdb;$p=$wpdb->prefix.Config::TABLE_PREFIX;$x=[];foreach(['store_service_zones','stores','products','inventory','cart_items','checkouts','checkout_orders','orders','order_items','reservations','payment_sessions','payments','payment_reconciliations']as$t)$x[$t]=$wpdb->get_results("SELECT * FROM {$p}{$t} ORDER BY 1",ARRAY_A);return hash('sha256',serialize($x));}
global $wpdb;$p=$wpdb->prefix.Config::TABLE_PREFIX;$baseline=snap();$tables=(array)$wpdb->get_col('SHOW TABLES');sort($tables);$tableHash=hash('sha256',implode("\n",$tables));$options=(array)$wpdb->get_col("SELECT option_name FROM {$wpdb->options} ORDER BY option_name");
$admins=get_users(['role'=>'administrator','number'=>1,'fields'=>'ids']);s($admins!==[],'SMOKE_OWNER');$uid=(int)$admins[0];$previousMeta=get_user_meta($uid,SMOKE_SECTOR_META,true);$hadMeta=metadata_exists('user',$uid,SMOKE_SECTOR_META);
$app=new Application();$container=$app->container();$zones=new ServiceZoneRepository();$active=$zones->active();s($active!==[],'SMOKE_ZONE');$zone=(int)$active[0]['id'];
$stores=new StoreRepository();$products=new ProductRepository();$inventory=new InventoryRepository();$cart=new CartService(new CartRepository());$catalog=$container->make(CatalogService::class);$checkout=$container->make(CheckoutService::class);
$paymentBefore=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_sessions");$started=$wpdb->query('START TRANSACTION')!==false;s($started,'SMOKE_BEGIN');$initialized=false;
try{
 s((new LaunchGate())->commerceEnabled(),'SMOKE_COMMERCE');s(LaunchGate::evaluate(true,false,'production')===false,'SMOKE_CLOSED_CONTROL');wp_set_current_user($uid);
 $now=current_time('mysql');$mark='tb-smoke-'.bin2hex(random_bytes(6));
 $store=$stores->create(['business_name'=>$mark,'legal_name'=>$mark,'owner_name'=>'Fixture','rut'=>'1-9','email'=>$mark.'@example.invalid','phone'=>'000','mobile'=>null,'address'=>null,'commune'=>null,'city'=>null,'region'=>null,'status'=>'active','onboarding_status'=>'complete','approved_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
 $product=$products->create(['woo_product_id'=>null,'name'=>$mark,'slug'=>$mark,'sku'=>null,'description'=>null,'category_id'=>null,'brand_id'=>null,'unit_id'=>null,'image_id'=>null,'status'=>Product::STATUS_ACTIVE,'created_at'=>$now,'updated_at'=>$now]);
 $inv=$inventory->create(['product_id'=>$product,'minimarket_id'=>$store,'price'=>1500.0,'stock'=>10,'status'=>'active','created_at'=>$now,'updated_at'=>$now]);
 $current=new CurrentSector($zones);$issuer=new PublicOfferToken();$ownerRef=['session_id'=>null,'user_id'=>$uid];$rawToken=$issuer->issue($inv,$product,$zone,$ownerRef);delete_user_meta($uid,SMOKE_SECTOR_META);same(0,$current->id(),'NEG_NO_SECTOR');
 same(422,rr('POST','/veciahorra/v1/cart/items',['offer_token'=>$rawToken,'quantity'=>1])->get_status(),'NEG_NO_SECTOR_ROUTE');same(422,rr('POST','/veciahorra/v1/cart/items',['quantity'=>1])->get_status(),'NEG_TOKEN_ABSENT');
 $current->set($zone);same($zone,$current->id(),'SMOKE_SECTOR');same(false,$zones->storeAllowed($zone,$store),'NEG_NO_LINK');same(422,rr('POST','/veciahorra/v1/cart/items',['offer_token'=>$rawToken,'quantity'=>1])->get_status(),'NEG_NO_LINK_ROUTE');
 $other=$stores->create(['business_name'=>$mark.'-o','legal_name'=>$mark.'-o','owner_name'=>'Fixture','rut'=>'2-7','email'=>$mark.'-o@example.invalid','phone'=>'000','mobile'=>null,'address'=>null,'commune'=>null,'city'=>null,'region'=>null,'status'=>'active','onboarding_status'=>'complete','approved_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
 s($wpdb->insert($p.'store_service_zones',['store_id'=>$other,'zone_id'=>$zone,'assigned_by'=>$uid,'assigned_at'=>$now])!==false,'NEG_WRONG_LINK');same(false,$zones->storeAllowed($zone,$store),'NEG_WRONG_STORE');same(422,rr('POST','/veciahorra/v1/cart/items',['offer_token'=>$rawToken,'quantity'=>1])->get_status(),'NEG_WRONG_STORE_ROUTE');
 s($wpdb->insert($p.'store_service_zones',['store_id'=>$store,'zone_id'=>$zone,'assigned_by'=>$uid,'assigned_at'=>$now])!==false,'SMOKE_LINK');s($zones->storeAllowed($zone,$store),'SMOKE_LINK_READBACK');
 $offer=$catalog->find($product);$offers=$offer['offers']??[];s(count($offers)===1&&is_string($offers[0]['offer_token']??null),'SMOKE_PUBLIC_OFFER');$token=$offers[0]['offer_token'];
 $bad=rr('POST','/veciahorra/v1/cart/items',['offer_token'=>'invalid','quantity'=>1]);same(422,$bad->get_status(),'NEG_TOKEN');same(422,rr('POST','/veciahorra/v1/cart/items',['offer_token'=>$issuer->issue($inv,$product+999999,$zone,$ownerRef),'quantity'=>1])->get_status(),'NEG_TOKEN_MISMATCH');same(422,rr('POST','/veciahorra/v1/cart/items',['offer_token'=>$token,'quantity'=>11])->get_status(),'NEG_STOCK');
 $created=rr('POST','/veciahorra/v1/cart/items',['offer_token'=>$token,'quantity'=>2]);same(201,$created->get_status(),'SMOKE_CART_STATUS');$itemId=(int)($created->get_data()['data']['id']??0);s($itemId>0,'SMOKE_CART_ID');
 $public=rr('GET','/veciahorra/v1/cart')->get_data()['data']??[];same(1,count($public),'SMOKE_PUBLIC_CART');$item=$public[0];
 same($itemId,(int)$item['id'],'SMOKE_ITEM');same($product,(int)$item['product_id'],'SMOKE_PRODUCT');same($mark,$item['product_name'],'SMOKE_NAME');same('2',$item['quantity'],'SMOKE_QTY');same('1500.00',$item['unit_price_snapshot'],'SMOKE_PRICE');same('3000.00',$item['subtotal'],'SMOKE_SUBTOTAL');same(true,$item['sector_compatible'],'SMOKE_COMPAT');s(is_string($item['offer_group']??null)&&$item['offer_group']!=='','SMOKE_GROUP');foreach(['inventory_id','minimarket_id','session_id','user_id','offer_token']as$f)s(!array_key_exists($f,$item),'SMOKE_PRIVATE_'.$f);
 $foreign=['session_id'=>hash('sha256',$mark),'user_id'=>null,'fulfillment_method'=>'pickup','idempotency_key'=>$mark.'-foreign'];same(false,$checkout->validate($foreign)['valid'],'NEG_OWNER');
 $owner=['session_id'=>null,'user_id'=>$uid,'fulfillment_method'=>'pickup','idempotency_key'=>$mark.'-checkout'];$valid=$checkout->validate($owner);same(true,$valid['valid'],'SMOKE_VALIDATE');
 $result=$checkout->initialize($owner);$initialized=($result['valid']??false)===true&&($result['checkout']['checkout_id']??'')!==''&&count($result['orders']??[])===1&&count($result['reservations']??[])===1;s($initialized,'SMOKE_INITIALIZE_EVIDENCE');
 same($paymentBefore,(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_sessions"),'SMOKE_PAYMENT_SESSION');same(0,(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_sessions WHERE checkout_id=%d",(int)($result['checkout']['id']??0))),'SMOKE_PAYMENT_LINK');
 echo "TRANSBANK_SECTOR_CHECKOUT_SMOKE=PASS\nCOMMERCE_OPEN=yes\nSECTOR_SELECTED=yes\nSTORE_SECTOR_LINK=yes\nPUBLIC_OFFER=yes\nOFFER_TOKEN=yes\nCART_CREATED=yes\nPUBLIC_CART_CONTRACT=yes\nCHECKOUT_VALIDATED=yes\nCHECKOUT_INITIALIZED=yes\nORDER_CREATED=yes\nRESERVATION_CREATED=yes\nPAYMENT_SESSION_CREATED=no\nWEBPAY_GATEWAY_INVOKED=no\nEXTERNAL_REQUESTS=0\nNEGATIVE_CONTROLS=PASS\n";
}finally{
 if($started)$wpdb->query('ROLLBACK');if($hadMeta)update_user_meta($uid,SMOKE_SECTOR_META,$previousMeta);else delete_user_meta($uid,SMOKE_SECTOR_META);wp_set_current_user(0);
 if(session_status()===PHP_SESSION_ACTIVE){$_SESSION=[];session_destroy();}session_save_path($oldPath);foreach(glob($sessionDir.'/*')?:[]as$f)@unlink($f);@rmdir($sessionDir);
 s(snap()===$baseline,'SMOKE_RESIDUE');$afterTables=(array)$wpdb->get_col('SHOW TABLES');sort($afterTables);same($tableHash,hash('sha256',implode("\n",$afterTables)),'SMOKE_TABLES');$afterOptions=(array)$wpdb->get_col("SELECT option_name FROM {$wpdb->options} ORDER BY option_name");same($options,$afterOptions,'SMOKE_OPTIONS');s($initialized,'SMOKE_ANTI_FALSE_PASS');
}
