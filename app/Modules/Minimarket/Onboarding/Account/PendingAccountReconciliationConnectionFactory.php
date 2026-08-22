<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Minimarket\Onboarding\Account;
interface PendingAccountReconciliationConnectionFactory{public function open(?int $originalConnectionId):PendingAccountReconciliationSession;}
