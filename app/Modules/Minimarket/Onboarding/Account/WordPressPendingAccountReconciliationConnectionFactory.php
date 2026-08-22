<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Minimarket\Onboarding\Account;
use VeciAhorra\Core\Config;
final class WordPressPendingAccountReconciliationConnectionFactory implements PendingAccountReconciliationConnectionFactory
{
    public function __construct(private ?string $databaseName=null,private ?string $prefix=null){}
    public function open(?int $originalConnectionId):PendingAccountReconciliationSession
    {
        try{$databaseName=$this->databaseName??DB_NAME;$prefix=$this->prefix??$GLOBALS['wpdb']->prefix;$database=new \wpdb(DB_USER,DB_PASSWORD,$databaseName,DB_HOST);$database->suppress_errors(true);$database->set_prefix($prefix);$database->last_error='';$connectionId=$database->get_var('SELECT CONNECTION_ID()');$selected=$database->get_var('SELECT DATABASE()');$inTransaction=$database->get_var('SELECT @@in_transaction');if($database->last_error!==''||!preg_match('/\A\d+\z/',(string)$connectionId)||(int)$connectionId<1||($originalConnectionId!==null&&(int)$connectionId===$originalConnectionId)||$selected!==$databaseName||(string)$inTransaction!=='0'||$database->prefix!==$prefix)throw new PendingAccountException('pending_account_outcome_uncertain');foreach([$prefix.Config::TABLE_PREFIX.'store_onboarding_applications',$prefix.Config::TABLE_PREFIX.'store_onboarding_email_verifications',$database->users] as $table){$database->last_error='';$exists=$database->get_var($database->prepare('SHOW TABLES LIKE %s',$table));if($database->last_error!==''||$exists!==$table)throw new PendingAccountException('pending_account_outcome_uncertain');}return new MariaDbPendingAccountReconciliationSession($database,new MariaDbPendingAccountReconciliationReader($database));}
        catch(PendingAccountException $exception){if(isset($database)&&$database instanceof \wpdb)try{$database->close();}catch(\Throwable){}throw new PendingAccountException($exception->reason);}catch(\Throwable){if(isset($database)&&$database instanceof \wpdb)try{$database->close();}catch(\Throwable){}throw new PendingAccountException('pending_account_outcome_uncertain');}
    }
}
