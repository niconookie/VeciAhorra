<?php

declare(strict_types=1);

use VeciAhorra\Core\Application;
use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Inventory\Repositories\InventoryRepository;
use VeciAhorra\Modules\Orders\Services\OrderService;
use VeciAhorra\Modules\Payments\Service\PaymentService;
use VeciAhorra\Modules\Payments\Service\PaymentSessionService;
use VeciAhorra\Modules\Products\Models\Product;
use VeciAhorra\Modules\Products\Repositories\ProductRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

function assertCustomerPanel(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertCustomerPanelSame(mixed $expected, mixed $actual): void
{
    assertCustomerPanel(
        $expected === $actual,
        sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        )
    );
}

function customerPanelRequest(string $route): WP_REST_Response
{
    return rest_do_request(new WP_REST_Request('GET', $route));
}

global $wpdb;

foreach (get_users(['search' => 'customer-panel-*']) as $staleUser) {
    wp_delete_user((int) $staleUser->ID);
}

$administrators = get_users([
    'role' => 'administrator',
    'number' => 1,
    'fields' => 'ids',
]);
assertCustomerPanel($administrators !== [], 'Falta administrador.');
$customerId = (int) $administrators[0];
$otherUserId = wp_insert_user([
    'user_login' => 'customer-panel-' . bin2hex(random_bytes(6)),
    'user_pass' => wp_generate_password(20),
    'user_email' => bin2hex(random_bytes(6)) . '@example.test',
    'role' => 'subscriber',
]);
assertCustomerPanel(! is_wp_error($otherUserId), 'No se creo usuario ajeno.');
$otherUserId = (int) $otherUserId;

$transaction = $wpdb->query('START TRANSACTION');
assertCustomerPanel($transaction !== false, 'No se inicio transaccion.');

