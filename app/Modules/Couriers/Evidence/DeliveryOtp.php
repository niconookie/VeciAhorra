<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Couriers\Evidence;

/** A random context and a site-keyed PRF allow owner-only redisplay without storing an OTP. */
final class DeliveryOtp
{
    public function code(int $deliveryId,string $context): string
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$context))throw new \DomainException('otp_unavailable');
        for($counter=0;;$counter++){
            $bytes=hash_hmac('sha256',"delivery-otp-derive:{$deliveryId}:{$context}:{$counter}",wp_salt('auth'),true);
            $value=unpack('N',substr($bytes,0,4))[1];
            // Rejection sampling avoids modulo bias.
            if($value<4294000000)return str_pad((string)($value%1000000),6,'0',STR_PAD_LEFT);
        }
    }
    public function digest(int $deliveryId,string $code): string
    {
        return hash_hmac('sha256',"delivery-otp-verify:{$deliveryId}:{$code}",wp_salt('secure_auth'));
    }
    public function matches(array $otp,string $code): bool
    {
        return preg_match('/^[0-9]{6}$/D',$code)===1 && hash_equals($otp['code_hash'],$this->digest((int)$otp['delivery_id'],$code));
    }
}
