<?php
declare(strict_types=1);
require_once dirname(__DIR__,5).'/wp-load.php';
$mode=$argv[1]??'';$user=get_user_by('login','va_demo_carolina');if(!$user instanceof WP_User)throw new RuntimeException('user');$key='_veciahorra_service_zone_id';
if($mode==='set'){$previous=(string)get_user_meta($user->ID,$key,true);$zone=(int)($argv[2]??0);if($zone<=0)throw new RuntimeException('zone');update_user_meta($user->ID,$key,$zone);echo base64_encode($previous);exit;}
if($mode==='restore'){$previous=base64_decode((string)($argv[2]??''),true);if(!is_string($previous))throw new RuntimeException('previous');if($previous==='')delete_user_meta($user->ID,$key);else update_user_meta($user->ID,$key,$previous);echo 'PASS';exit;}throw new RuntimeException('mode');
