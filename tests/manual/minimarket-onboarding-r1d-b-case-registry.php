<?php
declare(strict_types=1);

final readonly class R1dbCaseResult
{
    public function __construct(public string $id,public string $description,public int $assertions){}
}

final readonly class R1dbCaseResults
{
    /** @param list<R1dbCaseResult> $passed @param list<string> $failed */
    public function __construct(private array $passed,private array $failed,private array $failureDetails=[]){ }
    /** @return list<string> */ public function passedIds():array{return array_map(static fn(R1dbCaseResult $result):string=>$result->id,$this->passed);}
    /** @return list<string> */ public function failedIds():array{return $this->failed;}
    /** @return array<string,string> */ public function failureDetails():array{return $this->failureDetails;}
}

final class R1dbCaseRegistry
{
    /** @var array<string,array{description:string,setup:Closure,operation:Closure,assertions:Closure,cleanup:Closure}> */
    private array $cases=[];private array $executed=[];
    public function add(string $id,string $description,callable $setup,callable $operation,callable $assertions,callable $cleanup):void
    {
        if($id===''||isset($this->cases[$id]))throw new RuntimeException('r1db_case_duplicate');
        if($description==='')throw new RuntimeException('r1db_case_description_missing');
        $this->cases[$id]=['description'=>$description,'setup'=>Closure::fromCallable($setup),'operation'=>Closure::fromCallable($operation),'assertions'=>Closure::fromCallable($assertions),'cleanup'=>Closure::fromCallable($cleanup)];
    }
    public function skip(string $id,string $description,callable $setup,callable $operation,callable $assertions,callable $cleanup):void
    {
        $this->add($id,$description,$setup,$operation,$assertions,$cleanup);$this->executed[$id]=false;
    }
    public function runAll(int $expected):R1dbCaseResults
    {
        if(count($this->cases)!==$expected)throw new RuntimeException('r1db_case_total_mismatch');$passed=[];$failed=[];$failureDetails=[];
        foreach($this->cases as $id=>$case){if(($this->executed[$id]??null)===false)continue;$fixture=null;$outcome=null;$assertions=0;$setupRan=false;$operationRan=false;$cleanupRan=false;
            try{$fixture=($case['setup'])($id);$setupRan=true;$outcome=($case['operation'])($fixture,$id);$operationRan=true;$assert=function(bool $condition,string $message='r1db_case_assertion_failed')use(&$assertions):void{$assertions++;if(!$condition)throw new RuntimeException($message);};($case['assertions'])($fixture,$outcome,$assert,$id);}
            catch(Throwable $throwable){$failed[]=$id;$failureDetails[$id]=$throwable::class.':'.$throwable->getMessage();}
            finally{try{($case['cleanup'])($fixture,$id);$cleanupRan=true;}catch(Throwable $throwable){if(!in_array($id,$failed,true))$failed[]=$id;$failureDetails[$id]=$throwable::class.':cleanup:'.$throwable->getMessage();}}
            if(!$setupRan||!$operationRan||!$cleanupRan||$assertions<1){if(!in_array($id,$failed,true))$failed[]=$id;}else if(!in_array($id,$failed,true))$passed[]=new R1dbCaseResult($id,$case['description'],$assertions);$this->executed[$id]=true;
        }
        if(array_filter($this->executed,static fn(bool $executed):bool=>!$executed)!==[]||array_diff(array_keys($this->cases),array_keys($this->executed))!==[])throw new RuntimeException('r1db_case_not_executed');
        return new R1dbCaseResults($passed,array_values(array_unique($failed)),$failureDetails);
    }
}
