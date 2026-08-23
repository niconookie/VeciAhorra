<?php
declare(strict_types=1);
define('WP_INSTALLING', true);
require_once dirname(__DIR__, 5).'/wp-load.php';
require_once dirname(__DIR__, 2).'/vendor/autoload.php';
require_once __DIR__.'/minimarket-onboarding-r1d-c-a-case-registry.php';

use VeciAhorra\Modules\Minimarket\Onboarding\ActivationSession\ActivationSessionException;
use VeciAhorra\Modules\Minimarket\Onboarding\ActivationSession\StoreOnboardingActivationSessionRepository;
use VeciAhorra\Modules\Minimarket\Onboarding\RateLimit\DurableRateLimitException;
use VeciAhorra\Modules\Minimarket\Onboarding\RateLimit\DurableRateLimiter;

function q(bool $condition, string $reason): void { if (!$condition) throw new RuntimeException($reason); }
function ids(string $prefix, array $names): array { $out=[]; foreach($names as $name) $out[$prefix.$name]=$name; return $out; }

class Qa1Wpdb extends wpdb
{
    public string $fault=''; public string $target=''; public int $targetId=0;
    public int $deletes=0; public int $commits=0; public int $rollbacks=0; public int $starts=0;
    public int $forUpdates=0; public int $lockGets=0; public int $lockReleases=0;
    public function query($query){$sql=trim((string)$query);if(strcasecmp($sql,'START TRANSACTION')===0)$this->starts++;if(strcasecmp($sql,'ROLLBACK')===0)$this->rollbacks++;if(strcasecmp($sql,'COMMIT')===0){$this->commits++;if(str_starts_with($this->fault,'commit_')){$mode=substr($this->fault,7);if($mode==='applied')parent::query('COMMIT');else{parent::query('ROLLBACK');if($mode==='modified'){$field=str_contains($this->target,'rate_limit')?'hit_count=2':'updated_at=DATE_ADD(updated_at,INTERVAL 1 SECOND)';parent::query("UPDATE {$this->target} SET {$field} WHERE id=".$this->targetId);}}return false;}}return parent::query($query);}
    public function delete($table,$where,$where_format=null){$this->deletes++;if($this->fault==='delete_error'){$this->last_error='hostile driver detail';return false;}if($this->fault==='delete_zero')return 0;if($this->fault==='delete_multiple'){parent::delete($table,$where,$where_format);return 2;}return parent::delete($table,$where,$where_format);}
    public function get_row($query=null,$output=OBJECT,$y=0){if(is_string($query)&&str_contains($query,'FOR UPDATE')){$this->forUpdates++;if($this->fault==='before_delete'){$this->last_error='hostile pre-delete failure';return null;}}return parent::get_row($query,$output,$y);}
    public function get_var($query=null,$x=0,$y=0){if(is_string($query)&&str_contains($query,'GET_LOCK('))$this->lockGets++;if(is_string($query)&&str_contains($query,'RELEASE_LOCK('))$this->lockReleases++;return parent::get_var($query,$x,$y);}
}

class Qa1ProbeWpdb extends wpdb
{
    public mixed $inTransaction=0; public mixed $releaseValue=1;
    public bool $inSqlError=false,$lost=false,$readSqlError=false,$corrupt=false,$releaseSqlError=false,$releaseThrows=false,$closeThrows=false,$closeResult=true;
    public int $durableReads=0,$deletes=0,$closeCalls=0; public array $acquireSql=[],$releaseSql=[];
    public function get_var($query=null,$x=0,$y=0){$sql=(string)$query;if(str_contains($sql,'CONNECTION_ID()')&&$this->lost)throw new Error('hostile connection payload');if(str_contains($sql,'@@session.in_transaction')){if($this->inSqlError)$this->last_error='hostile sql state';return$this->inTransaction;}if(str_contains($sql,'GET_LOCK(')){$this->acquireSql[]=$sql;return 1;}if(str_contains($sql,'RELEASE_LOCK(')){$this->releaseSql[]=$sql;if($this->releaseThrows)throw new Error('hostile release payload');if($this->releaseSqlError)$this->last_error='hostile release sql';return$this->releaseValue;}return parent::get_var($query,$x,$y);}
    public function get_row($query=null,$output=OBJECT,$y=0){$sql=(string)$query;if(str_contains($sql,'store_onboarding_')){$this->durableReads++;if($this->readSqlError){$this->last_error='hostile read sql';return null;}$row=parent::get_row($query,$output,$y);if($this->corrupt&&is_array($row)){$row['id']='0';}return$row;}return parent::get_row($query,$output,$y);}
    public function delete($table,$where,$where_format=null){$this->deletes++;return parent::delete($table,$where,$where_format);}
    public function close(){$this->closeCalls++;if($this->closeThrows)throw new Error('hostile close payload');if(!$this->closeResult)return false;return parent::close();}
}

