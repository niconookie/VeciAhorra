<?php
declare(strict_types=1);

final class R1dbBarrier
{
    private const PHASES=['worker_ready','target_reached','coordinator_release','operation_finished'];
    public function __construct(private string $directory,private string $executionId,private string $scenarioId,private string $challenge,private string $operationId)
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/',$executionId)||!preg_match('/\A[a-f0-9]{32}\z/',$challenge)||!preg_match('/\ACONC-(?:0[1-9]|10)\z/',$scenarioId)||!preg_match('/\A[a-z_]+\z/',$operationId))throw new RuntimeException('r1db_barrier_identity_invalid');
        if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new RuntimeException('r1db_barrier_directory_failed');
    }
    public function signal(string $participant,string $phase,array $closed=[]):void
    {
        $this->validate($participant,$phase);$payload=['execution_id'=>$this->executionId,'scenario_id'=>$this->scenarioId,'participant'=>$participant,'phase'=>$phase,'pid'=>getmypid(),'challenge'=>$this->challenge,'operation_id'=>$this->operationId]+$closed;
        $path=$this->path($participant,$phase);$temporary=$path.'.'.getmypid().'.tmp';if(file_put_contents($temporary,json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),LOCK_EX)===false||!rename($temporary,$path))throw new RuntimeException('r1db_barrier_signal_failed');
    }
    public function await(string $participant,string $phase,int $timeoutMs=10000):array
    {
        $this->validate($participant,$phase);$deadline=microtime(true)+$timeoutMs/1000;
        do{$path=$this->path($participant,$phase);if(is_file($path)){$payload=json_decode((string)file_get_contents($path),true,16,JSON_THROW_ON_ERROR);if(($payload['execution_id']??null)!==$this->executionId||($payload['scenario_id']??null)!==$this->scenarioId||($payload['participant']??null)!==$participant||($payload['phase']??null)!==$phase||($payload['challenge']??null)!==$this->challenge||($payload['operation_id']??null)!==$this->operationId||!is_int($payload['pid']??null)||$payload['pid']<1)throw new RuntimeException('r1db_barrier_message_invalid');return $payload;}usleep(10000);}while(microtime(true)<$deadline);
        throw new RuntimeException('r1db_barrier_timeout');
    }
    public function cleanup():void
    {
        foreach(self::PHASES as $phase)foreach(['a','b','coordinator'] as $participant){$path=$this->path($participant,$phase);if(is_file($path)&&!unlink($path))throw new RuntimeException('r1db_barrier_cleanup_failed');}
        if(is_dir($this->directory)&&count(scandir($this->directory)?:[])===2&&!rmdir($this->directory))throw new RuntimeException('r1db_barrier_cleanup_failed');
    }
    private function path(string $participant,string $phase):string{return $this->directory.DIRECTORY_SEPARATOR.$this->executionId.'-'.$this->scenarioId.'-'.$participant.'-'.$phase.'.json';}
    private function validate(string $participant,string $phase):void{if(!in_array($participant,['a','b','coordinator'],true)||!in_array($phase,self::PHASES,true))throw new RuntimeException('r1db_barrier_phase_invalid');}
}
