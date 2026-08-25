<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$page = file_get_contents($root . '/app/Modules/Inventory/Admin/InventoryBulkImportPage.php');
$service = file_get_contents($root . '/app/Modules/Inventory/Import/InventoryBulkImportService.php');
$repository = file_get_contents($root . '/app/Modules/Inventory/Import/InventoryBulkImportRepository.php');
$parser = file_get_contents($root . '/app/Modules/Inventory/Import/InventoryCsvParser.php');
$view = file_get_contents($root . '/app/Modules/Inventory/Views/import.php');
function bulkContract(bool $ok, string $message): void { if (! $ok) throw new RuntimeException($message); }
foreach ([$page, $service, $repository, $parser, $view] as $source) bulkContract(is_string($source), 'No fue posible leer el contrato.');
bulkContract(str_contains($page, "private const CAPABILITY = 'manage_options'"), 'Capability administrativa.');
bulkContract(substr_count($page, 'check_admin_referer(') >= 4, 'Nonces/CSRF en acciones.');
bulkContract(str_contains($page, 'is_uploaded_file(') && str_contains($page, 'MAX_BYTES'), 'Upload acotado.');
bulkContract(str_contains($parser, "['sku', 'precio', 'stock', 'estado']") && ! str_contains($parser, 'store_id'), 'Encabezado canónico sin store_id.');
bulkContract(str_contains($service, 'productBySku(') && ! str_contains($service, 'createProduct'), 'SKU maestro sin creación silenciosa.');
bulkContract(str_contains($repository, 'START TRANSACTION') && str_contains($repository, 'ROLLBACK') && str_contains($repository, 'COMMIT'), 'Atomicidad transaccional.');
bulkContract(substr_count($repository, 'FOR UPDATE') >= 3 && str_contains($service, 'hash_equals(') && str_contains($service, 'usort('), 'Concurrencia y orden de locks.');
bulkContract(str_contains($service, 'StoreLifecycleContract::STATE_ACTIVE') && str_contains($service, "product['status'] !== 'active'"), 'Lifecycle/publicación territorial.');
bulkContract(str_contains($view, 'esc_html(') && str_contains($page, 'safeCsv('), 'Salida HTML/CSV sanitizada.');
bulkContract(str_contains($page, "'completed'"), 'Reintento de confirmación cerrado.');
bulkContract(str_contains($service, "if (! empty(\$preview['errors']))") && str_contains($view, "\$preview['errors'] === []"), 'CSV mixto bloqueado en servicio y UI.');
echo "inventory-bulk-import-contract-test: PASS\n";
