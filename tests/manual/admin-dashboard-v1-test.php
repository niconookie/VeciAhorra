<?php

declare(strict_types=1);

use VeciAhorra\Admin\DashboardReadRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function dashboardAssert(bool $condition, string $message): void
{
    if (! $condition) throw new RuntimeException($message);
}

$repository = new DashboardReadRepository();
$snapshot = $repository->snapshot();
$metrics = $snapshot['metrics'];

dashboardAssert((int) $snapshot['query_count'] === 2, 'El dashboard no respeta el presupuesto de dos queries.');
dashboardAssert(count($snapshot['recent_orders']) <= 10, 'Pedidos recientes sin límite.');
dashboardAssert((string) $snapshot['timezone'] === wp_timezone_string(), 'Timezone no canónico.');

foreach (['sales_today','orders_today','paid_orders','deliveries_pending','deliveries_assigned','deliveries_picked_up','deliveries_delivered','active_stores','active_products','public_offers','approved_couriers','published_service_providers'] as $key) {
    dashboardAssert(array_key_exists($key, $metrics), "Falta métrica {$key}.");
}

$source = (string) file_get_contents(VA_PLUGIN_PATH . 'app/Admin/DashboardReadRepository.php');
foreach ([' INSERT ', ' UPDATE ', ' DELETE ', ' REPLACE ', 'wp_insert_', 'wp_update_'] as $mutation) {
    dashboardAssert(stripos(' ' . $source, $mutation) === false, "Read model contiene mutación {$mutation}.");
}
dashboardAssert(str_contains($source, "p.status='paid'"), 'Ventas no usan Payment pagado.');
dashboardAssert(str_contains($source, "p.paid_at >= %%s AND p.paid_at < %%s"), 'Ventas no delimitan paid_at.');
dashboardAssert(str_contains($source, "i.status='active' AND i.stock > 0 AND i.price > 0"), 'Oferta pública incompleta.');
dashboardAssert(str_contains($source, "pr.status='active' AND s.status='active'"), 'Oferta pública no exige Product/Store activos.');

$view = (string) file_get_contents(VA_PLUGIN_PATH . 'app/Admin/Views/dashboard.php');
foreach (['Resumen de hoy','Despachos','Red VeciAhorra','Pedidos recientes','Ver detalle','veciahorra-orders'] as $contract) {
    dashboardAssert(str_contains($view, $contract), "Vista sin contrato {$contract}.");
}

echo "TRAINING_ADMIN_DASHBOARD=PASS\n";
echo "DASHBOARD_QUERY_COUNT=2\n";
echo "N_PLUS_ONE=no\n";
