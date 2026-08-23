<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Minimarket\Onboarding\ActivationSession;
use RuntimeException;
final class ActivationSessionException extends RuntimeException
{
    public function __construct(public readonly string $reason){parent::__construct($reason,0,null);}
}
