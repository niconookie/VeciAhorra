<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Schemas\InventorySchema;
use VeciAhorra\Database\Tables\ProductsTable;
use VeciAhorra\Database\Tables\StoresTable;
use VeciAhorra\Exceptions\ConflictException;
use VeciAhorra\Exceptions\RecordNotFoundException;
use VeciAhorra\Modules\Checkout\Repository\DeliveryFlagRepository;
use VeciAhorra\Modules\Checkout\Service\DeliveryFlagService;

$assertions=0;
$assert=static function(bool $condition,string $message)use(&$assertions):void{$assertions++;if(!$condition){throw new RuntimeException($message);}};

final class DeliveryFlagMemoryRepository extends DeliveryFlagRepository
{
    public array $rows=['store'=>[10=>0],'product'=>[20=>0],'inventory'=>[30=>0]];
    public function __construct() {}
    public function find(string $entity,int $id):?array{return isset($this->rows[$entity][$id])?['id'=>$id,'delivery_enabled'=>$this->rows[$entity][$id]]:null;}
    public function compareAndSet(string $entity,int $id,int $expected,int $enabled):int{if(($this->rows[$entity][$id]??null)!==$expected){return 0;}$this->rows[$entity][$id]=$enabled;return 1;}
    public function listing(string $entity):array{return[];}
}

foreach([new StoresTable(),new ProductsTable(),new InventorySchema()] as $schema){$builder=TableBuilder::make('test_'.$schema->name());$schema->define($builder);$sql=$builder->build();$assert((bool)preg_match("/delivery_enabled[^,\\n]+DEFAULT ['\"]?0['\"]?/i",$sql),$schema->name().' must default closed');}
$repository=new DeliveryFlagMemoryRepository();$service=new DeliveryFlagService($repository);
foreach([['store','10'],['product','20'],['inventory','30']] as [$entity,$id]){$result=$service->update(['entity'=>$entity,'id'=>$id,'expected'=>'0','enabled'=>'1']);$assert($result['changed']===true,$entity.' enable');$result=$service->update(['entity'=>$entity,'id'=>$id,'expected'=>'1','enabled'=>'0']);$assert($result['changed']===true,$entity.' disable');}
foreach([
    ['entity'=>'store','id'=>['10'],'expected'=>'0','enabled'=>'1'],
    ['entity'=>'store','id'=>'010','expected'=>'0','enabled'=>'1'],
    ['entity'=>'store','id'=>'10','expected'=>'yes','enabled'=>'1'],
    ['entity'=>'store','id'=>'10','expected'=>'0','enabled'=>'1','extra'=>'x'],
] as $payload){try{$service->update($payload);throw new RuntimeException('invalid payload accepted');}catch(InvalidArgumentException){$assert(true,'invalid rejected');}}
try{$service->update(['entity'=>'store','id'=>'999','expected'=>'0','enabled'=>'1']);throw new RuntimeException('missing accepted');}catch(RecordNotFoundException){$assert(true,'missing rejected');}
$repository->rows['store'][10]=1;
try{$service->update(['entity'=>'store','id'=>'10','expected'=>'0','enabled'=>'1']);throw new RuntimeException('stale accepted');}catch(ConflictException){$assert(true,'stale rejected');}
$admin=file_get_contents(dirname(__DIR__,2).'/app/Modules/Checkout/Admin/DeliveryFlagSettingsPage.php');
$assert(is_string($admin)&&str_contains($admin,"REQUEST_METHOD")&&str_contains($admin,"'POST'"),'POST guard');
$assert(str_contains($admin,"current_user_can('manage_options')"),'capability guard');
$assert(str_contains($admin,"check_admin_referer('veciahorra_delivery_flag_save')"),'nonce guard');
$feesAdmin=file_get_contents(dirname(__DIR__,2).'/app/Modules/Checkout/Admin/CheckoutFeeSettingsPage.php');
$assert(is_string($feesAdmin)&&str_contains($feesAdmin,'REQUEST_METHOD')&&str_contains($feesAdmin,"'POST'"),'fee settings POST guard');
$assert(str_contains($feesAdmin,"current_user_can('manage_options')")&&str_contains($feesAdmin,"check_admin_referer('veciahorra_checkout_fees_save')"),'fee settings authorization');

echo "PASS checkout-delivery-flags-contract assertions={$assertions}\n";
