<?php
declare(strict_types=1);

/** Disposable local MySQL integration. No wp-load, production config or payment calls. */
if (getenv('VA_TERRITORY_TEST') !== '1' || PHP_SAPI !== 'cli') throw new RuntimeException('explicit_disposable_test_required');
$wpRoot = (string)getenv('VA_TERRITORY_WP_ROOT');
if (!is_file($wpRoot.'/wp-includes/class-wpdb.php')) throw new RuntimeException('local_wpdb_required');
define('ABSPATH', rtrim($wpRoot, '/\\').'/');
define('WP_DEBUG', false);
define('WP_DEBUG_DISPLAY', false);
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
define('VECIAHORRA_PAYMENT_GATEWAY', 'mock');
function apply_filters($tag, $value, ...$args) { return $value; }
function has_filter(...$args) { return false; }
function add_filter(...$args): void {}
function do_action(...$args): void {}
function wp_debug_backtrace_summary(...$args): string { return 'territory-test'; }
function _doing_it_wrong($fn, $message, $version): void { throw new RuntimeException($fn.':'.$message); }
function wp_load_translations_early(): void {}
function __($s, ...$args) { return $s; }
function wp_die($s, ...$args): never { throw new RuntimeException('test_wp_die'); }
function is_multisite(): bool { return false; }
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function mbstring_binary_safe_encoding(): void {}
function reset_mbstring_encoding(): void {}
function current_time($type, $gmt = false): string { return gmdate('Y-m-d H:i:s'); }
function wp_get_environment_type(): string { return 'local'; }
function get_option($name, $default = false) { return $default; }
function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
function is_user_logged_in(): bool { return true; }
function get_current_user_id(): int { return $GLOBALS['territoryUser'] ?? 1001; }
function get_user_meta($id, $key, $single = false) { return $GLOBALS['territoryMeta'][$id][$key] ?? ''; }
function update_user_meta($id, $key, $value) { $GLOBALS['territoryMeta'][$id][$key] = $value; return true; }
function current_user_can($cap): bool { return true; }
function absint($value): int { return abs((int)$value); }
function sanitize_key($value): string { return strtolower($value); }
function sanitize_text_field($value): string { return trim(strip_tags($value)); }
function sanitize_email($value): string { return filter_var($value, FILTER_SANITIZE_EMAIL); }
function wp_unslash($value) { return $value; }
function is_email($value): bool { return filter_var($value, FILTER_VALIDATE_EMAIL) !== false; }
function get_userdata($id) { return false; }
function get_users($args): array { return []; }
function clean_user_cache($id): void {}
function register_rest_route($namespace, $path, $options): void { $GLOBALS['territoryRoutes'][$path] = $options; }
class WP_Error { public function __construct(public string $code, public string $message, public array $data = []) {} }
class WP_REST_Response { public function __construct(private mixed $data, private int $status=200) {} public function get_data(): mixed{return $this->data;} public function get_status():int{return $this->status;} }
class WP_REST_Request extends ArrayObject {}
require ABSPATH.'wp-includes/class-wpdb.php';
spl_autoload_register(static function(string $class): void {
    if (str_starts_with($class, 'VeciAhorra\\')) {
        $path=(getenv('VA_TERRITORY_PLUGIN_ROOT') ?: dirname(__DIR__,2)).'/app/'.str_replace('\\','/',substr($class,11)).'.php';
        if (is_file($path)) require $path;
    }
});

use VeciAhorra\Modules\Couriers\Repository\CourierDeliveryRepository;
use VeciAhorra\Modules\Couriers\Service\CourierDeliveryService;
use VeciAhorra\Modules\Couriers\Routes\CourierRoutes;
use VeciAhorra\Modules\Delivery\Service\DeliveryService;
use VeciAhorra\Modules\Delivery\Service\DeliveryTrackingService;
use VeciAhorra\Modules\Delivery\Repository\DeliveryRepository;
use VeciAhorra\Modules\Delivery\Repository\DeliveryTrackingRepository;
use VeciAhorra\Modules\Orders\Repositories\OrderRepository;
use VeciAhorra\Modules\Couriers\Repository\CourierRepository;
use VeciAhorra\Modules\Checkout\Service\CheckoutService;
use VeciAhorra\Modules\Delivery\Completion\Service\DeliveryCompletionProcessor;

