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

$installer = file_get_contents(dirname(__DIR__, 2) . '/app/Database/Installer.php');
isolatedAssert(is_string($installer) && strpos($installer, 'MigrationManager::migrate();') < strpos($installer, 'MigrationManager::updateVersion();'), 'Installer versiona antes de migrar.');
$migrationSource=file_get_contents(dirname(__DIR__,2).'/app/Database/Migrations/CreateStoreOnboardingFoundation.php');
isolatedAssert(is_string($migrationSource)&&strpos($migrationSource,'$this->assertStructure();')<strpos($migrationSource,'$this->backfillValidatedOwners();'),'Backfill ocurre antes de validar esquema.');
isolatedAssert(!str_contains((string)$installer,'catch ('),'Installer oculta excepcion de migracion.');

echo "R1A_SCHEMA_ISOLATED=PASS cases=5\nR1A_INSTALLER_ORDER=PASS assertions=3\n";

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
    /** @var array<int,?int> */ public array $stores=[10=>null,11=>null,12=>2];
    /** @var array<int,list<int>> */ public array $meta=[1=>[10],2=>[12],3=>[12]];
    private array $values=[]; private ?array $snapshot=null;
    public function prepare(string $query,mixed ...$values):string{$this->values=$values;return $query;}
    public function get_col(string $query):array
    {
        if(str_contains($query,'SELECT id FROM')){ $user=(int)$this->values[0];$ids=[];foreach($this->stores as $id=>$owner)if($owner===$user)$ids[]=$id;sort($ids);return $ids; }
        if(str_contains($query,'SELECT DISTINCT user_id FROM')){ $store=(int)$this->values[1];$excluded=(int)$this->values[2];$users=[];foreach($this->meta as $user=>$ids)if($user!==$excluded&&in_array($store,$ids,true))$users[]=$user;return $users; }
        if(str_contains($query,'SELECT meta_value FROM'))return array_map('strval',$this->meta[(int)$this->values[0]]??[]);
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
        $changed=$this->stores[$id]!==$data['owner_user_id'];$this->stores[$id]=$data['owner_user_id'];return $changed?1:0;
    }
    public function delete(string $table,array $where):int|false
    { $user=(int)$where['user_id'];$count=count($this->meta[$user]??[]);$this->meta[$user]=[];return $count; }
    public function insert(string $table,array $data):int|false
    { $this->meta[(int)$data['user_id']][]=(int)$data['meta_value'];return 1; }
}

require_once dirname(__DIR__,2).'/app/Modules/Minimarket/Ownership/StoreOwnershipRepository.php';
$wpdb=new R1aOwnershipWpdb();
$ownership=new VeciAhorra\Modules\Minimarket\Ownership\StoreOwnershipRepository();
try{$ownership->resolveStoreIdForOwnerUser(3);throw new RuntimeException('Fallback acepto owner ajeno.');}
catch(RuntimeException $exception){isolatedAssert($exception->getMessage()==='store_owner_projection_conflict','Fallo cerrado incorrecto.');}
isolatedAssert($wpdb->stores[12]===2&&$wpdb->meta[3]===[12],'Fallback conflictivo produjo escrituras.');
$ownership->setOwnerStoreForUser(1,10);
isolatedAssert($wpdb->stores[10]===1&&$wpdb->meta[1]===[10],'Asignacion atomica fallo.');
$ownership->setOwnerStoreForUser(1,11);
isolatedAssert($wpdb->stores[10]===null&&$wpdb->stores[11]===1&&$wpdb->meta[1]===[11],'Reasignacion atomica fallo.');
$ownership->unassignOwner(1);
isolatedAssert($wpdb->stores[11]===null&&$wpdb->meta[1]===[],'Desasignacion atomica fallo.');
try{$ownership->setOwnerStoreForUser(1,12);throw new RuntimeException('Reasigno Store ajeno.');}
catch(RuntimeException $exception){isolatedAssert($exception->getMessage()==='store_owner_store_already_owned','Conflicto de Store incorrecto.');}
isolatedAssert($wpdb->stores[12]===2&&$wpdb->meta[1]===[],'Rollback de Store ajeno fallo.');
$wpdb->stores[12]=null;
try{$ownership->setOwnerStoreForUser(1,12);throw new RuntimeException('Reasigno Store ajeno.');}
catch(RuntimeException $exception){isolatedAssert($exception->getMessage()==='store_owner_historical_store_ambiguous','Proyeccion legacy ajena no fue rechazada.');}
isolatedAssert($wpdb->stores[12]===null&&$wpdb->meta[1]===[]&&$wpdb->meta[3]===[12],'Rollback de proyeccion ajena fallo.');

echo "R1A_OWNERSHIP_ISOLATED=PASS cases=6\n";