$database=(string)getenv('VA_R1DCA_DATABASE');q($database==='minimarket_r1dca_restore','database_guard');
$newDb=static function(string $class=Qa1Wpdb::class)use($database):wpdb{$db=new$class(DB_USER,DB_PASSWORD,$database,DB_HOST);$db->set_prefix('wp_');return$db;};
$rateTable='wp_va_store_onboarding_rate_limit_buckets';$sessionTable='wp_va_store_onboarding_activation_sessions';
$wipe=static function()use($newDb,$rateTable,$sessionTable):void{$db=$newDb();$db->query("DELETE FROM {$rateTable}");$db->query("DELETE FROM {$sessionTable}");};
$seedRate=static function(Qa1Wpdb $db,string $tag,bool $eligible=true):int{$old=$eligible?'2025-01-01 00:00:00':'2025-02-01 23:50:00';$expiry=$eligible?'2025-01-01 00:10:00':'2025-02-02 00:00:00';q($db->insert('wp_va_store_onboarding_rate_limit_buckets',['bucket_hash'=>hash('sha256','qa1-rate-'.$tag,true),'domain'=>'get_token','window_started_at'=>$old,'window_seconds'=>600,'hit_count'=>1,'expires_at'=>$expiry,'created_at'=>$old,'updated_at'=>$old])===1,'seed_rate');return(int)$db->insert_id;};
$seedSession=static function(Qa1Wpdb $db,string $tag,bool $eligible=true):int{$created=$eligible?'2025-01-01 00:00:00':'2025-02-01 23:45:00';$expiry=$eligible?'2025-01-01 00:15:00':'2025-02-02 00:00:00';q($db->insert('wp_va_store_onboarding_activation_sessions',['session_hash'=>hash('sha256','qa1-session-'.$tag,true),'application_id'=>1,'verification_id'=>1,'generation'=>1,'purpose'=>'minimarket_account_activation','state'=>'active','expires_at'=>$expiry,'consumed_at'=>null,'invalidated_at'=>null,'failed_attempts'=>0,'last_attempt_at'=>null,'created_at'=>$created,'updated_at'=>$created])===1,'seed_session');return(int)$db->insert_id;};
$invoke=static function(string $type,string $fault='',$probeConfig=[],bool $eligible=true,int $rows=1,bool $twice=false)use($newDb,$seedRate,$seedSession,$rateTable,$sessionTable):array{$db=$newDb();q($db instanceof Qa1Wpdb,'source_db');$ids=[];for($i=0;$i<$rows;$i++)$ids[]=$type==='rate'?$seedRate($db,$fault.$i.bin2hex(random_bytes(2)),$eligible):$seedSession($db,$fault.$i.bin2hex(random_bytes(2)),$eligible);$db->fault=$fault;$db->target=$type==='rate'?$rateTable:$sessionTable;$db->targetId=$ids[0]??0;$probe=null;$factory=function()use(&$probe,$newDb,$probeConfig){$probe=$newDb(Qa1ProbeWpdb::class);q($probe instanceof Qa1ProbeWpdb,'probe_db');foreach($probeConfig as$property=>$value)$probe->$property=$value;return$probe;};$service=$type==='rate'?new DurableRateLimiter($db,$factory):new StoreOnboardingActivationSessionRepository($db,$factory);$values=[];$errors=[];for($i=0;$i<($twice?2:1);$i++){try{$values[]=$service->cleanup('2025-02-02 00:20:00',100);$errors[]=null;}catch(Throwable$e){$values[]=null;$errors[]=$e;}}return compact('db','probe','ids','values','errors');};
$safeError=static function(?Throwable $e,string $reason):bool{return$e!==null&&$e->getMessage()===$reason&&$e->getPrevious()===null&&!str_contains($e->getMessage(),'hostile');};

