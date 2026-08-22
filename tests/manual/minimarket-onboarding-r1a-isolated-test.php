<?php

declare(strict_types=1);

const ARRAY_A = 'ARRAY_A';

require_once dirname(__DIR__, 2) . '/app/Core/Config.php';
require_once dirname(__DIR__, 2) . '/app/Database/Builder/Column.php';
require_once dirname(__DIR__, 2) . '/app/Database/Builder/Index.php';
require_once dirname(__DIR__, 2) . '/app/Database/Builder/Blueprint.php';
require_once dirname(__DIR__, 2) . '/app/Database/Builder/SqlGenerator.php';
require_once dirname(__DIR__, 2) . '/app/Database/Builder/TableBuilder.php';
require_once dirname(__DIR__, 2) . '/app/Database/Contracts/TableInterface.php';
require_once dirname(__DIR__, 2) . '/app/Database/Tables/StoresTable.php';
require_once dirname(__DIR__, 2) . '/app/Database/Tables/StoreOnboardingApplicationsTable.php';
require_once dirname(__DIR__, 2) . '/app/Modules/Minimarket/Identity/MinimarketRole.php';
require_once dirname(__DIR__, 2) . '/app/Database/Migrations/CreateStoreOnboardingFoundation.php';

use VeciAhorra\Database\Migrations\CreateStoreOnboardingFoundation;

function isolatedAssert(bool $condition, string $message): void
{
    if (! $condition) throw new RuntimeException($message);
}

final class R1aSchemaWpdb
{
    public string $prefix = 'iso_';
    public string $users = 'iso_users';
    public string $usermeta = 'iso_usermeta';

    /** @param array<string,array<string,array<string,mixed>>> $columns @param array<string,array<string,array{columns:list<string>,unique:bool}>> $indexes */
    public function __construct(private array $columns, private array $indexes) {}

    public function esc_like(string $value): string { return $value; }
    public function prepare(string $query, mixed ...$values): string
    {
        foreach ($values as $value) {
            $replacement = is_int($value) ? (string) $value : "'" . (string) $value . "'";
            $query = preg_replace('/%[ds]/', $replacement, $query, 1) ?? $query;
        }
        return $query;
    }
    public function get_var(string $query): mixed
    {
        if (preg_match("/SHOW TABLES LIKE '([^']+)'/", $query, $matches) === 1) {
            return isset($this->columns[$matches[1]]) ? $matches[1] : null;
        }
        return null;
    }
    public function get_results(string $query, string $output): array
    {
        if (preg_match('/SHOW COLUMNS FROM ([a-z0-9_]+)/i', $query, $matches) === 1) {
            return array_values($this->columns[$matches[1]] ?? []);
        }
        if (preg_match("/SHOW INDEX FROM ([a-z0-9_]+) WHERE Key_name='([^']+)'/i", $query, $matches) === 1) {
            $definition = $this->indexes[$matches[1]][$matches[2]] ?? null;
            if ($definition === null) return [];
            $rows = [];
            foreach ($definition['columns'] as $offset => $column) {
                $rows[] = [
                    'Key_name' => $matches[2],
                    'Seq_in_index' => $offset + 1,
                    'Column_name' => $column,
                    'Non_unique' => $definition['unique'] ? 0 : 1,
                    'Sub_part' => $definition['sub_parts'][$offset] ?? null,
                ];
            }
            return $rows;
        }
        return [];
    }
}

function isolatedFixture(): array
{
    $storeColumns = [
        'id' => ['Field'=>'id','Type'=>'bigint(20) unsigned','Null'=>'NO'],
        'owner_user_id' => ['Field'=>'owner_user_id','Type'=>'bigint(20) unsigned','Null'=>'YES'],
    ];
    $names = ['id','public_id','user_id','account_email','owner_rut_normalized','status','idempotency_key_hash','terms_version','terms_accepted_at','store_id','failure_code','attempt_count','last_attempt_at','created_at','updated_at','abandoned_at'];
    $applicationColumns = [];
    foreach ($names as $name) $applicationColumns[$name] = ['Field'=>$name,'Type'=>'fixture','Null'=>'NO'];
    $indexes = [
        'iso_va_stores' => ['stores_owner_user_unique'=>['columns'=>['owner_user_id'],'unique'=>true]],
        'iso_va_store_onboarding_applications' => [
            'PRIMARY'=>['columns'=>['id'],'unique'=>true],
            'onboarding_public_id_unique'=>['columns'=>['public_id'],'unique'=>true],
            'onboarding_user_unique'=>['columns'=>['user_id'],'unique'=>true],
            'onboarding_store_unique'=>['columns'=>['store_id'],'unique'=>true],
            'onboarding_idempotency_unique'=>['columns'=>['idempotency_key_hash'],'unique'=>true],
            'onboarding_status_updated'=>['columns'=>['status','updated_at'],'unique'=>false],
            'onboarding_account_email'=>['columns'=>['account_email'],'unique'=>false],
            'onboarding_owner_rut'=>['columns'=>['owner_rut_normalized'],'unique'=>false],
        ],
    ];
    return [[
        'iso_va_stores'=>$storeColumns,
        'iso_va_store_onboarding_applications'=>$applicationColumns,
    ], $indexes];
}

function isolatedFailure(callable $mutate, string $expected): void
{
    global $wpdb;
    [$columns,$indexes] = isolatedFixture();
    $mutate($columns,$indexes);
    $wpdb = new R1aSchemaWpdb($columns,$indexes);
    try {
        (new CreateStoreOnboardingFoundation())->assertStructure();
    } catch (RuntimeException $exception) {
        isolatedAssert($exception->getMessage()===$expected,"Esperaba {$expected}, obtuvo {$exception->getMessage()}");
        return;
    }
    throw new RuntimeException("No rechazo {$expected}");
}