final class R1aOnboardingWpdb
{
    public string $prefix='iso_';public string $users='iso_users';public string $usermeta='iso_usermeta';
    public array $stores=[10=>1,11=>null,12=>2];
    public array $application;
    private array $values=[];private ?array $snapshot=null;
    public function __construct(){ $this->application=['id'=>'50','public_id'=>'onb_iso','user_id'=>'1','account_email'=>'owner@example.test','owner_rut_normalized'=>'123456785','status'=>'ready_to_materialize','idempotency_key_hash'=>str_repeat('a',64),'terms_version'=>'2026-08','terms_accepted_at'=>'2026-08-01 00:00:00','store_id'=>null,'failure_code'=>null,'attempt_count'=>'0','last_attempt_at'=>null,'created_at'=>'2026-08-01 00:00:00','updated_at'=>'2026-08-01 00:00:03','abandoned_at'=>null]; }
    public function prepare(string $query,mixed ...$values):string{$this->values=$values;return $query;}
    public function query(string $query):int|false{if($query==='START TRANSACTION'){$this->snapshot=$this->application;return 0;}if($query==='COMMIT'){$this->snapshot=null;return 0;}if($query==='ROLLBACK'){if($this->snapshot!==null)$this->application=$this->snapshot;$this->snapshot=null;return 0;}return false;}
    public function get_var(string $query):mixed{return str_contains($query,'SELECT ID FROM')&&isset($GLOBALS['r1aExistingUsers'][(int)$this->values[0]])?(string)$this->values[0]:null;}
    public function get_row(string $query,string $format):?array
    {
        if(str_contains($query,'store_onboarding_applications')){if((int)$this->values[0]!==50)return null;return str_contains($query,'SELECT *')?$this->application:['user_id'=>$this->application['user_id'],'status'=>$this->application['status'],'updated_at'=>$this->application['updated_at']];}
        if(str_contains($query,'va_stores')){$id=(int)$this->values[0];return array_key_exists($id,$this->stores)?['id'=>(string)$id,'owner_user_id'=>$this->stores[$id]===null?null:(string)$this->stores[$id]]:null;}
        return null;
    }
    public function update(string $table,array $data,array $where):int|false
    {
        if((int)$where['id']!==50||$this->application['status']!==$where['status']||$this->application['updated_at']!==$where['updated_at'])return 0;
        $this->application=array_merge($this->application,$data);return 1;
    }
}

require_once dirname(__DIR__,2).'/app/Modules/Minimarket/Onboarding/StoreOnboardingApplication.php';
require_once dirname(__DIR__,2).'/app/Modules/Minimarket/Onboarding/StoreOnboardingApplicationRepository.php';
$onboarding=new VeciAhorra\Modules\Minimarket\Onboarding\StoreOnboardingApplicationRepository();
$wpdb=new R1aOnboardingWpdb();
try{$onboarding->attachUser(50,999,'2026-08-01 00:00:00','2026-08-01 00:00:01');throw new RuntimeException('Acepto usuario inexistente.');}
catch(RuntimeException $exception){isolatedAssert($exception->getMessage()==='onboarding_user_missing','Error de usuario inexistente incorrecto.');}
foreach([[999,'onboarding_store_missing'],[11,'onboarding_store_owner_missing'],[12,'onboarding_store_owner_conflict']] as [$store,$error]){
    try{$onboarding->attachMaterializedStore(50,$store,'2026-08-01 00:00:03','2026-08-01 00:00:04');throw new RuntimeException('Acepto referencia invalida.');}
    catch(RuntimeException $exception){isolatedAssert($exception->getMessage()===$error,"Esperaba {$error}.");}
    isolatedAssert($wpdb->application['status']==='ready_to_materialize'&&$wpdb->application['store_id']===null,'Fallo referencial modifico aplicacion.');
}
$materialized=$onboarding->attachMaterializedStore(50,10,'2026-08-01 00:00:03','2026-08-01 00:00:04');
isolatedAssert($materialized->data['status']==='store_materialized'&&(int)$materialized->data['store_id']===10,'Materializacion canonica fallo.');

echo "R1A_ONBOARDING_REFERENCES_ISOLATED=PASS cases=5\n";

$roleSource=file_get_contents(dirname(__DIR__,2).'/app/Modules/Minimarket/Identity/MinimarketRole.php');
$contextSource=file_get_contents(dirname(__DIR__,2).'/app/Modules/Minimarket/Identity/StoreContext.php');
isolatedAssert(is_string($roleSource)&&str_contains($roleSource,'setOwnerStoreForUser('),'MinimarketRole no usa escritura canonica.');
foreach(['get_user_meta(','update_user_meta(','delete_user_meta('] as $forbidden)isolatedAssert(!str_contains($roleSource,$forbidden),"MinimarketRole conserva acceso directo {$forbidden}");
isolatedAssert(is_string($contextSource)&&str_contains($contextSource,'resolveStoreIdForOwnerUser('),'StoreContext no usa ownership canonico.');
echo "R1A_ROLE_CONTEXT_ISOLATED=PASS assertions=5\n";
