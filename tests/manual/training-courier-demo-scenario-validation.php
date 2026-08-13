<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';
require_once __DIR__ . '/support/training-courier-demo-scenario-support.php';

$context = vaCourierDemoContext();
$officialBefore = vaCourierDemoOfficialOffers($context);
$rows = vaCourierDemoValidate($context);
$projection = vaCourierDemoCourierProjection($rows, (int) $context['courierId']);
vaCourierDemoAssert(vaCourierDemoOfficialOffers($context) === $officialBefore, 'El dataset base cambió durante validación.');

echo 'PASS COURIER_ID=16 DEMO_ORDERS=3 DEMO_DELIVERIES=3', PHP_EOL;
echo 'AVAILABLE=' . count($projection['available'])
    . ' ASSIGNED=' . count($projection['assigned'])
    . ' IN_PROGRESS=' . count($projection['inProgress'])
    . ' FOREIGN_DELIVERIES_MODIFIED=0 BASE_DATASET_CHANGED=0', PHP_EOL;
echo 'TRAINING_COURIER_ORDER_IDS=[' . implode(',', array_map(static fn(array $row): int => (int) $row['order_id'], $rows)) . ']', PHP_EOL;
echo 'TRAINING_COURIER_DELIVERY_IDS=[' . implode(',', $projection['fixtureIds']) . ']', PHP_EOL;
