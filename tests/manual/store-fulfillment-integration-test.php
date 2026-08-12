<?php
declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Database\Installer;
use VeciAhorra\Modules\Couriers\Repository\CourierDeliveryRepository;
use VeciAhorra\Modules\Minimarket\Repository\MinimarketRepository;
use VeciAhorra\Modules\Minimarket\Service\StoreFulfillmentService;

require_once dirname(__DIR__, 5) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

function sfAssert(bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); }

Installer::install();
global $wpdb;
$p=$wpdb->prefix.Config::TABLE_PREFIX;$ids=[];$now=current_time('mysql',true);$future=gmdate('Y-m-d H:i:s',time()+3600);
try {
    foreach ($wpdb->get_col("SELECT ID FROM {$wpdb->users} WHERE user_login LIKE 'sf_customer_%'") as $staleUserId) wp_delete_user((int) $staleUserId);
    sfAssert($wpdb->get_var("SHOW COLUMNS FROM {$p}orders LIKE 'store_fulfillment_status'")==='store_fulfillment_status','Schema ausente');
    $customer=wp_create_user('sf_customer_'.wp_generate_password(8,false),'x'.wp_generate_password(20),'sf_'.wp_generate_password(8,false).'@example.test');$ids['users'][]=$customer;
    foreach(['Store A','Store B'] as $name){$wpdb->insert($p.'stores',['business_name'=>$name,'legal_name'=>$name,'rut'=>wp_generate_password(10,false),'email'=>strtolower(str_replace(' ','',$name)).wp_generate_password(5,false).'@example.test','phone'=>'+56911111111','mobile'=>'+56911111111','address'=>'Calle 1','commune'=>'Santiago','status'=>'active','onboarding_status'=>'complete','approved_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);$ids['stores'][]=(int)$wpdb->insert_id;}
    [$storeA,$storeB]=$ids['stores'];
    foreach(['A','B'] as $name){$wpdb->insert($p.'couriers',['display_name'=>'Courier '.$name,'phone'=>'+56922222222','status'=>'active','approved_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);$ids['couriers'][]=(int)$wpdb->insert_id;}
    [$courierA,$courierB]=$ids['couriers'];
    $wpdb->insert($p.'orders',['customer_id'=>$customer,'minimarket_id'=>$storeA,'total'=>'9000.00','status'=>'paid','store_fulfillment_status'=>'awaiting_confirmation','reservation_expires_at'=>$future,'created_at'=>$now,'updated_at'=>$now]);$order=(int)$wpdb->insert_id;$ids['orders'][]=$order;
    $wpdb->insert($p.'checkouts',['public_id'=>'chk_'.wp_generate_password(30,false),'owner_type'=>'user','user_id'=>$customer,'status'=>'payment_completed','fulfillment_method'=>'delivery','delivery_recipient_name'=>'Cliente','delivery_contact_phone'=>'+56933333333','delivery_address_line1'=>'Destino 1','delivery_commune'=>'Santiago','currency'=>'CLP','total_amount'=>'9000.00','created_at'=>$now,'updated_at'=>$now,'expires_at'=>$future]);$checkout=(int)$wpdb->insert_id;$ids['checkouts'][]=$checkout;
    $wpdb->insert($p.'checkout_orders',['checkout_id'=>$checkout,'order_id'=>$order,'created_at'=>$now]);$ids['checkout_orders'][]=(int)$wpdb->insert_id;
    $wpdb->insert($p.'deliveries',['order_id'=>$order,'customer_id'=>$customer,'minimarket_id'=>$storeA,'courier_id'=>null,'status'=>'pending','delivery_recipient_name'=>'Cliente','delivery_contact_phone'=>'+56933333333','delivery_address_line1'=>'Destino 1','delivery_commune'=>'Santiago','created_at'=>$now,'updated_at'=>$now]);$delivery=(int)$wpdb->insert_id;$ids['deliveries'][]=$delivery;
    $couriers=new CourierDeliveryRepository();$service=new StoreFulfillmentService(new MinimarketRepository());
    sfAssert($couriers->findAvailable($delivery)===null,'Visible antes de confirmar');sfAssert($couriers->accept($delivery,$courierA,$now)===0,'Aceptable antes de confirmar');
    sfAssert($service->transition($order,$storeA,'confirmed')['store_fulfillment_status']==='confirmed','Confirm falló');sfAssert($service->transition($order,$storeA,'confirmed')['store_fulfillment_status']==='confirmed','Double confirm no idempotente');sfAssert($couriers->findAvailable($delivery)===null,'Visible tras confirmar');sfAssert($couriers->accept($delivery,$courierA,$now)===0,'Aceptable tras confirmar');
    try{$service->transition($order,$storeA,'ready_for_pickup');throw new RuntimeException('Salto permitido');}catch(VeciAhorra\Exceptions\ConflictException){}
    try{$service->transition($order,$storeB,'preparing');throw new RuntimeException('Foreign permitido');}catch(VeciAhorra\Exceptions\RecordNotFoundException){}
    sfAssert($service->transition($order,$storeA,'preparing')['store_fulfillment_status']==='preparing','Preparing falló');sfAssert($couriers->findAvailable($delivery)===null,'Visible preparando');sfAssert($couriers->accept($delivery,$courierA,$now)===0,'Aceptable preparando');
    sfAssert($service->transition($order,$storeA,'ready_for_pickup')['store_fulfillment_status']==='ready_for_pickup','Ready falló');sfAssert($service->transition($order,$storeA,'ready_for_pickup')['store_fulfillment_status']==='ready_for_pickup','Double ready no idempotente');sfAssert($couriers->findAvailable($delivery)!==null,'No visible ready');
    sfAssert($couriers->accept($delivery,$courierA,$now)===1,'Accept ready falló');sfAssert($couriers->accept($delivery,$courierB,$now)===0,'Segundo courier aceptó');
    echo "STORE_FULFILLMENT_INTEGRATION=PASS\n";
} finally {
    foreach(['delivery_tracking','deliveries','checkout_orders','checkouts','orders','couriers','stores'] as $table) if(!empty($ids[$table])) $wpdb->query("DELETE FROM {$p}{$table} WHERE id IN (".implode(',',array_map('intval',$ids[$table])).')');
    foreach($ids['users']??[] as $id) wp_delete_user((int)$id);
}
