<?php

declare(strict_types=1);

use VeciAhorra\Modules\Couriers\Repository\CourierDeliveryRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';
require_once __DIR__ . '/support/training-courier-demo-scenario-support.php';

$context = vaCourierDemoContext();
$officialBefore = vaCourierDemoOfficialOffers($context);
$rows = vaCourierDemoValidate($context);
$repository = new CourierDeliveryRepository();
$ownedIds = array_map(static fn(array $row): int => (int) $row['delivery_id'], $rows);
$available = array_values(array_filter($repository->available(), static fn(array $row): bool => in_array((int) $row['id'], $ownedIds, true)));
$owned = array_values(array_filter($repository->owned(16), static fn(array $row): bool => in_array((int) $row['id'], $ownedIds, true)));
vaCourierDemoAssert(count($available) === 1, 'AVAILABLE demo debe ser 1.');
vaCourierDemoAssert(count(array_filter($owned, static fn(array $row): bool => $row['status'] === 'assigned')) === 1, 'ASSIGNED demo debe ser 1.');
vaCourierDemoAssert(count(array_filter($owned, static fn(array $row): bool => $row['status'] === 'picked_up')) === 1, 'IN_PROGRESS demo debe ser 1.');
vaCourierDemoAssert(vaCourierDemoOfficialOffers($context) === $officialBefore, 'El dataset base cambió durante validación.');

echo 'PASS COURIER_ID=16 DEMO_ORDERS=3 DEMO_DELIVERIES=3', PHP_EOL;
echo 'AVAILABLE=1 ASSIGNED=1 IN_PROGRESS=1 FOREIGN_DELIVERIES_MODIFIED=0 BASE_DATASET_CHANGED=0', PHP_EOL;
echo 'TRAINING_COURIER_ORDER_IDS=[' . implode(',', array_map(static fn(array $row): int => (int) $row['order_id'], $rows)) . ']', PHP_EOL;
echo 'TRAINING_COURIER_DELIVERY_IDS=[' . implode(',', $ownedIds) . ']', PHP_EOL;
