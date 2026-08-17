<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';

use VeciAhorra\Core\Config;
use VeciAhorra\Core\Container;
use VeciAhorra\Modules\Catalog\Service\CatalogService;

function hpAssert(bool $condition, string $message): void
{
    if (! $condition) throw new RuntimeException($message);
}

global $wpdb;
$prefix = $wpdb->prefix . Config::TABLE_PREFIX;
$writes = [];
$observedQueries = [];
add_filter('query', static function (string $sql) use (&$writes, &$observedQueries, $wpdb): string {
    $observedQueries[] = $sql;
    if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql, $m)
        && stripos($sql, $wpdb->options) !== false) $writes[] = strtoupper($m[1]) . ':' . $sql;
    return $sql;
});

hpAssert($wpdb->query('START TRANSACTION') !== false, 'transaction unavailable');
$userId = 1;
try {
    $now = '2034-01-01 12:00:00';
    $insert = static function (string $table, array $row) use ($wpdb): int {
        hpAssert($wpdb->insert($table, $row) === 1, 'fixture insert failed: ' . $table);
        return (int) $wpdb->insert_id;
    };
    $zone = $insert($prefix . 'service_zones', ['commune'=>'A1','name'=>'A1 valid','status'=>'active','created_at'=>$now,'updated_at'=>$now]);
    $emptyZone = $insert($prefix . 'service_zones', ['commune'=>'A1','name'=>'A1 empty','status'=>'active','created_at'=>$now,'updated_at'=>$now]);
    $inactiveZone = $insert($prefix . 'service_zones', ['commune'=>'A1','name'=>'A1 inactive','status'=>'inactive','created_at'=>$now,'updated_at'=>$now]);
    $store = static function (string $name, string $status='active') use ($insert,$prefix,$now): int {
        $token = bin2hex(random_bytes(6));
        return $insert($prefix.'stores',['business_name'=>$name,'legal_name'=>$name,'owner_name'=>'A1','rut'=>'A1-'.$token,'email'=>$token.'@example.test','phone'=>'0','mobile'=>null,'address'=>null,'commune'=>'A1','city'=>'A1','region'=>'A1','status'=>$status,'onboarding_status'=>'complete','approved_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
    };
    $storeA=$store('A1 A'); $storeB=$store('A1 B'); $outside=$store('A1 outside'); $inactiveStore=$store('A1 inactive','inactive');
    foreach([$storeA,$storeB,$inactiveStore] as $sid) $insert($prefix.'store_service_zones',['store_id'=>$sid,'zone_id'=>$zone,'assigned_by'=>0,'assigned_at'=>$now]);
    $product = static function (string $name,string $created,string $status='active') use($insert,$prefix,$now):int{return $insert($prefix.'products',['woo_product_id'=>null,'name'=>$name,'slug'=>sanitize_title($name).'-'.uniqid(),'sku'=>null,'description'=>'Description '.$name,'category_id'=>null,'brand_id'=>null,'unit_id'=>null,'image_id'=>null,'status'=>$status,'created_at'=>$created,'updated_at'=>$now]);};
    $inventory = static function(int $pid,int $sid,mixed $price,int $stock,string $status='active')use($insert,$prefix,$now):int{return $insert($prefix.'inventory',['product_id'=>$pid,'minimarket_id'=>$sid,'price'=>$price,'stock'=>$stock,'status'=>$status,'created_at'=>$now,'updated_at'=>$now]);};
    $valid=[];for($i=0;$i<8;$i++){ $valid[]=$product('A1 valid '.$i,$i<2?'2034-02-02 00:00:00':'2034-02-01 00:00:00');$inventory($valid[$i],$storeA,1000+$i,5); }
    $inventory($valid[1],$storeB,500,4);
    $badProduct=$product('A1 inactive product','2035-01-01 00:00:00','inactive');$inventory($badProduct,$storeA,1,5);
    foreach([['inactive',5,100],['active',0,100],['active',5,0],['active',5,-10]] as $j=>$v){$pid=$product('A1 invalid '.$j,'2035-01-01 00:00:00');$inventory($pid,$storeA,$v[2],$v[1],$v[0]);}
    $outsideProduct=$product('A1 outside','2035-01-01 00:00:00');$inventory($outsideProduct,$outside,1,5);
    $inactiveStoreProduct=$product('A1 inactive store','2035-01-01 00:00:00');$inventory($inactiveStoreProduct,$inactiveStore,1,5);

    wp_set_current_user($userId);
    update_user_meta($userId,'_veciahorra_service_zone_id',0);clean_user_cache($userId);
    $service=(new Container())->make(CatalogService::class);$before=count($observedQueries);$noSector=$service->homepageProducts();$noSectorQueries=array_slice($observedQueries,$before);
    hpAssert($noSector===['state'=>'no_sector','products'=>[]],'no sector state');
    hpAssert(!array_filter($noSectorQueries,static fn($q)=>str_contains((string)($q[0]??''),$prefix.'products')),'no-sector product query');

    update_user_meta($userId,'_veciahorra_service_zone_id',$inactiveZone);clean_user_cache($userId);hpAssert($service->homepageProducts()['state']==='no_sector','inactive sector');
    update_user_meta($userId,'_veciahorra_service_zone_id',$emptyZone);clean_user_cache($userId);hpAssert($service->homepageProducts()['state']==='empty','empty sector');
    update_user_meta($userId,'_veciahorra_service_zone_id',$zone);clean_user_cache($userId);$q0=$wpdb->num_queries;$first=$service->homepageProducts();$queries=$wpdb->num_queries-$q0;$second=$service->homepageProducts();
    hpAssert($first['state']==='success'&&count($first['products'])===6,'valid sector/limit');
    hpAssert($first===$second,'determinism');$ids=array_column($first['products'],'id');hpAssert(count($ids)===count(array_unique($ids)),'duplicates');
    $expected=[$valid[1],$valid[0],$valid[7],$valid[6],$valid[5],$valid[4]];hpAssert($ids===$expected,'order/filter before limit');
    $special=array_values(array_filter($first['products'],static fn($p)=>$p['id']===$valid[1]))[0]??null;hpAssert($special&&$special['min_price']==='500.00'&&$special['available_minimarkets']===2,'aggregate values');
    $keys=['available_minimarkets','brand','category','id','image','min_price','name','short_description','slug','unit'];$actual=array_keys($first['products'][0]);sort($actual);sort($keys);hpAssert($actual===$keys,'payload contract');
    $payload=json_encode($first,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);hpAssert(strlen($payload)<=6144,'payload budget');hpAssert($queries<=4,'query budget: '.$queries);
    $priceType=(string)$wpdb->get_var("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$prefix}inventory' AND COLUMN_NAME='price'");hpAssert(in_array($priceType,['decimal','double','float'],true),'price is not numeric schema');
    do_action('rest_api_init');$request=new WP_REST_Request('GET','/veciahorra/v1/catalog/homepage-products');$request->set_param('sector_id',$inactiveZone);$response=rest_do_request($request);hpAssert($response->get_status()===200&&$response->get_data()===$first,'endpoint/override');
    hpAssert(isset(rest_get_server()->get_routes()['/veciahorra/v1/catalog/homepage-products']),'route missing');
    hpAssert($writes===[],'wp_options writes: '.count($writes));
    echo json_encode(['state'=>$first['state'],'limit'=>count($first['products']),'queries'=>$queries,'payload_bytes'=>strlen($payload),'determinism'=>'PASS','wp_options_writes'=>count($writes)],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
} finally {
    $wpdb->query('ROLLBACK');
    clean_user_cache($userId);
}
