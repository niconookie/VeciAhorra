<?php
declare(strict_types=1);
require_once __DIR__.'/minimarket-onboarding-r1d-b-historical-fingerprint.php';
function r1dbFingerprintAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$types=['id'=>'bigint','value'=>'varchar(32)','binary_value'=>'varbinary(32)','nullable'=>'varchar(32)'];$primary=['id'];$rows=[['id'=>'1','value'=>'alpha','binary_value'=>"a\0b",'nullable'=>null],['id'=>'2','value'=>'beta','binary_value'=>"c\0d",'nullable'=>'']];$baseline=R1dbHistoricalFingerprint::fromRows($rows,$types,$primary);$cases=0;
$changed=$rows;$changed[0]['value']='changed';r1dbFingerprintAssert($baseline->fingerprint!==R1dbHistoricalFingerprint::fromRows($changed,$types,$primary)->fingerprint,'field mutation escaped');$cases++;
$replaced=$rows;$replaced[1]=['id'=>'3','value'=>'beta','binary_value'=>"c\0d",'nullable'=>''];r1dbFingerprintAssert($baseline->count===R1dbHistoricalFingerprint::fromRows($replaced,$types,$primary)->count&&$baseline->fingerprint!==R1dbHistoricalFingerprint::fromRows($replaced,$types,$primary)->fingerprint,'replacement escaped');$cases++;
$changedId=$rows;$changedId[0]['id']='9';r1dbFingerprintAssert($baseline->fingerprint!==R1dbHistoricalFingerprint::fromRows($changedId,$types,$primary)->fingerprint,'id mutation escaped');$cases++;
r1dbFingerprintAssert(hash_equals($baseline->fingerprint,R1dbHistoricalFingerprint::fromRows(array_reverse($rows),$types,$primary)->fingerprint),'row order changed fingerprint');$cases++;
$binary=$rows;$binary[0]['binary_value']="a\0c";r1dbFingerprintAssert($baseline->fingerprint!==R1dbHistoricalFingerprint::fromRows($binary,$types,$primary)->fingerprint,'binary mutation escaped');$cases++;
$null=$rows;$null[0]['nullable']='';r1dbFingerprintAssert($baseline->fingerprint!==R1dbHistoricalFingerprint::fromRows($null,$types,$primary)->fingerprint,'null mutation escaped');$cases++;
$ledger=new R1dbComparisonLedger(['one','two']);$ledger->record('one');try{$ledger->seal();throw new RuntimeException('omission accepted');}catch(RuntimeException $exception){r1dbFingerprintAssert($exception->getMessage()==='r1db_comparison_ledger_incomplete','wrong omission failure');}$ledger->record('two');r1dbFingerprintAssert($ledger->seal()===2,'ledger total');$cases+=2;
echo 'R1DB_HISTORICAL_FINGERPRINT_HELPER='.$cases.'/PASS'.PHP_EOL;
