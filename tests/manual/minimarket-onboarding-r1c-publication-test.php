<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';

use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\OnboardingLegalAuthorityValidator;
use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\OnboardingLegalConfiguration;

global $wpdb;
$root = dirname(__DIR__, 2);
$termsPath = $root . '/resources/legal/V-ES-P-01-v01.html';
$privacyPath = $root . '/resources/legal/V-ES-P-02-v01.html';
$termsHtml = file_get_contents($termsPath);
$privacyHtml = file_get_contents($privacyPath);
if (! is_string($termsHtml) || ! is_string($privacyHtml)) throw new RuntimeException('Fuentes legales ausentes.');

$targets = [
    ['title'=>'Términos y Condiciones de Uso de VeciAhorra','slug'=>'terminos-y-condiciones','content'=>$termsHtml],
    ['title'=>'Política de Privacidad y Tratamiento de Datos Personales','slug'=>'politica-de-privacidad','content'=>$privacyHtml],
    ['title'=>'Registro de minimarket','slug'=>'registro-minimarket','content'=>'[veciahorra_minimarket_onboarding]'],
];

if (($argv[1] ?? '') === '--apply') {
    foreach ($targets as $target) {
        $conflicts = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type='page' AND (post_name=%s OR post_title=%s)",
            $target['slug'], $target['title']
        ));
        if ($conflicts !== []) throw new RuntimeException('Conflicto de página: ' . $target['slug']);
    }
    if ($wpdb->query('START TRANSACTION') === false) throw new RuntimeException('No se pudo iniciar la publicación.');
    try {
        $ids=[];
        foreach ($targets as $target) {
            $id=wp_insert_post(wp_slash(['post_title'=>$target['title'],'post_name'=>$target['slug'],'post_content'=>$target['content'],'post_status'=>'publish','post_type'=>'page']), true);
            if (is_wp_error($id) || (int)$id<=0) throw new RuntimeException('No se pudo crear ' . $target['slug']);
            $ids[$target['slug']] = (int)$id;
        }
        $configuration = [
            'joint_version'=>'R1C-LEGAL-2026-07-30-V1',
            'terms_document_code'=>'V-ES-P-01','terms_version'=>'01','terms_effective_date'=>'2026-07-22',
            'terms_page_id'=>$ids['terminos-y-condiciones'],'terms_content_hash'=>OnboardingLegalAuthorityValidator::contentHash($termsHtml),
            'privacy_document_code'=>'V-ES-P-02','privacy_version'=>'01','privacy_effective_date'=>'2026-07-30',
            'privacy_page_id'=>$ids['politica-de-privacidad'],'privacy_content_hash'=>OnboardingLegalAuthorityValidator::contentHash($privacyHtml),
            'registration_page_id'=>$ids['registro-minimarket'],'registration_content_hash'=>OnboardingLegalAuthorityValidator::contentHash('[veciahorra_minimarket_onboarding]'),
        ];
        if (! update_option(OnboardingLegalConfiguration::OPTION, $configuration, false)) throw new RuntimeException('No se pudo guardar autoridad legal.');
        if (! update_option('wp_page_for_privacy_policy', $ids['politica-de-privacidad'])) throw new RuntimeException('No se pudo asignar privacidad.');
        if ($wpdb->query('COMMIT') === false) throw new RuntimeException('Commit incierto.');
    } catch (Throwable $exception) {
        $wpdb->query('ROLLBACK');
        throw $exception;
    }
    foreach ($ids as $slug=>$id) do_action('litespeed_purge_url', get_permalink($id));
}

if (($argv[1] ?? '') === '--refresh-created') {
    $stored = get_option(OnboardingLegalConfiguration::OPTION);
    if (! is_array($stored)) throw new RuntimeException('Configuración legal ausente.');
    $termsId=(int)($stored['terms_page_id']??0);$privacyId=(int)($stored['privacy_page_id']??0);
    $currentTerms=get_post($termsId);$currentPrivacy=get_post($privacyId);
    if(!$currentTerms instanceof WP_Post||!$currentPrivacy instanceof WP_Post
        ||!hash_equals((string)($stored['terms_content_hash']??''),OnboardingLegalAuthorityValidator::contentHash($currentTerms->post_content))
        ||!hash_equals((string)($stored['privacy_content_hash']??''),OnboardingLegalAuthorityValidator::contentHash($currentPrivacy->post_content))) {
        throw new RuntimeException('Autoridad legal previa inconsistente.');
    }
    if ($wpdb->query('START TRANSACTION') === false) throw new RuntimeException('No se pudo iniciar actualización legal.');
    try {
        $updates = [
            $termsId => $termsHtml,
            $privacyId => $privacyHtml,
        ];
        foreach ($updates as $id=>$content) {
            $changed=wp_update_post(wp_slash(['ID'=>$id,'post_content'=>$content]),true);
            if(is_wp_error($changed)||(int)$changed!==$id)throw new RuntimeException('No se pudo refrescar fuente legal.');
        }
        $configuration = get_option(OnboardingLegalConfiguration::OPTION);
        if(!is_array($configuration))throw new RuntimeException('Configuración legal ausente.');
        $configuration['terms_content_hash']=OnboardingLegalAuthorityValidator::contentHash($termsHtml);
        $configuration['privacy_content_hash']=OnboardingLegalAuthorityValidator::contentHash($privacyHtml);
        $registration=get_page_by_path('registro-minimarket',OBJECT,'page');
        if(!$registration instanceof WP_Post)throw new RuntimeException('Registro ausente.');
        $configuration['registration_page_id']=(int)$registration->ID;
        $configuration['registration_content_hash']=OnboardingLegalAuthorityValidator::contentHash($registration->post_content);
        update_option(OnboardingLegalConfiguration::OPTION,$configuration,false);
        if($wpdb->query('COMMIT')===false)throw new RuntimeException('Commit legal incierto.');
    }catch(Throwable $exception){$wpdb->query('ROLLBACK');throw $exception;}
    do_action('litespeed_purge_url',get_permalink($termsId));
    do_action('litespeed_purge_url',get_permalink($privacyId));
}

$config = OnboardingLegalConfiguration::fromWordPress();
$links = (new OnboardingLegalAuthorityValidator())->validate($config);
$registration = get_page_by_path('registro-minimarket', OBJECT, 'page');
if (! $registration instanceof WP_Post || $registration->post_status !== 'publish' || trim($registration->post_content) !== '[veciahorra_minimarket_onboarding]') {
    throw new RuntimeException('Página de registro inválida.');
}
echo 'R1C_PUBLICATION=PASS terms_id='.$config->termsPageId.' privacy_id='.$config->privacyPageId.' registration_id='.$registration->ID
    .' terms_hash='.$config->termsContentHash.' privacy_hash='.$config->privacyContentHash
    .' terms_url='.$links['terms_url'].' privacy_url='.$links['privacy_url'].' registration_url='.get_permalink($registration)."\n";
