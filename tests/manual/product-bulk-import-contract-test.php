<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$page=file_get_contents($root.'/app/Modules/Products/Admin/ProductBulkImportPage.php');$service=file_get_contents($root.'/app/Modules/Products/Import/ProductBulkImportService.php');$zip=file_get_contents($root.'/app/Modules/Products/Import/ProductImageZipParser.php');$view=file_get_contents($root.'/app/Modules/Products/Views/import.php');
function pbc(bool $ok,string $m):void{if(!$ok)throw new RuntimeException($m);}
pbc(str_contains($page,"CAPABILITY='manage_options'")&&str_contains($page,'check_admin_referer'),'Falta capability o CSRF.');
pbc(str_contains($page,'15*MINUTE_IN_SECONDS')&&str_contains($page,"!empty(\$p['completed'])"),'Falta staging expirado/consumible.');
pbc(str_contains($service,"if(!empty(\$preview['errors']))")&&str_contains($service,'START TRANSACTION')===false,'Defensa atómica ausente o transacción fuera de repositorio.');
pbc(str_contains($service,'inventoryCount')&&str_contains($service,'unlink'),'Falta invariancia Inventory o compensación física.');
pbc(str_contains($zip,'getExternalAttributesIndex')&&str_contains($zip,'MAX_EXPANDED_BYTES')&&str_contains($zip,'wp_check_filetype_and_ext'),'Defensas ZIP incompletas.');
pbc(str_contains($view,'El archivo contiene errores')&&str_contains($view,"\$preview['errors']===[]"),'UI permite confirmar rechazadas.');
echo "product-bulk-import-contract-test: PASS\n";
