<?php

declare(strict_types=1);

if (!extension_loaded('mysqli')) { throw new RuntimeException('mysqli unavailable'); }
foreach(['VA_TEST_DB_HOST','VA_TEST_DB_PORT','VA_TEST_DB_NAME','VA_TEST_DB_USER'] as $name){if(!is_string(getenv($name))||getenv($name)===''){throw new RuntimeException("{$name} is required");}}
defined('ARRAY_A')||define('ARRAY_A','ARRAY_A');
defined('ABSPATH')||define('ABSPATH',dirname(__DIR__,5).DIRECTORY_SEPARATOR);
require dirname(__DIR__,2).'/vendor/autoload.php';

final class IsolatedMigrationDb
{
    public string $prefix='va_iso_';public string $last_error='';public ?string $failPattern=null;private mysqli $connection;
    public function __construct(){
        $this->connection=new mysqli((string)getenv('VA_TEST_DB_HOST'),(string)getenv('VA_TEST_DB_USER'),(string)(getenv('VA_TEST_DB_PASSWORD')?:''),(string)getenv('VA_TEST_DB_NAME'),(int)getenv('VA_TEST_DB_PORT'));
        if($this->connection->connect_errno){throw new RuntimeException('isolated database connection failed');}
    }
    public function get_charset_collate():string{return'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';}
    public function prepare(string $sql,mixed ...$values):string{foreach($values as $value){$replacement=is_int($value)?(string)$value:"'".$this->connection->real_escape_string((string)$value)."'";$sql=preg_replace('/%[sd]/',$replacement,$sql,1)??$sql;}return$sql;}
    public function query(string $sql):int|false{$this->last_error='';if($this->failPattern!==null&&str_contains($sql,$this->failPattern)){$this->last_error='injected isolated failure';return false;}$result=$this->connection->query($sql);if($result===false){$this->last_error=$this->connection->error;return false;}return is_bool($result)?0:$this->connection->affected_rows;}
    public function get_var(string $sql):mixed{$rows=$this->rows($sql);return$rows===[]?null:array_values($rows[0])[0];}
    public function get_row(string $sql,string $format=ARRAY_A):?array{$rows=$this->rows($sql);return$rows[0]??null;}
    public function get_results(string $sql,string $format=ARRAY_A):array{return$this->rows($sql);}
    private function rows(string $sql):array{$this->last_error='';$result=$this->connection->query($sql);if($result===false){$this->last_error=$this->connection->error;return[];}$rows=[];while($row=$result->fetch_assoc()){$rows[]=$row;}$result->free();return$rows;}
}

