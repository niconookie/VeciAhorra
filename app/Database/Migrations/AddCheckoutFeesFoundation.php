<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Migrations;

use VeciAhorra\Core\Config;
use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Schemas\CheckoutRefundSchema;

/** Reanudable: cada DDL se ejecuta solo si falta y se verifica inmediatamente. */
final class AddCheckoutFeesFoundation
{
    private const CHECKOUT_COLUMNS = [
        'product_subtotal' => ['sql'=>'DECIMAL(10,2) NULL DEFAULT NULL','type'=>'decimal(10,2)','null'=>'YES','default'=>null],
        'platform_fee' => ['sql'=>"DECIMAL(10,2) NOT NULL DEFAULT '0.00'",'type'=>'decimal(10,2)','null'=>'NO','default'=>'0.00'],
        'delivery_fee' => ['sql'=>"DECIMAL(10,2) NOT NULL DEFAULT '0.00'",'type'=>'decimal(10,2)','null'=>'NO','default'=>'0.00'],
        'fee_policy_version' => ['sql'=>'VARCHAR(40) NULL DEFAULT NULL','type'=>'varchar(40)','null'=>'YES','default'=>null],
    ];

    public function up(): void
    {
        global $wpdb;
        $prefix=$wpdb->prefix.Config::TABLE_PREFIX;
        $this->requireTable($prefix.'checkouts','checkout-base');
        foreach(self::CHECKOUT_COLUMNS as $column=>$definition){$this->ensureColumn($prefix.'checkouts',$column,$definition,'checkout-'.$column);}
        $this->ensureRefundTable($prefix.'checkout_refunds');
        foreach(['stores','products','inventory'] as $name){$this->ensureClosedDeliveryFlag($prefix.$name,'delivery-'.$name);}
        $this->verifyPostconditions($prefix);
    }

    private function ensureRefundTable(string $table): void
    {
        global $wpdb;
        if(!$this->tableExists($table)){
            if(!function_exists('dbDelta')){require_once ABSPATH.'wp-admin/includes/upgrade.php';}
            $builder=TableBuilder::make($table);(new CheckoutRefundSchema())->define($builder);
            dbDelta($builder->build($wpdb->get_charset_collate()));
            if(!$this->tableExists($table)){throw new \RuntimeException("Migration stage refund-table failed: {$table} was not created.");}
        }
        $expected=[
            'checkout_id'=>['type'=>'bigint(20) unsigned','null'=>'NO','default'=>null],
            'idempotency_key'=>['type'=>'varchar(128)','null'=>'NO','default'=>null],
            'product_refund'=>['type'=>'decimal(10,2)','null'=>'NO','default'=>null],
            'platform_fee_refund'=>['type'=>'decimal(10,2)','null'=>'NO','default'=>'0.00'],
            'delivery_fee_refund'=>['type'=>'decimal(10,2)','null'=>'NO','default'=>'0.00'],
            'total_refund'=>['type'=>'decimal(10,2)','null'=>'NO','default'=>null],
            'status'=>['type'=>'varchar(30)','null'=>'NO','default'=>'recorded'],
            'created_at'=>['type'=>'datetime','null'=>'NO','default'=>null],
        ];
        foreach($expected as $column=>$definition){$this->assertColumn($table,$column,$definition,'refund-table');}
        $indexes=$wpdb->get_results("SHOW INDEX FROM `{$table}`",ARRAY_A);
        if(!is_array($indexes)||!in_array('checkout_refunds_key_unique',array_column($indexes,'Key_name'),true)){throw new \RuntimeException("Migration stage refund-table failed: unique index is missing on {$table}.");}
    }

    private function ensureColumn(string $table,string $column,array $definition,string $stage): void
    {
        global $wpdb;
        if($this->column($table,$column)===null&&$wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition['sql']}")===false){throw new \RuntimeException("Migration stage {$stage} failed adding {$table}.{$column}: {$wpdb->last_error}");}
        $this->assertColumn($table,$column,$definition,$stage);
    }

    private function ensureClosedDeliveryFlag(string $table,string $stage): void
    {
        global $wpdb;$this->requireTable($table,$stage);$current=$this->column($table,'delivery_enabled');
        if($current===null){
            if($wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `delivery_enabled` TINYINT(1) NOT NULL DEFAULT 0")===false){throw new \RuntimeException("Migration stage {$stage} failed adding {$table}.delivery_enabled: {$wpdb->last_error}");}
        }else{
            if(strtolower((string)$current['Type'])!=='tinyint(1)'||(string)$current['Null']!=='NO'){throw new \RuntimeException("Migration stage {$stage} rejected incompatible column {$table}.delivery_enabled.");}
            $default=(string)$current['Default'];
            if($default==='1'){
                if($wpdb->query("UPDATE `{$table}` SET `delivery_enabled`=0")===false||$wpdb->query("ALTER TABLE `{$table}` ALTER COLUMN `delivery_enabled` SET DEFAULT 0")===false){throw new \RuntimeException("Migration stage {$stage} failed closed backfill for {$table}: {$wpdb->last_error}");}
            }elseif($default!=='0'){throw new \RuntimeException("Migration stage {$stage} rejected incompatible default for {$table}.delivery_enabled.");}
        }
        $this->assertColumn($table,'delivery_enabled',['type'=>'tinyint(1)','null'=>'NO','default'=>'0'],$stage);
    }

    private function verifyPostconditions(string $prefix): void
    {
        foreach(self::CHECKOUT_COLUMNS as $column=>$definition){$this->assertColumn($prefix.'checkouts',$column,$definition,'postcondition');}
        foreach(['stores','products','inventory'] as $name){$this->assertColumn($prefix.$name,'delivery_enabled',['type'=>'tinyint(1)','null'=>'NO','default'=>'0'],'postcondition');}
        if(!$this->tableExists($prefix.'checkout_refunds')){throw new \RuntimeException('Migration postcondition failed: checkout_refunds is missing.');}
    }

    private function requireTable(string $table,string $stage): void{if(!$this->tableExists($table)){throw new \RuntimeException("Migration stage {$stage} failed: required table {$table} is missing.");}}
    private function tableExists(string $table): bool{global $wpdb;return(string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))===$table;}
    private function column(string $table,string $column): ?array{global $wpdb;$row=$wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s",$column),ARRAY_A);return is_array($row)?$row:null;}
    private function assertColumn(string $table,string $column,array $expected,string $stage): void
    {
        $actual=$this->column($table,$column);
        $actualType=$actual===null?'':strtolower((string)$actual['Type']);
        $typeCompatible=$actualType===$expected['type']
            || ($expected['type']==='bigint(20) unsigned'&&$actualType==='bigint unsigned');
        if($actual===null||!$typeCompatible||(string)$actual['Null']!==$expected['null']||$actual['Default']!==$expected['default']){throw new \RuntimeException("Migration stage {$stage} rejected incompatible column {$table}.{$column}.");}
    }
}
