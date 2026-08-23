<?php
declare(strict_types=1);
namespace VeciAhorra\Database\Tables;
final class StoreOnboardingActivationSessionsTable
{
    public function name():string{return 'store_onboarding_activation_sessions';}
    public function sql(string $table,string $charset):string{return "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
session_hash binary(32) NOT NULL,
application_id bigint(20) unsigned NOT NULL,
verification_id bigint(20) unsigned NOT NULL,
generation int(10) unsigned NOT NULL,
purpose varchar(32) NOT NULL,
state varchar(16) NOT NULL DEFAULT 'active',
expires_at datetime NOT NULL,
consumed_at datetime DEFAULT NULL,
invalidated_at datetime DEFAULT NULL,
failed_attempts smallint(5) unsigned NOT NULL DEFAULT 0,
last_attempt_at datetime DEFAULT NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY onboarding_activation_session_hash_unique (session_hash),
KEY onboarding_activation_session_verification_generation (verification_id,generation,state),
KEY onboarding_activation_session_application (application_id,state),
KEY onboarding_activation_session_expiry (state,expires_at),
KEY onboarding_activation_session_cleanup (updated_at,state)
) {$charset};";}
}
