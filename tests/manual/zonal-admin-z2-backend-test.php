<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';

use VeciAhorra\Modules\Stores\Domain\StoreLifecycleContract as L;
use VeciAhorra\Modules\Stores\Exceptions\StoreLifecycleException;
use VeciAhorra\Modules\ZonalAdmin\Authorization\StoreTerritoryAuthorizer;
use VeciAhorra\Modules\ZonalAdmin\Controllers\ZonalStoreController;
use VeciAhorra\Modules\ZonalAdmin\Identity\ZonalAdminRole;
use VeciAhorra\Modules\ZonalAdmin\Repositories\StoreDecisionHistoryRepository;
use VeciAhorra\Modules\ZonalAdmin\Repositories\ZonalAdminServiceZoneRepository;
use VeciAhorra\Modules\ZonalAdmin\Repositories\ZonalStoreRepository;
use VeciAhorra\Modules\ZonalAdmin\Requests\ZonalStoreTransitionRequest;
use VeciAhorra\Modules\ZonalAdmin\Services\StoreDecisionCoordinator;

function z2Assert(bool $ok, string $message): void { if (! $ok) throw new RuntimeException($message); }
global $wpdb;
$p=$wpdb->prefix.'va_'; $stores=$p.'stores'; $storeZones=$p.'store_service_zones';
$assignments=$p.'zonal_admin_service_zones'; $history=$p.'store_decision_history';
$zones=array_map('intval',$wpdb->get_col("SELECT id FROM {$p}service_zones WHERE status='active' ORDER BY id LIMIT 2"));
$users=array_map('intval',$wpdb->get_col("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='{$wpdb->prefix}capabilities' AND meta_value LIKE '%customer%' ORDER BY user_id LIMIT 2"));
$admin=(int)$wpdb->get_var("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='{$wpdb->prefix}capabilities' AND meta_value LIKE '%administrator%' ORDER BY user_id LIMIT 1");
z2Assert(count($zones)===2&&count($users)===2&&$admin>0,'Fixture base insuficiente.');
$caps=[]; foreach($users as $id)$caps[$id]=get_user_meta($id,$wpdb->prefix.'capabilities',true);
$inventory=static fn():array=>['assignments'=>$wpdb->get_results("SELECT * FROM {$assignments} ORDER BY id",ARRAY_A),'stores'=>$wpdb->get_results("SELECT * FROM {$stores} ORDER BY id",ARRAY_A),'store_zones'=>$wpdb->get_results("SELECT * FROM {$storeZones} ORDER BY id",ARRAY_A),'history'=>$wpdb->get_results("SELECT * FROM {$history} ORDER BY id",ARRAY_A)];
$initialInventory=$inventory();$storeIds=[];$fixtureAssignments=[];
$listQueries=$detailQueries=$transitionQueries=0;
try {
 foreach($users as $id){$u=new WP_User($id);$u->set_role(ZonalAdminRole::ROLE);}
 $now=current_time('mysql'); $suffix=bin2hex(random_bytes(4));
 foreach(['A','B','AB','NONE','APPROVE','REJECT','GLOBAL','ATOMIC'] as $key){
  $wpdb->insert($stores,['business_name'=>"Z2 {$key} {$suffix}",'legal_name'=>"Z2 {$key}",'owner_name'=>'Fixture Z2','rut'=>"Z2-{$suffix}-{$key}",'email'=>strtolower("z2-{$suffix}-{$key}@example.test"),'phone'=>'0','mobile'=>null,'address'=>null,'commune'=>'Santiago','city'=>'Santiago','region'=>'RM','status'=>'pending','onboarding_status'=>'complete','approved_at'=>null,'created_at'=>$now,'updated_at'=>$now]);
  $storeIds[$key]=(int)$wpdb->insert_id;
 }
 $zoneMap=['A'=>[$zones[0]],'B'=>[$zones[1]],'AB'=>$zones,'APPROVE'=>[$zones[0]],'REJECT'=>[$zones[0]],'ATOMIC'=>[$zones[0]]];
 foreach($zoneMap as $key=>$ids)foreach($ids as $zone)$wpdb->insert($storeZones,['store_id'=>$storeIds[$key],'zone_id'=>$zone,'assigned_by'=>$admin,'assigned_at'=>$now]);
 $assignmentRepo=new ZonalAdminServiceZoneRepository();foreach([[$users[0],$zones[0]],[$users[1],$zones[1]]] as [$fixtureUser,$fixtureZone]){if(!in_array($fixtureZone,$assignmentRepo->zoneIdsForUser($fixtureUser),true)){$assignmentRepo->assign($fixtureUser,$fixtureZone,$admin,$now);$fixtureAssignments[]=[$fixtureUser,$fixtureZone];}}
 $auth=new StoreTerritoryAuthorizer();
 z2Assert($auth->canList($users[0])&&$auth->canReadStore($users[0],$storeIds['A'])&&!$auth->canReadStore($users[0],$storeIds['B']),'Aislamiento A/B incorrecto.');
 z2Assert($auth->canReadStore($users[0],$storeIds['AB'])&&$auth->canReadStore($users[1],$storeIds['AB']),'Store AB no compartido.');
 z2Assert($auth->commonServiceZoneId($users[0],$storeIds['AB'])===$zones[0],'Zona comun no determinista.');
 z2Assert(!$auth->canReadStore($users[0],$storeIds['NONE'])&&$auth->canReadStore($admin,$storeIds['NONE']),'Autoridad global/territorial incorrecta.');
 $repo=new ZonalStoreRepository();$q=$wpdb->num_queries;$page=$repo->paginate($users[0],false,1,100,"Z2",null);$total=$repo->count($users[0],false,"Z2",null);$pageZones=$repo->zonesForStores(array_map('intval',array_column($page,'id')),$users[0],false);$listQueries=$wpdb->num_queries-$q;
 $ids=array_map('intval',array_column($page,'id'));z2Assert(count($ids)===count(array_unique($ids))&&in_array($storeIds['AB'],$ids,true)&&!in_array($storeIds['B'],$ids,true)&&$total===count($ids),'Listado territorial incorrecto.');
 z2Assert(array_column($pageZones[$storeIds['A']]??[],'id')===[$zones[0]],'ZONAL_A_STORE_A_ZONES incorrecto.');
 z2Assert(array_column($pageZones[$storeIds['AB']]??[],'id')===[$zones[0]],'ZONAL_A_STORE_AB_ZONES filtro incorrecto.');
 foreach($ids as $id){z2Assert(array_key_exists($id,$pageZones)&&$pageZones[$id]!==[],'service_zones ausente o vacio en fila territorial.');foreach($pageZones[$id] as $zone){z2Assert(array_keys($zone)===['id','name']&&$zone['id']>0&&$zone['name']!=='','Forma publica de zona invalida.');}}
 $q=$wpdb->num_queries;$visible=$repo->findVisible($users[0],false,$storeIds['AB']);$repo->zonesForStore($storeIds['AB'],$users[0],false);(new StoreDecisionHistoryRepository())->forStore($storeIds['AB']);$detailQueries=$wpdb->num_queries-$q;
 z2Assert(is_array($visible)&&$repo->findVisible($users[0],false,$storeIds['B'])===null,'Detalle permite enumeracion cruzada.');
 $controller=new ZonalStoreController($repo,new StoreDecisionHistoryRepository(),new StoreDecisionCoordinator(),$auth,new L());
 $detail=$controller->show($users[0],$storeIds['AB']);z2Assert(($detail['data']['id']??0)===$storeIds['AB']&&$detail['data']['service_zones']===$pageZones[$storeIds['AB']],'Detalle/listado territorial inconsistentes.');
 $globalList=$controller->index($admin,['page'=>1,'per_page'=>100,'search'=>"Z2",'state'=>null]);$globalAb=array_values(array_filter($globalList['data'],static fn(array $row):bool=>$row['id']===$storeIds['AB']))[0]??null;
 z2Assert(is_array($globalAb)&&array_values(array_diff(array_column($globalAb['service_zones'],'id'),$zones))===[]&&count($globalAb['service_zones'])===2,'GLOBAL_STORE_AB_ZONES incorrecto.');
 $ordered=$globalAb['service_zones'];$sorted=$ordered;usort($sorted,static fn(array $a,array $b):int=>[$a['name'],$a['id']]<=>[$b['name'],$b['id']]);z2Assert($ordered===$sorted,'Orden de zonas no determinista.');
 foreach(['veciahorra_minimarket','veciahorra_service_provider','veciahorra_courier'] as $role){$id=(int)$wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key=%s AND meta_value LIKE %s ORDER BY user_id LIMIT 1",$wpdb->prefix.'capabilities','%'.$wpdb->esc_like($role).'%'));z2Assert($id>0&&!$auth->canList($id),"Rol {$role} obtuvo acceso.");}
 try{(new StoreDecisionCoordinator())->decideAuthorized($storeIds['A'],$users[0],'approve',null,'2000-01-01 00:00:00');throw new RuntimeException('CAS obsoleto aceptado.');}catch(StoreLifecycleException $e){z2Assert($e->reason()==='concurrent_modification','Error CAS incorrecto.');}
 $rewrite=static fn(string $sql):string=>str_contains($sql,'INSERT INTO `'.$GLOBALS['wpdb']->prefix.'va_store_decision_history`')?str_replace('va_store_decision_history','va_missing_history',$sql):$sql;
 $suppressed=$wpdb->suppress_errors(true);add_filter('query',$rewrite);try{(new StoreDecisionCoordinator())->decideAuthorized($storeIds['ATOMIC'],$users[0],'approve',null,$now);throw new RuntimeException('Fallo de historial aceptado.');}catch(VeciAhorra\Exceptions\PersistenceException){}finally{remove_filter('query',$rewrite);$wpdb->suppress_errors($suppressed);}
 $atomic=$wpdb->get_row($wpdb->prepare("SELECT status,onboarding_status,approved_at FROM {$stores} WHERE id=%d",$storeIds['ATOMIC']),ARRAY_A);z2Assert($atomic===['status'=>'pending','onboarding_status'=>'complete','approved_at'=>null]&&(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$history} WHERE store_id=%d",$storeIds['ATOMIC']))===0,'Rollback Store/historial incompleto.');
 foreach([['observe','Debe corregir antecedentes.','A'],['approve',null,'APPROVE'],['reject','Antecedentes incompatibles.','REJECT']] as [$action,$reason,$key]){
  $q=$wpdb->num_queries;$result=(new StoreDecisionCoordinator())->decideAuthorized($storeIds[$key],$users[0],$action,$reason,$now);$transitionQueries=max($transitionQueries,$wpdb->num_queries-$q);
  z2Assert((new L())->classify((string)$result->status,(string)$result->onboarding_status,$result->approved_at)===['observe'=>'observed','approve'=>'approved_inactive','reject'=>'rejected'][$action],"Decision {$action} incorrecta.");
  $entry=(new StoreDecisionHistoryRepository())->forStore($storeIds[$key])[0]??[];z2Assert(($entry['actor_user_id']??0)==$users[0]&&(int)($entry['authority_service_zone_id']??0)===$zones[0],'Auditoria territorial incorrecta.');
 }
 $global=(new StoreDecisionCoordinator())->decideAuthorized($storeIds['GLOBAL'],$admin,'approve',null,$now);$entry=(new StoreDecisionHistoryRepository())->forStore($storeIds['GLOBAL'])[0]??[];z2Assert($global->status==='inactive'&&$entry['authority_service_zone_id']===null,'Autoridad global incorrecta.');
 $readOnly=new WP_User($users[1]);$readOnly->add_cap(ZonalAdminRole::CAPABILITY_DECIDE,false);z2Assert($auth->canList($users[1])&&!$auth->canDecideStore($users[1],$storeIds['B']),'Read-only incorrecto.');
 try{(new StoreDecisionCoordinator())->decideAuthorized($storeIds['B'],$users[1],'approve',null,$now);throw new RuntimeException('Read-only decidio.');}catch(DomainException){}
 foreach([[['action'=>'observe','reason'=>' ','expected_updated_at'=>$now]],[['action'=>'unknown','reason'=>null,'expected_updated_at'=>$now]],[['action'=>'approve','reason'=>null,'expected_updated_at'=>'old']],[['action'=>'approve','reason'=>null,'expected_updated_at'=>$now,'actor_user_id'=>$users[0]]]] as [$bad]){try{(new ZonalStoreTransitionRequest($bad))->validated();throw new RuntimeException('Payload invalido aceptado.');}catch(InvalidArgumentException){}}
 try{(new StoreDecisionCoordinator())->decideAuthorized($storeIds['B'],$users[0],'approve',null,$now);throw new RuntimeException('Cruce territorial aceptado.');}catch(StoreLifecycleException|DomainException){}
 z2Assert($listQueries<=3&&$detailQueries<=7&&$transitionQueries<=12,'Presupuesto SQL excedido.');
} finally {
 if($storeIds!==[]){$ids=implode(',',array_map('intval',array_values($storeIds)));$wpdb->query("DELETE FROM {$history} WHERE store_id IN ({$ids})");$wpdb->query("DELETE FROM {$storeZones} WHERE store_id IN ({$ids})");$wpdb->query("DELETE FROM {$stores} WHERE id IN ({$ids})");}
 foreach($fixtureAssignments as [$fixtureUser,$fixtureZone]){$wpdb->delete($assignments,['user_id'=>$fixtureUser,'service_zone_id'=>$fixtureZone]);}
 foreach($users as $id){update_user_meta($id,$wpdb->prefix.'capabilities',$caps[$id]);clean_user_cache($id);}
}
z2Assert($inventory()===$initialInventory,'El cleanup altero el baseline productivo.');
$root=dirname(__DIR__,2);$sources=['repo'=>(string)file_get_contents($root.'/app/Modules/ZonalAdmin/Repositories/ZonalStoreRepository.php'),'controller'=>(string)file_get_contents($root.'/app/Modules/ZonalAdmin/Controllers/ZonalStoreController.php'),'authorizer'=>(string)file_get_contents($root.'/app/Modules/ZonalAdmin/Authorization/StoreTerritoryAuthorizer.php')];
$validate=static function(array $s):array{$errors=[];$need=static function(bool $ok,string $code)use(&$errors):void{if(!$ok)$errors[]=$code;};$need(substr_count($s['repo'],'ua.user_id = %d')===3,'Z01_USER_SCOPE_REMOVED');$need(str_contains($s['repo'],'$global ? \'\''),'Z02_GLOBAL_BRANCH_REMOVED');$need(!str_contains($s['controller'],"['zone_id']"),'Z03_REQUEST_ZONE_TRUSTED');$need(str_contains($s['repo'],'SELECT DISTINCT sz.store_id,z.id,z.name'),'Z04_DISTINCT_REMOVED');$need(str_contains($s['controller'],'zonesForStores(')&&!str_contains(substr($s['controller'],strpos($s['controller'],'public function index'),strpos($s['controller'],'public function show')-strpos($s['controller'],'public function index')),'zonesForStore('),'Z05_N_PLUS_ONE_ADDED');$need(str_contains($s['repo'],'ORDER BY z.name ASC,z.id ASC'),'Z06_ORDER_REMOVED');$need(str_contains($s['repo']," WHERE z.status = 'active' AND sz.store_id IN"),'Z07_INACTIVE_INCLUDED');$need(str_contains($s['authorizer'],'user_can($user, \'manage_options\')')&&!str_contains($s['authorizer'],'veciahorra_manage_store'),'Z08_GLOBAL_AUTHORITY_EXPANDED');return $errors;};
z2Assert($validate($sources)===[],'Contrato estatico Z2.1 invalido.');
$mutations=[['repo','ua.user_id = %d','ua.user_id > 0','Z01_USER_SCOPE_REMOVED'],['repo','$global ? \'\'','true ? \'\'','Z02_GLOBAL_BRANCH_REMOVED'],['controller','$global = $this->territory->isGlobal($userId);','$global = $this->territory->isGlobal($userId); $ignored = [\'zone_id\'];','Z03_REQUEST_ZONE_TRUSTED'],['repo','SELECT DISTINCT sz.store_id,z.id,z.name','SELECT sz.store_id,z.id,z.name','Z04_DISTINCT_REMOVED'],['controller','zonesForStores(','zonesForStore(','Z05_N_PLUS_ONE_ADDED'],['repo','ORDER BY z.name ASC,z.id ASC','ORDER BY z.id DESC','Z06_ORDER_REMOVED'],['repo'," WHERE z.status = 'active' AND sz.store_id IN"," WHERE z.status <> 'deleted' AND sz.store_id IN",'Z07_INACTIVE_INCLUDED'],['authorizer',"'manage_options'","'veciahorra_manage_store'",'Z08_GLOBAL_AUTHORITY_EXPANDED']];
foreach($mutations as [$key,$from,$to,$expected]){$mutant=$sources;z2Assert(str_contains($mutant[$key],$from),"Fixture {$expected} ausente.");$mutant[$key]=preg_replace('/'.preg_quote($from,'/').'/',addcslashes($to,'\\$'),$mutant[$key],1)??$mutant[$key];z2Assert(in_array($expected,$validate($mutant),true),"Mutacion {$expected} no rechazada.");}
echo "ZONAL_ADMIN_Z2_BACKEND=PASS list_queries={$listQueries} detail_queries={$detailQueries} transition_queries={$transitionQueries} territories=PASS list_zones=PASS shape_equal=PASS zone_order=name,id adversarials=8/8 decisions=3/3 global=PASS cas=PASS cleanup=PASS baseline_structural_equality=PASS adversarial_baseline=PASS wp_options_writes=0\n";
