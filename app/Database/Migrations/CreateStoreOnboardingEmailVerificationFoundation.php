<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Migrations;

use RuntimeException;
use VeciAhorra\Core\Config;
use VeciAhorra\Database\Tables\StoreOnboardingEmailVerificationsTable;

final class CreateStoreOnboardingEmailVerificationFoundation
{
    public function up(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $schema = new StoreOnboardingEmailVerificationsTable();
        $table = $wpdb->prefix . Config::TABLE_PREFIX . $schema->name();
        $wpdb->last_error = '';
        dbDelta($schema->sql($table, $wpdb->get_charset_collate()));
        if ((string) $wpdb->last_error !== '') throw new RuntimeException('r1da_schema_install_failed');
        $this->assertStructure();
    }

    public function assertStructure(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . Config::TABLE_PREFIX . 'store_onboarding_email_verifications';
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ($found !== $table || $wpdb->last_error !== '') throw new RuntimeException('r1da_schema_missing:table');
        $engine = $wpdb->get_var($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table
        ));
        if (strtoupper((string) $engine) !== 'INNODB' || $wpdb->last_error !== '') throw new RuntimeException('r1da_schema_invalid:engine');
        $rows = $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
        if (! is_array($rows) || $wpdb->last_error !== '') throw new RuntimeException('r1da_schema_inspection_failed:columns');
        $actual=[]; foreach($rows as $row) $actual[(string)$row['Field']]=$row;
        $expected = [
            'id'=>['bigint unsigned','NO',null,'auto_increment'], 'application_id'=>['bigint unsigned','NO',null,''],
            'purpose'=>['varchar(32)','NO',null,''], 'generation'=>['int unsigned','NO','1',''],
            'candidate_user_id'=>['bigint unsigned','YES',null,''], 'attached_user_id'=>['bigint unsigned','YES',null,''],
            'email_binding_hash'=>['binary(32)','NO',null,''], 'token_hash'=>['binary(32)','NO',null,''],
            'expires_at'=>['datetime','NO',null,''], 'consumed_at'=>['datetime','YES',null,''],
            'failed_attempts'=>['smallint unsigned','NO','0',''], 'resend_count'=>['smallint unsigned','NO','0',''],
            'last_sent_at'=>['datetime','YES',null,''], 'delivery_state'=>['varchar(32)','NO',null,''],
            'delivery_attempt_count'=>['smallint unsigned','NO','0',''], 'last_error_code'=>['varchar(64)','YES',null,''],
            'created_at'=>['datetime','NO',null,''], 'updated_at'=>['datetime','NO',null,''],
        ];
        if (array_keys($actual)!==array_keys($expected)) throw new RuntimeException('r1da_schema_invalid:columns');
        foreach($expected as $name=>[$type,$null,$default,$extra]) {
            $row=$actual[$name]; $normalized=preg_replace('/\(\d+\)/','',strtolower((string)$row['Type']));
            $expectedType=preg_replace('/\(\d+\)/','',strtolower($type));
            if ($normalized!==$expectedType || ($row['Null']??'')!==$null || ($row['Default']??null)!==$default
                || strtolower((string)($row['Extra']??''))!==$extra) throw new RuntimeException('r1da_schema_invalid:column.'.$name);
            if (str_contains($type,'(32)') && strtolower((string)$row['Type'])!==$type) throw new RuntimeException('r1da_schema_invalid:column.'.$name);
        }
        $expectedIndexes=[
            'PRIMARY'=>[['id'],true],
            'onboarding_email_verification_application_unique'=>[['application_id'],true],
            'onboarding_email_verification_token_unique'=>[['token_hash'],true],
            'onboarding_email_verification_expiry_index'=>[['expires_at','consumed_at'],false],
            'onboarding_email_verification_delivery_index'=>[['delivery_state','updated_at'],false],
            'onboarding_email_verification_candidate_user_index'=>[['candidate_user_id'],false],
            'onboarding_email_verification_attached_user_index'=>[['attached_user_id'],false],
        ];
        foreach($expectedIndexes as $name=>[$columns,$unique]) $this->assertIndex($table,$name,$columns,$unique);
        $all=$wpdb->get_results("SHOW INDEX FROM {$table}",ARRAY_A);
        if(!is_array($all)||$wpdb->last_error!=='')throw new RuntimeException('r1da_schema_inspection_failed:indexes');
        $names=array_values(array_unique(array_map(static fn(array $row):string=>(string)$row['Key_name'],$all)));sort($names);
        $expectedNames=array_keys($expectedIndexes);sort($expectedNames);
        if($names!==$expectedNames)throw new RuntimeException('r1da_schema_invalid:indexes');
    }

    private function assertIndex(string $table,string $name,array $columns,bool $unique): void
    {
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare("SHOW INDEX FROM {$table} WHERE Key_name=%s",$name),ARRAY_A);
        if(!is_array($rows)||$rows===[]||$wpdb->last_error!=='') throw new RuntimeException('r1da_schema_missing:index.'.$name);
        usort($rows,static fn(array $a,array $b):int=>(int)$a['Seq_in_index']<=>(int)$b['Seq_in_index']);
        if(count($rows)!==count($columns)||array_map(static fn(array $r):string=>(string)$r['Column_name'],$rows)!==$columns
            || array_map(static fn(array $r):int=>(int)$r['Seq_in_index'],$rows)!==range(1,count($columns))
            || array_reduce($rows,static fn(bool $ok,array $r):bool=>$ok&&(int)$r['Non_unique']===($unique?0:1),true)===false
            || array_reduce($rows,static fn(bool $ok,array $r):bool=>$ok&&($r['Sub_part']??null)===null,true)===false) {
            throw new RuntimeException('r1da_schema_invalid:index.'.$name);
        }
    }
}
