<?php
declare(strict_types=1);
require_once dirname(__DIR__,5).'/wp-load.php';
$user=get_user_by('login','va_demo_carolina');if(!$user instanceof WP_User)throw new RuntimeException('Cliente de revisión ausente.');$expiry=time()+3600;
echo wp_json_encode(['user_id'=>(int)$user->ID,'cookies'=>[['name'=>SECURE_AUTH_COOKIE,'value'=>wp_generate_auth_cookie((int)$user->ID,$expiry,'secure_auth'),'path'=>ADMIN_COOKIE_PATH],['name'=>LOGGED_IN_COOKIE,'value'=>wp_generate_auth_cookie((int)$user->ID,$expiry,'logged_in'),'path'=>COOKIEPATH]]]);
