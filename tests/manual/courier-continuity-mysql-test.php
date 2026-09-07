<?php
declare(strict_types=1);

/** Disposable local MySQL integration. No wp-load, production config or payment calls. */
if (getenv('VA_CONTINUITY_TEST') !== '1' || PHP_SAPI !== 'cli') throw new RuntimeException('explicit_disposable_test_required');
$wpRoot = (string)getenv('VA_TERRITORY_WP_ROOT');
if (!is_file($wpRoot.'/wp-includes/class-wpdb.php')) throw new RuntimeException('local_wpdb_required');
define('ABSPATH', rtrim($wpRoot, '/\\').'/');
define('WP_DEBUG', false);
define('WP_DEBUG_DISPLAY', false);
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
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
function wp_salt($scheme = 'auth'): string { return hash_hmac('sha256',$scheme,'isolated-continuity-test-only'); }
function current_time($type, $gmt = false): string { return gmdate('Y-m-d H:i:s'); }
function wp_get_environment_type(): string { return 'local'; }
function get_option($name, $default = false) { return $default; }
function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
function is_user_logged_in(): bool { return true; }
function get_current_user_id(): int { return $GLOBALS['territoryUser'] ?? 1001; }
function get_user_meta($id, $key, $single = false) { return $GLOBALS['territoryMeta'][$id][$key] ?? ''; }
function update_user_meta($id, $key, $value) { $GLOBALS['territoryMeta'][$id][$key] = $value; return true; }
function current_user_can($cap): bool { return $GLOBALS['continuityAuthorized'] ?? true; }
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
function esc_html($value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
function wp_nonce_field(...$args): string { return ''; }
class WP_Error { public function __construct(public string $code, public string $message, public array $data = []) {} }
class WP_REST_Response { public function __construct(private mixed $data, private int $status=200) {} public function get_data(): mixed{return $this->data;} public function get_status():int{return $this->status;} }
class WP_REST_Request extends ArrayObject {}
require ABSPATH.'wp-includes/class-wpdb.php';
if (getenv('VA_CONTINUITY_USE_COMPOSER') === '1') {
    require (getenv('VA_TERRITORY_PLUGIN_ROOT') ?: dirname(__DIR__,2)).'/vendor/autoload.php';
} else {
spl_autoload_register(static function(string $class): void {
    if (str_starts_with($class, 'VeciAhorra\\')) {
        $path=(getenv('VA_TERRITORY_PLUGIN_ROOT') ?: dirname(__DIR__,2)).'/app/'.str_replace('\\','/',substr($class,11)).'.php';
        if (is_file($path)) require $path;
    }
});
}

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
$dbName = $race ? (string)($argv[2] ?? '') : 'va_continuity_test_'.bin2hex(random_bytes(8));
if (!preg_match('/^va_continuity_test_[a-f0-9]{16}$/D', $dbName)) throw new RuntimeException('disposable_database_name_required');
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
$wpdb->suppress_errors(true);

if ($race) {
    $service = new CourierDeliveryService();
    try {
        if (($argv[5] ?? '') === 'hold') {
            $wpdb->query('START TRANSACTION');
            $service->adminChange((int)$argv[3],(int)$argv[4],'race',1);
            echo "LOCKED=1\n"; flush(); usleep(1200000);
            $wpdb->query('COMMIT');
        } else {
            echo "STARTED=1\n"; flush();
            $service->adminChange((int)$argv[3],(int)$argv[4],'race',1);
        }
        echo "RESULT=1\n";
    } catch (DomainException $error) { echo "RESULT=0\n"; }
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

function countEvents(int $id): int { global $wpdb; return (int)$wpdb->get_var('SELECT COUNT(*) FROM t_va_delivery_tracking WHERE delivery_id='.$id); }
function failure(callable $call, string $label): void { try {$call();} catch (Throwable $e) {check(true,$label);return;} throw new RuntimeException('FAIL '.$label); }
function failTracking(int $id): void { sql("CREATE TRIGGER continuity_fail_tracking BEFORE INSERT ON t_va_delivery_tracking FOR EACH ROW BEGIN IF NEW.delivery_id={$id} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected tracking failure'; END IF; END"); }
function clearFailure(): void {sql('DROP TRIGGER continuity_fail_tracking');}
function version(int $id): int {return (int)row('deliveries',$id)['transition_version'];}
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

    (new VeciAhorra\Database\Migrations\CreateDeliveryProof())->up();
    $migration = new VeciAhorra\Database\Migrations\AddCourierContinuity();
    $migration->up(); $migration->up();
    check(true,'fresh_idempotent_migration');
    $now=current_time('mysql',true);
    $historicalId=insertRow('delivery_tracking',['delivery_id'=>900001,'event'=>'assigned','created_at'=>$now]);
    $historical=row('delivery_tracking',$historicalId);
    sql('ALTER TABLE t_va_delivery_tracking DROP INDEX delivery_tracking_version_unique');
    foreach(['transition_version','actor_type','actor_id','previous_status','new_status','previous_courier_id','new_courier_id','reason_code','reason'] as $column) sql('ALTER TABLE t_va_delivery_tracking DROP COLUMN '.$column);
    sql('ALTER TABLE t_va_deliveries DROP COLUMN transition_version');
    $migration->up(); $migration->up();
    check(row('delivery_tracking',$historicalId)==$historical,'historical_tracking_no_backfill');
    $now=current_time('mysql');
    foreach ([1,2,3] as $zone) insertRow('service_zones',['id'=>$zone,'commune'=>'Commune','name'=>'Zone '.$zone,'status'=>$zone===3?'inactive':'active','created_at'=>$now,'updated_at'=>$now]);
    insertRow('stores',['id'=>1001,'business_name'=>'Store','legal_name'=>'Store','owner_name'=>'Owner','rut'=>'test','email'=>'store@example.test','phone'=>'+56922222222','address'=>'Pickup 1','commune'=>'Commune','status'=>'active','created_at'=>$now,'updated_at'=>$now]);
    foreach([1,2,3] as $zone) insertRow('store_service_zones',['store_id'=>1001,'zone_id'=>$zone,'assigned_by'=>1001,'assigned_at'=>$now]);
    foreach([1001=>1,1002=>1,1003=>2,1004=>null,1005=>3] as $id=>$zone) insertRow('couriers',['id'=>$id,'display_name'=>'Courier '.$id,'phone'=>'+56933333333','status'=>'approved','service_zone_id'=>$zone,'created_at'=>$now,'updated_at'=>$now]);
    $GLOBALS['territoryMeta'][1001]=['_veciahorra_courier_id'=>1001,'_veciahorra_service_zone_id'=>1];

    insertRow('couriers',['id'=>1006,'display_name'=>'Courier 1006','phone'=>'123','status'=>'approved','service_zone_id'=>1,'created_at'=>$now,'updated_at'=>$now]);
    $repo=new CourierDeliveryRepository(); $service=new CourierDeliveryService();
    $a=fixture();$id=$a['delivery'];
    check($service->accept($id,1001,0)['status']==='assigned' && countEvents($id)===1,'accept_and_tracking_commit');
    $event=$repo->event($id,1);
    check($event['actor_type']==='courier' && (int)$event['actor_id']===1001 && $event['previous_status']==='pending' && $event['new_status']==='assigned' && abs(time()-strtotime($event['created_at'].' UTC'))<=5,'tracking_actor_transition_utc');
    $service->accept($id,1001,0); check(countEvents($id)===1,'accept_replay_once');
    $b=fixture();failTracking($b['delivery']);
    failure(fn()=>$service->accept($b['delivery'],1001,0),'accept_tracking_failure');
    check(row('deliveries',$b['delivery'])['status']==='pending' && row('deliveries',$b['delivery'])['courier_id']===null && version($b['delivery'])===0 && countEvents($b['delivery'])===0,'ACCEPT_ROLLBACK');clearFailure();
    rejected(fn()=>$service->abandon($id,1001,'  ',1),'blank_reason');
    rejected(fn()=>$service->abandon($id,1001,str_repeat('a',501),1),'bounded_reason');
    rejected(fn()=>$service->abandon($id,1002,'not mine',1),'other_courier_abandon');
    failTracking($id);failure(fn()=>$service->abandon($id,1001,'vehicle',1),'abandon_tracking_failure');
    check(row('deliveries',$id)['status']==='assigned' && version($id)===1 && countEvents($id)===1,'abandon_rollback');clearFailure();
    check($service->abandon($id,1001,'vehicle',1)['status']==='pending','abandon_pending');
    check(row('deliveries',$id)['courier_id']===null && (int)row('deliveries',$id)['service_zone_id']===1,'abandon_owner_and_zone');
    $event=$repo->event($id,2);check($event['reason']==='vehicle' && $event['reason_code']==='courier_abandoned' && (int)$event['previous_courier_id']===1001,'abandon_audit');
    $service->abandon($id,1001,'vehicle',1);check(countEvents($id)===2,'abandon_replay_once');
    check(!in_array($id,array_column($service->available(1003),'id'),true),'TERRITORIAL_LIST');
    rejected(fn()=>$service->accept($id,1003,2),'other_zone_accept');
    check($service->accept($id,1002,2)['status']==='assigned','same_zone_next_accept');
    rejected(fn()=>$service->abandon($id,1001,'vehicle',1),'stale_abandon_after_new_owner');
    rejected(fn()=>$service->adminChange($id,1003,'admin',3),'admin_off_zone');
    rejected(fn()=>$service->adminChange($id,1001,'',3),'admin_reason_required');
    sql("UPDATE t_va_orders SET store_fulfillment_status='preparing' WHERE id={$a['order']}");
    rejected(fn()=>$service->adminChange($id,1001,'admin',3),'admin_unprepared');
    rejected(fn()=>$service->transition($id,1002,'picked_up',3),'pickup_unprepared');
    sql("UPDATE t_va_orders SET store_fulfillment_status='ready_for_pickup' WHERE id={$a['order']}");
    failTracking($id);failure(fn()=>$service->adminChange($id,1001,'admin',3),'reassign_tracking_failure');
    check((int)row('deliveries',$id)['courier_id']===1002 && version($id)===3,'reassign_rollback');clearFailure();
    check($service->adminChange($id,1001,'admin',3)['status']==='assigned','admin_reassign');
    $service->adminChange($id,1001,'admin',3);check(countEvents($id)===4,'admin_reassign_replay_once');
    $event=$repo->event($id,4);check($event['actor_type']==='admin' && (int)$event['previous_courier_id']===1002 && (int)$event['new_courier_id']===1001,'reassign_actor_owners');
    rejected(fn()=>$service->adminChange($id,1006,'race loser',3),'stale_admin_command');
    check($service->adminChange($id,null,'release',4)['status']==='pending','admin_unassign');
    $service->adminChange($id,null,'release',4);check(countEvents($id)===5,'admin_unassign_replay_once');
    $service->accept($id,1001,5);
    failTracking($id);failure(fn()=>$service->transition($id,1001,'picked_up',6),'pickup_tracking_failure');
    check(row('deliveries',$id)['status']==='assigned' && version($id)===6,'pickup_rollback');clearFailure();
    check($service->transition($id,1001,'picked_up',6)['status']==='picked_up','pickup_commit');
    $service->transition($id,1001,'picked_up',6);check(countEvents($id)===7,'pickup_replay_once');
    rejected(fn()=>$service->abandon($id,1001,'late',7),'no_abandon_after_pickup');
    rejected(fn()=>$service->adminChange($id,null,'late',7),'no_unassign_after_pickup');
    rejected(fn()=>$service->adminChange($id,1002,'late',7),'no_reassign_after_pickup');
    $pending=fixture();$service->accept($pending['delivery'],1001,0);
    $beforeCourier=row('couriers',1001);
    rejected(fn()=>(new CourierRepository())->transition(1001,'inactive',$now),'suspension_picked_up_conflict');
    check(row('couriers',1001)===$beforeCourier && row('deliveries',$pending['delivery'])['status']==='assigned' && countEvents($pending['delivery'])===1,'suspension_conflict_preserves_all');
    rejected(fn()=>$service->transition($id,1001,'delivered',7),'legacy_delivery_requires_photo_otp');
    // Fixture setup only: the new proof suite tests the real completed transition.
    sql("UPDATE t_va_deliveries SET status='delivered' WHERE id={$id}");
    sql("UPDATE t_va_orders SET status='delivered' WHERE id={$a['order']}");
    $pending2=fixture();$service->accept($pending2['delivery'],1001,0);
    failTracking($pending2['delivery']);failure(fn()=>(new CourierRepository())->transition(1001,'inactive',$now),'suspension_tracking_failure');
    check(row('couriers',1001)===$beforeCourier && row('deliveries',$pending['delivery'])['status']==='assigned' && row('deliveries',$pending2['delivery'])['status']==='assigned' && countEvents($pending['delivery'])===1,'suspension_batch_rollback');clearFailure();
    sql("CREATE TRIGGER continuity_fail_courier BEFORE UPDATE ON t_va_couriers FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected courier write failure'");
    failure(fn()=>(new CourierRepository())->transition(1001,'inactive',$now),'courier_status_write_failure');
    check(row('deliveries',$pending['delivery'])['status']==='assigned' && countEvents($pending['delivery'])===1,'courier_write_rolls_back_releases');
    sql('DROP TRIGGER continuity_fail_courier');
    (new CourierRepository())->transition(1001,'inactive',$now);
    check(row('couriers',1001)['status']==='inactive' && row('deliveries',$pending['delivery'])['status']==='pending' && row('deliveries',$pending2['delivery'])['courier_id']===null,'suspension_releases_all');
    check($repo->event($pending['delivery'],2)['reason_code']==='courier_suspended','suspension_reason');
    (new CourierRepository())->transition(1001,'inactive',$now);check(countEvents($pending['delivery'])===2,'suspension_replay_once');
    rejected(fn()=>$service->accept($pending['delivery'],1001,2),'suspended_no_accept');
    rejected(fn()=>$service->transition($id,1001,'delivered',7),'suspended_no_advance');
    sql("UPDATE t_va_couriers SET status='approved' WHERE id=1001");
    foreach([fixture(0),fixture(1,'pickup'),fixture(1,'delivery','preparing')] as $blocked) rejected(fn()=>$service->adminAssign($blocked['delivery'],1001,0),'admin_eligibility_closed');
    $off=fixture(2); $before=row('deliveries',$off['delivery']);
    rejected(fn()=>$repo->change($before,'assigned',1001,$now,true),'TERRITORIAL_WRITE');
    $cas=fixture();$service->accept($cas['delivery'],1001,0);$stale=row('deliveries',$cas['delivery']);
    $service->adminChange($cas['delivery'],1002,'first',1);$service->adminChange($cas['delivery'],1001,'return',2);
    rejected(fn()=>$repo->change($stale,'assigned',1006,$now,true),'CAS_STALE_REVISION');
    check(version($cas['delivery'])===3 && (int)row('deliveries',$cas['delivery'])['courier_id']===1001,'CAS_NO_ABA_WRITE');
    $raceFixture=fixture();$service->accept($raceFixture['delivery'],1001,0);
    $pipes1=[];$pipes2=[];$descriptors=[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
    $p1=proc_open([PHP_BINARY,__FILE__,'--race',$dbName,(string)$raceFixture['delivery'],'1002','hold'],$descriptors,$pipes1);
    check(is_resource($p1),'race_worker_one');check(trim((string)fgets($pipes1[1]))==='LOCKED=1','race_first_lock_held');
    $p2=proc_open([PHP_BINARY,__FILE__,'--race',$dbName,(string)$raceFixture['delivery'],'1006'],$descriptors,$pipes2);
    check(is_resource($p2),'race_worker_two');check(trim((string)fgets($pipes2[1]))==='STARTED=1','race_overlap_started');
    $out2=stream_get_contents($pipes2[1]);$err2=stream_get_contents($pipes2[2]);$out1=stream_get_contents($pipes1[1]);$err1=stream_get_contents($pipes1[2]);
    foreach([$pipes1,$pipes2] as $pipes)foreach($pipes as $pipe)fclose($pipe);
    check(proc_close($p1)===0 && proc_close($p2)===0 && $err1==='' && $err2==='','race_workers_clean');
    check(str_contains($out1,'RESULT=1') && str_contains($out2,'RESULT=0') && countEvents($raceFixture['delivery'])===2,'MYSQL_REASSIGN_ONE_WINNER');
    // HTTP adapters run with explicit WordPress doubles, not a web certification.
    $routes=new CourierRoutes();$routes->register();
    check(isset($GLOBALS['territoryRoutes']['/courier/deliveries/(?P<id>\d+)/abandon']),'abandon_route_registered');
    $http=fixture();check($routes->accept(new WP_REST_Request(['id'=>$http['delivery'],'expected_version'=>0]))->get_status()===200,'courier_accept_adapter');
    check($routes->abandon(new WP_REST_Request(['id'=>$http['delivery'],'expected_version'=>1,'reason'=>'route']))->get_status()===200,'courier_abandon_adapter');
    check($routes->accept(new WP_REST_Request(['id'=>$http['delivery']]))->get_status()===409,'route_requires_revision');
    $service->accept($http['delivery'],1001,2);
    $page=new VeciAhorra\Modules\Couriers\Admin\CourierAdminPage();$handler=new ReflectionMethod($page,'handle');
    $_POST=['action'=>'reassign_delivery','delivery_id'=>$http['delivery'],'courier_id'=>1002,'expected_version'=>3,'reason'=>'form'];$handler->invoke($page);
    check((int)row('deliveries',$http['delivery'])['courier_id']===1002,'admin_form_same_domain');
    $tracking=new DeliveryTrackingService(new DeliveryTrackingRepository(),new DeliveryRepository());
    $adminService=new DeliveryService(new DeliveryRepository(),new OrderRepository(),new CourierRepository(),$tracking);
    $controller=new VeciAhorra\Modules\Delivery\Controller\DeliveryController($adminService,$tracking);
    check($controller->assignCourier($http['delivery'],['courier_id'=>1003,'reason'=>'wrong','expected_version'=>4])['success']===false,'admin_rest_no_zone_bypass');
    check($controller->assignCourier($http['delivery'],['courier_id'=>0,'reason'=>'release','expected_version'=>4])['success']===true,'admin_rest_unassign');
    check($controller->updateStatus($http['delivery'],['status'=>'cancelled','expected_version'=>5])['success']===false,'admin_unsafe_cancel_closed');
    rejected(fn()=>$tracking->recordTracking($http['delivery'],null,null,'delivered'),'no_forged_transition_tracking');
    $picked=fixture();$service->accept($picked['delivery'],1001,0);$service->transition($picked['delivery'],1001,'picked_up',1);
    $render=new ReflectionMethod($page,'deliveries');ob_start();$render->invoke($page);$html=(string)ob_get_clean();
    check(str_contains($html,'name="action" value="reassign_delivery"') && str_contains($html,'name="reason" required maxlength="500"'),'admin_actions_reason_rendered');
    check(!str_contains($html,'name="delivery_id" value="'.$picked['delivery'].'"') && !str_contains($html,'name="delivery_id" value="'.$id.'"'),'admin_no_picked_up_or_delivered_actions');
    check(!str_contains($html,'<option value="1003">'),'admin_choices_exclude_other_zone');
    $GLOBALS['continuityAuthorized']=false;
    rejected(fn()=>$service->adminChange($http['delivery'],1001,'unauthorized',5),'admin_domain_capability_required');
    check($routes->permission() instanceof WP_Error,'courier_context_capability_required');
    $GLOBALS['continuityAuthorized']=true;
    check((int)$wpdb->get_var("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND ENGINE<>'InnoDB'")===0,'all_test_tables_innodb');
    check((int)$wpdb->get_var('SELECT COUNT(*) FROM t_va_orders WHERE id BETWEEN 18 AND 22')===0,'protected_orders_absent');
    echo "CONTINUITY_MYSQL=PASS ASSERTIONS={$assertions} CONCURRENCY=production_service_two_connections\n";
} finally {
    $wpdb->close();$admin->query("DROP DATABASE `{$dbName}`");$GLOBALS['territoryCleaned']=true;$admin->close();
    echo "DISPOSABLE_DATABASE_REMOVED=yes\n";
}
