<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Fulfillment\Completion\Contracts\FulfillmentCompletionAttemptProcessorInterface;
use VeciAhorra\Modules\Fulfillment\Completion\Contracts\FulfillmentCompletionReadAuthorityInterface;
use VeciAhorra\Modules\Fulfillment\Completion\DTO\FulfillmentCompletionResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExecutionContext;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryProcessingFailure;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryStage;
use VeciAhorra\Modules\Orders\Services\DurableRetryFulfillmentProcessor;

final class FulfillmentAttemptDouble implements FulfillmentCompletionAttemptProcessorInterface
{
    public array $calls = [], $queue = [];
    public function process(int $businessCompletionId, string $owner, int $leaseSeconds = 600): FulfillmentCompletionResult
    {
        $this->calls[] = func_get_args(); $next = array_shift($this->queue);
        if ($next instanceof Throwable) { throw $next; } return $next;
    }
}
final class FulfillmentReadDouble implements FulfillmentCompletionReadAuthorityInterface
{
    public array $calls = [], $queue = [];
    public function findByBusinessCompletion(int $businessCompletionId): ?array
    {
        $this->calls[] = $businessCompletionId; $next = array_shift($this->queue);
        if ($next instanceof Throwable) { throw $next; } return $next;
    }
}
$assertions=0;
$assert=static function(bool $value,string $message)use(&$assertions):void{++$assertions;if(!$value){throw new RuntimeException($message);}};
$context=static fn(string $stage=DurableRetryStage::FULFILLMENT_COMPLETION,?int $completion=null,int $previous=0)=>
    new DurableRetryExecutionContext(70,$stage,80,$completion,1,$previous,$previous+1,'2030-01-01 00:01:00');
