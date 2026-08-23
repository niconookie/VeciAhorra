<?php
declare(strict_types=1);
define('WP_INSTALLING',true);
require_once dirname(__DIR__,5).'/wp-load.php';
require_once dirname(__DIR__,2).'/vendor/autoload.php';
use VeciAhorra\Modules\Minimarket\Onboarding\RateLimit\DurableRateLimiter;
use VeciAhorra\Modules\Minimarket\Onboarding\RateLimit\DurableRateLimitRequest;
use VeciAhorra\Modules\Minimarket\Onboarding\RateLimit\DurableRateLimitSpecification;
$database=(string)getenv('VA_R1DCA_DATABASE');if($database!=='minimarket_r1dca_restore')throw new RuntimeException('database_guard');
$hash=base64_decode((string)getenv('VA_R1DCA_BUCKET'),true);if(!is_string($hash)||strlen($hash)!==32)throw new RuntimeException('bucket_guard');
global$wpdb;$wpdb=new wpdb(DB_USER,DB_PASSWORD,$database,DB_HOST);$wpdb->set_prefix('wp_');
$second=base64_decode((string)getenv('VA_R1DCA_SECOND_BUCKET'),true);
$specifications=is_string($second)&&strlen($second)===32
    ?[new DurableRateLimitSpecification($hash,'post_network',20,900),new DurableRateLimitSpecification($second,'post_session',5,900)]
    :[new DurableRateLimitSpecification($hash,'get_token',10,600)];
$decision=(new DurableRateLimiter($wpdb))->consume(new DurableRateLimitRequest($specifications,(string)getenv('VA_R1DCA_NOW')));
echo $decision->outcome,PHP_EOL;
