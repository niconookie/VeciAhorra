<?php
declare(strict_types=1);

final readonly class R1dbHistoricalSnapshot
{
    public function __construct(public int $count,public string $fingerprint){}
}

final class R1dbHistoricalFingerprint
{
    public static function capture(wpdb $database,string $table,string $where='1=1'):R1dbHistoricalSnapshot
    {
        if(!preg_match('/\A[a-zA-Z0-9_]+\z/',$table)||!in_array($where,["meta_key='session_tokens'",'1=1',"public_id LIKE 'chk\\_%'"],true))throw new RuntimeException('r1db_fingerprint_surface_invalid');
        $columns=$database->get_results("SHOW COLUMNS FROM `{$table}`",ARRAY_A);if(!is_array($columns)||$columns===[]||$database->last_error!=='')throw new RuntimeException('r1db_fingerprint_schema_failed');
        $types=[];foreach($columns as $column){$name=(string)$column['Field'];$types[$name]=(string)$column['Type'];}
        $indexes=$database->get_results("SHOW INDEX FROM `{$table}`",ARRAY_A);if(!is_array($indexes)||$database->last_error!=='')throw new RuntimeException('r1db_fingerprint_primary_key_missing');$primaryRows=array_values(array_filter($indexes,static fn(array $index):bool=>(string)($index['Key_name']??'')==='PRIMARY'));usort($primaryRows,static fn(array $left,array $right):int=>(int)$left['Seq_in_index']<=>(int)$right['Seq_in_index']);$primary=array_map(static fn(array $index):string=>(string)$index['Column_name'],$primaryRows);if($primary===[])throw new RuntimeException('r1db_fingerprint_primary_key_missing');
        $order=implode(',',array_map(static fn(string $name):string=>"`{$name}`",$primary));$rows=$database->get_results("SELECT * FROM `{$table}` WHERE {$where} ORDER BY {$order}",ARRAY_A);if(!is_array($rows)||$database->last_error!=='')throw new RuntimeException('r1db_fingerprint_read_failed');
        return self::fromRows($rows,$types,$primary);
    }
    /** @param list<array<string,mixed>> $rows @param array<string,string> $types @param list<string> $primary */
    public static function fromRows(array $rows,array $types,array $primary):R1dbHistoricalSnapshot
    {
        foreach($rows as &$row){ksort($row,SORT_STRING);foreach($row as $key=>$value){$type=strtolower($types[$key]??'');$row[$key]=$value===null?['type'=>'null']:((str_contains($type,'binary')||str_contains($type,'blob'))?['type'=>'binary','value'=>base64_encode((string)$value)]:['type'=>'string','value'=>(string)$value]);}}unset($row);
        usort($rows,static function(array $left,array $right)use($primary):int{foreach($primary as $key){$comparison=json_encode($left[$key]??null,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)<=>json_encode($right[$key]??null,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);if($comparison!==0)return $comparison;}return json_encode($left,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)<=>json_encode($right,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);});
        $canonical=json_encode($rows,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);return new R1dbHistoricalSnapshot(count($rows),hash('sha256',$canonical));
    }
}

final class R1dbHistoricalBaseline
{
    /** @param array<string,R1dbHistoricalSnapshot> $surfaces */ public function __construct(private array $surfaces){}
    /** @param array<string,R1dbHistoricalSnapshot> $current */ public function assertAll(array $current):void
    {
        if(array_keys($current)!==array_keys($this->surfaces))throw new RuntimeException('r1db_historical_surface_mismatch');foreach($this->surfaces as $name=>$expected){$actual=$current[$name];if($actual->count!==$expected->count||!hash_equals($expected->fingerprint,$actual->fingerprint))throw new RuntimeException('r1db_historical_fingerprint_changed_'.$name);}
    }
    public function expected(string $name):R1dbHistoricalSnapshot{return $this->surfaces[$name]??throw new RuntimeException('r1db_historical_surface_missing');}
}

final class R1dbComparisonLedger
{
    /** @var array<string,true> */ private array $expected=[];/** @var array<string,true> */ private array $completed=[];
    /** @param list<string> $expected */ public function __construct(array $expected){foreach($expected as $key){if($key===''||isset($this->expected[$key]))throw new RuntimeException('r1db_comparison_ledger_invalid');$this->expected[$key]=true;}}
    public function record(string $key):void{if(!isset($this->expected[$key])||isset($this->completed[$key]))throw new RuntimeException('r1db_comparison_ledger_unexpected_'.$key);$this->completed[$key]=true;}
    public function seal():int{if(array_keys($this->completed)!==array_keys($this->expected))throw new RuntimeException('r1db_comparison_ledger_incomplete');return count($this->completed);}
}
