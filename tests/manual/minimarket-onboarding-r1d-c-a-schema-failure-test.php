<?php
declare(strict_types=1);
define('WP_INSTALLING',true);
require_once dirname(__DIR__,5).'/wp-load.php';
require_once dirname(__DIR__,2).'/vendor/autoload.php';
require_once __DIR__.'/minimarket-onboarding-r1d-c-a-case-registry.php';

use VeciAhorra\Database\MigrationManager;
use VeciAhorra\Database\Migrations\CreateStoreOnboardingActivationSessionFoundation as SessionMigration;
use VeciAhorra\Database\Migrations\CreateStoreOnboardingRateLimitFoundation as RateMigration;

function sf(bool $condition,string $reason):void{if(!$condition)throw new RuntimeException($reason);}
final class SchemaFaultWpdb extends wpdb
{
    public string $fault='';public int $versionWrites=0;
    public function query($query){$sql=(string)$query;$session=str_contains($sql,'store_onboarding_activation_sessions');$rate=str_contains($sql,'store_onboarding_rate_limit_buckets');$create=str_starts_with(ltrim($sql),'CREATE TABLE');if($create&&(($session&&$this->fault==='session_create')||($rate&&$this->fault==='rate_create'))){$this->last_error='hostile driver create detail';return false;}if($create&&$rate&&$this->fault==='rate_last_error'){$result=parent::query($query);$this->last_error='hostile driver last error';return$result;}return parent::query($query);}
    public function update($table,$data,$where,$format=null,$whereFormat=null){if(is_array($data)&&($data['option_value']??null)==='0.32.0')$this->versionWrites++;return parent::update($table,$data,$where,$format,$whereFormat);}
}
$database=(string)getenv('VA_R1DCA_DATABASE');sf($database==='minimarket_r1dca_restore','database_guard');
$dbFor=static function(string$prefix)use($database):SchemaFaultWpdb{$db=new SchemaFaultWpdb(DB_USER,DB_PASSWORD,$database,DB_HOST);$db->set_prefix($prefix);return$db;};
$setup=static function(string$id)use($dbFor):array{global$wpdb;preg_match('/SCH-F(\d{2})-/',$id,$match);$prefix='q2f'.($match[1]??'xx').'_';$db=$dbFor($prefix);$wpdb=$db;$db->query("DROP TABLE IF EXISTS {$prefix}va_store_onboarding_activation_sessions");$db->query("DROP TABLE IF EXISTS {$prefix}va_store_onboarding_rate_limit_buckets");$db->query("DROP TABLE IF EXISTS {$prefix}options");sf($db->query("CREATE TABLE {$prefix}options LIKE wp_options")!==false,'options_clone');$db->insert($prefix.'options',['option_name'=>'veciahorra_db_version','option_value'=>'0.31.0','autoload'=>'yes']);wp_cache_flush();return['db'=>$db,'prefix'=>$prefix,'session'=>$prefix.'va_store_onboarding_activation_sessions','rate'=>$prefix.'va_store_onboarding_rate_limit_buckets','options'=>$prefix.'options'];};
$cleanup=static function(?array$c)use($dbFor):void{if(!is_array($c))return;$db=$dbFor($c['prefix']);foreach([$c['session'],$c['rate'],$c['options']]as$table)$db->query("DROP TABLE IF EXISTS {$table}");wp_cache_flush();};
$version=static fn(array$c):string=>(string)$c['db']->get_var("SELECT option_value FROM {$c['options']} WHERE option_name='veciahorra_db_version'");
$createSession=static fn()=>(new SessionMigration())->up();$createRate=static fn()=>(new RateMigration())->up();
$assertBoth=static function():void{(new SessionMigration())->assertStructure();(new RateMigration())->assertStructure();};
$install=static function():void{(new SessionMigration())->up();(new RateMigration())->up();MigrationManager::updateVersion();};
$closed=static function(?Throwable$e,array$allowed):bool{return$e!==null&&get_class($e)===RuntimeException::class&&in_array($e->getMessage(),$allowed,true)&&$e->getPrevious()===null&&!preg_match('/(?:hostile|CREATE|ALTER|wp_|q2|index\.|column\.)/i',$e->getMessage());};
$caseIds=['SCH-F01-SESSION-COLUMN-DRIFT','SCH-F02-SESSION-INDEX-DRIFT','SCH-F03-BUCKET-COLUMN-DRIFT','SCH-F04-BUCKET-INDEX-DRIFT','SCH-F05-ONLY-SESSION-TABLE','SCH-F06-ONLY-BUCKET-TABLE','SCH-F07-BOTH-TABLES-MISSING','SCH-F08-FIRST-DBDELTA-FAILS','SCH-F09-SECOND-DBDELTA-FAILS','SCH-F10-LAST-ERROR-WITH-PARTIAL-SCHEMA','SCH-F11-PREFIXED-INDEX','SCH-F12-EXTRA-COLUMN','SCH-F13-EXTRA-INDEX','SCH-F14-WRONG-COLUMN-ORDER','SCH-F15-WRONG-ENGINE','SCH-F16-WRONG-COLLATION','SCH-F17-VERSION-NOT-ADVANCED','SCH-F18-RECOVERY-FROM-PARTIAL'];$expected=[];foreach($caseIds as$id)$expected[$id]=substr($id,8);
$registry=new R1dcaCaseRegistry('R1DCA_SCHEMA_FAILURE_PATHS',$expected,'R1DCA_SCHEMA_FAILURE_CASE_IDS');
$mutations=[
0=>fn($c)=>$c['db']->query("ALTER TABLE {$c['session']} MODIFY failed_attempts int unsigned NOT NULL DEFAULT 0"),
1=>fn($c)=>$c['db']->query("ALTER TABLE {$c['session']} DROP INDEX onboarding_activation_session_application"),
2=>fn($c)=>$c['db']->query("ALTER TABLE {$c['rate']} MODIFY hit_count bigint unsigned NOT NULL DEFAULT 0"),
3=>fn($c)=>$c['db']->query("ALTER TABLE {$c['rate']} DROP INDEX onboarding_rate_limit_domain_window"),
10=>fn($c)=>$c['db']->query("ALTER TABLE {$c['session']} DROP INDEX onboarding_activation_session_hash_unique,ADD UNIQUE KEY onboarding_activation_session_hash_unique(session_hash(8))"),
11=>fn($c)=>$c['db']->query("ALTER TABLE {$c['session']} ADD unexpected_surface varchar(8) NULL"),
12=>fn($c)=>$c['db']->query("ALTER TABLE {$c['rate']} ADD KEY unexpected_surface(updated_at)"),
13=>fn($c)=>$c['db']->query("ALTER TABLE {$c['session']} MODIFY application_id bigint unsigned NOT NULL AFTER verification_id"),
14=>fn($c)=>$c['db']->query("ALTER TABLE {$c['rate']} ENGINE=MyISAM"),
15=>fn($c)=>$c['db']->query("ALTER TABLE {$c['session']} CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"),
];
foreach($caseIds as$i=>$id){
    $registry->run($id,fn()=>$setup($id),function($c)use($i,$mutations,$createSession,$createRate,$assertBoth,$install){
        if(in_array($i,[0,1,2,3,10,11,12,13,14,15],true)){$createSession();$createRate();sf(($mutations[$i])($c)!==false,'mutation_failed');$assertBoth();return null;}
        if($i===4){$createSession();$assertBoth();return null;}
        if($i===5){$createRate();$assertBoth();return null;}
        if(in_array($i,[6,7,16],true)){$c['db']->fault='session_create';$install();return null;}
        if($i===8){$createSession();$c['db']->fault='rate_create';$install();return null;}
        if($i===9){$createSession();$c['db']->fault='rate_last_error';$createRate();return null;}
        if($i===17){$createSession();$install();$install();$assertBoth();return null;}
        throw new RuntimeException('schema_case_unrouted');
    },function($c,$result,$error)use($i,$closed,$version,$assertBoth){
        if($i===17){sf($error===null,'recovery_failed');sf($version($c)==='0.32.0','recovery_version');sf($c['db']->versionWrites===1,'recovery_version_writes');$assertBoth();return;}
        $allowed=$i===9?['r1dca_rate_schema_install_failed']:($i===8?['r1dca_rate_schema_install_failed','r1dca_rate_schema_invalid']:($i===6||$i===7||$i===16?['r1dca_session_schema_install_failed','r1dca_session_schema_invalid']:['r1dca_session_schema_invalid','r1dca_rate_schema_invalid']));sf($closed($error,$allowed),'failure_not_closed_'.$i);sf($version($c)==='0.31.0','version_advanced_'.$i);$sessionExists=$c['db']->get_var($c['db']->prepare('SHOW TABLES LIKE %s',$c['session']))===$c['session'];$rateExists=$c['db']->get_var($c['db']->prepare('SHOW TABLES LIKE %s',$c['rate']))===$c['rate'];if($i===4)sf($sessionExists&&!$rateExists,'only_session');if(in_array($i,[5,8],true))sf(!$sessionExists||!$rateExists,'partial_not_detected');if($i===9)sf($sessionExists&&$rateExists,'last_error_structure');
    },$cleanup);
}
$total=$registry->seal();sf($total===count($caseIds),'schema_total');
$guards=0;$reject=static function(callable$f)use(&$guards):void{try{$f();}catch(Throwable){$guards++;return;}throw new RuntimeException('schema_guard_not_closed');};
$reject(fn()=>(new R1dcaCaseRegistry('SG',['A'=>'a']))->seal());
$g=new R1dcaCaseRegistry('SG',['A'=>'a']);$g->run('A',fn()=>null,fn()=>null,fn()=>null,fn()=>null);$reject(fn()=>$g->run('A',fn()=>null,fn()=>null,fn()=>null,fn()=>null));
$mutant=static function(callable$scenario)use($setup,$cleanup):void{$c=$setup('SCH-F90-GUARD');try{$scenario($c);}finally{$cleanup($c);}};
$reject(fn()=>$mutant(function($c)use($createSession,$assertBoth){$createSession();$caught=null;try{$assertBoth();}catch(Throwable$e){$caught=$e;}sf($caught===null,'partial_schema_accepted');}));
$reject(fn()=>$mutant(function($c)use($createSession,$createRate){$createSession();$c['db']->fault='rate_last_error';$caught=null;try{$createRate();}catch(Throwable$e){$caught=$e;}sf($caught===null,'last_error_ignored');}));
$reject(fn()=>$mutant(function($c)use($createSession,$createRate){$createSession();$createRate();$c['db']->query("ALTER TABLE {$c['session']} DROP INDEX onboarding_activation_session_hash_unique,ADD UNIQUE KEY onboarding_activation_session_hash_unique(session_hash(8))");$caught=null;try{(new SessionMigration())->assertStructure();}catch(Throwable$e){$caught=$e;}sf($caught===null,'prefixed_index_accepted');}));
$reject(fn()=>$mutant(function($c)use($createSession,$createRate){$createSession();$createRate();$c['db']->query("ALTER TABLE {$c['session']} ADD guard_extra int NULL");$caught=null;try{(new SessionMigration())->assertStructure();}catch(Throwable$e){$caught=$e;}sf($caught===null,'extra_column_ignored');}));
$reject(fn()=>$mutant(function($c)use($install,$version){$c['db']->fault='session_create';try{$install();}catch(Throwable$e){sf($e->getPrevious()===null,'failure_previous');}MigrationManager::updateVersion();sf($version($c)==='0.31.0','version_advanced');}));
$reject(fn()=>$mutant(function($c)use($createSession){$createSession();$session=$c['db']->get_var($c['db']->prepare('SHOW TABLES LIKE %s',$c['session']))===$c['session'];$rate=$c['db']->get_var($c['db']->prepare('SHOW TABLES LIKE %s',$c['rate']))===$c['rate'];sf($session&&$rate,'recovery_incomplete');}));
sf($guards===8,'schema_guard_count');echo'R1DCA_SCHEMA_FAILURE_GUARDS='.$guards.'/PASS'.PHP_EOL;
