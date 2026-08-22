<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Minimarket\Onboarding\Account;
interface PendingUserSessionInspector{public function hasActiveSessions(int $userId):bool;}
