<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Minimarket\Identity\MinimarketRole;
use VeciAhorra\Modules\Minimarket\MinimarketModule;

require_once dirname(__DIR__, 5) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

function mmAssert(bool $condition, string $message): void
{
    if (! $condition) throw new RuntimeException($message);
}

function mmRequest(string $method, string $path, ?array $body = null): WP_REST_Response
{
    $parts = wp_parse_url($path);
    $request = new WP_REST_Request($method, '/veciahorra/v1/minimarket/' . ltrim((string) ($parts['path'] ?? ''), '/'));
    if (isset($parts['query'])) {
        parse_str((string) $parts['query'], $query);
        $request->set_query_params($query);
    }
    if ($body !== null) {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode($body));
    }
    return rest_do_request($request);
}

global $wpdb;
$p = $wpdb->prefix . Config::TABLE_PREFIX;
$ids = ['users' => [], 'stores' => [], 'products' => [], 'inventory' => [], 'orders' => [], 'checkouts' => [], 'links' => [], 'items' => [], 'deliveries' => []];
$now = current_time('mysql');
$future = current_datetime()->modify('+1 hour')->format('Y-m-d H:i:s');
$nonce = bin2hex(random_bytes(6));

try {
    foreach (['a', 'b', 'none'] as $suffix) {
        $id = wp_create_user("mm_{$suffix}_{$nonce}", wp_generate_password(24), "mm_{$suffix}_{$nonce}@example.test");
        mmAssert(is_int($id) && $id > 0, 'No se creó usuario fixture.');
        $ids['users'][] = $id;
        (new WP_User($id))->set_role(MinimarketRole::ROLE);
    }
    [$userA, $userB, $userNone] = $ids['users'];
    $customer = wp_create_user("mm_customer_{$nonce}", wp_generate_password(24), "mm_customer_{$nonce}@example.test");
    mmAssert(is_int($customer), 'No se creó cliente fixture.');
    $ids['users'][] = $customer;

    foreach (['Store A', 'Store B'] as $name) {
        mmAssert($wpdb->insert($p . 'stores', [
            'business_name' => $name . ' ' . $nonce, 'legal_name' => $name,
            'owner_name' => 'Owner', 'rut' => 'RUT-' . $nonce . '-' . count($ids['stores']),
            'email' => strtolower(str_replace(' ', '', $name)) . $nonce . '@example.test', 'phone' => '1234567',
            'status' => 'active', 'onboarding_status' => 'complete', 'approved_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]) === 1, 'No se creó Store fixture.');
        $ids['stores'][] = (int) $wpdb->insert_id;
    }
    [$storeA, $storeB] = $ids['stores'];
    update_user_meta($userA, MinimarketRole::STORE_META_KEY, $storeA);
    update_user_meta($userB, MinimarketRole::STORE_META_KEY, $storeB);

    foreach (['Producto propio', 'Producto disponible'] as $index => $name) {
        mmAssert($wpdb->insert($p . 'products', [
            'name' => $name, 'slug' => 'mm-' . $nonce . '-' . $index, 'sku' => 'MM-' . strtoupper($nonce) . '-' . $index,
            'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]) === 1, 'No se creó Product fixture.');
        $ids['products'][] = (int) $wpdb->insert_id;
    }
    [$productOwned, $productAvailable] = $ids['products'];
    foreach ([[$productOwned, $storeA], [$productOwned, $storeB]] as [$product, $store]) {
        $wpdb->insert($p . 'inventory', ['product_id'=>$product,'minimarket_id'=>$store,'price'=>'1000.00','stock'=>5,'status'=>'active','created_at'=>$now,'updated_at'=>$now]);
        $ids['inventory'][] = (int) $wpdb->insert_id;
    }
    [$inventoryA, $inventoryB] = $ids['inventory'];

    foreach ([$storeA, $storeB] as $store) {
        $wpdb->insert($p . 'orders', ['customer_id'=>$customer,'minimarket_id'=>$store,'total'=>'1000.00','status'=>'paid','reservation_expires_at'=>$future,'created_at'=>$now,'updated_at'=>$now]);
        $order = (int) $wpdb->insert_id; $ids['orders'][] = $order;
        $wpdb->insert($p . 'order_items', ['order_id'=>$order,'product_id'=>$productOwned,'inventory_id'=>$store === $storeA ? $inventoryA : $inventoryB,'quantity'=>1,'unit_price'=>'1000.00','subtotal'=>'1000.00','created_at'=>$now,'updated_at'=>$now]);
        $ids['items'][] = (int) $wpdb->insert_id;
        $wpdb->insert($p . 'checkouts', ['public_id'=>'chk_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),'owner_type'=>'user','user_id'=>$customer,'status'=>'payment_started','fulfillment_method'=>'delivery','currency'=>'CLP','total_amount'=>'1000.00','created_at'=>$now,'updated_at'=>$now,'expires_at'=>$future]);
        $checkout = (int) $wpdb->insert_id; $ids['checkouts'][] = $checkout;
        $wpdb->insert($p . 'checkout_orders', ['checkout_id'=>$checkout,'order_id'=>$order,'created_at'=>$now]); $ids['links'][] = (int) $wpdb->insert_id;
        $wpdb->insert($p . 'deliveries', ['order_id'=>$order,'customer_id'=>$customer,'minimarket_id'=>$store,'courier_id'=>null,'status'=>'assigned','created_at'=>$now,'updated_at'=>$now]); $ids['deliveries'][] = (int) $wpdb->insert_id;
    }
    [$orderA, $orderB] = $ids['orders'];

    wp_set_current_user($userA);
    $me = mmRequest('GET', 'me');
    mmAssert($me->get_status() === 200 && (int) $me->get_data()['data']['store']['id'] === $storeA, 'M01-M03 contexto propio inválido.');
    mmAssert(shortcode_exists(MinimarketModule::SHORTCODE) && str_contains(do_shortcode('[' . MinimarketModule::SHORTCODE . ']'), 'data-va-minimarket'), 'M04 panel no alcanzable.');

    $products = mmRequest('GET', 'products?search=Producto')->get_data()['data'];
    mmAssert(count($products) === 1 && (int) $products[0]['product_id'] === $productAvailable, 'M05 catálogo disponible no está correctamente filtrado.');
    $inventory = mmRequest('GET', 'inventory')->get_data()['data'];
    mmAssert(count($inventory) === 1 && (int) $inventory[0]['inventory_id'] === $inventoryA, 'M06 filtrado Inventory falló.');

    $updated = mmRequest('PATCH', 'inventory/' . $inventoryA, ['price'=>'1250.50','stock'=>'9','status'=>'inactive']);
    mmAssert($updated->get_status() === 200 && (int) $updated->get_data()['data']['stock'] === 9 && $updated->get_data()['data']['status'] === 'inactive', 'M07-M09 edición propia falló.');
    mmAssert(mmRequest('PATCH', 'inventory/' . $inventoryB, ['stock'=>99])->get_status() === 404, 'M10 Inventory ajeno fue modificable.');
    mmAssert(mmRequest('PATCH', 'inventory/' . $inventoryA, ['store_id'=>$storeB,'stock'=>99])->get_status() === 422, 'Spoof de store_id fue aceptado.');
    mmAssert(mmRequest('PATCH', 'inventory/' . $inventoryA, ['price'=>'-1'])->get_status() === 422, 'Precio inválido fue aceptado.');
    mmAssert(mmRequest('PATCH', 'inventory/' . $inventoryA, ['stock'=>'1.5'])->get_status() === 422, 'Stock inválido fue aceptado.');

    $created = mmRequest('POST', 'inventory', ['product_id'=>$productAvailable,'price'=>'800','stock'=>'3','status'=>'active']);
    mmAssert($created->get_status() === 201 && (int) $created->get_data()['data']['minimarket_id'] === $storeA, 'Incorporación de Product maestro falló.');
    $ids['inventory'][] = (int) $created->get_data()['data']['id'];
    mmAssert(mmRequest('POST', 'inventory', ['product_id'=>$productAvailable,'price'=>'800','stock'=>3])->get_status() === 409, 'Duplicado product/store fue aceptado.');
    mmAssert(mmRequest('POST', 'inventory', ['product_id'=>PHP_INT_MAX,'price'=>'800','stock'=>3])->get_status() === 404, 'Producto inexistente fue aceptado.');
    mmAssert(mmRequest('POST', 'products', ['name'=>'Intrusión'])->get_status() === 404, 'Se expuso mutación de Product maestro.');

    $orders = mmRequest('GET', 'orders')->get_data()['data'];
    mmAssert(count($orders) === 1 && (int) $orders[0]['order_id'] === $orderA, 'M11 listado Orders no está scoped.');
    $detail = mmRequest('GET', 'orders/' . $orderA);
    mmAssert($detail->get_status() === 200 && count($detail->get_data()['data']['items']) === 1, 'M12 detalle propio incompleto.');
    mmAssert($detail->get_data()['data']['delivery_status'] === 'assigned', 'M14 estado Delivery no proyectado.');
    mmAssert(mmRequest('GET', 'orders/' . $orderB)->get_status() === 404, 'M13 Order ajena fue visible.');
    mmAssert(mmRequest('GET', 'orders/' . PHP_INT_MAX)->get_status() === 404, 'Order inexistente no devolvió respuesta segura.');

    wp_set_current_user($userNone);
    mmAssert(mmRequest('GET', 'me')->get_status() === 403, 'Usuario sin Store no fue rechazado.');
    wp_set_current_user($customer);
    mmAssert(mmRequest('GET', 'orders/' . $orderA)->get_status() === 403, 'Otro rol pudo consultar el detalle de Store.');
    wp_set_current_user(0);
    mmAssert(mmRequest('GET', 'me')->get_status() === 401, 'Usuario anónimo no fue rechazado.');
    $wpdb->update($p . 'stores', ['status'=>'inactive'], ['id'=>$storeA]);
    wp_set_current_user($userA);
    mmAssert(mmRequest('GET', 'me')->get_status() === 403, 'Store inactivo no fue rechazado.');

    echo "PASS minimarket-mvp-integration-test M01-M14\n";
} finally {
    wp_set_current_user(0);
    foreach (array_reverse($ids['deliveries']) as $id) $wpdb->delete($p . 'deliveries', ['id'=>$id]);
    foreach (array_reverse($ids['links']) as $id) $wpdb->delete($p . 'checkout_orders', ['id'=>$id]);
    foreach (array_reverse($ids['checkouts']) as $id) $wpdb->delete($p . 'checkouts', ['id'=>$id]);
    foreach (array_reverse($ids['items']) as $id) $wpdb->delete($p . 'order_items', ['id'=>$id]);
    foreach (array_reverse($ids['orders']) as $id) $wpdb->delete($p . 'orders', ['id'=>$id]);
    foreach (array_reverse($ids['inventory']) as $id) $wpdb->delete($p . 'inventory', ['id'=>$id]);
    foreach (array_reverse($ids['products']) as $id) $wpdb->delete($p . 'products', ['id'=>$id]);
    foreach (array_reverse($ids['stores']) as $id) $wpdb->delete($p . 'stores', ['id'=>$id]);
    foreach (array_reverse($ids['users']) as $id) wp_delete_user($id);
}
