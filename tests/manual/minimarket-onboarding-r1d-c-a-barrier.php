<?php
declare(strict_types=1);

final class R1dcaBarrier
{
    private const MAX_FRAME=4096;
    public function __construct(private readonly string $directory,private readonly string $executionId,private readonly string $caseId,private readonly string $challenge){}
    public function publish(string $stage,string $worker,array $payload=[]):void{$path=$this->path($stage,$worker);if(is_file($path))throw new RuntimeException('r1dca_barrier_duplicate');$frame=['execution_id'=>$this->executionId,'case_id'=>$this->caseId,'stage'=>$stage,'worker'=>$worker,'pid'=>getmypid(),'time'=>microtime(true),'payload'=>$payload];$frame['mac']=hash_hmac('sha256',$this->canonical($frame),$this->challenge);$json=json_encode($frame,JSON_THROW_ON_ERROR);if(strlen($json)>self::MAX_FRAME)throw new RuntimeException('r1dca_barrier_frame_oversize');$temporary=$path.'.'.getmypid().'.tmp';if(file_put_contents($temporary,$json,LOCK_EX)!==strlen($json)||!rename($temporary,$path))throw new RuntimeException('r1dca_barrier_write_failed');}
    public function await(string $stage,string $worker,float $timeout=10.0):array{$path=$this->path($stage,$worker);$until=microtime(true)+$timeout;do{if(is_file($path)){$json=file_get_contents($path);if(!is_string($json)||strlen($json)>self::MAX_FRAME)throw new RuntimeException('r1dca_barrier_frame_invalid');$frame=json_decode($json,true,32,JSON_THROW_ON_ERROR);$mac=$frame['mac']??'';unset($frame['mac']);if(($frame['execution_id']??null)!==$this->executionId||($frame['case_id']??null)!==$this->caseId||($frame['stage']??null)!==$stage||($frame['worker']??null)!==$worker||!is_string($mac)||!hash_equals(hash_hmac('sha256',$this->canonical($frame),$this->challenge),$mac))throw new RuntimeException('r1dca_barrier_auth_failed');return$frame;}usleep(10000);}while(microtime(true)<$until);throw new RuntimeException('r1dca_barrier_timeout');}
    private function path(string$stage,string$worker):string{return$this->directory.DIRECTORY_SEPARATOR.preg_replace('/[^A-Za-z0-9_.-]/','_',$stage.'-'.$worker).'.json';}
    private function canonical(array$frame):string{ksort($frame);return json_encode($frame,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
}
