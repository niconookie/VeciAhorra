<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\Owner;

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Stores\Domain\StoreLifecycleContract;
use VeciAhorra\Modules\Stores\Exceptions\StoreLifecycleException;

final class OwnerOnboardingService
{
    public function snapshot(int $storeId, int $userId): array
    {
        global $wpdb;$p=$wpdb->prefix.Config::TABLE_PREFIX;
        $row=$wpdb->get_row($wpdb->prepare("SELECT s.*,a.public_id application_public_id,a.terms_version,a.terms_accepted_at FROM {$p}stores s LEFT JOIN {$p}store_onboarding_applications a ON a.store_id=s.id AND a.user_id=s.owner_user_id WHERE s.id=%d AND s.owner_user_id=%d LIMIT 1",$storeId,$userId),ARRAY_A);
        if(!is_array($row)||$wpdb->last_error!=='')throw new StoreLifecycleException('store_not_found','El minimarket no existe.');
        $state=(new StoreLifecycleContract())->validate((string)$row['status'],(string)$row['onboarding_status'],$row['approved_at']);
        $decision=$wpdb->get_row($wpdb->prepare("SELECT action,reason,created_at FROM {$p}store_decision_history WHERE store_id=%d AND action IN ('observe','reject') ORDER BY created_at DESC,id DESC LIMIT 1",$storeId),ARRAY_A);
        return ['store'=>array_intersect_key($row,array_flip(['id','business_name','legal_name','owner_name','rut','email','phone','mobile','address','commune','city','region','status','onboarding_status','approved_at','created_at','updated_at'])),'lifecycle_state'=>$state,'application'=>['public_id'=>$row['application_public_id']??null,'terms_version'=>$row['terms_version']??null,'terms_accepted_at'=>$row['terms_accepted_at']??null],'latest_decision'=>is_array($decision)?$decision:null,'can_correct'=>$state===StoreLifecycleContract::STATE_OBSERVED,'can_resubmit'=>$state===StoreLifecycleContract::STATE_DRAFT];
    }

    public function correct(int $storeId,int$userId,array$fields,string$expectedUpdatedAt):array
    {
        global$wpdb;$p=$wpdb->prefix.Config::TABLE_PREFIX;$this->begin();try{$locked=$wpdb->get_row($wpdb->prepare("SELECT status,onboarding_status,approved_at,updated_at FROM {$p}stores WHERE id=%d AND owner_user_id=%d FOR UPDATE",$storeId,$userId),ARRAY_A);if(!is_array($locked))throw new StoreLifecycleException('store_not_found','El minimarket no existe.');if($locked!==['status'=>'observed','onboarding_status'=>'complete','approved_at'=>null,'updated_at'=>$expectedUpdatedAt])throw new StoreLifecycleException('concurrent_modification','La solicitud cambio o no admite correccion.');$now=$this->nextTimestamp($expectedUpdatedAt);$sets=[];$params=[];foreach($fields as$field=>$value){$sets[]="{$field}=%s";$params[]=$value;}$sets[]='status=%s';$params[]=StoreLifecycleContract::STATUS_PENDING;$sets[]='onboarding_status=%s';$params[]=StoreLifecycleContract::ONBOARDING_DRAFT;$sets[]='approved_at=NULL';$sets[]='updated_at=%s';$params[]=$now;array_push($params,$storeId,$userId,$expectedUpdatedAt);$sql="UPDATE {$p}stores SET ".implode(',',$sets)." WHERE id=%d AND owner_user_id=%d AND status='observed' AND onboarding_status='complete' AND approved_at IS NULL AND updated_at=%s";$result=$wpdb->query($wpdb->prepare($sql,...$params));if($result===false)throw new \RuntimeException('No fue posible guardar la correccion.');if($result!==1)throw new StoreLifecycleException('concurrent_modification','La solicitud cambio o no admite correccion.');$this->commit();return$this->snapshot($storeId,$userId);}catch(\Throwable$e){$wpdb->query('ROLLBACK');throw$e;}
    }

    public function resubmit(int$storeId,int$userId,string$expectedUpdatedAt):array
    {
        global$wpdb;$p=$wpdb->prefix.Config::TABLE_PREFIX;$this->begin();try{$locked=$wpdb->get_row($wpdb->prepare("SELECT status,onboarding_status,approved_at,updated_at FROM {$p}stores WHERE id=%d AND owner_user_id=%d FOR UPDATE",$storeId,$userId),ARRAY_A);if(!is_array($locked))throw new StoreLifecycleException('store_not_found','El minimarket no existe.');if($locked!==['status'=>'pending','onboarding_status'=>'draft','approved_at'=>null,'updated_at'=>$expectedUpdatedAt])throw new StoreLifecycleException('concurrent_modification','La solicitud cambio o no admite reenvio.');$now=$this->nextTimestamp($expectedUpdatedAt);$result=$wpdb->query($wpdb->prepare("UPDATE {$p}stores SET onboarding_status='complete',updated_at=%s WHERE id=%d AND owner_user_id=%d AND status='pending' AND onboarding_status='draft' AND approved_at IS NULL AND updated_at=%s",$now,$storeId,$userId,$expectedUpdatedAt));if($result===false)throw new \RuntimeException('No fue posible reenviar la solicitud.');if($result!==1)throw new StoreLifecycleException('concurrent_modification','La solicitud cambio o no admite reenvio.');$this->commit();return$this->snapshot($storeId,$userId);}catch(\Throwable$e){$wpdb->query('ROLLBACK');throw$e;}
    }

    private function begin():void{global$wpdb;if($wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('No fue posible iniciar la operacion.');}
    private function commit():void{global$wpdb;if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');throw new \RuntimeException('No fue posible confirmar la operacion.');}}
    private function nextTimestamp(string$expected):string{$now=current_time('mysql');return$now>$expected?$now:gmdate('Y-m-d H:i:s',strtotime($expected.' UTC')+1);}
}
