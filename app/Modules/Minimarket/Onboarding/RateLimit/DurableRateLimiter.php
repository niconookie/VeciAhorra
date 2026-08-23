<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Minimarket\Onboarding\RateLimit;
use Throwable;
use VeciAhorra\Core\Config;
final class DurableRateLimiter
{
    public function __construct(private ?\wpdb $db=null,private $reconciliationFactory=null){}
    public function consume(DurableRateLimitRequest $request):DurableRateLimitDecision
    {
        $specs=$request->specifications;usort($specs,static fn($a,$b):int=>strcmp($a->bucketHash,$b->bucketHash));
        $locks=array_map(fn($s)=>$this->lockName($s->bucketHash),$specs);sort($locks,SORT_STRING);$db=$this->db();$held=[];
        try{
            foreach($locks as $lock){if((int)$db->get_var($db->prepare('SELECT GET_LOCK(%s,10)',$lock))!==1)throw new DurableRateLimitException('rate_limit_lock_failed');$held[]=$lock;}
            if($db->query('START TRANSACTION')===false)throw new DurableRateLimitException('rate_limit_transaction_failed');
            try{
                $projections=[];$retry=0;
                foreach($specs as $spec){
                    $bucket=$this->find($spec->bucketHash,true);
                    if($bucket!==null&&($bucket->domain!==$spec->domain||$bucket->windowSeconds!==$spec->windowSeconds))throw new DurableRateLimitException('rate_limit_bucket_corrupt');
                    if($bucket===null||$request->now>=$bucket->expiresAt){$projections[]=['spec'=>$spec,'bucket'=>$bucket,'count'=>1,'start'=>$request->now,'expires'=>DurableRateLimitBucket::plus($request->now,$spec->windowSeconds)];continue;}
                    $next=$bucket->hitCount+1;
                    if($next>$spec->limit){$remaining=$this->seconds($request->now,$bucket->expiresAt);$retry=(int)max($retry,min($spec->windowSeconds,max(1,$remaining)));}
                    $projections[]=['spec'=>$spec,'bucket'=>$bucket,'count'=>$next,'start'=>$bucket->windowStartedAt,'expires'=>$bucket->expiresAt];
                }
                if($retry>0){$db->query('ROLLBACK');return DurableRateLimitDecision::blocked($retry);}
                foreach($projections as $projection){
                    $spec=$projection['spec'];$bucket=$projection['bucket'];
                    if($bucket===null){$ok=$db->insert($this->table(),['bucket_hash'=>$spec->bucketHash,'domain'=>$spec->domain,'window_started_at'=>$projection['start'],'window_seconds'=>$spec->windowSeconds,'hit_count'=>1,'expires_at'=>$projection['expires'],'created_at'=>$request->now,'updated_at'=>$request->now]);if($ok!==1)throw new DurableRateLimitException('rate_limit_write_uncertain');}
                    else{$data=['window_started_at'=>$projection['start'],'window_seconds'=>$spec->windowSeconds,'hit_count'=>$projection['count'],'expires_at'=>$projection['expires'],'updated_at'=>$request->now];$changed=$db->update($this->table(),$data,['id'=>$bucket->id,'bucket_hash'=>$bucket->bucketHash,'domain'=>$bucket->domain,'window_started_at'=>$bucket->windowStartedAt,'hit_count'=>$bucket->hitCount,'updated_at'=>$bucket->updatedAt]);if($changed!==1)throw new DurableRateLimitException('rate_limit_write_uncertain');}
                }
                if($db->query('COMMIT')===false){foreach(array_reverse($held)as$lock)$db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)',$lock));$held=[];if($this->reconcileExpected($projections,$request->now))return DurableRateLimitDecision::allowed();throw new DurableRateLimitException('rate_limit_outcome_uncertain');}return DurableRateLimitDecision::allowed();
            }catch(Throwable $exception){$db->query('ROLLBACK');if($exception instanceof DurableRateLimitException)throw $exception;throw new DurableRateLimitException('rate_limit_outcome_uncertain');}
        }finally{foreach(array_reverse($held)as$lock)$db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    public function cleanup(string $now,int $limit=100):int
    {
        DurableRateLimitBucket::timestamp($now);if($limit<1||$limit>500)throw new DurableRateLimitException('rate_limit_cleanup_invalid');$cut=DurableRateLimitBucket::plus($now,-86400);$db=$this->db();
        $rows=$db->get_results($db->prepare("SELECT * FROM {$this->table()} WHERE expires_at<=%s ORDER BY id LIMIT %d",$cut,$limit),ARRAY_A);if(!is_array($rows)||$db->last_error!=='')throw new DurableRateLimitException('rate_limit_cleanup_failed');$deleted=0;
        foreach($rows as$row){$bucket=DurableRateLimitBucket::fromRow($row);$lock=$this->lockName($bucket->bucketHash);if((int)$db->get_var($db->prepare('SELECT GET_LOCK(%s,10)',$lock))!==1)throw new DurableRateLimitException('rate_limit_lock_failed');try{if($db->query('START TRANSACTION')===false)throw new DurableRateLimitException('rate_limit_transaction_failed');$fresh=$this->find($bucket->bucketHash,true);if($fresh!==null&&$fresh->expiresAt<=$cut){$changed=$db->delete($this->table(),['id'=>$fresh->id,'bucket_hash'=>$fresh->bucketHash,'expires_at'=>$fresh->expiresAt,'updated_at'=>$fresh->updatedAt]);if($changed!==1)throw new DurableRateLimitException('rate_limit_cleanup_uncertain');$deleted++;}if($db->query('COMMIT')===false)throw new DurableRateLimitException('rate_limit_cleanup_uncertain');}catch(Throwable$exception){$db->query('ROLLBACK');if($exception instanceof DurableRateLimitException)throw$exception;throw new DurableRateLimitException('rate_limit_cleanup_uncertain');}finally{$db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)',$lock));}}
        return$deleted;
    }
    private function find(string $hash,bool $lock=false):?DurableRateLimitBucket{$db=$this->db();$db->last_error='';$row=$db->get_row($db->prepare("SELECT * FROM {$this->table()} WHERE bucket_hash=%s".($lock?' FOR UPDATE':''),$hash),ARRAY_A);if($db->last_error!=='')throw new DurableRateLimitException('rate_limit_read_failed');if(!is_array($row))return null;try{return DurableRateLimitBucket::fromRow($row);}catch(Throwable){throw new DurableRateLimitException('rate_limit_bucket_corrupt');}}
    private function seconds(string $from,string $to):int{$a=new \DateTimeImmutable($from,new \DateTimeZone('UTC'));$b=new \DateTimeImmutable($to,new \DateTimeZone('UTC'));return max(0,$b->getTimestamp()-$a->getTimestamp());}
    private function reconcileExpected(array $projections,string $now):bool
    {
        try{$fresh=$this->reconciliationDb();$table=$fresh->prefix.Config::TABLE_PREFIX.'store_onboarding_rate_limit_buckets';$locks=array_map(fn($p)=>$this->lockName($p['spec']->bucketHash),$projections);sort($locks,SORT_STRING);$held=[];try{foreach($locks as$lock){if((int)$fresh->get_var($fresh->prepare('SELECT GET_LOCK(%s,10)',$lock))!==1)return false;$held[]=$lock;}foreach($projections as$projection){$spec=$projection['spec'];$row=$fresh->get_row($fresh->prepare("SELECT * FROM {$table} WHERE bucket_hash=%s",$spec->bucketHash),ARRAY_A);if(!is_array($row)||$fresh->last_error!=='')return false;$actual=DurableRateLimitBucket::fromRow($row);if($actual->domain!==$spec->domain||$actual->windowSeconds!==$spec->windowSeconds||$actual->windowStartedAt!==$projection['start']||$actual->hitCount!==$projection['count']||$actual->expiresAt!==$projection['expires']||$actual->updatedAt!==$now)return false;}return true;}finally{foreach(array_reverse($held)as$lock)$fresh->get_var($fresh->prepare('SELECT RELEASE_LOCK(%s)',$lock));}}catch(Throwable){return false;}
    }
    private function reconciliationDb():\wpdb
    {
        $source=$this->db();$database=(string)$source->get_var('SELECT DATABASE()');$original=(int)$source->get_var('SELECT CONNECTION_ID()');if($database==='')throw new DurableRateLimitException('rate_limit_outcome_uncertain');$factory=$this->reconciliationFactory;$fresh=is_callable($factory)?$factory($database):new \wpdb(DB_USER,DB_PASSWORD,$database,DB_HOST);if(!$fresh instanceof \wpdb||$fresh->last_error!==''||(int)$fresh->get_var('SELECT CONNECTION_ID()')===$original||(int)$fresh->get_var('SELECT @@session.in_transaction')!==0)throw new DurableRateLimitException('rate_limit_outcome_uncertain');$fresh->set_prefix($source->prefix);return$fresh;
    }
    private function lockName(string $hash):string{return'r1dca_r_'.substr(hash('sha256',"rate-limit\0".$hash),0,48);}
    private function table():string{$db=$this->db();return$db->prefix.Config::TABLE_PREFIX.'store_onboarding_rate_limit_buckets';}
    private function db():\wpdb{if($this->db instanceof \wpdb)return$this->db;global$wpdb;if(!$wpdb instanceof \wpdb)throw new DurableRateLimitException('rate_limit_unavailable');return$this->db=$wpdb;}
}
