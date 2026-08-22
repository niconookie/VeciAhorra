<?php
declare(strict_types=1);
require_once dirname(__DIR__,5).'/wp-load.php';
use VeciAhorra\Modules\Minimarket\Identity\PendingMinimarketRole;
use VeciAhorra\Modules\Minimarket\Onboarding\Account\ActivatePendingMinimarketAccountCommand;
use VeciAhorra\Modules\Minimarket\Onboarding\Account\MariaDbActivationLockManager;
use VeciAhorra\Modules\Minimarket\Onboarding\Account\PendingAccountException;
use VeciAhorra\Modules\Minimarket\Onboarding\Account\RandomOpaqueUsernameGenerator;
use VeciAhorra\Modules\Minimarket\Onboarding\Account\SensitivePassword;
use VeciAhorra\Modules\Minimarket\Onboarding\Account\WordPressPendingUserGateway;
use VeciAhorra\Modules\Minimarket\Onboarding\Account\WordPressPendingAccountReconciliationConnectionFactory;
use VeciAhorra\Modules\Minimarket\Onboarding\Account\ActivatePendingMinimarketAccount;
use VeciAhorra\Modules\Minimarket\Onboarding\StoreOnboardingApplicationRepository;
use VeciAhorra\Modules\Minimarket\Onboarding\Verification\StoreOnboardingEmailVerificationRepository;
$started=microtime(true);$applicationId=(int)($argv[1]??0);$database=(string)($argv[2]??'');$mode=(string)($argv[3]??'normal');
if($applicationId<1||$database!=='minimarket_r1da_disposable')exit(64);
if($mode==='lost'){class R1dbLostConnectionWpdb extends wpdb{private bool $killed=false;public function get_var($query=null,$x=0,$y=0){if(!$this->killed&&str_contains((string)$query,'RELEASE_LOCK')){$this->killed=true;$own=parent::get_var('SELECT CONNECTION_ID()');$control=new wpdb(DB_USER,DB_PASSWORD,'minimarket_r1da_disposable',DB_HOST);$control->query('KILL '.(int)$own);$control->close();}return parent::get_var($query,$x,$y);}};$connection=new R1dbLostConnectionWpdb(DB_USER,DB_PASSWORD,$database,DB_HOST);}else{$connection=new wpdb(DB_USER,DB_PASSWORD,$database,DB_HOST);}
global $wpdb;$wpdb=$connection;$wpdb->set_prefix('wp_');foreach(['actionscheduler_actions','actionscheduler_claims','actionscheduler_groups','actionscheduler_logs'] as $table){$wpdb->{$table}='wp_'.$table;}wp_roles()->for_site();(new PendingMinimarketRole())->register();
$generator=$mode==='fixed'?new class implements VeciAhorra\Modules\Minimarket\Onboarding\Account\OpaqueUsernameGenerator{public function generate():string{return 'va_mm_'.str_repeat('f',32);}}:new RandomOpaqueUsernameGenerator();
$service=new ActivatePendingMinimarketAccount(new StoreOnboardingApplicationRepository(),new StoreOnboardingEmailVerificationRepository(),new WordPressPendingUserGateway(),$generator,new MariaDbActivationLockManager($wpdb,str_repeat('c',32)),new WordPressPendingAccountReconciliationConnectionFactory($database,'wp_'));
try{$result=$service->execute(new ActivatePendingMinimarketAccountCommand($applicationId,str_repeat("q\0",16),1,new SensitivePassword('concurrency password 2026'),'2026-10-01 00:03:00'));$status=$result->outcome;$code=0;}
catch(PendingAccountException $e){$status=$e->reason;$code=2;}
catch(Throwable){$status='hostile_throwable';$code=3;}
echo json_encode(['pid'=>getmypid(),'duration_ms'=>(int)round((microtime(true)-$started)*1000),'exit_code'=>$code,'result'=>$status],JSON_UNESCAPED_SLASHES).PHP_EOL;exit($code);