[$columns,$indexes] = isolatedFixture();
$wpdb = new R1aSchemaWpdb($columns,$indexes);
(new CreateStoreOnboardingFoundation())->assertStructure();
isolatedFailure(static function(array &$columns):void{unset($columns['iso_va_store_onboarding_applications']);},'r1a_schema_missing:onboarding_table');
isolatedFailure(static function(array &$columns):void{unset($columns['iso_va_stores']['owner_user_id']);},'r1a_schema_missing:stores.owner_user_id');
isolatedFailure(static function(array &$columns,array &$indexes):void{unset($indexes['iso_va_stores']['stores_owner_user_unique']);},'r1a_schema_missing:index.stores_owner_user_unique');
isolatedFailure(static function(array &$columns):void{unset($columns['iso_va_store_onboarding_applications']['terms_version']);},'r1a_schema_missing:onboarding.terms_version');
isolatedFailure(static function(array &$columns,array &$indexes):void{$indexes['iso_va_store_onboarding_applications']['onboarding_public_id_unique']['sub_parts']=[20];},'r1a_schema_invalid:index.onboarding_public_id_unique');
isolatedFailure(static function(array &$columns,array &$indexes):void{$indexes['iso_va_stores']['stores_owner_user_unique']['sub_parts']=[8];},'r1a_schema_invalid:index.stores_owner_user_unique');
isolatedFailure(static function(array &$columns,array &$indexes):void{$indexes['iso_va_store_onboarding_applications']['onboarding_status_updated']['columns'][]='id';},'r1a_schema_invalid:index.onboarding_status_updated');
isolatedFailure(static function(array &$columns,array &$indexes):void{$indexes['iso_va_store_onboarding_applications']['onboarding_status_updated']['columns']=['updated_at','status'];},'r1a_schema_invalid:index.onboarding_status_updated');
isolatedFailure(static function(array &$columns,array &$indexes):void{$indexes['iso_va_store_onboarding_applications']['onboarding_public_id_unique']['unique']=false;},'r1a_schema_invalid:index.onboarding_public_id_unique');

$installer = file_get_contents(dirname(__DIR__, 2) . '/app/Database/Installer.php');
isolatedAssert(is_string($installer) && strpos($installer, 'MigrationManager::migrate();') < strpos($installer, 'MigrationManager::updateVersion();'), 'Installer versiona antes de migrar.');
$migrationSource=file_get_contents(dirname(__DIR__,2).'/app/Database/Migrations/CreateStoreOnboardingFoundation.php');
isolatedAssert(is_string($migrationSource)&&strpos($migrationSource,'$this->assertStructure();')<strpos($migrationSource,'$this->backfillValidatedOwners();'),'Backfill ocurre antes de validar esquema.');
isolatedAssert(!str_contains((string)$installer,'catch ('),'Installer oculta excepcion de migracion.');

echo "R1A_SCHEMA_ISOLATED=PASS cases=10\nR1A_INSTALLER_ORDER=PASS assertions=3\n";

final class R1aBackfillWpdb
{
    public string $prefix='iso_'; public string $users='iso_users'; public string $usermeta='iso_usermeta'; public string $last_error='';
    /** @var array<int,?int> */ public array $stores=[10=>null,2=>null];
    /** @var array<int,bool> */ public array $existingUsers=[10=>true,2=>true];
    /** @var list<array{umeta_id:int,user_id:int,meta_value:string}> */ public array $meta=[['umeta_id'=>20,'user_id'=>10,'meta_value'=>'10'],['umeta_id'=>10,'user_id'=>2,'meta_value'=>'2']];
    public array $events=[]; public ?string $failAt=null; public $mutation=null; private array $values=[]; private ?array $snapshot=null; private bool $mutated=false;
    public function prepare(string $query,mixed ...$values):string{$this->values=$values;return $query;}
    private function event(string $name):void
    {
        $this->events[]=$name;
        if(!$this->mutated&&is_callable($this->mutation)){$mutation=$this->mutation;if($mutation($name,$this)===true)$this->mutated=true;}
        if($this->failAt===$name){$this->last_error='fixture failure';}
    }
    public function get_results(string $query,string $format):array|false
    {
        if(str_contains($query,'FROM iso_usermeta')){
            $locked=str_contains($query,'FOR UPDATE');
            if($locked&&str_contains($query,'user_id=%d')){$user=(int)$this->values[0];$this->event("meta_user:{$user}");$rows=array_values(array_filter($this->meta,static fn(array $r):bool=>$r['user_id']===$user));}
            elseif($locked){$this->event('meta_global');$rows=$this->meta;}
            else{$this->event('discover');$rows=$this->meta;}
            if($this->last_error!=='')return false;
            usort($rows,static fn(array $a,array $b):int=>[$a['user_id'],$a['umeta_id']]<=>[$b['user_id'],$b['umeta_id']]);return $rows;
        }
        return [];
    }
    public function get_row(string $query,string $format):?array
    {
        $store=(int)$this->values[0];$this->event((str_contains($query,'FOR UPDATE')?'store_lock:':'store_read:').$store);
        if(!array_key_exists($store,$this->stores))return null;
        return ['id'=>(string)$store,'owner_user_id'=>$this->stores[$store]===null?null:(string)$this->stores[$store]];
    }
    public function get_var(string $query):mixed
    {
        if(str_contains($query,'SELECT ID FROM')){$user=(int)$this->values[0];$this->event((str_contains($query,'FOR UPDATE')?'user_lock:':'user_read:').$user);return isset($this->existingUsers[$user])?$user:null;}
        if(str_contains($query,'SELECT owner_user_id FROM')){$store=(int)$this->values[0];return $this->stores[$store]??null;}
        return null;
    }
    public function query(string $query):int|false
    {
        if($query==='START TRANSACTION'){$this->events[]='start';$this->snapshot=[$this->stores,$this->meta];return 1;}
        if($query==='COMMIT'){$this->events[]='commit';$this->snapshot=null;return 1;}
        if($query==='ROLLBACK'){$this->events[]='rollback';if($this->snapshot!==null)[$this->stores,$this->meta]=$this->snapshot;$this->snapshot=null;return 1;}
        if(str_starts_with($query,'UPDATE ')){
            $user=(int)$this->values[0];$store=(int)$this->values[1];$this->event("write:{$store}:{$user}");
            if($this->last_error!==''||($this->failAt==='second_write'&&count(array_filter($this->events,static fn(string $e):bool=>str_starts_with($e,'write:')))===2))return false;
            foreach($this->stores as $id=>$owner)if($id!==$store&&$owner===$user)return false;
            $current=$this->stores[$store]??null;if($current!==null&&$current!==$user)return 0;$this->stores[$store]=$user;return $current===$user?0:1;
        }
        return 1;
    }
}

