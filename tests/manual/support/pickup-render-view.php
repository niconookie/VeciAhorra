<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$root=getenv('VA_PROOF_PLUGIN_ROOT')?:dirname(__DIR__,3);
define('VECIAHORRA_PUBLIC_COMMERCE_ENABLED',true);
function esc_attr($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function esc_html($s){return esc_attr($s);}
function esc_url($s){return esc_attr($s);}
function __($s,$domain=''){return $s;}
function esc_html_e($s,$domain=''){echo esc_html($s);}
require $root.'/vendor/autoload.php';
$page=$argv[1]??'';if(!in_array($page,['cart','checkout'],true))exit(1);
$instanceId='test-'.$page;$buyerName='Test';$checkoutUrl='/checkout';$catalogUrl='/catalog';
require $root.'/app/Modules/Frontend/Views/'.$page.'.php';
