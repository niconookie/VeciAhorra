<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';

use VeciAhorra\Modules\Stores\Domain\StoreLifecycleContract as L;

function zlAssert(bool $condition, string $message): void { if (! $condition) { throw new RuntimeException($message); } }
$l = new L();
zlAssert($l->classify('observed','complete',null) === L::STATE_OBSERVED, 'Observed no clasifica.');
zlAssert($l->transitionAuthorities(L::ACTION_OBSERVE,'pending','complete',null) === ['status'=>'observed','onboarding_status'=>'complete','approved_at'=>null], 'Observe invalido.');
zlAssert($l->transitionAuthorities(L::ACTION_RETURN_TO_DRAFT,'observed','complete',null) === ['status'=>'pending','onboarding_status'=>'draft','approved_at'=>null], 'Observed a draft invalido.');
foreach ([[L::ACTION_APPROVE,'observed'],[L::ACTION_ACTIVATE,'observed'],[L::ACTION_SUBMIT_FOR_REVIEW,'rejected']] as [$action,$status]) {
    try { $l->transitionAuthorities($action,$status,'complete',null); throw new RuntimeException("Transicion {$action} aceptada."); } catch (VeciAhorra\Modules\Stores\Exceptions\StoreLifecycleException) {}
}
zlAssert($l->classify('rejected','complete',null) === L::STATE_REJECTED, 'Observed equivale a rejected.');
echo "ZONAL_ADMIN_Z1_LIFECYCLE=PASS invalid_transitions=3/3\n";