function backfillFailure(R1aBackfillWpdb $fake,string $expected):void
{
    global $wpdb;$wpdb=$fake;
    try{(new CreateStoreOnboardingFoundation())->backfillValidatedOwners();throw new RuntimeException("No rechazo {$expected}");}
    catch(RuntimeException $exception){isolatedAssert($exception->getMessage()===$expected,"Esperaba {$expected}, obtuvo {$exception->getMessage()}");}
}

$wpdb=new R1aBackfillWpdb();
isolatedAssert((new CreateStoreOnboardingFoundation())->backfillValidatedOwners()===2,'Backfill ordenado no proceso candidatos.');
isolatedAssert($wpdb->events===['discover','user_read:2','store_read:2','user_read:10','store_read:10','start','store_lock:2','store_lock:10','user_lock:2','user_lock:10','meta_user:2','meta_user:10','meta_global','write:2:2','write:10:10','commit'],'Secuencia global de locks incorrecta: '.json_encode($wpdb->events));

$duplicate=new R1aBackfillWpdb();$duplicate->meta[]=['umeta_id'=>21,'user_id'=>10,'meta_value'=>'10'];
$wpdb=$duplicate;isolatedAssert((new CreateStoreOnboardingFoundation())->backfillValidatedOwners()===2,'Filas duplicadas identicas no fueron idempotentes.');
$shared=new R1aBackfillWpdb();$shared->meta[0]['meta_value']='2';backfillFailure($shared,'store_owner_backfill_store_ambiguous');
$multi=new R1aBackfillWpdb();$multi->meta[]=['umeta_id'=>21,'user_id'=>10,'meta_value'=>'2'];backfillFailure($multi,'store_owner_backfill_user_ambiguous');
foreach(['change','insert','delete'] as $race){$fake=new R1aBackfillWpdb();$fake->mutation=static function(string $event,R1aBackfillWpdb $db)use($race):bool{if($event!=='meta_global')return false;if($race==='change')$db->meta[0]['meta_value']='2';elseif($race==='insert')$db->meta[]=['umeta_id'=>30,'user_id'=>3,'meta_value'=>'11'];else array_shift($db->meta);return true;};backfillFailure($fake,'store_owner_backfill_concurrent_conflict');isolatedAssert(in_array('rollback',$fake->events,true),'Carrera no hizo rollback.');if($race==='insert')isolatedAssert(!in_array('store_lock:11',$fake->events,true)&&!in_array('user_lock:3',$fake->events,true),'Conjunto creciente adquirio locks tardios.');}
$foreign=new R1aBackfillWpdb();$foreign->mutation=static function(string $event,R1aBackfillWpdb $db):bool{if($event!=='store_lock:10')return false;$db->stores[10]=99;return true;};backfillFailure($foreign,'store_owner_backfill_owner_conflict');
$newCanonical=new R1aBackfillWpdb();$newCanonical->stores[11]=null;$newCanonical->mutation=static function(string $event,R1aBackfillWpdb $db):bool{if($event!=='user_lock:10')return false;$db->stores[11]=10;return true;};backfillFailure($newCanonical,'store_owner_backfill_write_failed');isolatedAssert(!in_array('store_lock:11',$newCanonical->events,true),'Bloqueo tardio ID nuevo.');
$lockFailure=new R1aBackfillWpdb();$lockFailure->failAt='meta_user:10';backfillFailure($lockFailure,'store_owner_backfill_lock_failed');isolatedAssert($lockFailure->stores[2]===null&&$lockFailure->stores[10]===null&&in_array('rollback',$lockFailure->events,true),'Falla intermedia escribio o no revirtio.');
$writeFailure=new R1aBackfillWpdb();$writeFailure->failAt='second_write';backfillFailure($writeFailure,'store_owner_backfill_write_failed');isolatedAssert($writeFailure->stores[2]===null&&$writeFailure->stores[10]===null,'Rollback no revirtio escritura parcial.');
echo "R1A_BACKFILL_LOCK_ORDER_ISOLATED=PASS cases=11\n";

$r1aExistingUsers = [1=>true,2=>true,3=>true];
function get_userdata(int $userId): object|false
{
    global $r1aExistingUsers;
    return isset($r1aExistingUsers[$userId]) ? (object) ['ID'=>$userId] : false;
}
function clean_user_cache(int $userId): void {}

