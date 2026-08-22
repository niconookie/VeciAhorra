<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\Verification;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class StoreOnboardingEmailVerification
{
    public const PURPOSE='minimarket_account_activation';
    public const PENDING='pending'; public const SENT='sent'; public const FAILED='failed'; public const UNCERTAIN='uncertain';
    public const DELIVERY_FAILED='delivery_failed'; public const DELIVERY_UNCERTAIN='delivery_uncertain';
    public const EXPIRED='verification_expired'; public const ATTEMPTS_EXHAUSTED='verification_attempts_exhausted';
    public const REFERENCE_INVALID='verification_reference_invalid'; public const CONCURRENT='verification_concurrent_modification';
    private const STATES=[self::PENDING,self::SENT,self::FAILED,self::UNCERTAIN];
    private const ERRORS=[null,self::DELIVERY_FAILED,self::DELIVERY_UNCERTAIN,self::EXPIRED,self::ATTEMPTS_EXHAUSTED,self::REFERENCE_INVALID,self::CONCURRENT];

    public function __construct(
        public int $id, public int $applicationId, public string $purpose, public int $generation,
        public ?int $candidateUserId, public ?int $attachedUserId, public string $emailBindingHash,
        public string $tokenHash, public string $expiresAt, public ?string $consumedAt,
        public int $failedAttempts, public int $resendCount, public ?string $lastSentAt,
        public string $deliveryState, public int $deliveryAttemptCount, public ?string $lastErrorCode,
        public string $createdAt, public string $updatedAt
    ) {
        if($id<=0||$applicationId<=0||$generation<1||($candidateUserId!==null&&$candidateUserId<=0)||($attachedUserId!==null&&$attachedUserId<=0)) throw new InvalidArgumentException('verification_invalid_id');
        if($purpose!==self::PURPOSE) throw new InvalidArgumentException('verification_invalid_purpose');
        if(strlen($emailBindingHash)!==32||strlen($tokenHash)!==32) throw new InvalidArgumentException('verification_invalid_hash');
        foreach([$failedAttempts,$resendCount,$deliveryAttemptCount] as $counter) if($counter<0||$counter>65535) throw new InvalidArgumentException('verification_invalid_counter');
        foreach([$expiresAt,$createdAt,$updatedAt] as $value) self::timestamp($value);
        foreach([$consumedAt,$lastSentAt] as $value) if($value!==null) self::timestamp($value);
        if($expiresAt<=$createdAt||$updatedAt<$createdAt||($consumedAt!==null&&($consumedAt<$createdAt||$consumedAt>$updatedAt))) throw new InvalidArgumentException('verification_invalid_timestamp_order');
        if(!in_array($deliveryState,self::STATES,true)||!in_array($lastErrorCode,self::ERRORS,true)) throw new InvalidArgumentException('verification_invalid_state');
        if($deliveryState===self::PENDING&&($lastSentAt!==null||$lastErrorCode!==null)) throw new InvalidArgumentException('verification_incoherent_pending');
        if($deliveryState===self::SENT&&($lastSentAt===null||$deliveryAttemptCount<1||$lastErrorCode!==null)) throw new InvalidArgumentException('verification_incoherent_sent');
        if($deliveryState===self::FAILED&&($deliveryAttemptCount<1||$lastErrorCode!==self::DELIVERY_FAILED)) throw new InvalidArgumentException('verification_incoherent_failed');
        if($deliveryState===self::UNCERTAIN&&($deliveryAttemptCount<1||$lastErrorCode!==self::DELIVERY_UNCERTAIN)) throw new InvalidArgumentException('verification_incoherent_uncertain');
        if(($consumedAt===null)!==($attachedUserId===null)) throw new InvalidArgumentException('verification_incoherent_consumption');
    }

    public static function fromRow(array $r): self
    {
        foreach(['id','application_id','purpose','generation','candidate_user_id','attached_user_id','email_binding_hash','token_hash','expires_at','consumed_at','failed_attempts','resend_count','last_sent_at','delivery_state','delivery_attempt_count','last_error_code','created_at','updated_at'] as $key) if(!array_key_exists($key,$r)) throw new InvalidArgumentException('verification_invalid_shape');
        return new self((int)$r['id'],(int)$r['application_id'],(string)$r['purpose'],(int)$r['generation'],$r['candidate_user_id']===null?null:(int)$r['candidate_user_id'],$r['attached_user_id']===null?null:(int)$r['attached_user_id'],(string)$r['email_binding_hash'],(string)$r['token_hash'],(string)$r['expires_at'],$r['consumed_at']===null?null:(string)$r['consumed_at'],(int)$r['failed_attempts'],(int)$r['resend_count'],$r['last_sent_at']===null?null:(string)$r['last_sent_at'],(string)$r['delivery_state'],(int)$r['delivery_attempt_count'],$r['last_error_code']===null?null:(string)$r['last_error_code'],(string)$r['created_at'],(string)$r['updated_at']);
    }

    public static function timestamp(string $value): void
    {
        if(strlen($value)!==19||trim($value)!==$value) throw new InvalidArgumentException('verification_invalid_timestamp');
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone('UTC'));$errors=DateTimeImmutable::getLastErrors();
        if(!$parsed||($errors!==false&&($errors['warning_count']||$errors['error_count']))||$parsed->format('Y-m-d H:i:s')!==$value) throw new InvalidArgumentException('verification_invalid_timestamp');
    }
}