$wpdb=new IsolatedMigrationDb();
function dbDelta(string $sql):void{global $wpdb;if($wpdb->query($sql)===false){throw new RuntimeException('dbDelta failed: '.$wpdb->last_error);}}
$assertions=0;$assert=static function(bool $condition,string $message)use(&$assertions):void{$assertions++;if(!$condition){throw new RuntimeException($message);}};
$migration=new VeciAhorra\Database\Migrations\AddCheckoutFeesFoundation();
$tables=['checkouts','checkout_refunds','stores','products','inventory','payments','orders'];
$drop=static function()use($wpdb,$tables):void{foreach($tables as $name){$wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}va_{$name}`");}};
$columns=static function(string $table)use($wpdb):array{return$wpdb->get_results("SHOW COLUMNS FROM `{$table}`",ARRAY_A);};

try{
    $drop();
    foreach([new VeciAhorra\Database\Schemas\CheckoutSchema(),new VeciAhorra\Database\Schemas\InventorySchema(),new VeciAhorra\Database\Tables\ProductsTable(),new VeciAhorra\Database\Tables\StoresTable()] as $schema){$builder=VeciAhorra\Database\Builder\TableBuilder::make($wpdb->prefix.'va_'.$schema->name());$schema->define($builder);dbDelta($builder->build($wpdb->get_charset_collate()));}
    $migration->up();$migration->up();
    foreach(['stores','products','inventory'] as $name){$column=array_values(array_filter($columns($wpdb->prefix.'va_'.$name),static fn(array $row):bool=>$row['Field']==='delivery_enabled'))[0]??null;$assert(($column['Default']??null)==='0',"fresh default {$name}");}

    $drop();
    dbDelta("CREATE TABLE `{$wpdb->prefix}va_checkouts` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,total_amount DECIMAL(10,2) NOT NULL,PRIMARY KEY(id)) ENGINE=InnoDB");
    foreach(['stores','products','inventory'] as $name){dbDelta("CREATE TABLE `{$wpdb->prefix}va_{$name}` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,PRIMARY KEY(id)) ENGINE=InnoDB");}
    $wpdb->failPattern='ADD COLUMN `platform_fee`';
    try{$migration->up();throw new RuntimeException('injected failure accepted');}catch(RuntimeException $exception){$assert(str_contains($exception->getMessage(),'checkout-platform_fee'),'failed stage identified');}
    $assert($wpdb->get_var("SHOW COLUMNS FROM `{$wpdb->prefix}va_checkouts` LIKE 'product_subtotal'")!==null,'completed stage preserved');
    $assert($wpdb->get_var("SHOW COLUMNS FROM `{$wpdb->prefix}va_checkouts` LIKE 'platform_fee'")===null,'failed stage not materialized');
    $wpdb->failPattern=null;$migration->up();
    $assert($wpdb->get_var("SHOW COLUMNS FROM `{$wpdb->prefix}va_inventory` LIKE 'delivery_enabled'")!==null,'resumed through final stage');

    $drop();
    dbDelta("CREATE TABLE `{$wpdb->prefix}va_checkouts` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,total_amount DECIMAL(10,2) NOT NULL,PRIMARY KEY(id)) ENGINE=InnoDB");
    foreach(['stores','products','inventory'] as $name){dbDelta("CREATE TABLE `{$wpdb->prefix}va_{$name}` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,delivery_enabled TINYINT(1) NOT NULL DEFAULT 1,PRIMARY KEY(id)) ENGINE=InnoDB");for($i=0;$i<17;$i++){$wpdb->query("INSERT INTO `{$wpdb->prefix}va_{$name}` (delivery_enabled) VALUES (1)");}}
    dbDelta("CREATE TABLE `{$wpdb->prefix}va_payments` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,amount DECIMAL(10,2) NOT NULL,PRIMARY KEY(id)) ENGINE=InnoDB");
    dbDelta("CREATE TABLE `{$wpdb->prefix}va_orders` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,total DECIMAL(10,2) NOT NULL,PRIMARY KEY(id)) ENGINE=InnoDB");
    for($i=1;$i<=17;$i++){$wpdb->query("INSERT INTO `{$wpdb->prefix}va_checkouts` (total_amount) VALUES ('8000.00')");$wpdb->query("INSERT INTO `{$wpdb->prefix}va_orders` (total) VALUES ('8000.00')");if($i<=6){$wpdb->query("INSERT INTO `{$wpdb->prefix}va_payments` (amount) VALUES ('8000.00')");}}
    $migration->up();
    $assert((int)$wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}va_checkouts`")===17,'17 checkouts preserved');
    $assert((int)$wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}va_payments`")===6,'6 payments preserved');
    $assert((int)$wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}va_orders`")===17,'17 orders preserved');
    $assert((int)$wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}va_checkouts` WHERE product_subtotal IS NOT NULL OR platform_fee<>0 OR delivery_fee<>0 OR fee_policy_version IS NOT NULL")===0,'historical checkout unchanged');
    foreach(['stores','products','inventory'] as $name){$assert((int)$wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}va_{$name}` WHERE delivery_enabled<>0")===0,"closed backfill {$name}");}
    $wpdb->query("UPDATE `{$wpdb->prefix}va_stores` SET delivery_enabled=1 WHERE id=1");$migration->up();
    $assert((int)$wpdb->get_var("SELECT delivery_enabled FROM `{$wpdb->prefix}va_stores` WHERE id=1")===1,'idempotent rerun preserves explicit opt-in');

    $wpdb->query("ALTER TABLE `{$wpdb->prefix}va_products` MODIFY delivery_enabled VARCHAR(4) NOT NULL DEFAULT '0'");
    try{$migration->up();throw new RuntimeException('incompatible structure accepted');}catch(RuntimeException $exception){$assert(str_contains($exception->getMessage(),'delivery-products'),'incompatible stage identified');}
}finally{$drop();}

foreach($tables as $name){$assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->prefix.'va_'.$name))===null,"cleanup {$name}");}
echo "PASS checkout-fees-migration-isolated fresh=1 upgrade=1 resume=1 idempotent=1 fixture=17/6/17 incompatible=1 cleanup=1 assertions={$assertions}\n";