final class R1aOwnershipWpdb
{
    public string $prefix='iso_'; public string $users='iso_users'; public string $usermeta='iso_usermeta';
    public string $last_error='';
    /** @var array<int,?int> */ public array $stores=[10=>null,11=>null,12=>2];
    /** @var array<int,list<int>> */ public array $meta=[1=>[10],2=>[12],3=>[12]];
    public array $storeLockSequences=[]; public array $userLockSequences=[]; public bool $zeroRelease=false; public bool $failProjectionInsert=false;
    private array $values=[]; private ?array $snapshot=null;
    public function prepare(string $query,mixed ...$values):string{$this->values=$values;return $query;}
    public function get_col(string $query):array
    {
        if(str_contains($query,'SELECT id FROM')){ $user=(int)$this->values[0];$ids=[];foreach($this->stores as $id=>$owner)if($owner===$user)$ids[]=$id;sort($ids);return $ids; }
        if(str_contains($query,'SELECT ID FROM')){ $ids=array_map('intval',$this->values);sort($ids);$this->userLockSequences[]=$ids;return array_values(array_filter($ids,static fn(int $id):bool=>isset($GLOBALS['r1aExistingUsers'][$id]))); }
        if(str_contains($query,'SELECT DISTINCT user_id FROM')){ $store=(int)$this->values[1];$excluded=(int)$this->values[2];$users=[];foreach($this->meta as $user=>$ids)if($user!==$excluded&&in_array($store,$ids,true))$users[]=$user;return $users; }
        if(str_contains($query,'SELECT meta_value FROM'))return array_map('strval',$this->meta[(int)$this->values[0]]??[]);
        return [];
    }
    public function get_results(string $query,string $format):array
    {
        if(str_contains($query,'FROM iso_va_stores')){$ids=array_map('intval',$this->values);sort($ids);$this->storeLockSequences[]=$ids;$rows=[];foreach($ids as $id)if(array_key_exists($id,$this->stores))$rows[]=['id'=>(string)$id,'owner_user_id'=>$this->stores[$id]===null?null:(string)$this->stores[$id]];return $rows;}
        if(str_contains($query,'FROM iso_usermeta'))return [];
        return [];
    }
    public function get_row(string $query,string $format):?array
    {
        $id=(int)$this->values[0];
        return array_key_exists($id,$this->stores)?['id'=>(string)$id,'owner_user_id'=>$this->stores[$id]===null?null:(string)$this->stores[$id]]:null;
    }
    public function get_var(string $query):mixed
    {
        if(str_contains($query,'COUNT(DISTINCT user_id)')){ $store=(int)$this->values[1];$count=0;foreach($this->meta as $ids)if(in_array($store,$ids,true))$count++;return (string)$count; }
        return null;
    }
    public function query(string $query):int|false
    {
        if($query==='START TRANSACTION'){$this->snapshot=[$this->stores,$this->meta];return 0;}
        if($query==='COMMIT'){$this->snapshot=null;return 0;}
        if($query==='ROLLBACK'&&$this->snapshot!==null){[$this->stores,$this->meta]=$this->snapshot;$this->snapshot=null;return 0;}
        return false;
    }
    public function update(string $table,array $data,array $where):int|false
    {
        $id=(int)$where['id'];if(!array_key_exists($id,$this->stores))return 0;
        if(array_key_exists('owner_user_id',$where)&&$this->stores[$id]!==$where['owner_user_id'])return 0;
        if($this->zeroRelease&&$data['owner_user_id']===null)return 0;
        $changed=$this->stores[$id]!==$data['owner_user_id'];$this->stores[$id]=$data['owner_user_id'];return $changed?1:0;
    }
    public function delete(string $table,array $where):int|false
    { $user=(int)$where['user_id'];$count=count($this->meta[$user]??[]);$this->meta[$user]=[];return $count; }
    public function insert(string $table,array $data):int|false
    { if($this->failProjectionInsert)return false;$this->meta[(int)$data['user_id']][]=(int)$data['meta_value'];return 1; }
}

require_once dirname(__DIR__,2).'/app/Modules/Minimarket/Ownership/StoreOwnershipRepository.php';
$wpdb=new R1aOwnershipWpdb();$wpdb->stores[10]=1;$wpdb->meta[1]=[];
$ownership=new VeciAhorra\Modules\Minimarket\Ownership\StoreOwnershipRepository();
isolatedAssert($ownership->resolveStoreIdForOwnerUser(1)===10&&$wpdb->meta[1]===[],'Resolucion canonica escribio proyeccion.');
$ownership->reconcileCompatibilityProjection(10,1);
isolatedAssert($wpdb->stores[10]===1&&$wpdb->meta[1]===[10],'Reconciliacion explicita no proyecto autoridad.');
$ownership->unassignOwner(1);
isolatedAssert($ownership->resolveStoreIdForOwnerUser(1)===null&&$wpdb->meta[1]===[],'Lectura posterior restauro fallback.');
$wpdb->meta[1]=[10];$ownership->reconcileCompatibilityProjection(10,1);
isolatedAssert($wpdb->stores[10]===null&&$wpdb->meta[1]===[],'Reconciliacion explicita no limpio proyeccion sin autoridad.');
$wpdb->meta[1]=[10,11];
try{$ownership->reconcileCompatibilityProjection(10,1);throw new RuntimeException('Reconciliacion borro ambiguedad.');}
catch(RuntimeException $exception){isolatedAssert($exception->getMessage()==='store_owner_historical_user_ambiguous','Ambiguedad de reconciliacion incorrecta.');}
isolatedAssert($wpdb->meta[1]===[10,11],'Reconciliacion ambigua produjo escrituras.');

