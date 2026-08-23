<?php
declare(strict_types=1);
final class R1dcaManifestChannel
{
    private const VERSION='r1dca.final-manifest.v1';
    private static array $ids=[],$envelopes=[],$identities=[],$paths=[];
    private static bool $registered=false;
    public static function collect(array $ids):void
    {
        foreach($ids as$id){if(!is_string($id)||$id===''||isset(self::$ids[$id]))throw new RuntimeException('manifest_ids_invalid');self::$ids[$id]=true;}
        if(!self::$registered){self::$registered=true;register_shutdown_function([self::class,'finish']);}
    }
    private static function normalize(mixed $value):mixed
    {
        if(!is_array($value))return$value;
        if(array_is_list($value))return array_map([self::class,'normalize'],$value);
        ksort($value,SORT_STRING);foreach($value as$key=>$item)$value[$key]=self::normalize($item);return$value;
    }
    public static function canonical(array $payload):string
    {
        return json_encode(self::normalize($payload),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
    }
    public static function finish():void
    {
        $path=(string)getenv('VA_R1DCA_MANIFEST_PATH');if($path==='')return;
        $fatal=error_get_last();if(is_array($fatal)&&in_array($fatal['type']??0,[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR],true))return;
        $execution=(string)getenv('VA_R1DCA_EXECUTION_ID');$group=(string)getenv('VA_R1DCA_GROUP_ID');$nonce=(string)getenv('VA_R1DCA_GROUP_NONCE');$key=hex2bin((string)getenv('VA_R1DCA_GROUP_KEY'));
        if(!preg_match('/^[a-f0-9]{32}$/D',$execution)||!in_array($group,['qa1','qa2','qa3','qa4'],true)||!preg_match('/^[a-f0-9]{32}$/D',$nonce)||!is_string($key)||strlen($key)!==32)return;
        $dbName=(string)getenv('VA_R1DCA_DATABASE');$db=new wpdb(DB_USER,DB_PASSWORD,$dbName,DB_HOST);$db->set_prefix('wp_');$fixtures=0;
        foreach(['wp_va_store_onboarding_applications','wp_va_store_onboarding_email_verifications','wp_va_store_onboarding_activation_sessions','wp_va_store_onboarding_rate_limit_buckets']as$table)$fixtures+=(int)$db->get_var("SELECT COUNT(*) FROM {$table}");
        $fixtures+=(int)$db->get_var("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND (TABLE_NAME LIKE 'q2f%' OR TABLE_NAME LIKE 'm4_%')");
        $locks=(int)$db->get_var($db->prepare("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB=%s AND STATE LIKE %s",$dbName,'%lock%'))+(int)$db->get_var('SELECT COUNT(*) FROM information_schema.INNODB_TRX');
        $payload=['version'=>self::VERSION,'execution_id'=>$execution,'group_id'=>$group,'group_nonce'=>$nonce,'child_pid'=>getmypid(),'ids'=>array_keys(self::$ids),'count'=>count(self::$ids),'cleanup_complete'=>$fixtures===0&&$locks===0,'fixtures_remaining'=>$fixtures,'locks_remaining'=>$locks,'completed_at_utc'=>gmdate('Y-m-d\TH:i:s\Z')];
        $json=self::canonical($payload);$wire=base64_encode($json).'.'.hash_hmac('sha256',$json,$key)."\n";$tmp=$path.'.tmp.'.bin2hex(random_bytes(8));$handle=@fopen($tmp,'x+b');if($handle===false)return;$ok=false;
        try{$ok=fwrite($handle,$wire)===strlen($wire)&&fflush($handle);}finally{fclose($handle);}
        if(!$ok||file_exists($path)||!rename($tmp,$path))@unlink($tmp);putenv('VA_R1DCA_GROUP_KEY');
    }
    private static function receipt(string $directory,string $name):void
    {
        if(!is_dir($directory)&&!@mkdir($directory,0700)&&!is_dir($directory))throw new RuntimeException('manifest_receipt_directory');
        $handle=@fopen($directory.DIRECTORY_SEPARATOR.$name.'.receipt','x+b');if($handle===false)throw new RuntimeException('manifest_replayed');
        try{if(fwrite($handle,"consumed\n")!==9||!fflush($handle))throw new RuntimeException('manifest_receipt_failed');}finally{fclose($handle);}
    }
    public static function consume(string $path,array $authority,int $exit,int $pid):array
    {
        if($exit!==0)throw new RuntimeException('manifest_child_exit');
        if(isset($authority['manifest_path'])&&$path!==$authority['manifest_path'])throw new RuntimeException('manifest_path');
        if(!is_file($path))throw new RuntimeException('manifest_missing');$size=filesize($path);if(!is_int($size)||$size<10||$size>65536)throw new RuntimeException('manifest_size');
        $wire=file_get_contents($path);if(!is_string($wire)||substr_count($wire,"\n")!==1||!str_ends_with($wire,"\n"))throw new RuntimeException('manifest_wire');
        [$encoded,$mac]=array_pad(explode('.',rtrim($wire,"\n"),2),2,'');$json=base64_decode($encoded,true);$key=$authority['key']??null;if(!is_string($json)||!is_string($key)||strlen($key)!==32)throw new RuntimeException('manifest_hmac');
        try{$payload=json_decode(rtrim($json,"\n"),true,16,JSON_THROW_ON_ERROR);}catch(Throwable){throw new RuntimeException('manifest_json');}
        if(!is_array($payload)||self::canonical($payload)!==$json)throw new RuntimeException('manifest_noncanonical');
        if(!hash_equals(hash_hmac('sha256',$json,$key),$mac))throw new RuntimeException('manifest_hmac');
        $keys=['child_pid','cleanup_complete','completed_at_utc','count','execution_id','fixtures_remaining','group_id','group_nonce','ids','locks_remaining','version'];$actual=array_keys($payload);sort($keys,SORT_STRING);sort($actual,SORT_STRING);if($actual!==$keys)throw new RuntimeException('manifest_shape');
        if($payload['version']!==self::VERSION||$payload['execution_id']!==$authority['execution_id']||$payload['group_id']!==$authority['group_id']||$payload['group_nonce']!==$authority['group_nonce']||$payload['child_pid']!==$pid)throw new RuntimeException('manifest_authority');
        if($payload['ids']!==$authority['ids']||$payload['count']!==count($authority['ids'])||$payload['cleanup_complete']!==true||$payload['fixtures_remaining']!==0)throw new RuntimeException('manifest_evidence');
        $named=0;$db=new wpdb(DB_USER,DB_PASSWORD,(string)($authority['database']??getenv('VA_R1DCA_DATABASE')),DB_HOST);
        foreach($authority['lock_names']??[]as$name){$db->last_error='';$used=$db->get_var($db->prepare('SELECT IS_USED_LOCK(%s)',$name));if($db->last_error!=='')throw new RuntimeException('manifest_lock_uncertain');if($used!==null)$named++;}
        if($payload['locks_remaining']!==$named)throw new RuntimeException('manifest_lock_mismatch');if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$payload['completed_at_utc']))throw new RuntimeException('manifest_time');
        $fingerprint=hash('sha256',$wire);$identity=hash('sha256',$payload['execution_id']."\0".$payload['group_id']."\0".$payload['group_nonce']);$pathHash=hash('sha256',strtolower(str_replace('\\','/',realpath($path)?:$path)));
        if(isset(self::$envelopes[$fingerprint])||isset(self::$identities[$identity])||isset(self::$paths[$pathHash]))throw new RuntimeException('manifest_replayed');
        $receipts=(string)($authority['receipt_dir']??dirname($path).DIRECTORY_SEPARATOR.'receipts');self::receipt($receipts,$identity);self::receipt($receipts,$fingerprint);self::$envelopes[$fingerprint]=self::$identities[$identity]=self::$paths[$pathHash]=true;
        if(!unlink($path))throw new RuntimeException('manifest_consume');return$payload;
    }
}
