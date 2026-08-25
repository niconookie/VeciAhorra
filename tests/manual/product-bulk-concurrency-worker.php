<?php
declare(strict_types=1);
use VeciAhorra\Modules\Products\Import\ProductBulkImportService;
require_once dirname(__DIR__,5).'/wp-load.php';
[$script,$json,$barrier,$ready]=$argv;$row=json_decode(base64_decode($json,true),true,16,JSON_THROW_ON_ERROR);$service=new ProductBulkImportService();$preview=$service->preview([$row],[]);touch($ready);$deadline=microtime(true)+15;while(!is_file($barrier)&&microtime(true)<$deadline)usleep(10000);try{$r=$service->import($preview);echo json_encode(['status'=>'won','result'=>$r]);}catch(Throwable $e){echo json_encode(['status'=>'lost','message'=>str_contains($e->getMessage(),'cancelada')?'safe':'unsafe']);}
