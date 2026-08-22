<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Minimarket\Onboarding\Account;
use VeciAhorra\Modules\Minimarket\Onboarding\StoreOnboardingApplicationRepository;
use VeciAhorra\Modules\Minimarket\Onboarding\Verification\StoreOnboardingEmailVerificationRepository;
final class PendingAccountServiceFactory
{
    public static function make():ActivatePendingMinimarketAccount
    {
        global $wpdb;if(!$wpdb instanceof \wpdb)throw new PendingAccountException('pending_account_outcome_uncertain');
        $secret=defined('AUTH_SALT')?(string)AUTH_SALT:'';
        return new ActivatePendingMinimarketAccount(new StoreOnboardingApplicationRepository(),new StoreOnboardingEmailVerificationRepository(),new WordPressPendingUserGateway(),new RandomOpaqueUsernameGenerator(),new MariaDbActivationLockManager($wpdb,$secret),new WordPressPendingAccountReconciliationConnectionFactory());
    }
}
