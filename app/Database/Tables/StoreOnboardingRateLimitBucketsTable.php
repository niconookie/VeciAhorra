<?php
declare(strict_types=1);
namespace VeciAhorra\Database\Tables;
final class StoreOnboardingRateLimitBucketsTable
{
    public function name():string{return 'store_onboarding_rate_limit_buckets';}
    public function sql(string $table,string $charset):string{return "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
bucket_hash binary(32) NOT NULL,
domain varchar(32) NOT NULL,
window_started_at datetime NOT NULL,
window_seconds int(10) unsigned NOT NULL,
hit_count int(10) unsigned NOT NULL DEFAULT 0,
expires_at datetime NOT NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY onboarding_rate_limit_bucket_unique (bucket_hash),
KEY onboarding_rate_limit_cleanup (expires_at),
KEY onboarding_rate_limit_domain_window (domain,window_started_at)
) {$charset};";}
}
