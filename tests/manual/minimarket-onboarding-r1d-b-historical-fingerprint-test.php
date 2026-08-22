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

$exactClassCases=0;$reason='r1db_historical_fingerprint_changed_surface';
r1dbExpectExactRuntimeException(static fn()=>throw new RuntimeException($reason,0),$reason,0);$exactClassCases++;
r1dbExpectHarnessReject(static fn()=>r1dbExpectExactRuntimeException(static fn()=>throw new class($reason) extends RuntimeException{},$reason),'exception_class_not_exact');$exactClassCases++;
r1dbExpectHarnessReject(static fn()=>r1dbExpectExactRuntimeException(static fn()=>throw new LogicException($reason),$reason),'exception_class_not_exact');$exactClassCases++;
foreach([new Error($reason),new TypeError($reason)] as $unexpected)r1dbExpectHarnessReject(static fn()=>r1dbExpectExactRuntimeException(static fn()=>throw $unexpected,$reason),'exception_class_not_exact');$exactClassCases++;
r1dbExpectHarnessReject(static fn()=>r1dbExpectExactRuntimeException(static fn()=>throw new RuntimeException('different_reason'),$reason),'exception_reason_not_exact');r1dbExpectHarnessReject(static fn()=>r1dbExpectExactRuntimeException(static fn()=>throw new RuntimeException($reason,9),$reason),'exception_code_not_exact');$exactClassCases++;
r1dbExpectHarnessReject(static fn()=>r1dbExpectExactRuntimeException(static fn()=>throw new RuntimeException($reason,0,new RuntimeException('cause')),$reason),'exception_previous_not_null');$exactClassCases++;
r1dbExpectHarnessReject(static fn()=>r1dbExpectExactRuntimeException(static fn()=>null,$reason),'exception_missing');$exactClassCases++;
r1dbExpectHarnessReject(static fn()=>r1dbRequireFailedComparison(static fn()=>null,new R1dbComparisonLedger(['one']),$reason),'exception_missing');$exactClassCases++;
r1dbFingerprintAssert($exactClassCases===8,'exact class case total');
echo 'R1DB_EXACT_LEDGER_EXCEPTION_CLASS='.$exactClassCases.'/PASS'.PHP_EOL;

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
