<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Minimarket\Onboarding\Account;
use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Minimarket\Identity\PendingMinimarketRole;
final class MariaDbPendingAccountReconciliationReader implements PendingAccountReconciliationReader
{
    public function __construct(private \wpdb $database){}
    public function read(int $applicationId,int $userId):PendingAccountReconciliationSnapshot
    {
        $prefix=$this->database->prefix;$va=$prefix.Config::TABLE_PREFIX;
        $application=$this->row("SELECT * FROM {$va}store_onboarding_applications WHERE id=%d",[$applicationId],16);
        $verification=$this->row("SELECT * FROM {$va}store_onboarding_email_verifications WHERE application_id=%d",[$applicationId],18);
        $user=$this->row("SELECT ID,user_login,user_email,user_pass,display_name,user_registered FROM {$this->database->users} WHERE ID=%d",[$userId],6);
        $capsRow=$this->row("SELECT meta_value FROM {$this->database->usermeta} WHERE user_id=%d AND meta_key=%s",[$userId,$prefix.'capabilities'],1);
        $caps=maybe_unserialize((string)$capsRow['meta_value']);if(!is_array($caps))throw new PendingAccountException('pending_account_outcome_uncertain');
        $roles=[];foreach($caps as $key=>$granted)if($granted===true&&is_string($key))$roles[]=$key;
        $effective=$caps;if(isset($caps[PendingMinimarketRole::ROLE])&&$caps[PendingMinimarketRole::ROLE]===true){$effective['read']=true;$effective[PendingMinimarketRole::CAPABILITY]=true;}
        $secret=defined('AUTH_SALT')?(string)AUTH_SALT:'closed';$fingerprint=hash_hmac('sha256',implode("\0",[(string)$user['ID'],(string)$user['user_login'],(string)$user['user_email'],(string)$user['user_pass'],(string)$user['display_name'],(string)$user['user_registered'],wp_json_encode($caps)]),$secret);
        $pending=new PendingUser((int)$user['ID'],(string)$user['user_login'],(string)$user['user_email'],$roles,$effective,$fingerprint);
        return new PendingAccountReconciliationSnapshot($application,$verification,$pending,$this->count("SELECT COUNT(*) FROM {$va}stores WHERE owner_user_id=%d",[$userId])!==0,$this->count("SELECT COUNT(*) FROM {$this->database->usermeta} WHERE user_id=%d AND meta_key=%s",[$userId,'_veciahorra_store_id'])!==0,$this->count("SELECT COUNT(*) FROM {$va}store_onboarding_applications WHERE user_id=%d AND id<>%d",[$userId,$applicationId])!==0);
    }
    /** @param list<mixed> $args @return array<string,mixed> */
    private function row(string $sql,array $args,int $fields):array{$this->database->last_error='';$row=$this->database->get_row($this->database->prepare($sql,...$args),ARRAY_A);if($this->database->last_error!==''||!is_array($row)||count($row)!==$fields)throw new PendingAccountException('pending_account_outcome_uncertain');return $row;}
    /** @param list<mixed> $args */
    private function count(string $sql,array $args):int{$this->database->last_error='';$value=$this->database->get_var($this->database->prepare($sql,...$args));if($this->database->last_error!==''||$value===null||!preg_match('/\A\d+\z/',(string)$value))throw new PendingAccountException('pending_account_outcome_uncertain');return (int)$value;}
}
