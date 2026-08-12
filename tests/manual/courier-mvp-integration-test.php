<?php
declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Database\Installer;
use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Tables\CouriersTable;
use VeciAhorra\Database\Schemas\CheckoutSchema;
use VeciAhorra\Database\Schemas\DeliverySchema;
use VeciAhorra\Modules\Couriers\CourierModule;
use VeciAhorra\Modules\Couriers\Identity\CourierRole;
use VeciAhorra\Modules\Couriers\Repository\CourierRepository;

require_once dirname(__DIR__,5).'/wp-load.php';
require_once ABSPATH.'wp-admin/includes/user.php';

function rAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function rReq(string $method,string $path,array $body=[]):WP_REST_Response{$r=new WP_REST_Request($method,'/veciahorra/v1/'.ltrim($path,'/'));if($body!==[]){$r->set_header('Content-Type','application/json');$r->set_body((string)wp_json_encode($body));}return rest_do_request($r);}
function rInsert(string $table,array $data,array &$ids):int{global $wpdb;rAssert($wpdb->insert($table,$data)===1,'Fixture no insertada en '.$table);$id=(int)$wpdb->insert_id;$ids[$table][]=$id;return $id;}

global $wpdb; Installer::install(); CourierRole::register(); do_action('rest_api_init');
$p=$wpdb->prefix.Config::TABLE_PREFIX;$ids=[];$users=[];$temporary=[];$now=current_time('mysql',true);$future=gmdate('Y-m-d H:i:s',time()+3600);$n=bin2hex(random_bytes(5));
try{
    rAssert(version_compare(Config::SCHEMA_VERSION, '0.25.0', '>='),'Upgrade version Courier incorrecta.');
    require_once ABSPATH.'wp-admin/includes/upgrade.php';
    foreach([new CouriersTable(),new CheckoutSchema(),new DeliverySchema()] as $definition){$temp=$p.'r_clean_'.$n.'_'.$definition->name();$temporary[]=$temp;$builder=TableBuilder::make($temp);$definition->define($builder);dbDelta($builder->build($wpdb->get_charset_collate()));rAssert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$temp))===$temp,'Instalacion limpia fallo para '.$definition->name());}
    foreach(['couriers','checkouts','deliveries'] as $table)rAssert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$p.$table))===$p.$table,'Tabla ausente: '.$table);
    foreach(['delivery_recipient_name','delivery_contact_phone','delivery_address_line1','delivery_commune','delivery_reference','delivery_notes'] as $column){rAssert($wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$p}checkouts LIKE %s",$column))===$column,'Upgrade Checkout incompleto.');rAssert($wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$p}deliveries LIKE %s",$column))===$column,'Upgrade Delivery incompleto.');}
    foreach(['a','b','plain'] as $s){$u=wp_create_user("r_{$s}_{$n}",wp_generate_password(24),"r_{$s}_{$n}@example.test");rAssert(is_int($u),'Usuario no creado.');$users[]=$u;}
    [$ua,$ub,$plain]=$users;(new WP_User($ua))->set_role(CourierRole::ROLE);(new WP_User($ub))->set_role(CourierRole::ROLE);
    $ca=rInsert($p.'couriers',['display_name'=>'Courier A','phone'=>'+56911111111','email'=>null,'status'=>'approved','approved_at'=>$now,'created_at'=>$now,'updated_at'=>$now],$ids);
    $cb=rInsert($p.'couriers',['display_name'=>'Courier B','phone'=>'+56922222222','email'=>null,'status'=>'approved','approved_at'=>$now,'created_at'=>$now,'updated_at'=>$now],$ids);
    $pending=rInsert($p.'couriers',['display_name'=>'Pending','phone'=>'+56933333333','email'=>null,'status'=>'pending','approved_at'=>null,'created_at'=>$now,'updated_at'=>$now],$ids);
    update_user_meta($ua,CourierRole::META_KEY,$ca);update_user_meta($ub,CourierRole::META_KEY,$cb);
    $customer=wp_create_user("r_customer_{$n}",wp_generate_password(24),"r_customer_{$n}@example.test");rAssert(is_int($customer),'Cliente no creado.');$users[]=$customer;
    $store=rInsert($p.'stores',['business_name'=>'Retiro '.$n,'legal_name'=>'Retiro','owner_name'=>'Owner','rut'=>'R-'.$n,'email'=>'store'.$n.'@example.test','phone'=>'+5622222222','mobile'=>null,'address'=>'Calle Retiro 10','commune'=>'Santiago','city'=>'Santiago','region'=>'RM','status'=>'active','onboarding_status'=>'complete','approved_at'=>$now,'created_at'=>$now,'updated_at'=>$now],$ids);
    $order=rInsert($p.'orders',['customer_id'=>$customer,'minimarket_id'=>$store,'total'=>'1000.00','status'=>'paid','store_fulfillment_status'=>'ready_for_pickup','store_confirmed_at'=>$now,'store_preparation_started_at'=>$now,'store_ready_for_pickup_at'=>$now,'reservation_expires_at'=>$future,'created_at'=>$now,'updated_at'=>$now],$ids);
    $checkout=rInsert($p.'checkouts',['public_id'=>'chk_'.rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'='),'owner_type'=>'user','user_id'=>$customer,'session_id'=>null,'status'=>'payment_completed','fulfillment_method'=>'delivery','delivery_recipient_name'=>'Cliente Uno','delivery_contact_phone'=>'+56944444444','delivery_address_line1'=>'Calle Entrega 20','delivery_commune'=>'Providencia','delivery_reference'=>'Casa azul','delivery_notes'=>'Tocar timbre','currency'=>'CLP','total_amount'=>'1000.00','created_at'=>$now,'updated_at'=>$now,'expires_at'=>$future],$ids);
    rInsert($p.'checkout_orders',['checkout_id'=>$checkout,'order_id'=>$order,'created_at'=>$now],$ids);
    $delivery=rInsert($p.'deliveries',['order_id'=>$order,'customer_id'=>$customer,'minimarket_id'=>$store,'courier_id'=>null,'status'=>'pending','delivery_recipient_name'=>'Cliente Uno','delivery_contact_phone'=>'+56944444444','delivery_address_line1'=>'Calle Entrega 20','delivery_commune'=>'Providencia','delivery_reference'=>'Casa azul','delivery_notes'=>'Tocar timbre','created_at'=>$now,'updated_at'=>$now],$ids);

    rAssert(get_role(CourierRole::ROLE)?->has_cap(CourierRole::CAPABILITY)===true,'R01 role/capability');
    rAssert((new CourierRepository())->isApproved(['status'=>'approved','approved_at'=>null])&&! (new CourierRepository())->isApproved(['approved_at'=>$now,'is_approved'=>1]),'R02 autoridad status');
    wp_set_current_user($ua);rAssert(rReq('GET','courier/me')->get_status()===200,'R03 login');
    rAssert(shortcode_exists(CourierModule::SHORTCODE)&&str_contains(do_shortcode('['.CourierModule::SHORTCODE.']'),'data-va-courier'),'R04 panel');
    $available=rReq('GET','courier/deliveries/available')->get_data()['data'];$fixtureAvailable=array_values(array_filter($available,static fn(array $row):bool=>(int)$row['id']===$delivery));rAssert(count($fixtureAvailable)===1,'R05 disponibles');
    rAssert($fixtureAvailable[0]['minimarket']['address']==='Calle Retiro 10','R07 retiro');rAssert($fixtureAvailable[0]['delivery']['address_line1']==='Calle Entrega 20','R08 entrega');

    // Dos sesiones MySQL se superponen: la segunda consulta queda esperando el lock de la primera.
    $host=DB_HOST;$port=ini_get('mysqli.default_port')?:3306;if(str_contains($host,':')){[$host,$portText]=explode(':',$host,2);if(ctype_digit($portText))$port=(int)$portText;}
    $m1=new mysqli($host,DB_USER,DB_PASSWORD,DB_NAME,(int)$port);$m2=new mysqli($host,DB_USER,DB_PASSWORD,DB_NAME,(int)$port);rAssert(!$m1->connect_errno&&!$m2->connect_errno,'Conexiones concurrencia');
    $escapedNow=$m1->real_escape_string($now);$cas1="UPDATE {$p}deliveries SET courier_id={$ca},status='assigned',updated_at='{$escapedNow}' WHERE id={$delivery} AND courier_id IS NULL AND status='pending'";$cas2="UPDATE {$p}deliveries SET courier_id={$cb},status='assigned',updated_at='{$escapedNow}' WHERE id={$delivery} AND courier_id IS NULL AND status='pending'";
    $m1->begin_transaction();rAssert($m1->query($cas1)&&$m1->affected_rows===1,'Primer CAS no ganó.');rAssert($m2->query($cas2,MYSQLI_ASYNC),'Segundo CAS no inició.');$m1->commit();$links=[$m2];$errors=$reject=[];rAssert(mysqli_poll($links,$errors,$reject,5)>0,'Segundo CAS no finalizó.');$m2->reap_async_query();rAssert($m2->affected_rows===0,'Segundo CAS adquirió ownership.');$m1->close();$m2->close();
    rAssert((int)$wpdb->get_var($wpdb->prepare("SELECT courier_id FROM {$p}deliveries WHERE id=%d",$delivery))===$ca,'R09 owner final');
    $repeat=rReq('POST',"courier/deliveries/{$delivery}/accept");rAssert($repeat->get_status()===200,'R09 repetición no idempotente');
    wp_set_current_user($ub);rAssert(rReq('GET',"courier/deliveries/{$delivery}")->get_status()===404&&rReq('POST',"courier/deliveries/{$delivery}/picked-up",['courier_id'=>$cb])->get_status()===404,'R14 aislamiento/spoof');
    wp_set_current_user($ua);rAssert(rReq('POST',"courier/deliveries/{$delivery}/delivered")->get_status()===409,'Delivered anticipado aceptado.');
    rAssert(rReq('POST',"courier/deliveries/{$delivery}/picked-up")->get_status()===200,'R10 picked_up');rAssert(rReq('POST',"courier/deliveries/{$delivery}/picked-up")->get_status()===200,'picked_up repetido');
    rAssert(rReq('POST',"courier/deliveries/{$delivery}/delivered")->get_status()===200,'R11 delivered');rAssert(rReq('POST',"courier/deliveries/{$delivery}/delivered")->get_status()===200,'delivered repetido');
    rAssert($wpdb->get_var($wpdb->prepare("SELECT status FROM {$p}orders WHERE id=%d",$order))==='delivered','R12/R13 proyección Order');
    rAssert(count(rReq('GET','courier/deliveries')->get_data()['data'])===1,'R06 propias');
    wp_set_current_user($plain);rAssert(rReq('GET','courier/me')->get_status()===403,'Usuario sin role/meta admitido.');update_user_meta($plain,CourierRole::META_KEY,$pending);(new WP_User($plain))->set_role(CourierRole::ROLE);rAssert(rReq('GET','courier/me')->get_status()===403,'Pending admitido.');(new CourierRepository())->transition($pending,'inactive',$now);rAssert(rReq('GET','courier/me')->get_status()===403,'Inactive admitido.');
    echo "R01-R14 PASS\nCONCURRENCY winner=1 loser=1 final_courier={$ca} idempotent=PASS\nREADINESS=14/14 (100%)\n";
}finally{
    wp_set_current_user(0);
    foreach($ids[$p.'deliveries']??[] as $deliveryId)$wpdb->delete($p.'delivery_tracking',['delivery_id'=>$deliveryId]);
    foreach([$p.'deliveries',$p.'checkout_orders',$p.'checkouts',$p.'orders',$p.'couriers',$p.'stores'] as $table){foreach(array_reverse($ids[$table]??[]) as $id)$wpdb->delete($table,['id'=>$id]);}
    foreach(array_reverse($users) as $id)wp_delete_user($id);
    foreach($temporary as $table){rAssert(str_starts_with($table,$p.'r_clean_'.$n.'_'),'Target temporal invalido.');$wpdb->query('DROP TABLE IF EXISTS `'.esc_sql($table).'`');}
}