$attempt=static fn(string $status,string $reason)=>new FulfillmentCompletionResult($status,$reason,80,90);
$row=static fn(string $status,mixed $count=1,?string $reason=null,mixed $id=90,mixed $business=80)=>[
    'id'=>$id,'business_completion_id'=>$business,'completion_status'=>$status,
    'attempt_count'=>$count,'last_result_code'=>$reason,
    'completed_at'=>$status==='completed'?'2030-01-01 00:02:00':null,
];
$run=static function(FulfillmentCompletionResult|Throwable $outcome,array|Throwable|null $authority,?DurableRetryExecutionContext $ctx=null)use($context):array{
    $attempts=new FulfillmentAttemptDouble();$attempts->queue[]=$outcome;
    $reads=new FulfillmentReadDouble();$reads->queue[]=$authority;
    $processor=new DurableRetryFulfillmentProcessor($attempts,$reads);
    return[$processor->process($ctx??$context()),$attempts,$reads,$processor];
};
[$success,$attempts,$reads,$processor]=$run($attempt(FulfillmentCompletionResult::COMPLETED,'pickup_fulfillment_completed'),$row('completed',1,'pickup_fulfillment_completed'));
$assert($processor->stage()===DurableRetryStage::FULFILLMENT_COMPLETION,'stage');
$assert($success->succeededProcessing(),'pickup success');
$assert(count($attempts->calls)===1&&count($reads->calls)===1,'one attempt/read');
$assert(preg_match('/^worker_[a-f0-9]{32}$/D',$attempts->calls[0][1])===1,'owner');
$assert($attempts->calls[0][0]===80&&$attempts->calls[0][2]===600,'subject and lease');
foreach([$context(DurableRetryStage::DELIVERY_COMPLETION),$context(DurableRetryStage::FULFILLMENT_COMPLETION,null,5)]as$invalid){
    [$result,$a,$r]=$run($attempt(FulfillmentCompletionResult::COMPLETED,'pickup_fulfillment_completed'),$row('completed',1,'pickup_fulfillment_completed'),$invalid);
    $assert($result->classification()===DurableRetryProcessingFailure::OUTCOME_UNCERTAIN,'invalid uncertain');
    $assert($a->calls===[]&&$r->calls===[],'invalid zero calls');
}
foreach([
    [null,'pickup_fulfillment_completed',FulfillmentCompletionResult::ALREADY_COMPLETED],
    [90,'pickup_fulfillment_completed',FulfillmentCompletionResult::COMPLETED],
    [90,'delivery_fulfillment_completed',FulfillmentCompletionResult::COMPLETED],
]as[$completion,$reason,$status]){
    [$result]=$run($attempt($status,$reason),$row('completed',1,$reason),$context(DurableRetryStage::FULFILLMENT_COMPLETION,$completion));
    $assert($result->succeededProcessing(),"{$reason} succeeds");
}
foreach(['delivery_completion_not_ready','lease_lost','unexpected_failure']as$reason){
    [$result]=$run($attempt($reason==='lease_lost'?FulfillmentCompletionResult::LEASE_LOST:FulfillmentCompletionResult::RETRYABLE,$reason),$row('retryable',1,$reason));
    $assert($result->classification()===DurableRetryProcessingFailure::RETRYABLE_FAILURE,"{$reason} retryable");
}
foreach([
    ['permanent_failure','business_completion_not_completed',FulfillmentCompletionResult::PERMANENT_FAILURE],
    ['permanent_failure','order_snapshot_invalid',FulfillmentCompletionResult::PERMANENT_FAILURE],
    ['manual_review','fulfillment_snapshot_invalid',FulfillmentCompletionResult::MANUAL_REVIEW],
    ['manual_review','delivery_completion_failed',FulfillmentCompletionResult::MANUAL_REVIEW],
    ['manual_review','delivery_completion_conflict',FulfillmentCompletionResult::MANUAL_REVIEW],
    ['manual_review','delivery_set_conflict',FulfillmentCompletionResult::MANUAL_REVIEW],
]as[$state,$reason,$status]){
    [$result]=$run($attempt($status,$reason),$row($state,1,$reason));
    $assert($result->classification()===DurableRetryProcessingFailure::TERMINAL_FAILURE,"{$reason} terminal");
}
foreach([
    [$attempt(FulfillmentCompletionResult::COMPLETED,'pickup_fulfillment_completed'),$row('pending',1,null),1,'success unconfirmed'],
    [$attempt(FulfillmentCompletionResult::RETRYABLE,'lease_unavailable'),$row('processing',1,null),1,'busy'],
    [$attempt('malformed','pickup_fulfillment_completed'),$row('completed',1,'pickup_fulfillment_completed'),1,'malformed'],
    [$attempt(FulfillmentCompletionResult::RETRYABLE,'unexpected_failure'),null,null,'absent'],
    [$attempt(FulfillmentCompletionResult::RETRYABLE,'unexpected_failure'),$row('retryable',0,'unexpected_failure'),null,'invalid count'],
    [$attempt(FulfillmentCompletionResult::RETRYABLE,'unexpected_failure'),$row('retryable',2,'unexpected_failure'),2,'contradictory count'],
    [$attempt(FulfillmentCompletionResult::COMPLETED,'pickup_fulfillment_completed'),$row('completed',1,'pickup_fulfillment_completed',90,81),1,'identity'],
]as[$outcome,$authority,$count,$label]){
    [$result]=$run($outcome,$authority);$assert($result->classification()===DurableRetryProcessingFailure::OUTCOME_UNCERTAIN,"{$label} uncertain");
    $assert($result->confirmedAttemptNumber()===$count,"{$label} count");
}
[$lost]=$run(new PersistenceException('private SQL'),$row('completed',1,'delivery_fulfillment_completed'));
$assert($lost->succeededProcessing(),'lost response recovered');
[$infra,$a,$r]=$run(new PersistenceException('private SQL'),$row('processing',1,null));
$assert($infra->classification()===DurableRetryProcessingFailure::OUTCOME_UNCERTAIN,'infra uncertain');
$assert(count($a->calls)===1&&count($r->calls)===1,'infra budget');
[$readFailure]=$run($attempt(FulfillmentCompletionResult::RETRYABLE,'unexpected_failure'),new PersistenceException('private SQL'));
$assert($readFailure->confirmedAttemptNumber()===null,'read failure nullable');
$a=new FulfillmentAttemptDouble();$a->queue[]=new LogicException('defect');$r=new FulfillmentReadDouble();
$p=new DurableRetryFulfillmentProcessor($a,$r);
try{$p->process($context());$assert(false,'logic must propagate');}catch(LogicException $e){$assert($e->getMessage()==='defect','logic propagated');}
$assert($r->calls===[],'logic no reread');
echo "durable retry fulfillment processor: {$assertions} assertions\n";