$names=['-01-NOMINAL','-02-INELIGIBLE','-03-ZERO-ROWS','-04-MULTIPLE-ROWS','-05-DELETE-ERROR','-06-ROLLBACK-BEFORE-DELETE','-07-COMMIT-APPLIED-FALSE','-08-COMMIT-NOT-APPLIED-FALSE','-09-ABSENT-AFTER-AMBIGUOUS','-10-PRIOR-SNAPSHOT-PRESENT','-11-ROLLED-OVER-SNAPSHOT','-12-CLEANUP-IDEMPOTENT'];
$bucket=new R1dcaCaseRegistry('R1DCA_BUCKET_CLEANUP',ids('BCL',$names));
$bucketConfigs=[['',[],true,1,false],['',[],false,1,false],['delete_zero',[],true,1,false],['delete_multiple',[],true,1,false],['delete_error',[],true,1,false],['before_delete',[],true,1,false],['commit_applied',[],true,1,false],['commit_not',[],true,1,false],['commit_applied',[],true,1,false],['commit_not',[],true,1,false],['commit_modified',[],true,1,false],['',[],true,1,true]];
foreach(array_keys(ids('BCL',$names))as$i=>$id)$bucket->run($id,fn()=>null,fn()=> $invoke('rate',...$bucketConfigs[$i]),function($_,$r,$e)use($i,$safeError,$rateTable){q($e===null,'bucket_harness_'.($e?->getMessage()??''));$db=$r['db'];$err=$r['errors'][0];$count=$r['values'][0];if(in_array($i,[2,3,4,5,10],true))q($safeError($err,$i===2||$i===3||$i===4?'rate_limit_cleanup_persistence_failed':($i===5?'rate_limit_read_failed':'rate_limit_cleanup_outcome_uncertain')),'bucket_closed_failure');else q($err===null,'bucket_unexpected_error');if($i===0)q($count===1&&$db->deletes===1&&$db->commits===1,'bucket_nominal');if($i===1)q($count===0&&$db->deletes===0,'bucket_ineligible');if($i===5)q($db->deletes===0&&$db->rollbacks>0,'bucket_before_delete');if(in_array($i,[6,8],true))q($count===1&&$db->deletes===1&&$r['probe']?->deletes===0,'bucket_applied');if(in_array($i,[7,9],true))q($count===0&&$db->deletes===1&&$r['probe']?->deletes===0,'bucket_not');if($i===10)q((int)$db->get_var("SELECT COUNT(*) FROM {$rateTable} WHERE id=".$r['ids'][0])===1,'bucket_rollover_preserved');if($i===11)q($r['values']===[1,0]&&$db->deletes===1,'bucket_idempotent');q($db->lockGets>0||$i===1,'bucket_lock');q($db->lockReleases>0||$i===1,'bucket_release');},fn()=> $wipe());
$bucketTotal=$bucket->seal();

