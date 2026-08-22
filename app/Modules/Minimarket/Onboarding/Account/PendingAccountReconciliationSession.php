<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Minimarket\Onboarding\Account;
interface PendingAccountReconciliationSession{public function reconcile(array $lockNames,PendingAccountActivationReceipt $receipt):PendingAccountActivationReceipt;}