try {
    $routes = rest_get_server()->get_routes();
    assertCustomerPanel(
        isset($routes['/veciahorra/v1/me/orders']),
        'GET /me/orders no esta registrada.'
    );
    assertCustomerPanel(
        isset($routes['/veciahorra/v1/me/orders/(?P<id>\d+)']),
        'GET /me/orders/{id} no esta registrada.'
    );

    wp_set_current_user(0);
    assertCustomerPanel(
        in_array(
            customerPanelRequest('/veciahorra/v1/me/orders')->get_status(),
            [401, 403],
            true
        ),
        'El endpoint permite invitados.'
    );

    $now = current_time('mysql');
    $productRepository = new ProductRepository();
    $inventoryRepository = new InventoryRepository();
    $orderService = new OrderService();
    $application = new Application();
    $paymentService = $application->container()->make(PaymentService::class);
    $sessionService = $application->container()->make(
        PaymentSessionService::class
    );
    $makeOrder = static function (
        int $ownerId,
        int $token
    ) use (
        $productRepository,
        $inventoryRepository,
        $orderService,
        $now
    ): array {
        $productId = $productRepository->create([
            'woo_product_id' => null,
            'name' => 'Customer product ' . $token,
            'slug' => 'customer-product-' . $token,
            'sku' => null,
            'description' => null,
            'category_id' => null,
            'brand_id' => null,
            'unit_id' => null,
            'image_id' => null,
            'status' => Product::STATUS_ACTIVE,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $inventoryId = $inventoryRepository->create([
            'product_id' => $productId,
            'minimarket_id' => 710000000 + $token,
            'price' => 1750.0,
            'stock' => 5,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $order = $orderService->create([
            'customer_id' => $ownerId,
            'minimarket_id' => 710000000 + $token,
            'items' => [[
                'product_id' => $productId,
                'inventory_id' => $inventoryId,
                'quantity' => 2,
                'unit_price' => 1750.0,
            ]],
        ]);

        return ['order' => $order, 'inventory_id' => $inventoryId];
    };

    $owned = $makeOrder($customerId, random_int(100000, 199999));
    $foreign = $makeOrder($otherUserId, random_int(200000, 299999));
    $payment = $paymentService->create([
        'customer_id' => $customerId,
        'amount' => '3500.00',
        'currency' => 'CLP',
        'provider' => null,
        'order_ids' => [(int) $owned['order']['id']],
    ]);
    $sessionService->create((int) $payment['id']);
    $ordersStatusBefore = $wpdb->get_results(
        'SELECT id, status FROM '
            . $wpdb->prefix . Config::TABLE_PREFIX . 'orders ORDER BY id',
        ARRAY_A
    );
    $stockBefore = (int) $inventoryRepository->find(
        $owned['inventory_id']
    )['stock'];

    wp_set_current_user($customerId);
    $listed = customerPanelRequest('/veciahorra/v1/me/orders');
    assertCustomerPanelSame(200, $listed->get_status());
    $orders = $listed->get_data()['data'] ?? [];
    $listedIds = array_map('intval', array_column($orders, 'order_id'));
    assertCustomerPanel(
        in_array((int) $owned['order']['id'], $listedIds, true),
        'No se listo el pedido propio.'
    );
    assertCustomerPanel(
        ! in_array((int) $foreign['order']['id'], $listedIds, true),
        'Se filtro un pedido ajeno.'
    );
    $summary = current(array_filter(
        $orders,
        static fn (array $order): bool =>
            (int) $order['order_id'] === (int) $owned['order']['id']
    ));
    assertCustomerPanelSame('Reservado', $summary['visible_status'] ?? null);
    assertCustomerPanelSame('pending', $summary['payment_status'] ?? null);
    assertCustomerPanelSame('dummy', $summary['payment_method'] ?? null);

    $detail = customerPanelRequest(
        '/veciahorra/v1/me/orders/' . $owned['order']['id']
    );
    assertCustomerPanelSame(200, $detail->get_status());
    $data = $detail->get_data()['data'] ?? [];
    assertCustomerPanelSame('Reservado', $data['visible_status'] ?? null);
    assertCustomerPanelSame(2, $data['items'][0]['quantity'] ?? null);
    assertCustomerPanelSame('1750.00', $data['items'][0]['unit_price'] ?? null);
    assertCustomerPanelSame('3500.00', $data['items'][0]['subtotal'] ?? null);
    assertCustomerPanel(
        str_starts_with(
            (string) ($data['items'][0]['product']['name'] ?? ''),
            'Customer product '
        ),
        'No se serializo el producto.'
    );
    assertCustomerPanelSame('pending', $data['payment']['status'] ?? null);
    assertCustomerPanelSame('dummy', $data['payment']['method'] ?? null);

    assertCustomerPanelSame(
        404,
        customerPanelRequest(
            '/veciahorra/v1/me/orders/' . $foreign['order']['id']
        )->get_status()
    );
    assertCustomerPanelSame(
        $ordersStatusBefore,
        $wpdb->get_results(
            'SELECT id, status FROM '
                . $wpdb->prefix . Config::TABLE_PREFIX . 'orders ORDER BY id',
            ARRAY_A
        )
    );
    assertCustomerPanelSame(
        $stockBefore,
        (int) $inventoryRepository->find($owned['inventory_id'])['stock']
    );

    $moduleFiles = glob(
        dirname(__DIR__, 2) . '/app/Modules/CustomerPanel/*/*.php'
    ) ?: [];
    $source = '';

    foreach ($moduleFiles as $file) {
        $source .= (string) file_get_contents($file);
    }
    assertCustomerPanel(
        preg_match('/\b(INSERT|UPDATE|DELETE)\b/i', $source) !== 1,
        'CustomerPanel contiene escrituras.'
    );

    echo "PASS customer-panel-foundation-test\n";
} finally {
    $wpdb->query('ROLLBACK');
    wp_set_current_user(0);
    wp_delete_user($otherUserId);
}
