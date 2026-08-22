<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Minimarket\Onboarding\Account;
final class RandomOpaqueUsernameGenerator implements OpaqueUsernameGenerator{public function generate():string{return 'va_mm_'.bin2hex(random_bytes(16));}}
