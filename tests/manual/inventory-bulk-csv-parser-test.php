<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use VeciAhorra\Modules\Inventory\Import\InventoryCsvParser;

function csvAssert(bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); }
$parser = new InventoryCsvParser();
$valid = $parser->parse("\xEF\xBB\xBFsku,precio,stock,estado\nSKU-1,1990,0,active\n");
csvAssert(count($valid['rows']) === 1 && $valid['errors'] === [], 'Plantilla válida/BOM.');
for ($store = 1; $store <= 5; $store++) { $five = $parser->parse("sku,precio,stock,estado\nSKU-{$store},1990,10,active\n"); csvAssert(count($five['rows']) === 1, "CSV válido para minimarket {$store}."); }
$invalid = $parser->parse("sku,precio,stock,estado\nSKU-1,1.5,-1,unknown\nSKU-X,100,2,active\nSKU-X,100,2,inactive\n=CMD,100,2,active\n");
csvAssert(count($invalid['rows']) === 1 && count($invalid['errors']) === 3, 'Validaciones numéricas, estado, duplicado e injection.');
foreach (["sku,precio,stock\nA,1,1\n", "sku,precio,stock,estado,store_id\nA,1,1,active,5\n"] as $bad) { try { $parser->parse($bad); throw new RuntimeException('Encabezado inválido aceptado.'); } catch (InvalidArgumentException) {} }
echo "inventory-bulk-csv-parser-test: PASS\n";
