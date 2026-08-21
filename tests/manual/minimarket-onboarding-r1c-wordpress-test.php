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
$GLOBALS['post']=$page; setup_postdata($page);
$renderA=do_shortcode('[veciahorra_minimarket_onboarding]');
$renderB=do_shortcode('[veciahorra_minimarket_onboarding]');
preg_match('/name="idempotency_key" value="([a-f0-9]{64})"/',$renderA,$ma);
preg_match('/name="idempotency_key" value="([a-f0-9]{64})"/',$renderB,$mb);
r1c_wp_assert(isset($ma[1],$mb[1])&&$ma[1]!==$mb[1],'Keys GET no son únicas.');
r1c_wp_assert(str_contains($renderA,'Términos y Condiciones')&&str_contains($renderA,'Política de Privacidad'),'Enlaces legales ausentes.');

$key=bin2hex(random_bytes(32));$email='r1c.synthetic.'.substr($key,0,12).'@example.com';$rut='12.345.678-5';
$service=new StartStoreOnboarding(new StoreOnboardingApplicationRepository(),new SystemOnboardingClock(),new RandomOnboardingPublicIdGenerator(),new ConfiguredCurrentOnboardingTerms(new OnboardingLegalAuthorityValidator()),new OnboardingEmailNormalizer(),new ChileanRutNormalizer());
try{
    $first=$service->execute(new StartStoreOnboardingCommand($email,$rut,$key,true));
    $replay=$service->execute(new StartStoreOnboardingCommand($email,$rut,$key,true));
    r1c_wp_assert($first->publicId===$replay->publicId,'Replay cambió public ID.');
    $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}va_store_onboarding_applications WHERE account_email=%s",$email));
    r1c_wp_assert($count===1,'Doble envío creó duplicado.');
}finally{
    $wpdb->delete($wpdb->prefix.'va_store_onboarding_applications',['account_email'=>$email]);
}
$after=['apps'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}va_store_onboarding_applications"),'users'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}"),'stores'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}va_stores"),'meta'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key=%s",'_veciahorra_store_id'))];
r1c_wp_assert($before===$after,'Datos operativos cambiaron.');
wp_reset_postdata();
echo "R1C_WORDPRESS=PASS shortcode=PASS legal=PASS unique_keys=PASS r1b=PASS replay=PASS cleanup=PASS\n";
