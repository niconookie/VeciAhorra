<?php

declare(strict_types=1);

use VeciAhorra\Core\Application;
use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Cart\Repository\CartRepository;
use VeciAhorra\Modules\Cart\Service\CartService;
use VeciAhorra\Modules\Checkout\Service\CheckoutService;
use VeciAhorra\Modules\Inventory\Repositories\InventoryRepository;
use VeciAhorra\Modules\Payments\Service\PaymentConfirmationService;
use VeciAhorra\Modules\Payments\Service\PaymentService;
use VeciAhorra\Modules\Payments\Service\PaymentSessionService;
use VeciAhorra\Modules\Products\Models\Product;
use VeciAhorra\Modules\Products\Repositories\ProductRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertTransactionalWorkflow(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertTransactionalWorkflowSame(mixed $expected, mixed $actual): void
{
    assertTransactionalWorkflow(
        $expected === $actual,
        sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        )
    );
}

global $wpdb;

$prefix = $wpdb->prefix . Config::TABLE_PREFIX;
$tables = [
    'cart' => $prefix . 'cart_items',
    'inventory' => $prefix . 'inventory',
    'products' => $prefix . 'products',
    'orders' => $prefix . 'orders',
    'items' => $prefix . 'order_items',
    'reservations' => $prefix . 'reservations',
    'payments' => $prefix . 'payments',
    'payment_orders' => $prefix . 'payment_orders',
];
$administrators = get_users([
    'role' => 'administrator',
    'number' => 1,
    'fields' => 'ids',
]);
assertTransactionalWorkflow($administrators !== [], 'Falta administrador.');
$customerId = (int) $administrators[0];
$owner = ['session_id' => null, 'user_id' => $customerId];
$application = new Application();
$container = $application->container();
$cartService = new CartService(new CartRepository());
$checkoutService = $container->make(CheckoutService::class);
$paymentService = $container->make(PaymentService::class);
$sessionService = $container->make(PaymentSessionService::class);
$confirmationService = $container->make(PaymentConfirmationService::class);
$productRepository = new ProductRepository();
$inventoryRepository = new InventoryRepository();
$productId = 0;
$inventoryId = 0;
$orderIds = [];
$paymentId = 0;

try {
    $now = current_time('mysql');
    $token = bin2hex(random_bytes(8));
    $productId = $productRepository->create([
        'woo_product_id' => null,
        'name' => 'Transactional workflow ' . $token,
        'slug' => 'transactional-workflow-' . $token,
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
        'minimarket_id' => random_int(600000000, 609999999),
        'price' => 3200.0,
        'stock' => 5,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $cartService->addItem($owner, $inventoryId, 2);

    $validation = $checkoutService->validate($owner);
    assertTransactionalWorkflowSame(true, $validation['valid']);
    assertTransactionalWorkflowSame('6400.00', $validation['summary']['total']);

    $checkout = $checkoutService->initialize($owner);
    assertTransactionalWorkflowSame(true, $checkout['valid']);
    assertTransactionalWorkflowSame(1, count($checkout['orders']));
    assertTransactionalWorkflowSame(1, count($checkout['reservations']));
    $orderIds = array_map(
        static fn (array $order): int => (int) $order['id'],
        $checkout['orders']
    );
    assertTransactionalWorkflowSame([], $cartService->getCart($owner));
    assertTransactionalWorkflowSame(
        3,
        (int) $inventoryRepository->find($inventoryId)['stock']
    );

    $checkoutRetry = $checkoutService->initialize($owner);
    assertTransactionalWorkflowSame(false, $checkoutRetry['valid']);
    assertTransactionalWorkflowSame(
        1,
        (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$tables['orders']} WHERE id = %d",
            $orderIds[0]
        ))
    );

    $paymentPayload = [
        'customer_id' => $customerId,
        'amount' => '6400.00',
        'currency' => 'CLP',
        'provider' => null,
        'order_ids' => $orderIds,
    ];
    $payment = $paymentService->create($paymentPayload);
    $paymentId = (int) $payment['id'];
    $samePayment = $paymentService->create($paymentPayload);
    assertTransactionalWorkflowSame($paymentId, (int) $samePayment['id']);

    $session = $sessionService->create($paymentId);
    $sameSession = $sessionService->create($paymentId);
    assertTransactionalWorkflowSame(
        $session['provider_reference'],
        $sameSession['provider_reference']
    );

    $confirmed = $confirmationService->confirm(
        'dummy',
        $session['provider_reference']
    );
    assertTransactionalWorkflowSame('paid', $confirmed['status']);
    assertTransactionalWorkflowSame(1, $confirmed['orders_updated']);
    assertTransactionalWorkflowSame(1, $confirmed['reservations_confirmed']);
    assertTransactionalWorkflowSame(
        'paid',
        $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$tables['orders']} WHERE id = %d",
            $orderIds[0]
        ))
    );
    assertTransactionalWorkflowSame(
        'consumed',
        $wpdb->get_var($wpdb->prepare(
            "SELECT status
             FROM {$tables['reservations']}
             WHERE order_id = %d",
            $orderIds[0]
        ))
    );
    assertTransactionalWorkflowSame(
        3,
        (int) $inventoryRepository->find($inventoryId)['stock']
    );

    $confirmedAgain = $confirmationService->confirm(
        'dummy',
        $session['provider_reference']
    );
    assertTransactionalWorkflowSame('paid', $confirmedAgain['status']);
    assertTransactionalWorkflowSame(0, $confirmedAgain['orders_updated']);
    assertTransactionalWorkflowSame(
        0,
        $confirmedAgain['reservations_confirmed']
    );
    assertTransactionalWorkflowSame(
        3,
        (int) $inventoryRepository->find($inventoryId)['stock']
    );

    assertTransactionalWorkflowSame(
        0,
        (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
             FROM {$tables['items']} oi
             LEFT JOIN {$tables['orders']} o ON o.id = oi.order_id
             WHERE oi.order_id = %d AND o.id IS NULL",
                $orderIds[0]
            )
        )
    );
    assertTransactionalWorkflowSame(
        0,
        (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$tables['reservations']} r
             LEFT JOIN {$tables['orders']} o ON o.id = r.order_id
             WHERE r.order_id = %d AND o.id IS NULL",
            $orderIds[0]
        ))
    );
    assertTransactionalWorkflowSame(
        0,
        (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$tables['payment_orders']} po
             LEFT JOIN {$tables['orders']} o ON o.id = po.order_id
             WHERE po.payment_id = %d AND o.id IS NULL",
            $paymentId
        ))
    );

    echo "PASS transactional-workflow-test\n";
} finally {
    if ($paymentId > 0) {
        $wpdb->delete($tables['payment_orders'], ['payment_id' => $paymentId]);
        $wpdb->delete($tables['payments'], ['id' => $paymentId]);
    }
    foreach ($orderIds as $orderId) {
        $wpdb->delete($tables['reservations'], ['order_id' => $orderId]);
        $wpdb->delete($tables['items'], ['order_id' => $orderId]);
        $wpdb->delete($tables['orders'], ['id' => $orderId]);
    }
    $wpdb->delete($tables['cart'], ['user_id' => $customerId]);
    if ($inventoryId > 0) {
        $wpdb->delete($tables['inventory'], ['id' => $inventoryId]);
    }
    if ($productId > 0) {
        $wpdb->delete($tables['products'], ['id' => $productId]);
    }
}
