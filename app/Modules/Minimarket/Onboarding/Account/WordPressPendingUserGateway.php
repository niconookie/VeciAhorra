<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Minimarket\Onboarding\Account;
use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Minimarket\Identity\PendingMinimarketRole;
final class WordPressPendingUserGateway implements PendingUserGateway
{
    public function __construct(private ?PendingUserSessionInspector $sessions=null){$this->sessions??=new WordPressPendingUserSessionInspector();}
    public function findByEmail(string $email):array{global $wpdb;$wpdb->last_error='';$ids=$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE user_email=%s ORDER BY ID LIMIT 3",$email));if($wpdb->last_error!=='')throw new PendingAccountException('pending_account_outcome_uncertain');$out=[];foreach($ids as $id){$user=get_user_by('id',(int)$id);if(!$user instanceof \WP_User)throw new PendingAccountException('pending_account_outcome_uncertain');$out[]=$this->map($user);}return $out;}
    public function findByLogin(string $login):?PendingUser{global $wpdb;$wpdb->last_error='';$id=$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE user_login=%s LIMIT 1",$login));if($wpdb->last_error!=='')throw new PendingAccountException('pending_account_outcome_uncertain');return $id===null?null:$this->find((int)$id);}
    public function find(int $id):?PendingUser{global $wpdb;$wpdb->last_error='';$exists=$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE ID=%d LIMIT 1",$id));if($wpdb->last_error!=='')throw new PendingAccountException('pending_account_outcome_uncertain');if($exists===null)return null;$user=get_user_by('id',$id);if(!$user instanceof \WP_User)throw new PendingAccountException('pending_account_outcome_uncertain');return $this->map($user);}
    public function isLoginOccupied(string $login):bool{return username_exists($login)!==false;}
    public function create(string $login,string $email,SensitivePassword $password):PendingUser
    {
        global $wpdb;$secret=defined('AUTH_SALT')?(string)AUTH_SALT:'closed';$lock='va-r1db-ul-'.substr(hash_hmac('sha256',"r1db-user-login\0".$login,$secret),0,48);$wpdb->last_error='';$acquired=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %f)',$lock,2.0));if($wpdb->last_error!==''||(string)$acquired!=='1')throw new PendingAccountException('pending_account_outcome_uncertain');$id=null;$failure=null;$releaseFailed=false;
        try{if(username_exists($login)!==false)throw new PendingAccountException('pending_account_identity_collision');$id=$password->exposeTo(static fn(string $raw):int|\WP_Error=>wp_insert_user(['user_login'=>$login,'user_email'=>$email,'user_pass'=>$raw,'display_name'=>'Minimarket pendiente','role'=>PendingMinimarketRole::ROLE]));}catch(PendingAccountException $exception){$failure=$exception->reason;}catch(\Throwable){$failure='pending_account_creation_failed';}finally{try{$wpdb->last_error='';$released=$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));$releaseFailed=$wpdb->last_error!==''||(string)$released!=='1';}catch(\Throwable){$releaseFailed=true;}}
        if($releaseFailed)throw new PendingAccountException('pending_account_outcome_uncertain');
        if($failure!==null)throw new PendingAccountException($failure);
        if(is_wp_error($id)){$codes=$id->get_error_codes();if(in_array('existing_user_login',$codes,true))throw new PendingAccountException('pending_account_identity_collision');throw new PendingAccountException('pending_account_creation_failed');}
        $user=$this->find((int)$id);if($user===null)throw new PendingAccountException('pending_account_outcome_uncertain');return $user;
    }
    public function isCompatible(PendingUser $user,int $applicationId):bool
    {
        $actual=array_filter($user->capabilities,static fn(mixed $value):bool=>$value===true);$allowed=['read',PendingMinimarketRole::CAPABILITY,'exist',PendingMinimarketRole::ROLE];
        if($user->roles!==[PendingMinimarketRole::ROLE]||!isset($actual['read'],$actual[PendingMinimarketRole::CAPABILITY])||array_diff(array_keys($actual),$allowed)!==[])return false;
        global $wpdb;$stores=$wpdb->prefix.Config::TABLE_PREFIX.'stores';$apps=$wpdb->prefix.Config::TABLE_PREFIX.'store_onboarding_applications';
        if((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$stores} WHERE owner_user_id=%d",$user->id))!==0)return false;
        if(get_user_meta($user->id,'_veciahorra_store_id',true)!=='')return false;
        $other=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$apps} WHERE user_id=%d AND id<>%d",$user->id,$applicationId));return $other===0;
    }
    public function canCompensate(PendingUser $user,int $applicationId):bool
    {
        $current=$this->find($user->id);if($current===null||$current->login!==$user->login||$current->email!==$user->email||!hash_equals($current->integrityFingerprint,$user->integrityFingerprint)||!$this->isCompatible($current,$applicationId))return false;global $wpdb;$apps=$wpdb->prefix.Config::TABLE_PREFIX.'store_onboarding_applications';$ver=$wpdb->prefix.Config::TABLE_PREFIX.'store_onboarding_email_verifications';
        if((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$apps} WHERE user_id=%d",$user->id))!==0)return false;
        if((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$ver} WHERE candidate_user_id=%d OR attached_user_id=%d",$user->id,$user->id))!==0)return false;
        if($this->hasCommercialReferences($user->id))return false;
        return !$this->sessions->hasActiveSessions($user->id);
    }
    public function compensate(PendingUser $user):bool{require_once ABSPATH.'wp-admin/includes/user.php';return wp_delete_user($user->id)===true;}
    private function hasCommercialReferences(int $userId):bool
    {
        global $wpdb;$va=$wpdb->prefix.Config::TABLE_PREFIX;
        $references=[
            [$wpdb->comments,'user_id'],[$wpdb->posts,'post_author'],
            [$va.'cart_items','user_id'],[$va.'checkouts','user_id'],
            [$va.'deliveries','courier_id'],[$va.'deliveries','customer_id'],
            [$va.'orders','customer_id'],[$va.'payments','customer_id'],
            [$va.'store_decision_history','actor_user_id'],
            [$va.'zonal_admin_service_zones','user_id'],[$va.'zonal_admin_service_zones','created_by'],
            [$wpdb->prefix.'wc_customer_lookup','user_id'],[$wpdb->prefix.'wc_download_log','user_id'],
            [$wpdb->prefix.'wc_orders','customer_id'],[$wpdb->prefix.'wc_webhooks','user_id'],
            [$wpdb->prefix.'woocommerce_api_keys','user_id'],
            [$wpdb->prefix.'woocommerce_downloadable_product_permissions','user_id'],
            [$wpdb->prefix.'woocommerce_payment_tokens','user_id'],[$wpdb->prefix.'wpforms_logs','user_id'],
        ];
        foreach($references as [$table,$column]){$wpdb->last_error='';$exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table));if($wpdb->last_error!=='')return true;if($exists!==$table)continue;$count=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}`=%d",$userId));if($wpdb->last_error!==''||$count===null||(int)$count!==0)return true;}
        return false;
    }
    private function map(\WP_User $user):PendingUser{$secret=defined('AUTH_SALT')?(string)AUTH_SALT:'closed';$fingerprint=hash_hmac('sha256',implode("\0",[(string)$user->ID,(string)$user->user_login,(string)$user->user_email,(string)$user->user_pass,(string)$user->display_name,(string)$user->user_registered,wp_json_encode($user->caps)]),$secret);return new PendingUser((int)$user->ID,(string)$user->user_login,(string)$user->user_email,array_values($user->roles),(array)$user->allcaps,$fingerprint);}
}
