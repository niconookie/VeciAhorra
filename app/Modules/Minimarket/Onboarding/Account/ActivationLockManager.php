<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Minimarket\Onboarding\Account;
interface ActivationLockManager{public function synchronized(array $identities,callable $criticalSection,?callable $reconcileAfterReleaseFailure=null):mixed;}
