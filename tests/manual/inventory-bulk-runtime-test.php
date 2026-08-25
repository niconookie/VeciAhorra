<?php

declare(strict_types=1);

define('WP_USE_THEMES', false);
require 'C:/xampp/htdocs/Minimarket/wp-load.php';

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Inventory\Domain\OfferAvailabilityPolicy;
use VeciAhorra\Modules\Inventory\Import\InventoryBulkImportService;
use VeciAhorra\Modules\Inventory\Import\InventoryCsvParser;

function rt(bool $ok, string $label): void { if (! $ok) throw new RuntimeException($label); echo "PASS {$label}\n"; }
global $wpdb;
$prefix = $wpdb->prefix . Config::TABLE_PREFIX;
$run = 'va_bulk_' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(3));
$storeIds = []; $productIds = []; $userIds = [];
try {
    $admin = wp_create_user($run . '_admin', wp_generate_password(28), $run . '_admin@example.test');
    $viewer = wp_create_user($run . '_viewer', wp_generate_password(28), $run . '_viewer@example.test');
    rt(! is_wp_error($admin) && ! is_wp_error($viewer), 'fixtures users');
    $userIds = [(int) $admin, (int) $viewer];
    (new WP_User((int) $admin))->set_role('administrator'); (new WP_User((int) $viewer))->set_role('subscriber');
    wp_set_current_user((int) $admin); rt(current_user_can('manage_options'), 'permissions manage_options allow');
    wp_set_current_user((int) $viewer); rt(! current_user_can('manage_options'), 'permissions non-admin deny');
    wp_set_current_user((int) $admin);
    $now = current_time('mysql');
    for ($i = 1; $i <= 5; $i++) {
        rt($wpdb->insert($prefix . 'stores', ['business_name' => "{$run} Store {$i}", 'legal_name' => "{$run} Legal {$i}", 'owner_name' => 'Runtime Test', 'rut' => "99.999.99{$i}-" . ($i - 1), 'email' => "{$run}_store{$i}@example.test", 'phone' => '+5690000000' . $i, 'status' => 'active', 'onboarding_status' => 'complete', 'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now]) === 1, "create store {$i}");
        $storeIds[] = (int) $wpdb->insert_id;
    }
    foreach (['A', 'B', 'C', 'D'] as $letter) {
        rt($wpdb->insert($prefix . 'products', ['name' => "{$run} Product {$letter}", 'slug' => strtolower("{$run}-product-{$letter}"), 'sku' => strtoupper("{$run}-{$letter}"), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]) === 1, "create product {$letter}");
        $productIds[] = (int) $wpdb->insert_id;
    }
    $parser = new InventoryCsvParser(); $service = new InventoryBulkImportService();
    $template = file_get_contents(dirname(__DIR__, 2) . '/assets/templates/inventory-import.csv');
    $parsedTemplate = $parser->parse((string) $template); rt(count($parsedTemplate['rows']) === 1, 'template runtime');
    foreach ($storeIds as $index => $storeId) {
        $csv = "sku,precio,stock,estado\n" . strtoupper("{$run}-A") . ',' . (1000 + $index) . ',10,active' . "\n";
        $parsed = $parser->parse($csv); $before = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}inventory WHERE minimarket_id=%d", $storeId));
        $preview = $service->preview($storeId, $parsed['rows'], $parsed['errors']);
        rt($before === 0 && $preview['created'] === 1, 'preview no writes store ' . ($index + 1));
        $result = $service->import($preview); rt($result['created'] === 1, 'creation store ' . ($index + 1));
    }
    $storeId = $storeIds[0]; $skuA = strtoupper("{$run}-A");
    $update = $service->preview($storeId, $parser->parse("sku,precio,stock,estado\n{$skuA},2500,20,inactive\n")['rows'], []);
    rt($service->import($update)['updated'] === 1, 'update');
    $same = $service->preview($storeId, $parser->parse("sku,precio,stock,estado\n{$skuA},2500,20,inactive\n")['rows'], []);
    rt($same['unchanged'] === 1 && $service->import($same)['unchanged'] === 1, 'unchanged and idempotence');
    $skuB = strtoupper("{$run}-B");
    $mixedParsed = $parser->parse("sku,precio,stock,estado\n{$skuB},1900,5,active\nNO-EXISTE-{$run},100,1,active\n{$skuA},1.5,-1,bad\n");
    $mixed = $service->preview($storeId, $mixedParsed['rows'], $mixedParsed['errors']);
    rt(count($mixed['rows']) === 1 && count($mixed['errors']) === 2 && $mixed['errors'][0]['line'] > 0, 'mixed CSV and row errors');
    $beforeMixed = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}inventory WHERE minimarket_id=%d AND product_id=%d", $storeId, $productIds[1]));
    try { $service->import($mixed); rt(false, 'mixed confirmation blocked'); } catch (InvalidArgumentException) { $afterMixed = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}inventory WHERE minimarket_id=%d AND product_id=%d", $storeId, $productIds[1])); rt($beforeMixed === $afterMixed && $afterMixed === 0, 'mixed CSV zero writes and confirmation blocked'); }
    $corrected = $service->preview($storeId, $parser->parse("sku,precio,stock,estado\n{$skuB},1900,5,active\n")['rows'], []);
    rt($service->import($corrected)['created'] === 1, 'corrected CSV complete retry');
    $stale1 = $service->preview($storeId, $parser->parse("sku,precio,stock,estado\n{$skuB},2100,7,active\n")['rows'], []);
    $stale2 = $service->preview($storeId, $parser->parse("sku,precio,stock,estado\n{$skuB},2200,8,active\n")['rows'], []);
    $service->import($stale1); try { $service->import($stale2); rt(false, 'stale confirmation rejected'); } catch (RuntimeException) { rt(true, 'double confirmation/concurrency stale snapshot'); }
    $skuC = strtoupper("{$run}-C"); $skuD = strtoupper("{$run}-D");
    $rollback = $service->preview($storeId, $parser->parse("sku,precio,stock,estado\n{$skuC},3000,3,active\n{$skuD},4000,4,active\n")['rows'], []);
    $wpdb->update($prefix . 'products', ['updated_at' => gmdate('Y-m-d H:i:s', time() + 2)], ['id' => $productIds[3]]);
    try { $service->import($rollback); rt(false, 'rollback forced'); } catch (RuntimeException) { $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}inventory WHERE minimarket_id=%d AND product_id IN (%d,%d)", $storeId, $productIds[2], $productIds[3])); rt($count === 0, 'rollback zero partial rows'); }
    $inactiveStore = $storeIds[4]; $wpdb->update($prefix . 'stores', ['status' => 'inactive', 'updated_at' => gmdate('Y-m-d H:i:s', time() + 3)], ['id' => $inactiveStore]);
    $lifeRows = $parser->parse("sku,precio,stock,estado\n{$skuB},1500,2,active\n")['rows'];
    try { $service->preview($inactiveStore, $lifeRows, []); rt(false, 'lifecycle activation denied'); } catch (Throwable) { rt(true, 'lifecycle activation denied'); }
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}inventory WHERE minimarket_id=%d AND product_id=%d", $storeId, $productIds[1]), ARRAY_A);
    $policy = (new OfferAvailabilityPolicy())->evaluate(['inventory_exists'=>true,'product_id'=>$productIds[1],'minimarket_id'=>$storeId,'resolved_product_id'=>$productIds[1],'resolved_store_id'=>$storeId,'product_exists'=>true,'store_exists'=>true,'inventory_status'=>$row['status'],'product_status'=>'active','store_status'=>'active','store_onboarding_status'=>'complete','store_approved_at'=>$now,'price'=>$row['price'],'stock'=>$row['stock']]);
    rt($policy['is_publicly_available'] === true, 'territorial publication policy');
    $stageKey = 'va_inventory_import_' . $admin . '_' . hash('sha256', str_repeat('a', 48)); set_transient($stageKey, ['test'=>true], 1); sleep(2); rt(get_transient($stageKey) === false, 'staging expiration runtime');
} finally {
    if ($storeIds !== []) { $ids = implode(',', array_map('intval', $storeIds)); $wpdb->query("DELETE FROM {$prefix}inventory WHERE minimarket_id IN ({$ids})"); $wpdb->query("DELETE FROM {$prefix}stores WHERE id IN ({$ids})"); }
    if ($productIds !== []) { $ids = implode(',', array_map('intval', $productIds)); $wpdb->query("DELETE FROM {$prefix}products WHERE id IN ({$ids})"); }
    require_once ABSPATH . 'wp-admin/includes/user.php'; foreach ($userIds as $id) wp_delete_user($id);
    $residue = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}stores WHERE business_name LIKE %s", $run . '%')) + (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}products WHERE sku LIKE %s", strtoupper($run) . '%'));
    rt($residue === 0, 'zero residues');
}
