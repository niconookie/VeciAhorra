<?php

declare(strict_types=1);

require_once dirname(__DIR__,2).'/vendor/autoload.php';

use VeciAhorra\Modules\Fulfillment\Completion\Contracts\FulfillmentCompletionAttemptProcessorInterface;
use VeciAhorra\Modules\Fulfillment\Completion\Contracts\FulfillmentCompletionReadAuthorityInterface;
use VeciAhorra\Modules\Fulfillment\Completion\DTO\FulfillmentCompletionResult;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryExternalScheduleCoordinatorInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryScheduleRepositoryInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\{DurableRetryCoordinationResult,DurableRetryExecutionResult,DurableRetryNextAttemptDecision,DurableRetryNextGenerationPersistenceResult,DurableRetryPersistenceResult,DurableRetryProcessingPolicy,DurableRetryScheduleSnapshot};
use VeciAhorra\Modules\Orders\Services\{DurableRetryExecutor,DurableRetryFulfillmentProcessor};

final class FIAttempt implements FulfillmentCompletionAttemptProcessorInterface{
 public int $calls=0;public FulfillmentCompletionResult $result;
 public function process(int $businessCompletionId,string $owner,int $leaseSeconds=600):FulfillmentCompletionResult{++$this->calls;return $this->result;}
}
final class FIRead implements FulfillmentCompletionReadAuthorityInterface{
 public int $calls=0;public ?array $row;
 public function findByBusinessCompletion(int $businessCompletionId):?array{++$this->calls;return $this->row;}
}
final class FIRepo implements DurableRetryScheduleRepositoryInterface{
 public array $reads=[],$transitions=[],$successions=[],$readQueue=[],$transitionQueue=[],$successionQueue=[];
 public function create(array $initialFields):DurableRetryPersistenceResult{throw new LogicException();}
 public function findById(int $id):DurableRetryPersistenceResult{$this->reads[]=$id;return array_shift($this->readQueue);}
 public function findByIdentity(string $stage,int $subjectId,int $generation):DurableRetryPersistenceResult{throw new LogicException();}
 public function associateScheduledAction(int $id,int $expectedVersion,int $scheduledActionId,string $dispatchedAt,string $updatedAt):DurableRetryPersistenceResult{throw new LogicException();}
 public function transition(DurableRetryScheduleSnapshot $expected,DurableRetryScheduleSnapshot $target):DurableRetryPersistenceResult{$this->transitions[]=[$expected,$target];return array_shift($this->transitionQueue);}
 public function supersedeAndCreateNextGeneration(DurableRetryScheduleSnapshot $claimed,DurableRetryNextAttemptDecision $decision,string $at):DurableRetryNextGenerationPersistenceResult{$this->successions[]=[$claimed,$decision,$at];return array_shift($this->successionQueue);}
}
final class FICoordinator implements DurableRetryExternalScheduleCoordinatorInterface{
 public array $calls=[];public function coordinate(int $scheduleId,int $generation):DurableRetryCoordinationResult{$this->calls[]=[$scheduleId,$generation];return new DurableRetryCoordinationResult(DurableRetryCoordinationResult::SYNCHRONIZED_NEW,$scheduleId,$generation,901);}
}
$assertions=0;$assert=static function(bool$v,string$m)use(&$assertions):void{++$assertions;if(!$v){throw new RuntimeException($m);}};
$snapshot=static function(string$status,int$attempt=0,int$generation=1,int$id=70):DurableRetryScheduleSnapshot{
 $terminal=in_array($status,['consumed','failed','orphaned','superseded'],true);
 return DurableRetryScheduleSnapshot::fromArray(['id'=>$id,'public_id'=>str_repeat($id===70?'a':'c',64),'stage'=>'fulfillment_completion','subject_id'=>80,'completion_id'=>90,'generation'=>$generation,'attempt_number'=>$attempt,'scheduled_for'=>'2030-01-01 00:01:00','scheduled_action_id'=>$status==='dispatching'?null:900,'dispatch_token_hash'=>str_repeat($id===70?'b':'d',64),'status'=>$status,'active_slot'=>$terminal?null:1,'version'=>$status==='scheduled'?2:($status==='dispatching'?1:3),'reason_code'=>match($status){'consumed'=>'retry_consumed','failed'=>'processing_terminal_failure','orphaned'=>'processing_outcome_uncertain','superseded'=>'superseded_generation',default=>'retryable_failure'},'dispatched_at'=>$status==='dispatching'?null:'2030-01-01 00:00:30','claimed_at'=>in_array($status,['claimed','consumed','failed','orphaned','superseded'],true)?'2030-01-01 00:01:00':null,'consumed_at'=>$status==='consumed'?'2030-01-01 00:02:00':null,'terminal_at'=>$terminal?'2030-01-01 00:02:00':null,'created_at'=>'2030-01-01 00:00:00','updated_at'=>$terminal?'2030-01-01 00:02:00':'2030-01-01 00:00:30']);
};
$persist=static fn(string$c,?DurableRetryScheduleSnapshot$s=null)=>new DurableRetryPersistenceResult($c,$s);
$run=static function(int$previous,FulfillmentCompletionResult$outcome,?array$row,string$close,string$reason,?DurableRetryNextGenerationPersistenceResult$next=null)use($snapshot,$persist):array{
 $scheduled=$snapshot('scheduled',$previous);$claimed=DurableRetryScheduleSnapshot::fromArray(array_replace($scheduled->toArray(),['status'=>'claimed','version'=>3,'claimed_at'=>'2030-01-01 00:01:00','updated_at'=>'2030-01-01 00:01:00']));
 $closed=DurableRetryScheduleSnapshot::fromArray(array_replace($claimed->toArray(),['status'=>$close,'active_slot'=>null,'version'=>4,'reason_code'=>$reason,'terminal_at'=>'2030-01-01 00:02:00','updated_at'=>'2030-01-01 00:02:00','consumed_at'=>$close==='consumed'?'2030-01-01 00:02:00':null]));
 $a=new FIAttempt();$a->result=$outcome;$r=new FIRead();$r->row=$row;$repo=new FIRepo();$repo->readQueue[]=$persist(DurableRetryPersistenceResult::EXISTING_COMPATIBLE,$scheduled);$repo->transitionQueue[]=$persist(DurableRetryPersistenceResult::APPLIED,$claimed);
 if($next){$repo->successionQueue[]=$next;}else{$repo->transitionQueue[]=$persist(DurableRetryPersistenceResult::APPLIED,$closed);}
 $c=new FICoordinator();$times=['2030-01-01 00:01:00','2030-01-01 00:02:00'];$clock=static function()use(&$times){return array_shift($times);};
 $executor=new DurableRetryExecutor($repo,new DurableRetryProcessingPolicy(),$c,new DurableRetryFulfillmentProcessor($a,$r),$clock(...));
 return[$executor->execute('veciahorra_durable_retry_fulfillment_completion',70,1),$repo,$a,$r,$c,$executor];
};
$row=static fn(string$s,int$a,?string$r)=>['id'=>90,'business_completion_id'=>80,'completion_status'=>$s,'attempt_count'=>$a,'last_result_code'=>$r,'completed_at'=>$s==='completed'?'2030-01-01 00:02:00':null];
[$ok,$repo,$a,$r,,$executor]=$run(0,new FulfillmentCompletionResult('completed','pickup_fulfillment_completed',80,90),$row('completed',1,'pickup_fulfillment_completed'),'consumed','retry_consumed');
$assert($ok->code()===DurableRetryExecutionResult::PROCESSED,'consumed');$assert($a->calls===1&&$r->calls===1,'budget');
$repo->readQueue[]=$persist(DurableRetryPersistenceResult::EXISTING_COMPATIBLE,$repo->transitions[1][1]);$assert($executor->execute('veciahorra_durable_retry_fulfillment_completion',70,1)->code()===DurableRetryExecutionResult::ALREADY_COMPLETED,'replay');$assert($a->calls===1,'replay no call');
$next=new DurableRetryNextGenerationPersistenceResult(DurableRetryNextGenerationPersistenceResult::CREATED,$snapshot('superseded',0),$snapshot('dispatching',1,2,71));
[$retry,$repo,$a,$r,$c]=$run(0,new FulfillmentCompletionResult('retryable','unexpected_failure',80,90),$row('retryable',1,'unexpected_failure'),'superseded','superseded_generation',$next);
$assert($retry->code()===DurableRetryExecutionResult::RETRY_SCHEDULED,'retry');$assert($c->calls===[[71,2]],'coordination');
[$terminal]=$run(0,new FulfillmentCompletionResult('permanent_failure','business_completion_not_completed',80,90),$row('permanent_failure',1,'business_completion_not_completed'),'failed','processing_terminal_failure');$assert($terminal->code()===DurableRetryExecutionResult::TERMINAL_FAILURE,'terminal');
[$uncertain]=$run(0,new FulfillmentCompletionResult('retryable','lease_unavailable',80,90),null,'orphaned','processing_outcome_uncertain');$assert($uncertain->code()===DurableRetryExecutionResult::OUTCOME_UNCERTAIN,'nullable uncertain');
[$contract,$repo]=$run(0,new FulfillmentCompletionResult('retryable','unexpected_failure',80,90),$row('retryable',2,'unexpected_failure'),'orphaned','processing_outcome_uncertain');$assert($contract->code()===DurableRetryExecutionResult::PROCESSING_CONTRACT_ERROR,'counter contract');$assert(count($repo->transitions)===1,'remains claimed');
echo "durable retry fulfillment executor integration: {$assertions} assertions\n";
