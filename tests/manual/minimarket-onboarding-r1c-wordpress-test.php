<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';

use VeciAhorra\Modules\Minimarket\Onboarding\Application\StartStoreOnboarding;
use VeciAhorra\Modules\Minimarket\Onboarding\Application\StartStoreOnboardingCommand;
use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\ConfiguredCurrentOnboardingTerms;
use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\OnboardingLegalAuthorityValidator;
use VeciAhorra\Modules\Minimarket\Onboarding\StoreOnboardingApplicationRepository;
use VeciAhorra\Modules\Minimarket\Onboarding\Support\ChileanRutNormalizer;
use VeciAhorra\Modules\Minimarket\Onboarding\Support\OnboardingEmailNormalizer;
use VeciAhorra\Modules\Minimarket\Onboarding\Support\RandomOnboardingPublicIdGenerator;
use VeciAhorra\Modules\Minimarket\Onboarding\Support\SystemOnboardingClock;
use VeciAhorra\Modules\Minimarket\Onboarding\Contracts\OnboardingIntentClassifier;
use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\HmacRateLimitKeyDeriver;
use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\MariaDbNamedRateLimitLockManager;
use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\PublicClientAddress;
use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\RateLimitIdentity;
use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\TransientPublicOnboardingRateLimiter;
use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\WordPressTransientRateLimitBucketStore;

global $wpdb;
function r1c_wp_assert(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
$before=[
    'apps'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}va_store_onboarding_applications"),
    'users'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}"),
    'stores'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}va_stores"),
    'meta'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key=%s",'_veciahorra_store_id')),
];
r1c_wp_assert(shortcode_exists('veciahorra_minimarket_onboarding'),'Shortcode ausente.');
$page=get_page_by_path('registro-minimarket',OBJECT,'page');
r1c_wp_assert($page instanceof WP_Post && $page->post_status==='publish','Página registro ausente.');
$pageId=$page->ID;$GLOBALS['post']=$page;setup_postdata($page);$page=get_post($pageId);$GLOBALS['wp_query']->queried_object=$page;$GLOBALS['wp_query']->queried_object_id=$pageId;
$renderA=do_shortcode('[veciahorra_minimarket_onboarding]');
$renderB=do_shortcode('[veciahorra_minimarket_onboarding]');
preg_match('/name="idempotency_key" value="([a-f0-9]{64})"/',$renderA,$ma);
preg_match('/name="idempotency_key" value="([a-f0-9]{64})"/',$renderB,$mb);
r1c_wp_assert(isset($ma[1],$mb[1])&&$ma[1]!==$mb[1],'Keys GET no son únicas.');
r1c_wp_assert(str_contains($renderA,'Términos y Condiciones')&&str_contains($renderA,'Política de Privacidad'),'Enlaces legales ausentes.');

$key=bin2hex(random_bytes(32));$email='r1c.synthetic.'.substr($key,0,12).'@example.com';$rut='12.345.678-5';
$repository=new StoreOnboardingApplicationRepository();
$service=new StartStoreOnboarding($repository,new SystemOnboardingClock(),new RandomOnboardingPublicIdGenerator(),new ConfiguredCurrentOnboardingTerms(new OnboardingLegalAuthorityValidator()),new OnboardingEmailNormalizer(),new ChileanRutNormalizer());
try{
    $first=$service->execute(new StartStoreOnboardingCommand($email,$rut,$key,true));
    $replay=$service->execute(new StartStoreOnboardingCommand($email,$rut,$key,true));
    r1c_wp_assert($first->publicId===$replay->publicId,'Replay cambió public ID.');
    $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}va_store_onboarding_applications WHERE account_email=%s",$email));
    r1c_wp_assert($count===1,'Doble envío creó duplicado.');
    $normalizedEmail=(new OnboardingEmailNormalizer())->normalize($email);$normalizedRut=(new ChileanRutNormalizer())->normalizeAndValidate($rut);
    $intentHash=hash('sha256','minimarket-onboarding-v1|'.$key);
    r1c_wp_assert($repository->classify($intentHash,$normalizedEmail,$normalizedRut,'R1C-LEGAL-2026-07-30-V1')===OnboardingIntentClassifier::COMPATIBLE_REPLAY,'Replay durable no clasificado.');
    r1c_wp_assert($repository->classify($intentHash,'different@example.com',$normalizedRut,'R1C-LEGAL-2026-07-30-V1')===OnboardingIntentClassifier::CONFLICT,'Conflicto durable no clasificado.');

    $deriver=new HmacRateLimitKeyDeriver();$identity=new RateLimitIdentity($deriver->derive('identity-email',$normalizedEmail),$deriver->derive('identity-rut',$normalizedRut),$normalizedEmail,$normalizedRut);
    $limiter=new TransientPublicOnboardingRateLimiter($deriver,new WordPressTransientRateLimitBucketStore($wpdb,new MariaDbNamedRateLimitLockManager($wpdb)));
    $client=new PublicClientAddress(inet_pton('198.51.100.77'),32);
    $limiter->consume($client,$identity,$key,static fn()=>OnboardingIntentClassifier::NEW,static function():void{});
    $identityName='va_r1c_rl_'.substr($deriver->derive('bucket-identity-day',$identity->emailHmac.$identity->rutHmac),0,48);
    $keyName='va_r1c_rl_'.substr($deriver->derive('bucket-key-short',$deriver->derive('idempotency',$key)),0,48);
    $identityBefore=get_transient($identityName);
    delete_transient($keyName);
    $limiter->consume($client,$identity,$key,static fn()=>OnboardingIntentClassifier::COMPATIBLE_REPLAY,static function():void{});
    $identityAfter=get_transient($identityName);
    r1c_wp_assert(is_array($identityBefore)&&$identityBefore===$identityAfter&&$identityAfter['count']===1,'Replay durable consumió identidad.');
}finally{
    $wpdb->delete($wpdb->prefix.'va_store_onboarding_applications',['account_email'=>$email]);
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_va\\_r1c\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_va\\_r1c\\_rl\\_%'");
}
$after=['apps'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}va_store_onboarding_applications"),'users'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}"),'stores'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}va_stores"),'meta'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key=%s",'_veciahorra_store_id'))];
r1c_wp_assert($before===$after,'Datos operativos cambiaron.');
wp_reset_postdata();
echo "R1C_WORDPRESS=PASS shortcode=PASS legal=PASS unique_keys=PASS r1b=PASS replay=PASS durable_replay=PASS identity_not_reconsumed=PASS cleanup=PASS\n";
