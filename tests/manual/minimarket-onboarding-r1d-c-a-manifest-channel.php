<?php
declare(strict_types=1);

final class R1dcaManifestChannel
{
    private const VERSION='r1dca.final-manifest.v1';
    private static array $ids=[];
    private static bool $registered=false;
    public static function collect(array $ids):void
    {
        foreach($ids as$id){if(!is_string($id)||$id===''||isset(self::$ids[$id]))throw new RuntimeException('manifest_ids_invalid');self::$ids[$id]=true;}
        if(!self::$registered){self::$registered=true;register_shutdown_function([self::class,'finish']);}
    }
    public static function finish():void
    {
        $path=(string)getenv('VA_R1DCA_MANIFEST_PATH');if($path==='')return;
        $fatal=error_get_last();if(is_array($fatal)&&in_array($fatal['type']??0,[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR],true))return;
        $execution=(string)getenv('VA_R1DCA_EXECUTION_ID');$group=(string)getenv('VA_R1DCA_GROUP_ID');$nonce=(string)getenv('VA_R1DCA_GROUP_NONCE');$keyHex=(string)getenv('VA_R1DCA_GROUP_KEY');$key=hex2bin($keyHex);
        if(!preg_match('/^[a-f0-9]{32}$/D',$execution)||!in_array($group,['qa1','qa2','qa3','qa4'],true)||!preg_match('/^[a-f0-9]{32}$/D',$nonce)||!is_string($key)||strlen($key)!==32)return;
        $dbName=(string)getenv('VA_R1DCA_DATABASE');$db=new wpdb(DB_USER,DB_PASSWORD,$dbName,DB_HOST);$db->set_prefix('wp_');$fixtures=0;foreach(['wp_va_store_onboarding_applications','wp_va_store_onboarding_email_verifications','wp_va_store_onboarding_activation_sessions','wp_va_store_onboarding_rate_limit_buckets']as$table)$fixtures+=(int)$db->get_var("SELECT COUNT(*) FROM {$table}");$fixtures+=(int)$db->get_var("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'q2f%'");$locks=(int)$db->get_var($db->prepare("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB=%s AND STATE LIKE %s",$dbName,'%lock%'))+(int)$db->get_var('SELECT COUNT(*) FROM information_schema.INNODB_TRX');
        $payload=['child_pid'=>getmypid(),'cleanup_complete'=>$fixtures===0&&$locks===0,'completed_at_utc'=>gmdate('Y-m-d\TH:i:s\Z'),'count'=>count(self::$ids),'execution_id'=>$execution,'fixtures_remaining'=>$fixtures,'group_id'=>$group,'group_nonce'=>$nonce,'ids'=>array_keys(self::$ids),'locks_remaining'=>$locks,'version'=>self::VERSION];$json=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";$wire=base64_encode($json).'.'.hash_hmac('sha256',$json,$key)."\n";$tmp=$path.'.tmp.'.bin2hex(random_bytes(8));$handle=@fopen($tmp,'x+b');if($handle===false)return;try{if(fwrite($handle,$wire)!==strlen($wire)||!fflush($handle))return;}finally{fclose($handle);}if(file_exists($path)||!rename($tmp,$path))@unlink($tmp);putenv('VA_R1DCA_GROUP_KEY');
    }
    public static function consume(string$path,array$authority,int$exit,int$pid):array
    {
        if($exit!==0)throw new RuntimeException('manifest_child_exit');if(($authority['consumed']??false)===true)throw new RuntimeException('manifest_replayed');if(!is_file($path))throw new RuntimeException('manifest_missing');$size=filesize($path);if(!is_int($size)||$size<10||$size>65536)throw new RuntimeException('manifest_size');$wire=file_get_contents($path);if(!is_string($wire)||substr_count($wire,"\n")!==1||!str_ends_with($wire,"\n"))throw new RuntimeException('manifest_wire');[$encoded,$mac]=array_pad(explode('.',rtrim($wire,"\n"),2),2,'');$json=base64_decode($encoded,true);$key=$authority['key']??null;if(!is_string($json)||!is_string($key)||strlen($key)!==32||!hash_equals(hash_hmac('sha256',$json,$key),$mac))throw new RuntimeException('manifest_hmac');$payload=json_decode(rtrim($json,"\n"),true,16,JSON_THROW_ON_ERROR);$keys=['child_pid','cleanup_complete','completed_at_utc','count','execution_id','fixtures_remaining','group_id','group_nonce','ids','locks_remaining','version'];if(!is_array($payload)||array_keys($payload)!==$keys)throw new RuntimeException('manifest_shape');if($payload['version']!==self::VERSION||$payload['execution_id']!==$authority['execution_id']||$payload['group_id']!==$authority['group_id']||$payload['group_nonce']!==$authority['group_nonce']||$payload['child_pid']!==$pid)throw new RuntimeException('manifest_authority');if($payload['ids']!==$authority['ids']||$payload['count']!==count($authority['ids'])||$payload['cleanup_complete']!==true||$payload['fixtures_remaining']!==0||$payload['locks_remaining']!==0)throw new RuntimeException('manifest_evidence');if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$payload['completed_at_utc']))throw new RuntimeException('manifest_time');if(!unlink($path))throw new RuntimeException('manifest_consume');return$payload;
    }
}
