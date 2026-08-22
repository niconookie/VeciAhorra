<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Minimarket\Onboarding\Account;
final readonly class PendingUser{public function __construct(public int $id,public string $login,public string $email,public array $roles,public array $capabilities,public string $integrityFingerprint){} }
