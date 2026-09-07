<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Couriers\Evidence;

/** No Media Library entries; every byte is staged under a proven deny-all directory. */
class PrivateDeliveryStorage
{
    public function __construct(private ?string $root=null,private ?string $url=null)
    {
        $this->root??=WP_CONTENT_DIR.'/veciahorra-private-delivery';
        $this->url??=content_url('/veciahorra-private-delivery');
    }
    public function protect(): void
    {
        if(is_link($this->root))throw new \RuntimeException('private_storage_unavailable');
        if(!is_dir($this->root)&&!wp_mkdir_p($this->root))throw new \RuntimeException('private_storage_unavailable');
        $rules="Require all denied\nDeny from all\n";
        foreach(['.htaccess'=>$rules,'index.php'=>"<?php http_response_code(403); exit;\n"] as $name=>$body){
            $path=$this->root.'/'.$name;
            if(is_link($path))throw new \RuntimeException('private_storage_unavailable');
            if(is_file($path)){
                if(file_get_contents($path)!==$body)throw new \RuntimeException('private_storage_not_protected');
            }else{
                $handle=@fopen($path,'x');if($handle===false)throw new \RuntimeException('private_storage_unavailable');
                try{if(fwrite($handle,$body)!==strlen($body)||!fflush($handle))throw new \RuntimeException('private_storage_unavailable');}finally{fclose($handle);}
            }
        }
        $key=bin2hex(random_bytes(32)).'.jpg';$token=bin2hex(random_bytes(32));$path=$this->path($key);
        try{
            $handle=@fopen($path,'x');if($handle===false)throw new \RuntimeException('private_storage_unavailable');
            try{if(fwrite($handle,$token)!==strlen($token))throw new \RuntimeException('private_storage_unavailable');}finally{fclose($handle);}
            $response=wp_remote_get($this->url.'/'.$key,['timeout'=>10,'redirection'=>0,'limit_response_size'=>4096,'cookies'=>[]]);
            if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==403||str_contains(wp_remote_retrieve_body($response),$token))throw new \RuntimeException('private_storage_not_protected');
        }finally{if(is_file($path))unlink($path);}
    }
    public function path(string $key): string
    {
        if(preg_match('/^[a-f0-9]{64}\.jpg$/D',$key)!==1)throw new \DomainException('invalid_storage_key');
        $root=realpath($this->root);
        if($root===false||is_link($this->root)||is_link($root.'/'.$key))throw new \RuntimeException('private_storage_unavailable');
        return $root.DIRECTORY_SEPARATOR.$key;
    }
    /** Input is PHP's upload temporary path supplied by the server, never a posted path. */
    public function prepare(array $upload): array
    {
        $source=$upload['tmp_name']??'';
        if(($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_string($source)||!is_file($source))throw new \DomainException('photo_required');
        $bytes=filesize($source);
        if($bytes===false||$bytes<1||$bytes>8*1024*1024)throw new \DomainException('photo_max_8mb');
        $info=@getimagesize($source);
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($source);
        if(!$info||!in_array($mime,['image/jpeg','image/png','image/webp'],true)||($info['mime']??null)!==$mime)throw new \DomainException('photo_invalid');
        if($info[0]<1||$info[1]<1||$info[0]*$info[1]>40000000)throw new \DomainException('photo_dimensions_invalid');
        $this->protect();
        $temp=bin2hex(random_bytes(32)).'.jpg';$final=bin2hex(random_bytes(32)).'.jpg';$path=$this->path($temp);
        $reserved=@fopen($path,'x');if($reserved===false)throw new \RuntimeException('photo_write_failed');fclose($reserved);
        $jpegOnly=static fn(array $formats):array=>['image/jpeg'=>'image/jpeg','image/png'=>'image/jpeg','image/webp'=>'image/jpeg'];
        add_filter('image_editor_output_format',$jpegOnly,PHP_INT_MAX);
        try{
            $editor=wp_get_image_editor($source,['mime_type'=>$mime]);
            if(is_wp_error($editor))throw new \DomainException('photo_decode_failed');
            $rotated=$editor->maybe_exif_rotate();
            if(is_wp_error($rotated))throw new \DomainException('photo_orientation_failed');
            $size=$editor->get_size();
            if(max($size)>1600&&is_wp_error($editor->resize(1600,1600,false)))throw new \DomainException('photo_resize_failed');
            if(is_wp_error($editor->set_quality(82))||$editor->get_quality()<80||$editor->get_quality()>85)throw new \DomainException('photo_encode_failed');
            $saved=$editor->save($path,'image/jpeg');
            if(is_wp_error($saved)||wp_normalize_path((string)($saved['path']??''))!==wp_normalize_path($path)||($saved['mime-type']??null)!=='image/jpeg')throw new \DomainException('photo_encode_failed');
            // WP editors may preserve profiles on small images. Remove APP/COM metadata explicitly.
            $clean=$this->stripMetadata((string)file_get_contents($path));
            if(file_put_contents($path,$clean,LOCK_EX)!==strlen($clean))throw new \RuntimeException('photo_write_failed');
            $result=@getimagesize($path);
            if(!$result||$result['mime']!=='image/jpeg'||max($result[0],$result[1])>1600)throw new \DomainException('photo_output_invalid');
            $verify=wp_get_image_editor($path,['mime_type'=>'image/jpeg']);
            if(is_wp_error($verify))throw new \DomainException('photo_output_invalid');
            return ['temp'=>$temp,'final'=>$final,'sha256'=>hash_file('sha256',$path)];
        }catch(\Throwable $e){if(is_file($path))unlink($path);throw $e;}
        finally{remove_filter('image_editor_output_format',$jpegOnly,PHP_INT_MAX);}
    }
    private function stripMetadata(string $jpeg): string
    {
        if(substr($jpeg,0,2)!=="\xff\xd8")throw new \DomainException('photo_output_invalid');
        $out=substr($jpeg,0,2);$offset=2;$length=strlen($jpeg);
        while($offset<$length){
            if(ord($jpeg[$offset])!==255)throw new \DomainException('photo_output_invalid');
            $start=$offset;while($offset<$length&&ord($jpeg[$offset])===255)$offset++;
            if($offset>=$length)break;
            $marker=ord($jpeg[$offset++]);
            if($marker===0xda)return $out.substr($jpeg,$start);
            if($offset+2>$length)break;
            $size=unpack('n',substr($jpeg,$offset,2))[1];
            if($size<2||$offset+$size>$length)break;
            if(!(($marker>=0xe1&&$marker<=0xef)||$marker===0xfe))$out.=substr($jpeg,$start,$offset+$size-$start);
            $offset+=$size;
        }
        throw new \DomainException('photo_output_invalid');
    }
    public function move(array $file): void
    {
        $from=$this->path($file['temp']);$to=$this->path($file['final']);
        if(file_exists($to)||!@rename($from,$to))throw new \RuntimeException('photo_move_failed');
    }
    public function compensate(array $file,bool $moved=false): void
    {
        // These fresh random keys belong exclusively to this attempt, never to request data.
        foreach($moved?['temp','final']:['temp'] as $kind){$path=$this->path($file[$kind]);if(is_file($path)&&!unlink($path))throw new \RuntimeException('photo_cleanup_failed');}
    }
}