$sessionNames=$names;$sessionNames[10]='-11-MODIFIED-SNAPSHOT';
$session=new R1dcaCaseRegistry('R1DCA_SESSION_CLEANUP',ids('SCL',$sessionNames));
$sessionConfigs=$bucketConfigs;
foreach(array_keys(ids('SCL',$sessionNames))as$i=>$id)$session->run($id,fn()=>null,fn()=> $invoke('session',...$sessionConfigs[$i]),function($_,$r,$e)use($i,$safeError,$sessionTable){q($e===null,'session_harness');$db=$r['db'];$err=$r['errors'][0];$count=$r['values'][0];if(in_array($i,[2,3,4,5,10],true))q($safeError($err,$i===2||$i===3||$i===4?'activation_session_cleanup_persistence_failed':($i===5?'activation_session_read_failed':'activation_session_cleanup_outcome_uncertain')),'session_closed_failure');else q($err===null,'session_unexpected_error');if($i===0)q($count===1&&$db->deletes===1&&$db->commits===1,'session_nominal');if($i===1)q($count===0&&$db->deletes===0,'session_ineligible');if($i===5)q($db->deletes===0&&$db->rollbacks>0,'session_before_delete');if(in_array($i,[6,8],true))q($count===1&&$db->deletes===1&&$r['probe']?->deletes===0,'session_applied');if(in_array($i,[7,9],true))q($count===0&&$db->deletes===1&&$r['probe']?->deletes===0,'session_not');if($i===10)q((int)$db->get_var("SELECT COUNT(*) FROM {$sessionTable} WHERE id=".$r['ids'][0])===1,'session_modified_preserved');if($i===11)q($r['values']===[1,0]&&$db->deletes===1,'session_idempotent');q($db->lockGets>0||$i===1,'session_lock');q($db->lockReleases>0||$i===1,'session_release');},fn()=> $wipe());
$sessionTotal=$session->seal();

$rcnIds=ids('RCN',['-01-INT-ZERO','-02-STRING-ZERO','-03-INT-ONE','-04-STRING-ONE','-05-NULL','-06-FALSE','-07-EMPTY-STRING','-08-NONCANONICAL-NUMERIC','-09-SQL-ERROR','-10-CONNECTION-LOST','-11-READ-SQL-ERROR','-12-HYDRATION-CORRUPT']);
$rcn=new R1dcaCaseRegistry('R1DCA_CLEANUP_RECONCILIATION_CONNECTION',$rcnIds);$rcnCfg=[['inTransaction'=>0],['inTransaction'=>'0'],['inTransaction'=>1],['inTransaction'=>'1'],['inTransaction'=>null],['inTransaction'=>false],['inTransaction'=>''],['inTransaction'=>'00'],['inSqlError'=>true],['lost'=>true],['readSqlError'=>true],['corrupt'=>true]];
foreach(array_keys($rcnIds)as$i=>$id)$rcn->run($id,fn()=>null,function()use($i,$invoke,$rcnCfg){$fault=$i===11?'commit_not':'commit_applied';$configs=$i===7?[['inTransaction'=>'00'],['inTransaction'=>' 0'],['inTransaction'=>0.0]]:[$rcnCfg[$i]];$runs=[];foreach($configs as$config){$runs[]=$invoke('rate',$fault,$config);$runs[]=$invoke('session',$fault,$config);}return$runs;},function($_,$runs,$e)use($i,$safeError){q($e===null,'rcn_harness');foreach($runs as$j=>$r){$error=$r['errors'][0];if($i<2)q($error===null&&$r['values'][0]===1&&$r['probe']->durableReads===1,'rcn_zero');else{$reason=$j%2===0?'rate_limit_cleanup_outcome_uncertain':'activation_session_cleanup_outcome_uncertain';q($safeError($error,$reason),'rcn_uncertain');if($i<=9)q(($r['probe']->durableReads??0)===0&&$r['probe']->closeCalls===1,'dirty_connection_read');}q($r['db']->deletes===1&&($r['probe']->deletes??0)===0,'rcn_second_delete');}},fn()=> $wipe());
$rcnTotal=$rcn->seal();

