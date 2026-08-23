<?php
declare(strict_types=1);
namespace VeciAhorra\Database\Migrations;
use RuntimeException;
use VeciAhorra\Core\Config;
use VeciAhorra\Database\Tables\StoreOnboardingActivationSessionsTable;
final class CreateStoreOnboardingActivationSessionFoundation
{
    private const COLUMNS=[
        'id'=>['bigint unsigned','NO',null,'auto_increment'],'session_hash'=>['binary(32)','NO',null,''],
        'application_id'=>['bigint unsigned','NO',null,''],'verification_id'=>['bigint unsigned','NO',null,''],
        'generation'=>['int unsigned','NO',null,''],'purpose'=>['varchar(32)','NO',null,''],
        'state'=>['varchar(16)','NO','active',''],'expires_at'=>['datetime','NO',null,''],
        'consumed_at'=>['datetime','YES',null,''],'invalidated_at'=>['datetime','YES',null,''],
        'failed_attempts'=>['smallint unsigned','NO','0',''],'last_attempt_at'=>['datetime','YES',null,''],
        'created_at'=>['datetime','NO',null,''],'updated_at'=>['datetime','NO',null,''],
    ];
    private const INDEXES=['PRIMARY'=>[['id'],true],'onboarding_activation_session_hash_unique'=>[['session_hash'],true],
        'onboarding_activation_session_verification_generation'=>[['verification_id','generation','state'],false],
        'onboarding_activation_session_application'=>[['application_id','state'],false],
        'onboarding_activation_session_expiry'=>[['state','expires_at'],false],
        'onboarding_activation_session_cleanup'=>[['updated_at','state'],false]];
    public function up():void{global $wpdb;require_once ABSPATH.'wp-admin/includes/upgrade.php';$s=new StoreOnboardingActivationSessionsTable();$wpdb->last_error='';$result=dbDelta($s->sql($this->table(),$wpdb->get_charset_collate()));if(!is_array($result)||(string)$wpdb->last_error!=='')throw new RuntimeException('r1dca_session_schema_install_failed');$this->assertStructure();}
    public function assertStructure():void{$this->assertTable(self::COLUMNS,self::INDEXES);}
    private function table():string{global $wpdb;return $wpdb->prefix.Config::TABLE_PREFIX.'store_onboarding_activation_sessions';}
    private function assertTable(array $expected,array $indexes):void{global $wpdb;$table=$this->table();$wpdb->last_error='';$meta=$wpdb->get_row($wpdb->prepare('SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table),ARRAY_A);if(!is_array($meta)||$wpdb->last_error!==''||strtoupper((string)$meta['ENGINE'])!=='INNODB')throw new RuntimeException('r1dca_session_schema_invalid:table');$this->assertCharset((string)$meta['TABLE_COLLATION']);$rows=$wpdb->get_results("SHOW COLUMNS FROM {$table}",ARRAY_A);if(!is_array($rows)||$wpdb->last_error!=='')throw new RuntimeException('r1dca_session_schema_inspection_failed');$actual=[];foreach($rows as $r)$actual[(string)$r['Field']]=$r;if(array_keys($actual)!==array_keys($expected))throw new RuntimeException('r1dca_session_schema_invalid:columns');foreach($expected as $name=>[$type,$null,$default,$extra]){$r=$actual[$name];if(!$this->type((string)$r['Type'],$type)||$r['Null']!==$null||($r['Default']??null)!==$default||strtolower((string)$r['Extra'])!==$extra)throw new RuntimeException('r1dca_session_schema_invalid:column.'.$name);}$all=$wpdb->get_results("SHOW INDEX FROM {$table}",ARRAY_A);if(!is_array($all)||$wpdb->last_error!=='')throw new RuntimeException('r1dca_session_schema_inspection_failed');$group=[];foreach($all as $r)$group[(string)$r['Key_name']][]=$r;if(array_diff_key($group,$indexes)!==[]||array_diff_key($indexes,$group)!==[])throw new RuntimeException('r1dca_session_schema_invalid:indexes');foreach($indexes as $name=>[$cols,$unique]){$rs=$group[$name];usort($rs,fn($a,$b)=>(int)$a['Seq_in_index']<=>(int)$b['Seq_in_index']);if(array_map(fn($r)=>(string)$r['Column_name'],$rs)!==$cols||array_map(fn($r)=>(int)$r['Seq_in_index'],$rs)!==range(1,count($cols)))throw new RuntimeException('r1dca_session_schema_invalid:index.'.$name);foreach($rs as $r)if((int)$r['Non_unique']!==($unique?0:1)||($r['Sub_part']??null)!==null)throw new RuntimeException('r1dca_session_schema_invalid:index.'.$name);}}
    private function assertCharset(string $collation):void{global $wpdb;$contract=(string)$wpdb->get_charset_collate();if(!preg_match('/CHARACTER SET\s+([^\s]+)/i',$contract,$c)||!preg_match('/COLLATE\s+([^\s]+)/i',$contract,$x))throw new RuntimeException('r1dca_session_schema_invalid:charset');$charset=$wpdb->get_var($wpdb->prepare('SELECT CHARACTER_SET_NAME FROM information_schema.COLLATIONS WHERE COLLATION_NAME=%s',$collation));if(strtolower((string)$charset)!==strtolower($c[1])||strtolower($collation)!==strtolower($x[1]))throw new RuntimeException('r1dca_session_schema_invalid:charset');}
    private function type(string $actual,string $expected):bool{$actual=strtolower($actual);if(str_ends_with($expected,' unsigned'))return preg_match('/^'.strtok($expected,' ').'(?:\(\d+\))? unsigned$/D',$actual)===1;return $actual===$expected;}
}
