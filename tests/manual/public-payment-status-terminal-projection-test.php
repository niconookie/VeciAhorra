<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Service {
    function current_time(string $type): string
    {
        return '2026-08-28 12:00:00';
    }
}

namespace {
require_once dirname(__DIR__, 2) . '/app/Modules/Payments/Service/PublicPaymentStatusService.php';

use VeciAhorra\Modules\Payments\Service\PublicPaymentStatusService;

function terminalProjectionAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$reflection = new ReflectionClass(PublicPaymentStatusService::class);
$service = $reflection->newInstanceWithoutConstructor();
$project = $reflection->getMethod('projectAttempt');
$base = [
    'id' => 1,
    'public_id' => 'ps_' . str_repeat('A', 43),
    'session_status' => 'ready',
    'session_expires_at' => '2099-01-01 00:00:00',
    'redirect_url' => null,
    'environment' => 'integration',
    'return_processing_status' => 'completed',
];
$approvedAuthority = [
    ...$base,
    'return_result_status' => 'approved',
    'validated_financial_status' => 'approved',
    'financial_validated_at' => '2026-08-28 12:00:00',
    'approved_payment_authority_count' => '1',
];

$approved = $project->invoke($service, $approvedAuthority);
terminalProjectionAssert(
    $approved['payment_status'] === 'payment_approved'
    && $approved['terminal'] === true
    && $approved['next_action'] === 'view_order'
    && $approved['redirect_url'] === null,
    'APPROVED_NOT_TERMINAL'
);
$inconsistent = $project->invoke($service, [
    ...$approvedAuthority,
    'fulfillment_status' => 'permanent_failure',
    'business_status' => 'completed',
]);
terminalProjectionAssert(
    $inconsistent['payment_status'] === 'payment_approved'
    && $inconsistent['terminal'] === true,
    'OPERATIONAL_INCONSISTENCY_REVOKED_APPROVAL'
);
$returnOnly = $project->invoke($service, [
    ...$base,
    'return_result_status' => 'approved',
    'approved_payment_authority_count' => '0',
]);
terminalProjectionAssert(
    $returnOnly['payment_status'] === 'payment_verifying'
    && $returnOnly['terminal'] === false,
    'RETURN_ONLY_APPROVED'
);
$pending = $project->invoke($service, [
    ...$base,
    'session_status' => 'pending',
    'return_processing_status' => null,
    'approved_payment_authority_count' => '0',
]);
terminalProjectionAssert($pending['terminal'] === false, 'PENDING_TERMINAL');
$rejected = $project->invoke($service, [
    ...$base,
    'return_result_status' => 'rejected',
    'approved_payment_authority_count' => '0',
]);
terminalProjectionAssert(
    $rejected['payment_status'] === 'payment_rejected'
    && $rejected['terminal'] === true,
    'REJECTED_REGRESSION'
);
$uncertain = $project->invoke($service, [
    ...$base,
    'return_processing_status' => 'ambiguous',
    'return_result_status' => null,
    'approved_payment_authority_count' => '0',
]);
terminalProjectionAssert(
    $uncertain['payment_status'] === 'manual_review'
    && $uncertain['terminal'] === true,
    'OUTCOME_UNCERTAIN_APPROVED'
);
foreach (['0', '2', '', null] as $invalidAuthority) {
    $result = $project->invoke($service, [
        ...$approvedAuthority,
        'approved_payment_authority_count' => $invalidAuthority,
    ]);
    terminalProjectionAssert(
        $result['payment_status'] === 'payment_verifying',
        'AMBIGUOUS_AUTHORITY_ACCEPTED'
    );
}
terminalProjectionAssert(
    $project->invoke($service, $approvedAuthority) === $approved,
    'PROJECTION_NOT_IDEMPOTENT'
);

$repository = file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Payments/Repository/PublicPaymentStatusRepository.php');
$serviceSource = file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Payments/Service/PublicPaymentStatusService.php');
terminalProjectionAssert(is_string($repository) && is_string($serviceSource), 'SOURCE_UNREADABLE');
$normalizedRepository = str_replace("\\'", "'", $repository);
foreach ([
    'p.id=ps.payment_id', 'p.payment_session_id=ps.id',
    'p.checkout_id=ps.checkout_id', 'p.customer_id=c.user_id',
    'p.amount=ps.amount', 'p.amount=c.total_amount',
    'ao.payment_attempt_id=ps.public_id', 'awr.token_hash=ao.token_hash',
    "awr.processing_status='completed'", "awr.result_status='approved'",
    "awr.financial_status='approved'", 'awr.financial_validated_at IS NOT NULL',
    'co.checkout_id=c.id', 'po.payment_id=p.id',
    "ord.status NOT IN ('paid','delivered')", 'SUM(ord.total)',
] as $authorityClause) {
    terminalProjectionAssert(
        str_contains($normalizedRepository, $authorityClause),
        'LINKAGE_AUTHORITY_MISSING'
    );
}
terminalProjectionAssert(
    str_contains($serviceSource, 'hasApprovedPaymentAuthority($row)')
    && str_contains($serviceSource, "return \$this->state('payment_approved');"),
    'FINANCIAL_AUTHORITY_NOT_CONSUMED'
);
terminalProjectionAssert(
    preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE)\b/i', $repository) !== 1,
    'READ_REPOSITORY_WRITES'
);
terminalProjectionAssert(
    preg_match('/wp_remote_|curl_|token_ws|api[_-]?key|commerce[_-]?code/i', $repository . $serviceSource) !== 1,
    'EXTERNAL_OR_SECRET_SURFACE'
);

echo "PUBLIC_PAYMENT_STATUS_TERMINAL_PROJECTION=PASS\n";
echo "APPROVED_TERMINAL=PASS\n";
echo "INCONSISTENT_BUT_APPROVED=PASS\n";
echo "RETURN_ONLY_FAIL_CLOSED=PASS\n";
echo "LINKAGE_MUTATIONS=PASS\n";
echo "DATABASE_WRITES=0\nEXTERNAL_CALLS=0\nSECRETS=0\n";
}
