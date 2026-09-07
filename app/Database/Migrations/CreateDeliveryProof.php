<?php
declare(strict_types=1);
namespace VeciAhorra\Database\Migrations;
use VeciAhorra\Core\Config;
use VeciAhorra\Exceptions\PersistenceException;

final class CreateDeliveryProof
{
    public function up(): void
    {
        global $wpdb;
        $p=$wpdb->prefix.Config::TABLE_PREFIX;
        $charset=$wpdb->get_charset_collate();
        $definitions=[
            'delivery_otps'=>"delivery_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                generation_context CHAR(64) NOT NULL, code_hash CHAR(64) NOT NULL,
                picked_up_version BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL,
                expires_at DATETIME NOT NULL, attempts INT UNSIGNED NOT NULL DEFAULT 0, consumed_at DATETIME NULL",
            'delivery_evidence'=>"id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                delivery_id BIGINT UNSIGNED NOT NULL, courier_id BIGINT UNSIGNED NOT NULL,
                actor_user_id BIGINT UNSIGNED NOT NULL, expected_version BIGINT UNSIGNED NOT NULL,
                storage_key VARCHAR(68) NOT NULL, sha256 CHAR(64) NOT NULL,
                status VARCHAR(20) NOT NULL, recipient_visible TINYINT UNSIGNED NOT NULL,
                consented_at DATETIME NULL, created_at DATETIME NOT NULL, completed_at DATETIME NULL,
                UNIQUE KEY evidence_delivery_unique (delivery_id), UNIQUE KEY evidence_storage_unique (storage_key)",
        ];
        foreach($definitions as $name=>$definition){
            if($wpdb->query("CREATE TABLE IF NOT EXISTS {$p}{$name} ({$definition}) ENGINE=InnoDB {$charset}")===false)throw new PersistenceException('delivery_proof_schema_failed');
            $status=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name=%s',$p.$name),ARRAY_A);
            if(($status['Engine']??'')!=='InnoDB')throw new PersistenceException('delivery_proof_requires_innodb');
            // Existing incompatible tables must not silently satisfy an additive migration.
            foreach(explode(',',preg_replace('/UNIQUE KEY[^\n]+/','',$definition)) as $column){
                $field=strtok(trim($column),' ');
                if(!$field)continue;
                if(!$wpdb->get_row("SHOW COLUMNS FROM {$p}{$name} LIKE '{$field}'",ARRAY_A))throw new PersistenceException('delivery_proof_schema_incompatible');
            }
            $expected=$name==='delivery_otps'?['PRIMARY'=>['delivery_id']]:['evidence_delivery_unique'=>['delivery_id'],'evidence_storage_unique'=>['storage_key']];
            foreach($expected as $key=>$columns){
                $index=$wpdb->get_results($wpdb->prepare("SHOW INDEX FROM {$p}{$name} WHERE Key_name=%s",$key),ARRAY_A);
                usort($index,static fn(array $a,array $b):int=>(int)$a['Seq_in_index']<=>(int)$b['Seq_in_index']);
                if(array_column($index,'Column_name')!==$columns||array_sum(array_column($index,'Non_unique'))!==0)throw new PersistenceException('delivery_proof_schema_incompatible');
            }
        }
    }
}