$race = ($argv[1] ?? '') === '--race';
$dbName = $race ? (string)($argv[2] ?? '') : 'va_territory_test_'.bin2hex(random_bytes(8));
if (!preg_match('/^va_territory_test_[a-f0-9]{16}$/D', $dbName)) throw new RuntimeException('disposable_database_name_required');
$host = '127.0.0.1:3306';
$dbUser = (string)(getenv('VA_TERRITORY_DB_USER') ?: 'root');
$dbPassword = (string)(getenv('VA_TERRITORY_DB_PASSWORD') ?: '');
$admin = new mysqli('127.0.0.1', $dbUser, $dbPassword, '', 3306);
if (!$race) {
    $admin->query("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4");
    register_shutdown_function(static function() use ($admin,$dbName): void {
        if (!($GLOBALS['territoryCleaned'] ?? false)) $admin->query("DROP DATABASE `{$dbName}`");
    });
}
$wpdb = new wpdb($dbUser, $dbPassword, $dbName, $host);
$wpdb->set_prefix('t_');
$wpdb->show_errors(false);

if ($race) {
    $wpdb->query('START TRANSACTION');
    $won = (new CourierDeliveryRepository())->accept((int)$argv[3], (int)$argv[4], current_time('mysql', true));
    echo "LOCKED={$won}\n"; flush();
    if (($argv[5] ?? '') === 'hold') usleep(1000000);
    $wpdb->query('COMMIT');
    echo "RESULT={$won}\n";
    exit;
}

