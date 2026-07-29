<?php

declare(strict_types=1);

$root=dirname(__DIR__,2);$path='app/Modules/Orders/Services/DurableRetryFulfillmentProcessor.php';
$source=file_get_contents($root.'/'.$path);$assertions=0;
$assert=static function(bool $v,string $m)use(&$assertions):void{++$assertions;if(!$v){throw new RuntimeException($m);}};
$assert(is_string($source),'processor exists');
foreach(['implements DurableRetryStageProcessorInterface','DurableRetryStage::FULFILLMENT_COMPLETION','FulfillmentCompletionAttemptProcessorInterface $attemptProcessor','FulfillmentCompletionReadAuthorityInterface $readAuthority',"'worker_' . bin2hex(random_bytes(16))",'$context->completionId() === null']as$required){$assert(str_contains($source,$required),"requires {$required}");}
$assert(substr_count($source,'$this->attemptProcessor->process(')===1,'one attempt site');
$assert(substr_count($source,'$this->readAuthority->findByBusinessCompletion(')===1,'one read site');
$assert(substr_count($source,'catch (PersistenceException)')===2,'recognized catches');
$assert(!str_contains($source,'catch (Throwable'),'no throwable catch');
foreach(['$wpdb','SELECT ','INSERT ','UPDATE ','DELETE ','error_log','as_schedule_','add_action','add_filter','do_action','wp_schedule_','actionscheduler_','sleep(','usleep(','foreach (','while (','for (','current_time(','wp_date(']as$forbidden){$assert(!str_contains($source,$forbidden),"forbids {$forbidden}");}
$tokens=token_get_all($source);$assert(array_filter($tokens,static fn($t)=>is_array($t)&&in_array($t[0],[T_FOR,T_FOREACH,T_WHILE,T_DO],true))===[],'zero loops');
$services=glob($root.'/app/Modules/Orders/Services/*.php')?:[];$matches=[];
foreach($services as$file){$s=file_get_contents($file);if(str_contains($s,'implements DurableRetryStageProcessorInterface')&&str_contains($s,'DurableRetryStage::FULFILLMENT_COMPLETION')){$matches[]=basename($file);}}
$assert($matches===['DurableRetryFulfillmentProcessor.php'],'single implementation');
$assert(str_contains(file_get_contents($root.'/app/Modules/Fulfillment/Completion/Service/FulfillmentCompletionProcessor.php'),'implements FulfillmentCompletionAttemptProcessorInterface'),'functional port');
$assert(str_contains(file_get_contents($root.'/app/Modules/Fulfillment/Completion/Repository/FulfillmentCompletionRepository.php'),'implements FulfillmentCompletionReadAuthorityInterface'),'read port');
echo "durable retry fulfillment processor infrastructure: {$assertions} assertions\n";
