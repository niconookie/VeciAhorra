<?php
declare(strict_types=1);
require_once __DIR__.'/minimarket-onboarding-r1d-b-case-registry.php';
function r1dbRegistryReject(callable $operation,string $reason):void{try{$operation();throw new RuntimeException('registry_negative_not_rejected');}catch(Throwable $throwable){if($throwable->getMessage()==='registry_negative_not_rejected')throw $throwable;if($throwable instanceof RuntimeException&&$reason!==''&&!str_contains($throwable->getMessage(),$reason))throw $throwable;}}
$passed=[];$noop=static fn()=>null;$assertion=static function($fixture,$outcome,$assert):void{$assert(true);};
$registry=new R1dbCaseRegistry();$registry->add('ONE','one',$noop,$noop,$assertion,$noop);r1dbRegistryReject(fn()=>$registry->add('ONE','duplicate',$noop,$noop,$assertion,$noop),'duplicate');$passed[]='duplicate';
foreach([['setup',static fn()=>new R1dbCaseRegistry(),['X','x',null,$noop,$assertion,$noop]],['operation',static fn()=>new R1dbCaseRegistry(),['X','x',$noop,null,$assertion,$noop]],['assertion',static fn()=>new R1dbCaseRegistry(),['X','x',$noop,$noop,null,$noop]],['cleanup',static fn()=>new R1dbCaseRegistry(),['X','x',$noop,$noop,$assertion,null]]] as [$id,$factory,$arguments]){r1dbRegistryReject(static function()use($factory,$arguments):void{$candidate=$factory();$candidate->add(...$arguments);},'');$passed[]=$id;}
$omitted=new R1dbCaseRegistry();$omitted->skip('OMITTED','omitted',$noop,$noop,$assertion,$noop);r1dbRegistryReject(fn()=>$omitted->runAll(1),'not_executed');$passed[]='omitted';
$wrongTotal=new R1dbCaseRegistry();$wrongTotal->add('ONE','one',$noop,$noop,$assertion,$noop);r1dbRegistryReject(fn()=>$wrongTotal->runAll(2),'total_mismatch');$passed[]='expected_total';
if(count($passed)!==7)throw new RuntimeException('registry_negative_total');echo 'R1DB_CASE_REGISTRY='.count($passed).'/PASS ids='.implode(',',$passed).PHP_EOL;
