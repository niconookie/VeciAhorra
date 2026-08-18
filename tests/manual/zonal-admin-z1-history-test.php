<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';

use VeciAhorra\Modules\Stores\Domain\StoreLifecycleContract as L;
use VeciAhorra\Modules\ZonalAdmin\Identity\ZonalAdminRole;
use VeciAhorra\Modules\ZonalAdmin\Repositories\StoreDecisionHistoryRepository;
use VeciAhorra\Modules\ZonalAdmin\Repositories\ZonalAdminServiceZoneRepository;
use VeciAhorra\Modules\ZonalAdmin\Services\StoreDecisionCoordinator;

function zhAssert(bool $condition, string $message): void { if (! $condition) { throw new RuntimeException($message); } }
global $wpdb;
$storesTable = $wpdb->prefix . 'va_stores';
$historyTable = $wpdb->prefix . 'va_store_decision_history';
$assignmentTable = $wpdb->prefix . 'va_zonal_admin_service_zones';
$storeZoneTable = $wpdb->prefix . 'va_store_service_zones';
$adminId = (int)$wpdb->get_var("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='{$wpdb->prefix}capabilities' AND meta_value LIKE '%administrator%' ORDER BY user_id LIMIT 1");
$zonalId = (int)$wpdb->get_var("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='{$wpdb->prefix}capabilities' AND meta_value LIKE '%customer%' ORDER BY user_id LIMIT 1");
$zoneId = (int)$wpdb->get_var("SELECT id FROM {$wpdb->prefix}va_service_zones ORDER BY id LIMIT 1");
zhAssert($adminId > 0 && $zonalId > 0 && $zoneId > 0, 'Fixture base insuficiente.');
$originalCaps = get_user_meta($zonalId, $wpdb->prefix . 'capabilities', true);
$inventory = static fn(): array => ['assignments'=>$wpdb->get_results("SELECT * FROM {$assignmentTable} ORDER BY id",ARRAY_A),'stores'=>$wpdb->get_results("SELECT * FROM {$storesTable} ORDER BY id",ARRAY_A),'store_zones'=>$wpdb->get_results("SELECT * FROM {$storeZoneTable} ORDER BY id",ARRAY_A),'history'=>$wpdb->get_results("SELECT * FROM {$historyTable} ORDER BY id",ARRAY_A)];
$initialInventory=$inventory(); $storeIds=[]; $fixtureAssignmentCreated=false;
try {
    $zonal = new WP_User($zonalId); $zonal->set_role(ZonalAdminRole::ROLE);
    foreach (['approve','observe','reject'] as $action) {
        $now = current_time('mysql');
        $wpdb->insert($storesTable, ['business_name'=>'Z1 fixture','legal_name'=>'Z1 fixture','owner_name'=>'Z1 fixture','rut'=>'Z1-' . $action,'email'=>'z1-' . $action . '@example.test','phone'=>'0','mobile'=>null,'address'=>null,'commune'=>null,'city'=>null,'region'=>null,'status'=>'pending','onboarding_status'=>'complete','approved_at'=>null,'created_at'=>$now,'updated_at'=>$now]);
        $storeIds[] = $storeId = (int)$wpdb->insert_id;
        if ($action === 'observe') {
            $fixtureAssignmentCreated=!in_array($zoneId,(new ZonalAdminServiceZoneRepository())->zoneIdsForUser($zonalId),true);
            if($fixtureAssignmentCreated){(new ZonalAdminServiceZoneRepository())->assign($zonalId,$zoneId,$adminId,$now);}
            $wpdb->insert($storeZoneTable,['store_id'=>$storeId,'zone_id'=>$zoneId,'assigned_by'=>$adminId,'assigned_at'=>$now]);
            $actor=$zonalId; $authority=$zoneId; $reason='Debe corregir antecedentes.';
        } else { $actor=$adminId; $authority=null; $reason=$action === 'reject' ? 'Solicitud incompatible.' : null; }
        $updated=(new StoreDecisionCoordinator())->decide($storeId,$actor,$action,$reason,$authority);
        $expected=['approve'=>'approved_inactive','observe'=>'observed','reject'=>'rejected'][$action];
        $lifecycle=new L();
        zhAssert($lifecycle->classify((string)$updated->status,(string)$updated->onboarding_status,$updated->approved_at)===$expected,"Decision {$action} incorrecta.");
        if ($action === 'observe' && $fixtureAssignmentCreated) { (new ZonalAdminServiceZoneRepository())->unassign($zonalId,$zoneId); }
    }
    foreach ($storeIds as $storeId) { zhAssert(count((new StoreDecisionHistoryRepository())->forStore($storeId)) === 1, 'Decision sin historial unico.'); }
    $target=$storeIds[1]; $before=$wpdb->get_row($wpdb->prepare("SELECT status,onboarding_status,approved_at FROM {$storesTable} WHERE id=%d",$target),ARRAY_A);
    try { (new StoreDecisionCoordinator())->decide($target,$adminId,L::ACTION_REJECT,'',null); throw new RuntimeException('Decision incompleta aceptada.'); } catch (InvalidArgumentException) {}
    $after=$wpdb->get_row($wpdb->prepare("SELECT status,onboarding_status,approved_at FROM {$storesTable} WHERE id=%d",$target),ARRAY_A);
    zhAssert($before===$after,'Decision incompleta altero Store.');
    $reflection=new ReflectionClass(StoreDecisionHistoryRepository::class);
    zhAssert(! $reflection->hasMethod('update') && ! $reflection->hasMethod('delete'),'Historial no append-only.');
} finally {
    if ($storeIds !== []) {
        $ids=implode(',',array_map('intval',$storeIds));
        $wpdb->query("DELETE FROM {$historyTable} WHERE store_id IN ({$ids})");
        $wpdb->query("DELETE FROM {$storeZoneTable} WHERE store_id IN ({$ids})");
        $wpdb->query("DELETE FROM {$storesTable} WHERE id IN ({$ids})");
    }
    if($fixtureAssignmentCreated){$wpdb->delete($assignmentTable,['user_id'=>$zonalId,'service_zone_id'=>$zoneId]);}
    update_user_meta($zonalId,$wpdb->prefix . 'capabilities',$originalCaps);
    clean_user_cache($zonalId);
}
zhAssert($inventory()===$initialInventory,'El cleanup altero el baseline productivo.');
echo "ZONAL_ADMIN_Z1_HISTORY=PASS decisions=3/3 atomic_incomplete=PASS append_only=PASS fixture_cleanup=PASS baseline_structural_equality=PASS adversarial_baseline=PASS\n";
