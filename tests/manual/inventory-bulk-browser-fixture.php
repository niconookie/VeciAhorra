<?php

declare(strict_types=1);

define('WP_USE_THEMES', false);
require 'C:/xampp/htdocs/Minimarket/wp-load.php';
use VeciAhorra\Core\Config;
global $wpdb;
$prefix = $wpdb->prefix . Config::TABLE_PREFIX;
$mode = $argv[1] ?? '';
if ($mode === 'setup') {
    $run = 'va_bulk_ui_' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(3)); $now = current_time('mysql');
    $admin = wp_create_user($run . '_admin', wp_generate_password(28), $run . '@example.test');
    if (is_wp_error($admin)) throw new RuntimeException('No fue posible crear usuario UI.');
    (new WP_User((int) $admin))->set_role('administrator');
    $wpdb->insert($prefix . 'stores', ['business_name'=>"{$run} Minimarket",'legal_name'=>"{$run} Legal",'owner_name'=>'Runtime UI','rut'=>'76.543.210-K','email'=>"{$run}_store@example.test",'phone'=>'+56911111111','status'=>'active','onboarding_status'=>'complete','approved_at'=>$now,'created_at'=>$now,'updated_at'=>$now]); $store=(int)$wpdb->insert_id;
    foreach (['A','B'] as $letter) { $wpdb->insert($prefix . 'products', ['name'=>"{$run} Producto {$letter}",'slug'=>strtolower("{$run}-{$letter}"),'sku'=>strtoupper("{$run}-{$letter}"),'status'=>'active','created_at'=>$now,'updated_at'=>$now]); }
    $expiry=time()+3600;
    echo wp_json_encode(['run'=>$run,'user_id'=>(int)$admin,'store_id'=>$store,'sku_a'=>strtoupper("{$run}-A"),'sku_b'=>strtoupper("{$run}-B"),'cookies'=>[['name'=>SECURE_AUTH_COOKIE,'value'=>wp_generate_auth_cookie((int)$admin,$expiry,'secure_auth'),'path'=>ADMIN_COOKIE_PATH],['name'=>LOGGED_IN_COOKIE,'value'=>wp_generate_auth_cookie((int)$admin,$expiry,'logged_in'),'path'=>COOKIEPATH]]]);
    exit;
}
if ($mode === 'cleanup') {
    $run = $argv[2] ?? ''; if (preg_match('/^va_bulk_ui_[a-zA-Z0-9_]+$/D',$run)!==1) throw new RuntimeException('Run inválido.');
    $storeIds=$wpdb->get_col($wpdb->prepare("SELECT id FROM {$prefix}stores WHERE business_name LIKE %s",$run.'%')); $productIds=$wpdb->get_col($wpdb->prepare("SELECT id FROM {$prefix}products WHERE sku LIKE %s",strtoupper($run).'%'));
    if($storeIds){$ids=implode(',',array_map('intval',$storeIds));$wpdb->query("DELETE FROM {$prefix}inventory WHERE minimarket_id IN ({$ids})");$wpdb->query("DELETE FROM {$prefix}stores WHERE id IN ({$ids})");}
    if($productIds){$ids=implode(',',array_map('intval',$productIds));$wpdb->query("DELETE FROM {$prefix}products WHERE id IN ({$ids})");}
    require_once ABSPATH.'wp-admin/includes/user.php';$users=get_users(['search'=>$run.'*','search_columns'=>['user_login']]);foreach($users as $user)wp_delete_user($user->ID);
    $left=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}stores WHERE business_name LIKE %s",$run.'%'))+(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}products WHERE sku LIKE %s",strtoupper($run).'%'));
    echo $left===0?'PASS':'FAIL'; exit;
}
throw new RuntimeException('Modo requerido.');