$rlsIds=ids('RLS',['-01-RELEASE-ONE','-02-RELEASE-STRING-ONE','-03-RELEASE-ZERO','-04-RELEASE-NULL','-05-RELEASE-FALSE','-06-RELEASE-SQL-ERROR','-07-RELEASE-THROWABLE','-08-MULTIPLE-LOCKS-INVERSE','-09-CLOSE-AFTER-SAFE-CLEANUP','-10-CLOSE-BEFORE-CLEANUP-PROVEN']);
$rls=new R1dcaCaseRegistry('R1DCA_CLEANUP_RELEASE_CLOSE',$rlsIds);$rlsCfg=[['releaseValue'=>1],['releaseValue'=>'1'],['releaseValue'=>0],['releaseValue'=>null],['releaseValue'=>false],['releaseSqlError'=>true],['releaseThrows'=>true],['releaseValue'=>1],['closeResult'=>false],['inTransaction'=>1,'closeResult'=>false]];
foreach(array_keys($rlsIds)as$i=>$id)$rls->run($id,fn()=>null,function()use($i,$invoke,$rlsCfg){$types=$i===7?['session']:['rate','session'];$out=[];foreach($types as$type)$out[]=$invoke($type,'commit_applied',$rlsCfg[$i]);return$out;},function($_,$runs,$e)use($i,$safeError){q($e===null,'rls_harness');foreach($runs as$j=>$r){$type=count($runs)===1?'session':($j===0?'rate':'session');$error=$r['errors'][0];if(in_array($i,[0,1,7,8],true))q($error===null&&$r['values'][0]===1,'release_safe');else{$reason=$type==='rate'?'rate_limit_cleanup_outcome_uncertain':'activation_session_cleanup_outcome_uncertain';q($safeError($error,$reason),'release_uncertain');}q($r['probe']->closeCalls===1,'close_attempted');if($i===7){q(count($r['probe']->releaseSql)===2&&count($r['probe']->acquireSql)===2,'all_releases');$locks=static function(array$sql):array{$out=[];foreach($sql as$query){preg_match("/\\('([^']+)'/",$query,$m);$out[]=$m[1]??'';}return$out;};q($locks($r['probe']->releaseSql)===array_reverse($locks($r['probe']->acquireSql)),'inverse_release');}}},fn()=> $wipe());
$rlsTotal=$rls->seal();

$guardPass=0;
$expectGuard=static function(callable $test)use(&$guardPass):void{try{$test();}catch(Throwable){$guardPass++;return;}throw new RuntimeException('registry_guard_not_closed');};
$expectGuard(fn()=>(new R1dcaCaseRegistry('G',['A'=>'a','B'=>'b']))->seal());
$g=new R1dcaCaseRegistry('G',['A'=>'a']);$g->run('A',fn()=>null,fn()=>null,fn()=>null,fn()=>null);$expectGuard(fn()=>$g->run('A',fn()=>null,fn()=>null,fn()=>null,fn()=>null));
$expectGuard(fn()=>(new R1dcaCaseRegistry('G',['A'=>'a']))->run('X',fn()=>null,fn()=>null,fn()=>null,fn()=>null));
$expectGuard(function(){$registry=new R1dcaCaseRegistry('G',['A'=>'a']);$registry->seal();});
$expectGuard(fn()=>(new R1dcaCaseRegistry('G',['A'=>'a']))->run('A',fn()=>null,fn()=>null,fn()=>throw new RuntimeException('assert'),fn()=>null));
$expectGuard(fn()=>(new R1dcaCaseRegistry('G',['A'=>'a']))->run('A',fn()=>null,fn()=>null,fn()=>null,fn()=>throw new RuntimeException('cleanup')));
$expectGuard(function(){$registry=new R1dcaCaseRegistry('G',['A'=>'a']);$failed=false;try{$registry->run('A',fn()=>null,fn()=>null,fn()=>throw new RuntimeException('assert'),fn()=>null);}catch(Throwable){$failed=true;}q($failed,'failed_case_not_observed');$registry->seal();});
$expectGuard(function()use($bucketTotal,$sessionTotal,$rcnTotal,$rlsTotal){q($bucketTotal+$sessionTotal+$rcnTotal+$rlsTotal===45,'literal_total_mismatch');});
q($guardPass===8,'registry_guard_count');echo'R1DCA_QA1_REGISTRY_GUARDS='.$guardPass.'/PASS'.PHP_EOL;
$qa1=$bucketTotal+$sessionTotal+$rcnTotal+$rlsTotal;q($qa1===count($bucketConfigs)+count($sessionConfigs)+count($rcnCfg)+count($rlsCfg),'qa1_ledger');echo'R1DCA_QA1_CASES='.$qa1.'/PASS'.PHP_EOL;
$wipe();$final=$newDb();q((int)$final->get_var("SELECT COUNT(*) FROM {$rateTable}")===0&&(int)$final->get_var("SELECT COUNT(*) FROM {$sessionTable}")===0,'qa1_residual');
