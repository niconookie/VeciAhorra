<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Minimarket\Onboarding\Account;
final readonly class ActivatePendingMinimarketAccountCommand
{
    public function __construct(public int $applicationId,public string $tokenHash,public int $generation,public SensitivePassword $password,public string $now)
    {if($applicationId<1||$generation<1||strlen($tokenHash)!==32)throw new PendingAccountException('invalid_activation_command');}
    public function __serialize():array{throw new PendingAccountException('invalid_activation_command');}
}
