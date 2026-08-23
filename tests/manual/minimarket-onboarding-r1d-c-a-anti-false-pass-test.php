<?php
declare(strict_types=1);
define('WP_INSTALLING',true);require_once dirname(__DIR__,5).'/wp-load.php';require_once __DIR__.'/minimarket-onboarding-r1d-c-a-case-registry.php';
function af(bool$c,string$r):void{if(!$c)throw new RuntimeException($r);}function exactReject(callable$f,string$r):void{$seen=null;try{$f();}catch(Throwable$e){$seen=$e;}af($seen!==null&&get_class($seen)===RuntimeException::class&&$seen->getMessage()===$r&&$seen->getCode()===0&&$seen->getPrevious()===null,'mutant_not_rejected_'.$r);}
$ids=['MUT-01-ABSENCE-AS-ERROR','MUT-02-SQL-ERROR-AS-ABSENCE','MUT-03-MODIFIED-AS-APPLIED','MUT-04-DIRTY-CONNECTION-READS','MUT-05-DELETE-REPEATED','MUT-06-RENEWED-ROW-DELETED','MUT-07-PARTIAL-SCHEMA-ACCEPTED','MUT-08-VERSION-ADVANCED','MUT-09-CONCURRENCY-CASE-OMITTED','MUT-10-REGISTERED-NOT-EXECUTED','MUT-11-LITERAL-TOTAL','MUT-12-RESIDUAL-FIXTURE-IGNORED'];$expected=[];foreach($ids as$id)$expected[$id]=$id;$registry=new R1dcaCaseRegistry('R1DCA_ANTI_FALSE_PASS',$expected);
$database=(string)getenv('VA_R1DCA_DATABASE');af($database==='minimarket_r1dca_restore','database_guard');$db=new wpdb(DB_USER,DB_PASSWORD,$database,DB_HOST);$db->set_prefix('wp_');$table='wp_va_store_onboarding_rate_limit_buckets';
$guards=[
fn($m)=>af($m['absence']==='absent','absence_misclassified'),
fn($m)=>af($m['sql_error']===false,'sql_error_hidden'),
fn($m)=>af($m['before']===$m['after'],'modified_as_applied'),
fn($m)=>af(in_array($m['transaction'],[0,'0'],true)&&$m['reads']===0,'dirty_connection_read'),
fn($m)=>af($m['deletes']===1,'delete_repeated'),
fn($m)=>af($m['before']===$m['after'],'renewed_row_deleted'),
fn($m)=>af($m['session']&&$m['rate'],'partial_schema_accepted'),
fn($m)=>af(!$m['failed']||$m['version']==='0.31.0','version_advanced'),
fn($m)=>$m->seal(),
fn($m)=>$m->seal(),
fn($m)=>af(count($m['ids'])===12&&count(array_unique($m['ids']))===12,'literal_total'),
fn($m)=>af($m['fixtures']===0&&$m['locks']===0,'residual_fixture'),
];
foreach($ids as$i=>$id)$registry->run($id,fn()=>null,function()use($i,$db,$table){if($i===0)return['absence'=>'error'];if($i===1)return['sql_error'=>true,'absence'=>true];if($i===2)return['before'=>'A','after'=>'B','applied'=>true];if($i===3)return['transaction'=>'00','reads'=>1];if($i===4)return['deletes'=>2];if($i===5)return['before'=>'renewed','after'=>null];if($i===6)return['session'=>true,'rate'=>false];if($i===7)return['failed'=>true,'version'=>'0.32.0'];if($i===8){$r=new R1dcaCaseRegistry('MUT',['A'=>'a','B'=>'b']);$r->run('A',fn()=>null,fn()=>null,fn()=>null,fn()=>null);return$r;}if($i===9)return new R1dcaCaseRegistry('MUT',['A'=>'a']);if($i===10)return['ids'=>array_fill(0,12,'PASS'),'literal'=>'12/PASS'];$db->insert($table,['bucket_hash'=>hash('sha256','qa4-residual',true),'domain'=>'get_token','window_started_at'=>'2026-01-01 00:00:00','window_seconds'=>600,'hit_count'=>1,'expires_at'=>'2026-01-01 00:10:00','created_at'=>'2026-01-01 00:00:00','updated_at'=>'2026-01-01 00:00:00']);return['fixtures'=>(int)$db->get_var("SELECT COUNT(*) FROM {$table}"),'locks'=>0];},function($_,$mutant,$error)use($i,$guards){af($error===null,'mutant_setup_failed');$reasons=['absence_misclassified','sql_error_hidden','modified_as_applied','dirty_connection_read','delete_repeated','renewed_row_deleted','partial_schema_accepted','version_advanced','r1dca_registry_incomplete','r1dca_registry_incomplete','literal_total','residual_fixture'];exactReject(fn()=>($guards[$i])($mutant),$reasons[$i]);},function()use($db,$table){$db->query("DELETE FROM {$table} WHERE domain='get_token'");});
$total=$registry->seal();af($total===count($ids),'anti_false_total');af((int)$db->get_var("SELECT COUNT(*) FROM {$table}")===0,'anti_false_cleanup');
