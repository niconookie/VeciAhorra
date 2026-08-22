<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Minimarket\Onboarding\Account;
interface PendingAccountReconciliationReader{public function read(int $applicationId,int $userId):PendingAccountReconciliationSnapshot;}