$wpdb=new R1aOwnershipWpdb();
$ownership=new VeciAhorra\Modules\Minimarket\Ownership\StoreOwnershipRepository();
try{$ownership->resolveStoreIdForOwnerUser(3);throw new RuntimeException('Fallback acepto owner ajeno.');}
catch(RuntimeException $exception){isolatedAssert($exception->getMessage()==='store_owner_projection_conflict','Fallo cerrado incorrecto.');}
isolatedAssert($wpdb->stores[12]===2&&$wpdb->meta[3]===[12],'Fallback conflictivo produjo escrituras.');
$ownership->setOwnerStoreForUser(1,10);
isolatedAssert($wpdb->stores[10]===1&&$wpdb->meta[1]===[10],'Asignacion atomica fallo.');
$wpdb->failProjectionInsert=true;
try{$ownership->setOwnerStoreForUser(1,11);throw new RuntimeException('Acepto fallo de proyeccion.');}
catch(RuntimeException $exception){isolatedAssert($exception->getMessage()==='store_owner_projection_write_failed','Fallo parcial incorrecto.');}
isolatedAssert($wpdb->stores[10]===1&&$wpdb->stores[11]===null&&$wpdb->meta[1]===[10],'Rollback posterior a escritura parcial fallo.');
$wpdb->failProjectionInsert=false;$wpdb->zeroRelease=true;
try{$ownership->setOwnerStoreForUser(1,11);throw new RuntimeException('Acepto liberacion cero.');}
catch(RuntimeException $exception){isolatedAssert($exception->getMessage()==='store_owner_unassignment_failed','Liberacion cero incorrecta.');}
isolatedAssert($wpdb->stores[10]===1&&$wpdb->stores[11]===null&&$wpdb->meta[1]===[10],'Rollback liberacion cero fallo.');
$wpdb->zeroRelease=false;
$ownership->setOwnerStoreForUser(1,11);
isolatedAssert($wpdb->stores[10]===null&&$wpdb->stores[11]===1&&$wpdb->meta[1]===[11],'Reasignacion atomica fallo.');
isolatedAssert(end($wpdb->storeLockSequences)===[10,11],'Orden global de Stores no fue ascendente.');
$ownership->unassignOwner(1);
isolatedAssert($wpdb->stores[11]===null&&$wpdb->meta[1]===[],'Desasignacion atomica fallo.');
try{$ownership->setOwnerStoreForUser(1,12);throw new RuntimeException('Reasigno Store ajeno.');}
catch(RuntimeException $exception){isolatedAssert($exception->getMessage()==='store_owner_store_already_owned','Conflicto de Store incorrecto.');}
isolatedAssert($wpdb->stores[12]===2&&$wpdb->meta[1]===[],'Rollback de Store ajeno fallo.');
$wpdb->stores[12]=null;
try{$ownership->setOwnerStoreForUser(1,12);throw new RuntimeException('Reasigno Store ajeno.');}
catch(RuntimeException $exception){isolatedAssert($exception->getMessage()==='store_owner_historical_store_ambiguous','Proyeccion legacy ajena no fue rechazada.');}
isolatedAssert($wpdb->stores[12]===null&&$wpdb->meta[1]===[]&&$wpdb->meta[3]===[12],'Rollback de proyeccion ajena fallo.');

$cross=new R1aOwnershipWpdb();$cross->stores=[10=>1,11=>2];$cross->meta=[1=>[10],2=>[11]];$wpdb=$cross;
foreach([[1,11],[2,10]] as [$user,$target])try{$ownership->setOwnerStoreForUser($user,$target);}catch(RuntimeException){}
isolatedAssert($cross->storeLockSequences[0]===[10,11]&&$cross->storeLockSequences[1]===[10,11],'Reasignaciones cruzadas no comparten orden global.');
isolatedAssert($cross->userLockSequences[0]===[1,2]&&$cross->userLockSequences[1]===[1,2],'Reasignaciones cruzadas no bloquean usuarios en orden global.');
$competition=new R1aOwnershipWpdb();$competition->stores=[10=>null];$competition->meta=[1=>[],2=>[]];$wpdb=$competition;
$ownership->setOwnerStoreForUser(1,10);
try{$ownership->setOwnerStoreForUser(2,10);throw new RuntimeException('Dos usuarios obtuvieron el mismo Store.');}
catch(RuntimeException $exception){isolatedAssert($exception->getMessage()==='store_owner_store_already_owned','Competencia por Store fallo abierta.');}
isolatedAssert($competition->stores[10]===1&&$competition->meta[1]===[10]&&$competition->meta[2]===[],'Competencia altero ganador.');

echo "R1A_OWNERSHIP_ISOLATED=PASS cases=15\n";

final class R1aOnboardingWpdb
{
    public string $prefix='iso_';public string $users='iso_users';public string $usermeta='iso_usermeta';
    public string $last_error='';
    public array $stores=[10=>1,11=>null,12=>2];
    public array $application;
    public ?array $verification;
    public bool $failUpdate=false;public bool $commitReturnsFalse=false;public bool $commitApplies=true;public bool $rollbackCloses=true;public bool $rollbackReturnsFalse=false;public bool $stateQueryFails=false;public mixed $stateOverride='__default__';public ?string $applicationReadMode=null;public ?string $applicationReadModeAfterCommit=null;public array $afterCommitMutation=[];public int $applicationReads=0; public array $events=[];
    private bool $inTransaction=false;
    private array $values=[];private ?array $snapshot=null;
    public function __construct(){ $this->application=['id'=>'50','public_id'=>'onb_iso','user_id'=>'1','account_email'=>'owner@example.test','owner_rut_normalized'=>'123456785','status'=>'ready_to_materialize','idempotency_key_hash'=>str_repeat('a',64),'terms_version'=>'2026-08','terms_accepted_at'=>'2026-08-01 00:00:00','store_id'=>null,'failure_code'=>null,'attempt_count'=>'0','last_attempt_at'=>null,'created_at'=>'2026-08-01 00:00:00','updated_at'=>'2026-08-01 00:00:03','abandoned_at'=>null];$this->verification=['id'=>'7','application_id'=>'50','purpose'=>'minimarket_account_activation','generation'=>'2','candidate_user_id'=>null,'attached_user_id'=>'1','email_binding_hash'=>str_repeat('e',32),'token_hash'=>str_repeat('t',32),'expires_at'=>'2026-08-02 00:00:00','consumed_at'=>'2026-08-01 00:00:02','failed_attempts'=>'0','resend_count'=>'1','last_sent_at'=>'2026-08-01 00:00:01','delivery_state'=>'sent','delivery_attempt_count'=>'1','last_error_code'=>null,'created_at'=>'2026-08-01 00:00:00','updated_at'=>'2026-08-01 00:00:02']; }
    public function prepare(string $query,mixed ...$values):string{$this->values=$values;return $query;}
    public function query(string $query):int|false{$this->events[]=$query;if($query==='START TRANSACTION'){$this->snapshot=$this->application;$this->inTransaction=true;return 0;}if($query==='COMMIT'){if($this->commitApplies){$this->snapshot=null;$this->inTransaction=false;$this->application=array_merge($this->application,$this->afterCommitMutation);}if($this->applicationReadModeAfterCommit!==null)$this->applicationReadMode=$this->applicationReadModeAfterCommit;return $this->commitReturnsFalse?false:0;}if($query==='ROLLBACK'){if($this->rollbackCloses){if($this->snapshot!==null)$this->application=$this->snapshot;$this->snapshot=null;$this->inTransaction=false;}return $this->rollbackReturnsFalse?false:0;}return false;}
    public function get_var(string $query):mixed{$this->events[]=$query;if($query==='SELECT @@in_transaction'){if($this->stateQueryFails){$this->last_error='hostile';return null;}return $this->stateOverride==='__default__'?($this->inTransaction?'1':'0'):$this->stateOverride;}return str_contains($query,'SELECT ID FROM')&&isset($GLOBALS['r1aExistingUsers'][(int)$this->values[0]])?(string)$this->values[0]:null;}
    public function get_row(string $query,string $format):?array
    {
        $this->events[]=$query;
        if(str_contains($query,'store_onboarding_applications')){$this->applicationReads++;if($this->applicationReadMode==='null_error'){$this->last_error='hostile';return null;}if((int)$this->values[0]!==50)return null;$row=str_contains($query,'SELECT *')?$this->application:['user_id'=>$this->application['user_id'],'status'=>$this->application['status'],'updated_at'=>$this->application['updated_at'],'store_id'=>$this->application['store_id']];if($this->applicationReadMode==='row_error')$this->last_error='hostile';return $row;}
        if(str_contains($query,'store_onboarding_email_verifications'))return $this->verification;
        if(str_contains($query,'va_stores')){$id=(int)$this->values[0];return array_key_exists($id,$this->stores)?['id'=>(string)$id,'owner_user_id'=>$this->stores[$id]===null?null:(string)$this->stores[$id]]:null;}
        return null;
    }
    public function update(string $table,array $data,array $where):int|false
    {
        $this->events[]='UPDATE';if($this->failUpdate)return false;
        if((int)$where['id']!==50||$this->application['status']!==$where['status']||$this->application['updated_at']!==$where['updated_at'])return 0;
        $this->application=array_merge($this->application,$data);return 1;
    }
}

