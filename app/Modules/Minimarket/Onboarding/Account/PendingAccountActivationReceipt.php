<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Minimarket\Onboarding\Account;
final class PendingAccountActivationReceipt
{
    public function __construct(private ActivatePendingMinimarketAccountResult $result,private int $applicationId,private string $expectedLogin,private string $expectedFingerprint,private int $generation,private string $tokenHash){}
    public function result():ActivatePendingMinimarketAccountResult{return $this->result;}public function applicationId():int{return $this->applicationId;}public function expectedLogin():string{return $this->expectedLogin;}public function expectedFingerprint():string{return $this->expectedFingerprint;}public function generation():int{return $this->generation;}public function tokenHash():string{return $this->tokenHash;}
    public function __serialize():array{throw new PendingAccountException('pending_account_outcome_uncertain');}public function __debugInfo():array{return ['receipt'=>'[redacted]'];}
}
