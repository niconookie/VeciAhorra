<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Products\Import;

final class ProductImageZipParser
{
    public const MAX_ARCHIVE_BYTES=20971520;
    public const MAX_FILES=1000;
    public const MAX_IMAGE_BYTES=5242880;
    public const MAX_EXPANDED_BYTES=26214400;
    private const MIMES=['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];

    public function parse(string $path,int $archiveBytes):array
    {
        if($archiveBytes<1||$archiveBytes>self::MAX_ARCHIVE_BYTES||!is_file($path))throw new \InvalidArgumentException('El ZIP debe pesar como máximo 20 MB.');
        if(!class_exists(\ZipArchive::class))throw new \RuntimeException('El servidor no dispone de ZipArchive.');
        $z=new \ZipArchive();if($z->open($path)!==true)throw new \InvalidArgumentException('El ZIP está malformado.');
        try{if($z->numFiles<1||$z->numFiles>self::MAX_FILES)throw new \InvalidArgumentException('El ZIP debe contener entre 1 y 1000 imágenes.');$out=[];$keys=[];$total=0;
            for($i=0;$i<$z->numFiles;$i++){$st=$z->statIndex($i,\ZipArchive::FL_UNCHANGED);if(!is_array($st))throw new \InvalidArgumentException('No fue posible inspeccionar una entrada del ZIP.');$name=(string)($st['name']??'');$size=(int)($st['size']??0);$compressed=(int)($st['comp_size']??0);$key=mb_strtolower($name,'UTF-8');
                if($name===''||basename(str_replace('\\','/',$name))!==$name||str_contains($name,'..')||str_contains($name,"\0")||str_ends_with($name,'/')||!preg_match('/^[A-Za-z0-9._-]+\.(?:jpe?g|png|webp)$/iD',$name))throw new \InvalidArgumentException('El ZIP contiene rutas, carpetas o nombres no permitidos.');
                if(isset($keys[$key]))throw new \InvalidArgumentException('El ZIP contiene nombres de imagen duplicados.');$keys[$key]=true;
                $ops=0;$attr=0;if($z->getExternalAttributesIndex($i,$ops,$attr)&&$ops===\ZipArchive::OPSYS_UNIX){$type=($attr>>16)&0170000;if($type!==0&&$type!==0100000)throw new \InvalidArgumentException('El ZIP contiene enlaces o entradas que no son archivos regulares.');}
                if($size<1||$size>self::MAX_IMAGE_BYTES||($total+=$size)>self::MAX_EXPANDED_BYTES||($compressed===0&&$size>0))throw new \InvalidArgumentException('El ZIP supera los límites seguros de imágenes o tamaño expandido.');
                $bytes=$z->getFromIndex($i);if(!is_string($bytes)||strlen($bytes)!==$size)throw new \InvalidArgumentException('No fue posible leer una imagen del ZIP.');
                $tmp=wp_tempnam($name);if(!$tmp||file_put_contents($tmp,$bytes)!==$size)throw new \RuntimeException('No fue posible validar una imagen.');$check=wp_check_filetype_and_ext($tmp,$name,self::MIMES);@unlink($tmp);if(empty($check['ext'])||empty($check['type'])||!in_array($check['type'],self::MIMES,true))throw new \InvalidArgumentException('El ZIP contiene una imagen cuyo MIME real no está permitido.');$out[$name]=$bytes;
            }return$out;
        }finally{$z->close();}
    }
}
