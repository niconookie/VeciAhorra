<?php
declare(strict_types=1);
require_once __DIR__.'/minimarket-onboarding-r1d-b-historical-fingerprint.php';

function r1dbFingerprintAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function r1dbExpectExactRuntimeException(callable $operation,string $reason,int $code=0):RuntimeException{$caught=null;try{$operation();}catch(Throwable $exception){$caught=$exception;}r1dbFingerprintAssert($caught!==null,'exception_missing');r1dbFingerprintAssert(get_class($caught)===RuntimeException::class,'exception_class_not_exact');r1dbFingerprintAssert($caught->getMessage()===$reason,'exception_reason_not_exact');r1dbFingerprintAssert($caught->getCode()===$code,'exception_code_not_exact');r1dbFingerprintAssert($caught->getPrevious()===null,'exception_previous_not_null');return $caught;}
function r1dbExpectHarnessReject(callable $operation,string $reason):void{r1dbExpectExactRuntimeException($operation,$reason);}
function r1dbFingerprintReject(callable $operation,string $reason):void{r1dbExpectExactRuntimeException($operation,$reason);}
function r1dbColumn(int $ordinal,string $name,string $declared,string $key='',bool $nullable=false,?string $collation=null,mixed $default=null):array{return R1dbHistoricalFingerprint::schemaColumn(['Field'=>$name,'Type'=>$declared,'Collation'=>$collation,'Null'=>$nullable?'YES':'NO','Key'=>$key,'Default'=>$default,'Extra'=>''],$ordinal);}
function r1dbSchema(string $idType='bigint',string $valueType='varchar(64)',bool $nullable=true,?string $collation='utf8mb4_unicode_ci',mixed $default=null):array{return [r1dbColumn(1,'id',$idType,'PRI',false,str_contains($idType,'char')?$collation:null),r1dbColumn(2,'value',$valueType,'',$nullable,$collation,$default),r1dbColumn(3,'binary_value','varbinary(32)'),r1dbColumn(4,'nullable','varchar(32)','',true,$collation)];}
function r1dbRequireFailedComparison(callable $comparison,R1dbComparisonLedger $ledger,string $reason):void{r1dbExpectExactRuntimeException($comparison,$reason);r1dbFingerprintReject(fn()=>$ledger->seal(),'r1db_comparison_ledger_incomplete');}
function r1dbEmptyCatchCount(string $source):int{$tokens=token_get_all($source);$empty=0;$total=count($tokens);for($index=0;$index<$total;$index++){if(!is_array($tokens[$index])||$tokens[$index][0]!==T_CATCH)continue;while(++$index<$total&&$tokens[$index]!=='{'){}if($index>=$total)throw new RuntimeException('catch_body_missing');$depth=1;$meaningful=false;while(++$index<$total&&$depth>0){$token=$tokens[$index];if($token==='{'){$depth++;continue;}if($token==='}'){$depth--;continue;}if($depth>0&&(!is_array($token)||!in_array($token[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)))$meaningful=true;}if(!$meaningful)$empty++;}return $empty;}
final class R1dbPermissiveMutantThrowable extends RuntimeException{}
final class R1dbExactExceptionCaseRegistry{private array $expected=[];private array $executed=[];private array $passed=[];private array $failed=[];public function __construct(array $expected){foreach($expected as $id){r1dbFingerprintAssert(is_string($id)&&$id!=='','exact_case_id_invalid');r1dbFingerprintAssert(!isset($this->expected[$id]),'exact_case_id_duplicate');$this->expected[$id]=true;}}public function run(string $id,callable $case):void{r1dbFingerprintAssert(isset($this->expected[$id]),'exact_case_id_unexpected');r1dbFingerprintAssert(!isset($this->executed[$id]),'exact_case_id_already_executed');$this->executed[$id]=true;try{$case();$this->passed[$id]=true;}catch(Throwable $exception){$this->failed[$id]=get_class($exception);throw $exception;}}public function seal():array{r1dbFingerprintAssert($this->failed===[],'exact_case_failed');r1dbFingerprintAssert(array_keys($this->executed)===array_keys($this->expected),'exact_case_not_executed');r1dbFingerprintAssert(array_keys($this->passed)===array_keys($this->expected),'exact_case_not_passed');return array_keys($this->passed);}}
function r1dbRequirePermissiveEvidence(bool $observed,?string $class,bool $sentinelRejected,R1dbExactExceptionCaseRegistry $registry):void{r1dbFingerprintAssert($observed===true,'permissive_throwable_not_observed');r1dbFingerprintAssert($class===R1dbPermissiveMutantThrowable::class,'permissive_throwable_class_not_exact');r1dbFingerprintAssert($sentinelRejected===true,'permissive_sentinel_not_rejected');r1dbFingerprintReject(fn()=>$registry->seal(),'exact_case_failed');}

$primary=['id'];
$rows=[['id'=>'1','value'=>'alpha','binary_value'=>"a\0b",'nullable'=>null],['id'=>'2','value'=>'beta','binary_value'=>"c\0d",'nullable'=>'']];
$schema=r1dbSchema();
$baseline=R1dbHistoricalFingerprint::fromRows($rows,$schema,$primary,'fixture');
$mutationIds=[];
$different=function(string $id,array $candidateRows,array $candidateSchema,string $surface='fixture')use($baseline,$primary,&$mutationIds):void{r1dbFingerprintAssert(!isset($mutationIds[$id]),'duplicate mutation id');r1dbFingerprintAssert($baseline->fingerprint!==R1dbHistoricalFingerprint::fromRows($candidateRows,$candidateSchema,$primary,$surface)->fingerprint,'mutation escaped '.$id);$mutationIds[$id]=true;};
$candidate=$rows;$candidate[0]['value']='changed';$different('MUT-STRING',$candidate,$schema);
$candidate=$rows;$candidate[1]=['id'=>'3','value'=>'beta','binary_value'=>"c\0d",'nullable'=>''];$different('MUT-ROW-REPLACEMENT',$candidate,$schema);
$candidate=$rows;$candidate[0]['id']='9';$different('MUT-PRIMARY-KEY',$candidate,$schema);
$candidate=$rows;$candidate[0]['binary_value']="a\0c";$different('MUT-BINARY',$candidate,$schema);
$candidate=$rows;$candidate[0]['nullable']='';$different('MUT-NULL-EMPTY',$candidate,$schema);
$different('MUT-SQL-TYPE',$rows,r1dbSchema('varchar(64)'));
$different('MUT-COLUMN-DEFINITION',$rows,r1dbSchema('bigint','varchar(63)'));
$ordinal=$schema;$ordinal[1]['ordinal']=4;$ordinal[3]['ordinal']=2;$different('MUT-ORDINAL-PERMUTATION',$rows,$ordinal);
r1dbFingerprintAssert(count($mutationIds)===8,'mutation total');
echo 'R1DB_HISTORICAL_FINGERPRINT_MUTATION_IDS='.implode(',',array_keys($mutationIds)).PHP_EOL;
echo 'R1DB_HISTORICAL_FINGERPRINT_MUTATIONS='.count($mutationIds).'/PASS'.PHP_EOL;

$invariants=0;
r1dbFingerprintAssert(hash_equals($baseline->fingerprint,R1dbHistoricalFingerprint::fromRows(array_reverse($rows),$schema,$primary,'fixture')->fingerprint),'row order changed fingerprint');$invariants++;
$rekeyed=array_map(static fn(array $row):array=>['nullable'=>$row['nullable'],'binary_value'=>$row['binary_value'],'value'=>$row['value'],'id'=>$row['id']],$rows);r1dbFingerprintAssert(hash_equals($baseline->fingerprint,R1dbHistoricalFingerprint::fromRows($rekeyed,$schema,$primary,'fixture')->fingerprint),'key order changed fingerprint');$invariants++;
r1dbFingerprintAssert(hash_equals($baseline->fingerprint,R1dbHistoricalFingerprint::fromRows($rows,$schema,$primary,'fixture')->fingerprint),'identical input changed fingerprint');$invariants++;
foreach([[r1dbSchema('int'),'int'],[r1dbSchema('bigint','varchar(64)',false),'nullability'],[r1dbSchema('bigint','varchar(64)',true,'utf8mb4_bin'),'collation'],[r1dbSchema('bigint','varchar(64)',true,'utf8mb4_unicode_ci','x'),'default'],[r1dbSchema(),'surface']] as [$candidateSchema,$label]){$surface=$label==='surface'?'other':'fixture';r1dbFingerprintAssert($baseline->fingerprint!==R1dbHistoricalFingerprint::fromRows($rows,$candidateSchema,$primary,$surface)->fingerprint,$label.' invariant escaped');$invariants++;}
echo 'R1DB_HISTORICAL_FINGERPRINT_INVARIANTS='.$invariants.'/PASS'.PHP_EOL;

$binarySchemas=['varchar-normal'=>r1dbSchema('bigint','varchar(64)',true,'utf8mb4_unicode_ci'),'varchar-modifier'=>r1dbSchema('bigint','varchar(64) binary',true,'utf8mb4_unicode_ci'),'varchar-bin-collation'=>r1dbSchema('bigint','varchar(64)',true,'utf8mb4_bin'),'binary-storage'=>r1dbSchema('bigint','binary(64)',true,null),'varbinary-storage'=>r1dbSchema('bigint','varbinary(64)',true,null)];
$binaryExpected=[[false,false,false,'string'],[false,true,true,'string'],[false,true,true,'string'],[true,false,true,'binary'],[true,false,true,'binary']];
$binaryFingerprints=[];$binaryProofs=0;
foreach(array_values($binarySchemas) as $index=>$binarySchema){$column=$binarySchema[1];[$storage,$modifier,$binary,$kind]=$binaryExpected[$index];r1dbFingerprintAssert($column['binary_storage']===$storage&&$column['binary_modifier']===$modifier&&$column['binary']===$binary&&$column['semantic_kind']===$kind,'binary flags '.$index);$binaryFingerprints[]=R1dbHistoricalFingerprint::fromRows($rows,$binarySchema,$primary,'fixture')->fingerprint;$binaryProofs++;}
r1dbFingerprintAssert(count(array_unique($binaryFingerprints))===5,'binary fingerprints collided');
r1dbFingerprintAssert($binarySchemas['varchar-modifier'][1]['semantic_kind']==='string'&&$binarySchemas['binary-storage'][1]['semantic_kind']==='binary','binary modifier confused with storage');
echo 'R1DB_SQL_BINARY_SEMANTICS='.$binaryProofs.'/PASS'.PHP_EOL;

$exactCaseIds=['EXC-01-EXACT-RUNTIME','EXC-02-RUNTIME-SUBCLASS','EXC-03-LOGIC-EXCEPTION','EXC-04-ERROR','EXC-05-WRONG-MESSAGE','EXC-06-WRONG-CODE','EXC-07-PREVIOUS-NOT-NULL','EXC-08-NO-EXCEPTION'];
$reason='r1db_historical_fingerprint_changed_surface';$subclassRejected=false;$exactRegistry=new R1dbExactExceptionCaseRegistry($exactCaseIds);
$exactRegistry->run('EXC-01-EXACT-RUNTIME',static fn()=>r1dbExpectExactRuntimeException(static fn()=>throw new RuntimeException($reason,0),$reason,0));
$exactRegistry->run('EXC-02-RUNTIME-SUBCLASS',static function()use($reason,&$subclassRejected):void{r1dbExpectHarnessReject(static fn()=>r1dbExpectExactRuntimeException(static fn()=>throw new class($reason,0) extends RuntimeException{},$reason,0),'exception_class_not_exact');$subclassRejected=true;});
$exactRegistry->run('EXC-03-LOGIC-EXCEPTION',static fn()=>r1dbExpectHarnessReject(static fn()=>r1dbExpectExactRuntimeException(static fn()=>throw new LogicException($reason),$reason),'exception_class_not_exact'));
$exactRegistry->run('EXC-04-ERROR',static fn()=>r1dbExpectHarnessReject(static fn()=>r1dbExpectExactRuntimeException(static fn()=>throw new Error($reason),$reason),'exception_class_not_exact'));
$exactRegistry->run('EXC-05-WRONG-MESSAGE',static fn()=>r1dbExpectHarnessReject(static fn()=>r1dbExpectExactRuntimeException(static fn()=>throw new RuntimeException('different_reason',0),$reason,0),'exception_reason_not_exact'));
$exactRegistry->run('EXC-06-WRONG-CODE',static fn()=>r1dbExpectHarnessReject(static fn()=>r1dbExpectExactRuntimeException(static fn()=>throw new RuntimeException($reason,9),$reason,0),'exception_code_not_exact'));
$exactRegistry->run('EXC-07-PREVIOUS-NOT-NULL',static fn()=>r1dbExpectHarnessReject(static fn()=>r1dbExpectExactRuntimeException(static fn()=>throw new RuntimeException($reason,0,new RuntimeException('cause')),$reason,0),'exception_previous_not_null'));
$exactRegistry->run('EXC-08-NO-EXCEPTION',static fn()=>r1dbExpectHarnessReject(static fn()=>r1dbExpectExactRuntimeException(static fn()=>null,$reason,0),'exception_missing'));
$approvedExactCaseIds=$exactRegistry->seal();r1dbFingerprintAssert($subclassRejected,'runtime_subclass_not_rejected');
$antiFalsePass=0;$firstId=$exactCaseIds[0];
$omitted=new R1dbExactExceptionCaseRegistry($exactCaseIds);foreach(array_slice($exactCaseIds,0,7) as $id)$omitted->run($id,static fn()=>null);r1dbFingerprintReject(fn()=>$omitted->seal(),'exact_case_not_executed');$antiFalsePass++;
$duplicate=new R1dbExactExceptionCaseRegistry([$firstId]);$duplicate->run($firstId,static fn()=>null);r1dbFingerprintReject(fn()=>$duplicate->run($firstId,static fn()=>null),'exact_case_id_already_executed');$antiFalsePass++;
$unexpected=new R1dbExactExceptionCaseRegistry([$firstId]);r1dbFingerprintReject(fn()=>$unexpected->run('EXC-99-UNEXPECTED',static fn()=>null),'exact_case_id_unexpected');$antiFalsePass++;
$permissiveThrowableObserved=false;$permissiveThrowableClass=null;$permissiveSentinelRejected=false;$permissive=static function(callable $operation)use(&$permissiveThrowableObserved,&$permissiveThrowableClass):void{try{$operation();}catch(Throwable $exception){$permissiveThrowableObserved=true;$permissiveThrowableClass=get_class($exception);}};$subclassMutation=new R1dbExactExceptionCaseRegistry(['EXC-02-RUNTIME-SUBCLASS']);r1dbFingerprintReject(static function()use($subclassMutation,$permissive,$reason,&$permissiveThrowableObserved,&$permissiveThrowableClass):void{$subclassMutation->run('EXC-02-RUNTIME-SUBCLASS',static function()use($permissive,$reason,&$permissiveThrowableObserved,&$permissiveThrowableClass):void{$permissive(static fn()=>throw new R1dbPermissiveMutantThrowable($reason,0));r1dbFingerprintAssert($permissiveThrowableObserved===true,'permissive_throwable_not_observed');r1dbFingerprintAssert($permissiveThrowableClass===R1dbPermissiveMutantThrowable::class,'permissive_throwable_class_not_exact');r1dbFingerprintAssert(false,'subclass_mutation_accepted');});},'subclass_mutation_accepted');$permissiveSentinelRejected=true;r1dbRequirePermissiveEvidence($permissiveThrowableObserved,$permissiveThrowableClass,$permissiveSentinelRejected,$subclassMutation);$permissiveEvidence=1;$antiFalsePass++;
r1dbFingerprintReject(fn()=>r1dbRequirePermissiveEvidence(false,R1dbPermissiveMutantThrowable::class,true,$subclassMutation),'permissive_throwable_not_observed');r1dbFingerprintReject(fn()=>r1dbRequirePermissiveEvidence(true,null,true,$subclassMutation),'permissive_throwable_class_not_exact');r1dbFingerprintReject(fn()=>r1dbRequirePermissiveEvidence(true,RuntimeException::class,true,$subclassMutation),'permissive_throwable_class_not_exact');r1dbFingerprintReject(fn()=>r1dbRequirePermissiveEvidence(true,R1dbPermissiveMutantThrowable::class,false,$subclassMutation),'permissive_sentinel_not_rejected');$registeredMutation=new R1dbExactExceptionCaseRegistry(['EXC-02-RUNTIME-SUBCLASS']);$registeredMutation->run('EXC-02-RUNTIME-SUBCLASS',static fn()=>null);r1dbFingerprintReject(fn()=>r1dbRequirePermissiveEvidence(true,R1dbPermissiveMutantThrowable::class,true,$registeredMutation),'exception_missing');
$noExceptionMutation=new R1dbExactExceptionCaseRegistry(['EXC-08-NO-EXCEPTION']);r1dbFingerprintReject(fn()=>$noExceptionMutation->run('EXC-08-NO-EXCEPTION',static function()use($permissive):void{$permissive(static fn()=>null);r1dbFingerprintAssert(false,'no_exception_mutation_accepted');}),'no_exception_mutation_accepted');$antiFalsePass++;
$expectationMutation=new R1dbExactExceptionCaseRegistry([$firstId]);r1dbFingerprintReject(fn()=>$expectationMutation->run($firstId,static fn()=>r1dbExpectExactRuntimeException(static fn()=>throw new RuntimeException('actual'), 'mutated')),'exception_reason_not_exact');$antiFalsePass++;
r1dbFingerprintAssert($antiFalsePass===6,'anti_false_pass_total');
$permissiveEvidence=$permissiveEvidence??0;r1dbFingerprintAssert($permissiveEvidence===1,'permissive_evidence_total');
$emptyCatches=r1dbEmptyCatchCount((string)file_get_contents(__FILE__));r1dbFingerprintAssert($emptyCatches===0,'empty_catch_detected');
echo 'R1DB_EXACT_LEDGER_EXCEPTION_CASE_IDS='.implode(',',$approvedExactCaseIds).PHP_EOL;
echo 'R1DB_EXACT_LEDGER_EXCEPTION_CLASS='.count($approvedExactCaseIds).'/PASS'.PHP_EOL;
echo "UNEXPECTED_RUNTIME_SUBCLASS_REJECTED=PASS\nR1DB_EXACT_LEDGER_EXCEPTION_ANTI_FALSE_PASS={$antiFalsePass}/PASS\nR1DB_PERMISSIVE_MUTANT_THROWABLE_EVIDENCE={$permissiveEvidence}/PASS\nR1DB_EMPTY_CATCHES={$emptyCatches}/PASS\n";

$guards=0;
r1dbFingerprintReject(fn()=>new R1dbComparisonLedger(['one','one']),'r1db_comparison_ledger_invalid');$guards++;
$ledger=new R1dbComparisonLedger(['one']);$ledger->record('one');r1dbFingerprintReject(fn()=>$ledger->record('one'),'r1db_comparison_ledger_unexpected_one');$guards++;
$ledger=new R1dbComparisonLedger(['one']);r1dbFingerprintReject(fn()=>$ledger->record('other'),'r1db_comparison_ledger_unexpected_other');$guards++;
$ledger=new R1dbComparisonLedger(['one','two']);$ledger->record('one');r1dbFingerprintReject(fn()=>$ledger->seal(),'r1db_comparison_ledger_incomplete');$guards++;
$ledger=new R1dbComparisonLedger(['one']);r1dbRequireFailedComparison(fn()=>(new R1dbHistoricalBaseline(['surface'=>$baseline]))->assertAll(['surface'=>R1dbHistoricalFingerprint::fromRows($rows,r1dbSchema('int'),$primary,'fixture')]),$ledger,'r1db_historical_fingerprint_changed_surface');$guards++;
r1dbFingerprintReject(fn()=>r1dbRequireFailedComparison(static fn()=>null,new R1dbComparisonLedger(['one']),'r1db_historical_fingerprint_changed_surface'),'exception_missing');$guards++;
$ledger=new R1dbComparisonLedger(['one','two']);r1dbFingerprintReject(fn()=>$ledger->seal(),'r1db_comparison_ledger_incomplete');$guards++;
$ledger=new R1dbComparisonLedger(['one']);r1dbFingerprintReject(fn()=>$ledger->seal(),'r1db_comparison_ledger_incomplete');$ledger->record('one');r1dbFingerprintAssert($ledger->seal()===1,'completed ledger failed');$guards++;
echo 'R1DB_HISTORICAL_LEDGER_GUARDS='.$guards.'/PASS'.PHP_EOL;
