<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Minimarket\Onboarding\Account;
final class SensitivePassword
{
    private string $value;
    public function __construct(string $value)
    {
        if(strlen($value)>512||!preg_match('//u',$value)||mb_strlen($value,'UTF-8')<12||mb_strlen($value,'UTF-8')>128||str_contains($value,"\0")||preg_match('/[\x00-\x1F\x7F]/',$value))throw new PendingAccountException('invalid_password');
        $this->value=$value;
    }
    public function exposeTo(callable $consumer):mixed{return $consumer($this->value);}
    public function __serialize():array{throw new PendingAccountException('invalid_password');}
    public function __debugInfo():array{return ['sensitive'=>'[redacted]'];}
}