require_once dirname(__DIR__,2).'/app/Modules/Minimarket/Onboarding/StoreOnboardingApplication.php';
require_once dirname(__DIR__,2).'/app/Modules/Minimarket/Onboarding/Contracts/StoreOnboardingApplicationWriter.php';
require_once dirname(__DIR__,2).'/app/Modules/Minimarket/Onboarding/Exceptions/OnboardingPublicIdCollisionException.php';
require_once dirname(__DIR__,2).'/app/Modules/Minimarket/Onboarding/Verification/StoreOnboardingEmailVerification.php';
require_once dirname(__DIR__,2).'/app/Modules/Minimarket/Onboarding/Verification/StoreOnboardingEmailVerificationRepository.php';
require_once dirname(__DIR__,2).'/app/Modules/Minimarket/Onboarding/StoreOnboardingApplicationRepository.php';
$onboarding=new VeciAhorra\Modules\Minimarket\Onboarding\StoreOnboardingApplicationRepository();
$wpdb=new R1aOnboardingWpdb();
$wpdb->application['status']='provisioning';$wpdb->application['user_id']=null;$wpdb->application['updated_at']='2026-08-01 00:00:00';
try{$onboarding->attachUser(50,999,'2026-08-01 00:00:00','2026-08-01 00:00:01');throw new RuntimeException('Acepto usuario inexistente.');}
catch(RuntimeException $exception){isolatedAssert($exception->getMessage()==='onboarding_user_missing','Error de usuario inexistente incorrecto.');}
isolatedAssert($wpdb->application['status']==='provisioning'&&$wpdb->application['user_id']===null,'Usuario inexistente produjo escritura parcial.');
$wpdb->application['updated_at']='2026-08-01 00:00:02';
try{$onboarding->attachUser(50,1,'2026-08-01 00:00:00','2026-08-01 00:00:01');throw new RuntimeException('Acepto aplicacion concurrente.');}
catch(RuntimeException $exception){isolatedAssert($exception->getMessage()==='onboarding_concurrent_modification','CAS attachUser incorrecto.');}
$wpdb->application['updated_at']='2026-08-01 00:00:00';
$wpdb->failUpdate=true;
try{$onboarding->attachUser(50,1,'2026-08-01 00:00:00','2026-08-01 00:00:01');throw new RuntimeException('Acepto fallo UPDATE.');}
catch(RuntimeException $exception){isolatedAssert($exception->getMessage()==='onboarding_attach_user_conflict','Fallo UPDATE incorrecto.');}
isolatedAssert($wpdb->application['status']==='provisioning'&&$wpdb->application['user_id']===null,'Rollback attachUser incompleto.');
$wpdb->failUpdate=false;$wpdb->events=[];
$attached=$onboarding->attachUser(50,1,'2026-08-01 00:00:00','2026-08-01 00:00:01');
isolatedAssert($attached->data['status']==='account_created'&&(int)$attached->data['user_id']===1,'attachUser atomico fallo.');
isolatedAssert(array_search('UPDATE',$wpdb->events,true)>array_search('SELECT ID FROM iso_users WHERE ID=%d FOR UPDATE',$wpdb->events,true),'Usuario no fue bloqueado antes del UPDATE.');
$wpdb=new R1aOnboardingWpdb();$wpdb->application['status']='provisioning';$wpdb->application['user_id']=null;$wpdb->application['updated_at']='2026-08-01 00:00:00';$wpdb->commitReturnsFalse=true;$wpdb->commitApplies=true;
$ambiguousApplied=$onboarding->attachUser(50,1,'2026-08-01 00:00:00','2026-08-01 00:00:01');
isolatedAssert($ambiguousApplied->data['status']==='account_created'&&in_array('ROLLBACK',$wpdb->events,true),'Commit aplicado/false no reconcilio replay.');
$wpdb=new R1aOnboardingWpdb();$wpdb->application['status']='provisioning';$wpdb->application['user_id']=null;$wpdb->application['updated_at']='2026-08-01 00:00:00';$wpdb->commitReturnsFalse=true;$wpdb->commitApplies=false;
try{$onboarding->attachUser(50,1,'2026-08-01 00:00:00','2026-08-01 00:00:01');throw new LogicException('Commit no aplicado aceptado.');}
catch(RuntimeException $exception){isolatedAssert($exception->getMessage()==='onboarding_attach_user_conflict'&&$exception->getPrevious()===null,'Commit no aplicado/false mal clasificado.');}
$wpdb=new R1aOnboardingWpdb();$wpdb->application['status']='provisioning';$wpdb->application['user_id']=null;$wpdb->application['updated_at']='2026-08-01 00:00:00';$wpdb->commitReturnsFalse=true;$wpdb->commitApplies=true;$wpdb->rollbackReturnsFalse=true;
$rollbackFalseClean=$onboarding->attachUser(50,1,'2026-08-01 00:00:00','2026-08-01 00:00:01');isolatedAssert($rollbackFalseClean->data['status']==='account_created','Rollback false limpio no reconcilio.');
$wpdb=new R1aOnboardingWpdb();$wpdb->application['status']='provisioning';$wpdb->application['user_id']=null;$wpdb->application['updated_at']='2026-08-01 00:00:00';$wpdb->commitReturnsFalse=true;$wpdb->commitApplies=false;$wpdb->rollbackReturnsFalse=true;$wpdb->rollbackCloses=false;
try{$onboarding->attachUser(50,1,'2026-08-01 00:00:00','2026-08-01 00:00:01');throw new LogicException('Conexion activa aceptada.');}catch(RuntimeException $exception){isolatedAssert($exception->getMessage()==='onboarding_attach_user_outcome_uncertain'&&$wpdb->applicationReads===2,'Conexion activa ejecuto relectura.');}
foreach([['stateQueryFails',true],['stateOverride',null],['stateOverride','00'],['stateOverride',1]] as [$property,$value]){
    $wpdb=new R1aOnboardingWpdb();$wpdb->application['status']='provisioning';$wpdb->application['user_id']=null;$wpdb->application['updated_at']='2026-08-01 00:00:00';$wpdb->commitReturnsFalse=true;$wpdb->commitApplies=true;$wpdb->{$property}=$value;
    try{$onboarding->attachUser(50,1,'2026-08-01 00:00:00','2026-08-01 00:00:01');throw new LogicException('Estado transaccional hostil aceptado.');}catch(RuntimeException $exception){isolatedAssert($exception->getMessage()==='onboarding_attach_user_outcome_uncertain'&&$wpdb->applicationReads===2,'Estado transaccional hostil ejecuto relectura.');}
}
$wpdb=new R1aOnboardingWpdb();$wpdb->last_error='stale';$wpdb->application['status']='provisioning';$wpdb->application['user_id']=null;$wpdb->application['updated_at']='2026-08-01 00:00:00';$clearedStale=$onboarding->attachUser(50,1,'2026-08-01 00:00:00','2026-08-01 00:00:01');isolatedAssert($clearedStale->data['status']==='account_created','Error previo contamino SELECT actual.');
foreach(['null_error','row_error'] as $readMode){$wpdb=new R1aOnboardingWpdb();$wpdb->application['status']='provisioning';$wpdb->application['user_id']=null;$wpdb->application['updated_at']='2026-08-01 00:00:00';$wpdb->commitReturnsFalse=true;$wpdb->commitApplies=true;$wpdb->applicationReadModeAfterCommit=$readMode;try{$onboarding->attachUser(50,1,'2026-08-01 00:00:00','2026-08-01 00:00:01');throw new LogicException('Read failure aceptado.');}catch(RuntimeException $exception){isolatedAssert($exception->getMessage()==='onboarding_attach_user_outcome_uncertain'&&$exception->getPrevious()===null,'Read failure mal clasificado.');}}
foreach([['store_id',10],['failure_code','unexpected'],['updated_at','2026-08-01 00:00:02'],['terms_version','changed']] as [$field,$value]){$wpdb=new R1aOnboardingWpdb();$wpdb->application['status']='provisioning';$wpdb->application['user_id']=null;$wpdb->application['updated_at']='2026-08-01 00:00:00';$wpdb->commitReturnsFalse=true;$wpdb->commitApplies=true;$wpdb->afterCommitMutation=[$field=>$value];try{$onboarding->attachUser(50,1,'2026-08-01 00:00:00','2026-08-01 00:00:01');throw new LogicException('Snapshot distinto aceptado.');}catch(RuntimeException $exception){isolatedAssert($exception->getMessage()==='onboarding_attach_user_conflict','Snapshot attach incompleto: '.$field);}}
$applicationProjection=new ReflectionMethod($onboarding,'applicationProjection');$applicationProjection->setAccessible(true);$verificationProjection=new ReflectionMethod($onboarding,'verificationProjection');$verificationProjection->setAccessible(true);$reconcileRecovery=new ReflectionMethod($onboarding,'reconcileRecovery');$reconcileRecovery->setAccessible(true);
$wpdb=new R1aOnboardingWpdb();$wpdb->application['status']='account_created';$wpdb->application['user_id']='1';$wpdb->application['updated_at']='2026-08-01 00:00:04';$expectedApplication=$applicationProjection->invoke($onboarding,$wpdb->application);$verificationEntity=VeciAhorra\Modules\Minimarket\Onboarding\Verification\StoreOnboardingEmailVerification::fromRow($wpdb->verification);$expectedVerification=$verificationProjection->invoke($onboarding,$verificationEntity);$exactRecovery=$reconcileRecovery->invoke($onboarding,50,$expectedApplication,$expectedVerification);isolatedAssert($exactRecovery->data['status']==='account_created','Recovery exacta no reconcilio.');
foreach([['generation','3'],['purpose','other'],['attached_user_id','2']] as [$field,$value]){$wpdb=new R1aOnboardingWpdb();$wpdb->application['status']='account_created';$wpdb->application['user_id']='1';$wpdb->application['updated_at']='2026-08-01 00:00:04';$wpdb->verification[$field]=$value;try{$reconcileRecovery->invoke($onboarding,50,$expectedApplication,$expectedVerification);throw new LogicException('Verification distinta aceptada.');}catch(RuntimeException $exception){isolatedAssert($exception->getMessage()==='onboarding_recovery_conflict'||$exception->getMessage()==='onboarding_recovery_outcome_uncertain','Recovery verification hostil mal clasificada: '.$field);}}
$wpdb=new R1aOnboardingWpdb();$wpdb->application['status']='account_created';$wpdb->application['user_id']='1';$wpdb->application['updated_at']='2026-08-01 00:00:04';$wpdb->verification=null;try{$reconcileRecovery->invoke($onboarding,50,$expectedApplication,$expectedVerification);throw new LogicException('Verification ausente aceptada.');}catch(RuntimeException $exception){isolatedAssert($exception->getMessage()==='onboarding_recovery_conflict','Verification ausente mal clasificada.');}
$wpdb=new R1aOnboardingWpdb();$wpdb->application['status']='provisioning';$wpdb->application['user_id']=null;$wpdb->application['updated_at']='2026-08-01 00:00:04';$wpdb->verification['attached_user_id']=null;$wpdb->verification['consumed_at']=null;$wpdb->verification['last_sent_at']=null;$wpdb->verification['delivery_state']='pending';$wpdb->verification['delivery_attempt_count']='0';$wpdb->verification['resend_count']='0';$wpdb->verification['updated_at']='2026-08-01 00:00:00';$expectedProvisioning=$applicationProjection->invoke($onboarding,$wpdb->application);$pendingEntity=VeciAhorra\Modules\Minimarket\Onboarding\Verification\StoreOnboardingEmailVerification::fromRow($wpdb->verification);$expectedPending=$verificationProjection->invoke($onboarding,$pendingEntity);$exactProvisioning=$reconcileRecovery->invoke($onboarding,50,$expectedProvisioning,$expectedPending);isolatedAssert($exactProvisioning->data['status']==='provisioning','Recovery provisioning exacta no reconcilio.');
$wpdb=new R1aOnboardingWpdb();
foreach([[999,'onboarding_store_missing'],[11,'onboarding_store_owner_missing'],[12,'onboarding_store_owner_conflict']] as [$store,$error]){
    try{$onboarding->attachMaterializedStore(50,$store,'2026-08-01 00:00:03','2026-08-01 00:00:04');throw new RuntimeException('Acepto referencia invalida.');}
    catch(RuntimeException $exception){isolatedAssert($exception->getMessage()===$error,"Esperaba {$error}.");}
    isolatedAssert($wpdb->application['status']==='ready_to_materialize'&&$wpdb->application['store_id']===null,'Fallo referencial modifico aplicacion.');
}
$materialized=$onboarding->attachMaterializedStore(50,10,'2026-08-01 00:00:03','2026-08-01 00:00:04');
isolatedAssert($materialized->data['status']==='store_materialized'&&(int)$materialized->data['store_id']===10,'Materializacion canonica fallo.');
$timestamp=$materialized->data['updated_at'];
$replayed=$onboarding->attachMaterializedStore(50,10,'2026-08-01 00:00:03','2026-08-01 00:00:09');
isolatedAssert($replayed->data['status']==='store_materialized'&&$replayed->data['updated_at']===$timestamp,'Replay valido modifico estado o timestamp.');
try{$onboarding->attachMaterializedStore(50,12,'2026-08-01 00:00:04','2026-08-01 00:00:09');throw new RuntimeException('Replay cambio Store.');}
catch(RuntimeException $exception){isolatedAssert($exception->getMessage()==='onboarding_materialized_store_conflict','Replay de Store distinto incorrecto.');}
unset($wpdb->stores[10]);
try{$onboarding->attachMaterializedStore(50,10,'2026-08-01 00:00:04','2026-08-01 00:00:09');throw new RuntimeException('Replay acepto Store eliminado.');}
catch(RuntimeException $exception){isolatedAssert($exception->getMessage()==='onboarding_store_missing','Replay Store eliminado incorrecto.');}
$wpdb->stores[10]=null;
try{$onboarding->attachMaterializedStore(50,10,'2026-08-01 00:00:04','2026-08-01 00:00:09');throw new RuntimeException('Replay acepto owner NULL.');}
catch(RuntimeException $exception){isolatedAssert($exception->getMessage()==='onboarding_store_owner_missing','Replay owner NULL incorrecto.');}
$wpdb->stores[10]=2;
try{$onboarding->attachMaterializedStore(50,10,'2026-08-01 00:00:04','2026-08-01 00:00:09');throw new RuntimeException('Replay acepto owner cambiado.');}
catch(RuntimeException $exception){isolatedAssert($exception->getMessage()==='onboarding_store_owner_conflict','Replay owner cambiado incorrecto.');}

echo "R1A_ONBOARDING_REFERENCES_ISOLATED=PASS cases=14\n";

$roleSource=file_get_contents(dirname(__DIR__,2).'/app/Modules/Minimarket/Identity/MinimarketRole.php');
$contextSource=file_get_contents(dirname(__DIR__,2).'/app/Modules/Minimarket/Identity/StoreContext.php');
isolatedAssert(is_string($roleSource)&&str_contains($roleSource,'setOwnerStoreForUser('),'MinimarketRole no usa escritura canonica.');
foreach(['get_user_meta(','update_user_meta(','delete_user_meta('] as $forbidden)isolatedAssert(!str_contains($roleSource,$forbidden),"MinimarketRole conserva acceso directo {$forbidden}");
isolatedAssert(is_string($contextSource)&&str_contains($contextSource,'resolveStoreIdForOwnerUser('),'StoreContext no usa ownership canonico.');
echo "R1A_ROLE_CONTEXT_ISOLATED=PASS assertions=5\n";
