<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Minimarket\Onboarding\Account;
final readonly class ActivatePendingMinimarketAccountResult
{
    public const CREATED='created';public const RECOVERED='recovered';public const REPLAYED='replayed';
    public function __construct(public string $applicationPublicId,public string $status,public int $userId,public string $outcome,public string $createdAt,public string $updatedAt)
    {if(!in_array($outcome,[self::CREATED,self::RECOVERED,self::REPLAYED],true))throw new PendingAccountException('pending_account_outcome_uncertain');}
}
