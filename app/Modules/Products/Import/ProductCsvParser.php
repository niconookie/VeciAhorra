<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Products\Import;

final class ProductCsvParser
{
    public const HEADERS=['sku','nombre','categoria','subcategoria','marca','unidad','descripcion','estado','imagen'];
    public const MAX_BYTES=1048576;
    public const MAX_ROWS=1000;

    public function parse(string $contents): array
    {
        if($contents===''||strlen($contents)>self::MAX_BYTES)throw new \InvalidArgumentException('El CSV está vacío o supera 1 MB.');
        if(!mb_check_encoding($contents,'UTF-8')||str_contains($contents,"\0"))throw new \InvalidArgumentException('El archivo debe ser texto UTF-8 válido.');
        if(str_starts_with($contents,"\xEF\xBB\xBF"))$contents=substr($contents,3);
        $s=fopen('php://temp','r+');if($s===false)throw new \RuntimeException('No fue posible procesar el CSV.');fwrite($s,$contents);rewind($s);
        $header=fgetcsv($s,0,',','"','\\');
        if($header!==self::HEADERS){fclose($s);throw new \InvalidArgumentException('El encabezado debe ser exactamente: '.implode(',',self::HEADERS).'. No se aceptan precio, stock, Store ID ni minimarket.');}
        $rows=[];$errors=[];$seen=[];$line=1;
        while(($v=fgetcsv($s,0,',','"','\\'))!==false){$line++;if($v===[null]||$v===[])continue;if($line>self::MAX_ROWS+1){fclose($s);throw new \InvalidArgumentException('El CSV supera el máximo de 1000 filas.');}
            $sku=trim((string)($v[0]??''));if(count($v)!==9){$errors[]=['line'=>$line,'sku'=>$sku,'message'=>'La fila no tiene exactamente 9 columnas.'];continue;}
            [$sku,$name,$category,$subcategory,$brand,$unit,$description,$status,$image]=array_map(static fn($x):string=>trim((string)$x),$v);$messages=[];$key=mb_strtolower($sku,'UTF-8');
            if($sku===''||strlen($sku)>100||preg_match('/[\x00-\x1F\x7F]/u',$sku)===1||preg_match('/^[=+\-@]/',$sku)===1)$messages[]='SKU vacío, demasiado largo o inseguro.';
            if($sku!==''&&isset($seen[$key]))$messages[]='SKU duplicado dentro del CSV.';$seen[$key]=true;
            if($name===''||mb_strlen($name)>180||preg_match('/^[=+\-@]/',$name)===1)$messages[]='Nombre obligatorio, máximo 180 caracteres y sin fórmula CSV.';
            if($category===''||preg_match('/^[=+\-@]/',$category)===1)$messages[]='Categoría obligatoria o insegura.';
            foreach(['subcategoría'=>$subcategory,'marca'=>$brand,'unidad'=>$unit,'descripción'=>$description,'imagen'=>$image] as $label=>$value)if($value!==''&&preg_match('/^[=+\-@]/',$value)===1)$messages[]=ucfirst($label).' contiene una fórmula CSV.';
            if(!in_array($status,['draft','active','inactive'],true))$messages[]='Estado debe ser draft, active o inactive.';
            if($image!==''&&!preg_match('/^[A-Za-z0-9._-]+\.(?:jpe?g|png|webp)$/iD',$image))$messages[]='Imagen debe ser un nombre JPG, PNG o WEBP seguro.';
            if($messages!==[]){$errors[]=['line'=>$line,'sku'=>$sku,'message'=>implode(' ',$messages)];continue;}
            $rows[]=['line'=>$line,'sku'=>$sku,'name'=>$name,'category'=>$category,'subcategory'=>$subcategory,'brand'=>$brand,'unit'=>$unit,'description'=>$description,'status'=>$status,'image'=>$image];
        }fclose($s);return['rows'=>$rows,'errors'=>$errors];
    }
}