$assertions=0;
function check(bool $ok, string $label): void { $GLOBALS['assertions']++; if (!$ok) throw new RuntimeException('FAIL '.$label); }
function sql(string $query): void { global $wpdb; if ($wpdb->query($query) === false) throw new RuntimeException($wpdb->last_error); }
function insertRow(string $table, array $data): int { global $wpdb; if ($wpdb->insert('t_va_'.$table,$data) !== 1) throw new RuntimeException($wpdb->last_error); return (int)$wpdb->insert_id; }
function rejected(callable $call, string $label): void { try { $call(); } catch (DomainException|InvalidArgumentException $e) { check(true,$label); return; } throw new RuntimeException('FAIL '.$label); }
function row(string $table, int $id): array { global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM t_va_'.$table.' WHERE id=%d',$id),ARRAY_A); }
function fixture(int $zone=1, string $method='delivery', string $preparation='ready_for_pickup', bool $withDelivery=true): array {
    $now=current_time('mysql');
    $order=insertRow('orders',['customer_id'=>1001,'minimarket_id'=>1001,'total'=>'10000.00','status'=>'paid','service_zone_id'=>$zone?:null,'store_fulfillment_status'=>$preparation,'created_at'=>$now,'updated_at'=>$now]);
    $snapshot=['delivery_recipient_name'=>'Recipient','delivery_contact_phone'=>'+56911111111','delivery_address_line1'=>'Destination 1','delivery_commune'=>'Commune'];
    $checkout=insertRow('checkouts',[...$snapshot,'public_id'=>'chk_'.bin2hex(random_bytes(20)),'owner_type'=>'user','user_id'=>1001,'status'=>'payment_completed','fulfillment_method'=>$method,'service_zone_id'=>$zone?:null,'total_amount'=>'10000.00','created_at'=>$now,'updated_at'=>$now]);
    insertRow('checkout_orders',['checkout_id'=>$checkout,'order_id'=>$order,'created_at'=>$now]);
    $delivery=$withDelivery?insertRow('deliveries',[...$snapshot,'order_id'=>$order,'customer_id'=>1001,'minimarket_id'=>1001,'service_zone_id'=>$zone?:null,'status'=>'pending','created_at'=>$now,'updated_at'=>$now]):0;
    return compact('order','checkout','delivery');
}
function complete(array $fixture): object {
    $now=current_time('mysql');
    $business=insertRow('business_completions',['reconciliation_id'=>$fixture['order'],'idempotency_key'=>hash('sha256',(string)$fixture['order']),'status'=>'completed','fulfillment_method'=>row('checkouts',$fixture['checkout'])['fulfillment_method'],'completed_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
    insertRow('business_completion_orders',['business_completion_id'=>$business,'order_id'=>$fixture['order'],'created_at'=>$now]);
    return (new DeliveryCompletionProcessor())->process($business,'worker_'.bin2hex(random_bytes(16)));
}

try {
    $schemaClasses = [
        'Tables\\CouriersTable','Tables\\StoresTable','Tables\\ServiceZonesTable','Tables\\StoreServiceZonesTable',
        'Schemas\\CheckoutSchema','Schemas\\OrderSchema','Schemas\\DeliverySchema','Schemas\\CheckoutOrderSchema',
        'Schemas\\DeliveryTrackingSchema','Schemas\\DeliveryCompletionSchema','Schemas\\BusinessCompletionSchema','Schemas\\BusinessCompletionOrderSchema',
    ];
    foreach ($schemaClasses as $suffix) {
        $class='VeciAhorra\\Database\\'.$suffix; $schema=new $class();
        $builder=VeciAhorra\Database\Builder\TableBuilder::make('t_va_'.$schema->name());
        $schema->define($builder); sql($builder->build($wpdb->get_charset_collate()));
        sql('ALTER TABLE t_va_'.$schema->name().' AUTO_INCREMENT=1001');
    }
    $migration=new VeciAhorra\Database\Migrations\AddCourierTerritory();
    $migration->up(); $migration->up();
    check(true,'fresh_and_idempotent_migration');
    $now=current_time('mysql');
    insertRow('couriers',['id'=>900001,'display_name'=>'Historical','phone'=>'123','status'=>'inactive','created_at'=>$now,'updated_at'=>$now]);
    insertRow('checkouts',['id'=>900001,'public_id'=>'historical','owner_type'=>'user','total_amount'=>'1.00','created_at'=>$now,'updated_at'=>$now]);
    insertRow('orders',['id'=>900001,'customer_id'=>900001,'minimarket_id'=>900001,'total'=>'1.00','status'=>'paid','created_at'=>$now,'updated_at'=>$now]);
    insertRow('deliveries',['id'=>900001,'order_id'=>900001,'customer_id'=>900001,'minimarket_id'=>900001,'status'=>'pending','created_at'=>$now,'updated_at'=>$now]);
    $historical=[];
    foreach (['couriers','checkouts','orders','deliveries'] as $table) $historical[$table]=row($table,900001);
    // Exercise the upgrade path with untouched, non-production historical rows.
    foreach (['couriers','checkouts','orders','deliveries'] as $table) {
        if ($table==='deliveries') sql('ALTER TABLE t_va_deliveries DROP INDEX deliveries_zone_available_index');
        sql('ALTER TABLE t_va_'.$table.' DROP COLUMN service_zone_id');
    }
    $migration->up();$migration->up();
    foreach (['couriers','checkouts','orders','deliveries'] as $table) {
        $col=$wpdb->get_row("SHOW COLUMNS FROM t_va_{$table} LIKE 'service_zone_id'",ARRAY_A);
        check($col['Null']==='YES' && $col['Default']===null,'nullable_upgrade_'.$table);
        check(row($table,900001)==$historical[$table],'historical_row_preserved_'.$table);
    }
    $now=current_time('mysql');
    foreach ([1,2,3] as $zone) insertRow('service_zones',['id'=>$zone,'commune'=>'Commune','name'=>'Zone '.$zone,'status'=>$zone===3?'inactive':'active','created_at'=>$now,'updated_at'=>$now]);
    insertRow('stores',['id'=>1001,'business_name'=>'Store','legal_name'=>'Store','owner_name'=>'Owner','rut'=>'test','email'=>'store@example.test','phone'=>'+56922222222','address'=>'Pickup 1','commune'=>'Commune','status'=>'active','created_at'=>$now,'updated_at'=>$now]);
    foreach([1,2,3] as $zone) insertRow('store_service_zones',['store_id'=>1001,'zone_id'=>$zone,'assigned_by'=>1001,'assigned_at'=>$now]);
    foreach([1001=>1,1002=>1,1003=>2,1004=>null,1005=>3] as $id=>$zone) insertRow('couriers',['id'=>$id,'display_name'=>'Courier '.$id,'phone'=>'+56933333333','status'=>'approved','service_zone_id'=>$zone,'created_at'=>$now,'updated_at'=>$now]);
    $GLOBALS['territoryMeta'][1001]=['_veciahorra_courier_id'=>1001,'_veciahorra_service_zone_id'=>1];
    $repo=new CourierDeliveryRepository(); $service=new CourierDeliveryService();
    $adminService=new DeliveryService(new DeliveryRepository(),new OrderRepository(),new CourierRepository(),new DeliveryTrackingService(new DeliveryTrackingRepository(),new DeliveryRepository()));
    $a=fixture();$b=fixture(2);$old=fixture(0);$pickup=fixture(1,'pickup');$unready=fixture(1,'delivery','preparing');
    $routes=new CourierRoutes();$routes->register();
    check($routes->permission()===true,'authenticated_zone_context');
    $visible=array_column($routes->available()->get_data()['data'],'id');
    check(in_array($a['delivery'],$visible,true),'A_sees_A');
    check(!in_array($b['delivery'],$visible,true),'A_cannot_see_B');
    check(!in_array($old['delivery'],$visible,true),'historical_null_hidden');
    check(!in_array($pickup['delivery'],$visible,true),'pickup_hidden');
    check(!in_array($unready['delivery'],$visible,true),'unprepared_hidden');
    check($routes->accept(new WP_REST_Request(['id'=>$b['delivery']]))->get_status()===409,'endpoint_cross_zone_rejected');
    check($repo->accept($b['delivery'],1001,$now)===0,'CAS_cross_zone_rejected');
    check($repo->accept($old['delivery'],1001,$now)===0,'CAS_null_rejected');
    check($repo->accept($pickup['delivery'],1001,$now)===0,'CAS_pickup_rejected');
    check($repo->accept($unready['delivery'],1001,$now)===0,'CAS_unready_rejected');
    check($repo->available(1004)===[] && $repo->accept($a['delivery'],1004,$now)===0,'courier_without_zone_closed');
    check($repo->available(1005)===[] && $repo->accept($a['delivery'],1005,$now)===0,'inactive_zone_closed');
    check($service->accept($a['delivery'],1001)['status']==='assigned','A_accepts_A');
    check($service->accept($a['delivery'],1001)['status']==='assigned','accept_idempotent');
    check((int)$wpdb->get_var('SELECT COUNT(*) FROM t_va_delivery_tracking')===1,'accept_tracks_once');
    foreach([$b,$pickup,$unready,$old] as $blocked) rejected(fn()=>$adminService->assignCourier($blocked['delivery'],1001),'admin_same_eligibility');
    rejected(fn()=>$adminService->updateStatus($unready['delivery'],'assigned'),'admin_status_cannot_bypass_assignment');
    $aa=fixture(); check($adminService->assignCourier($aa['delivery'],1001)['status']==='assigned','admin_valid_assignment');
    sql("UPDATE t_va_service_zones SET status='inactive' WHERE id=1");
    check($repo->available(1001)===[],'deactivated_zone_blocks_listing');
    check($routes->permission() instanceof WP_Error,'deactivated_zone_blocks_context');
    sql("UPDATE t_va_service_zones SET status='active' WHERE id=1");
    sql("UPDATE t_va_couriers SET status='inactive' WHERE id=1001");
    check($routes->permission() instanceof WP_Error,'suspended_courier_blocked');
    sql("UPDATE t_va_couriers SET status='approved' WHERE id=1001");
    $page=new VeciAhorra\Modules\Couriers\Admin\CourierAdminPage();
    $handler=new ReflectionMethod($page,'handle');
    $before=row('couriers',1001);
    $_POST=['action'=>'save','courier_id'=>1001,'display_name'=>'Changed','phone'=>'123','service_zone_id'=>3];
    rejected(fn()=>$handler->invoke($page),'inactive_zone_admin_rejected');
    check(row('couriers',1001)===$before,'admin_invalid_zone_no_partial_write');
    $_POST['service_zone_id']=2;$_POST['user_id']=9999;
    rejected(fn()=>$handler->invoke($page),'invalid_user_admin_rejected');
    check(row('couriers',1001)===$before,'admin_invalid_association_no_partial_write');
    unset($_POST['user_id']);$handler->invoke($page);
    check((int)row('couriers',1001)['service_zone_id']===2,'admin_zone_assignment_persisted');
    sql('UPDATE t_va_couriers SET service_zone_id=1 WHERE id=1001');

    // Run the real production repository CAS in two overlapping PHP/MySQL connections.
    $raceFixture=fixture();
    $pipes1=[];$pipes2=[];$descriptors=[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
    $p1=proc_open([PHP_BINARY,__FILE__,'--race',$dbName,(string)$raceFixture['delivery'],'1001','hold'],$descriptors,$pipes1);
    check(is_resource($p1),'race_worker_one');
    check(trim((string)fgets($pipes1[1]))==='LOCKED=1','race_first_lock_held');
    $p2=proc_open([PHP_BINARY,__FILE__,'--race',$dbName,(string)$raceFixture['delivery'],'1002'],$descriptors,$pipes2);
    check(is_resource($p2),'race_worker_two');
    $out2=stream_get_contents($pipes2[1]);$err2=stream_get_contents($pipes2[2]);
    $out1=stream_get_contents($pipes1[1]);$err1=stream_get_contents($pipes1[2]);
    foreach([$pipes1,$pipes2] as $pipes)foreach($pipes as $pipe)fclose($pipe);
    check(proc_close($p1)===0 && proc_close($p2)===0 && $err1==='' && $err2==='','race_workers_clean');
    check(str_contains($out1,'RESULT=1') && str_contains($out2,'RESULT=0'),'MYSQL_CONCURRENT_ONE_WINNER');

    // Real persistent Checkout method; only reservation lookup/session read are doubles.
    $checkoutService=(new ReflectionClass(CheckoutService::class))->newInstanceWithoutConstructor();
    $reservationDouble=new class extends VeciAhorra\Modules\Reservations\Service\ReservationService {
        public function __construct() {} public function findByOrderId(int $id): array {return [['status'=>'active','expires_at'=>gmdate('Y-m-d H:i:s',time()+3600)]];}
    };
    $sessionDouble=new class extends VeciAhorra\Modules\Payments\Repository\PaymentSessionRepository {
        public function findActive(int $checkoutId,string $now): ?array {return null;}
    };
    foreach(['reservationService'=>$reservationDouble,'checkoutRepository'=>new VeciAhorra\Modules\Checkout\Repository\CheckoutRepository(),'checkoutOrderRepository'=>new VeciAhorra\Modules\Checkout\Repository\CheckoutOrderRepository(),'orderRepository'=>new OrderRepository(),'idempotencyService'=>new VeciAhorra\Modules\Payments\Service\IdempotencyService(),'paymentSessionRepository'=>$sessionDouble] as $property=>$value) (new ReflectionProperty($checkoutService,$property))->setValue($checkoutService,$value);
    $order=insertRow('orders',['customer_id'=>1001,'minimarket_id'=>1001,'total'=>'10000.00','status'=>'reserved','reservation_expires_at'=>gmdate('Y-m-d H:i:s',time()+3600),'created_at'=>$now,'updated_at'=>$now]);
    $payload=['user_id'=>1001,'idempotency_key'=>'territory-checkout-replay','service_zone_id'=>2,'fulfillment_method'=>'delivery','financial_snapshot'=>['fulfillment_method'=>'delivery','product_subtotal'=>'10000.00','platform_fee'=>'0.00','delivery_fee'=>'0.00','total'=>'10000.00','fee_policy_version'=>'territory-test'],'delivery'=>['recipient_name'=>'Recipient','contact_phone'=>'123','address_line1'=>'Street','commune'=>'Commune','reference'=>null,'notes'=>null]];
    $snapshot=$checkoutService->createPersistent($payload,[$order]);
    $checkout=$wpdb->get_row($wpdb->prepare('SELECT * FROM t_va_checkouts WHERE public_id=%s',$snapshot['checkout_id']),ARRAY_A);
    check((int)$checkout['service_zone_id']===1 && (int)row('orders',$order)['service_zone_id']===1,'checkout_current_sector_and_order_snapshot_not_payload');
    (new VeciAhorra\Modules\Sectorization\CurrentSector())->set(2);
    check((int)row('checkouts',(int)$checkout['id'])['service_zone_id']===1 && (int)row('orders',$order)['service_zone_id']===1,'customer_change_does_not_rewrite_snapshot');
    $replay=$checkoutService->initialize($payload);
    check((int)$replay['orders'][0]['service_zone_id']===1,'checkout_replay_preserves_zone');
    sql('DELETE FROM t_va_store_service_zones WHERE zone_id=1');
    check((int)row('orders',$order)['service_zone_id']===1,'store_change_does_not_rewrite_order');
    insertRow('store_service_zones',['store_id'=>1001,'zone_id'=>1,'assigned_by'=>1001,'assigned_at'=>$now]);
    sql("UPDATE t_va_orders SET status='paid' WHERE id={$order}");
    $result=complete(['order'=>$order,'checkout'=>(int)$checkout['id']]);
    check($result->status==='completed','delivery_materialization_completed');
    $delivery=$wpdb->get_row("SELECT * FROM t_va_deliveries WHERE order_id={$order}",ARRAY_A);
    check((int)$delivery['service_zone_id']===1,'delivery_exact_order_zone');
    $GLOBALS['territoryMeta'][1001]['_veciahorra_service_zone_id']=1;
    $batch=[];
    foreach([1001,999999] as $storeId) $batch[]=insertRow('orders',['customer_id'=>1001,'minimarket_id'=>$storeId,'total'=>'5000.00','status'=>'reserved','reservation_expires_at'=>gmdate('Y-m-d H:i:s',time()+3600),'created_at'=>$now,'updated_at'=>$now]);
    $batchPayload=[...$payload,'idempotency_key'=>'territory-batch-rollback'];
    rejected(fn()=>$checkoutService->createPersistent($batchPayload,$batch),'store_membership_validated');
    check(row('orders',$batch[0])['service_zone_id']===null && row('orders',$batch[1])['service_zone_id']===null,'batch_snapshot_rollback');
    check((int)$wpdb->get_var("SELECT COUNT(*) FROM t_va_checkouts WHERE idempotency_key='territory-batch-rollback'")===0,'failed_batch_no_checkout');
    $p=fixture(1,'pickup','ready_for_pickup',false);
    check(complete($p)->status==='not_required' && (int)$wpdb->get_var('SELECT COUNT(*) FROM t_va_deliveries WHERE order_id='.$p['order'])===0,'pickup_no_delivery_created');
    $legacy=fixture(0,'delivery','ready_for_pickup',false);
    check(complete($legacy)->status==='manual_review','legacy_null_not_inferred');
    check(row('orders',$legacy['order'])['service_zone_id']===null,'historical_null_untouched');
    $mismatch=fixture(2,'delivery','ready_for_pickup',false);
    sql('UPDATE t_va_orders SET service_zone_id=1 WHERE id='.$mismatch['order']);
    check(complete($mismatch)->status==='manual_review','materialization_snapshot_mismatch_closed');
    check((int)$wpdb->get_var('SELECT COUNT(*) FROM t_va_orders WHERE id BETWEEN 18 AND 22')===0,'protected_order_ids_never_used');
    echo "TERRITORY_MYSQL=PASS ASSERTIONS={$assertions} CONCURRENCY=production_repository_two_connections\n";
} finally {
    $wpdb->close();
    $admin->query("DROP DATABASE `{$dbName}`");
    $GLOBALS['territoryCleaned']=true;
    $admin->close();
    echo "DISPOSABLE_DATABASE_REMOVED=yes\n";
}
