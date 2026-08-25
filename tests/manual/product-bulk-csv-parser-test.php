<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/vendor/autoload.php';
use VeciAhorra\Modules\Products\Import\ProductCsvParser;
function pbp(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$p=new ProductCsvParser();$h=implode(',',ProductCsvParser::HEADERS);
$valid=$p->parse("\xEF\xBB\xBF{$h}\r\nSKU-1,Producto,Categoría,,Marca,Unidad,Descripción,draft,\r\n");
pbp(count($valid['rows'])===1&&$valid['errors']===[],'Plantilla UTF-8/BOM inválida.');
$duplicate=$p->parse("{$h}\nSKU-1,A,C,,,U,,active,\nsku-1,B,C,,,U,,active,\n");pbp(count($duplicate['errors'])===1&&str_contains($duplicate['errors'][0]['message'],'duplicado'),'Duplicado no rechazado.');
$injection=$p->parse("{$h}\n=CMD,A,C,,,,,draft,\n");pbp(count($injection['errors'])===1,'CSV injection no rechazada.');
foreach(['sku,nombre,categoria,precio,stock,estado','sku,nombre,categoria,subcategoria,marca,unidad,descripcion,estado,imagen,store_id']as$bad){try{$p->parse($bad."\n");throw new RuntimeException('Encabezado prohibido aceptado.');}catch(InvalidArgumentException){}}
$rows=[];for($i=1;$i<=1000;$i++)$rows[]="SKU-{$i},Producto {$i},Categoría,,,,,draft,";$max=$p->parse($h."\n".implode("\n",$rows));pbp(count($max['rows'])===1000,'No aceptó 1000 filas.');
try{$p->parse($h."\n".implode("\n",$rows)."\nSKU-1001,P,C,,,,,draft,");throw new RuntimeException('Aceptó 1001 filas.');}catch(InvalidArgumentException){}
echo "product-bulk-csv-parser-test: PASS\n";
