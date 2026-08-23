<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Minimarket\Onboarding\ActivationSession;
use DateTimeImmutable;
use DateTimeZone;
final readonly class StoreOnboardingActivationSession
{
    public const PURPOSE='minimarket_account_activation';public const ACTIVE='active';public const CONSUMED='consumed';public const INVALIDATED='invalidated';
    private const KEYS=['id','session_hash','application_id','verification_id','generation','purpose','state','expires_at','consumed_at','invalidated_at','failed_attempts','last_attempt_at','created_at','updated_at'];
    public function __construct(public int $id,public string $sessionHash,public int $applicationId,public int $verificationId,public int $generation,public string $purpose,public string $state,public string $expiresAt,public ?string $consumedAt,public ?string $invalidatedAt,public int $failedAttempts,public ?string $lastAttemptAt,public string $createdAt,public string $updatedAt)
    {
        if($id<1||$applicationId<1||$verificationId<1||$generation<1)throw new ActivationSessionException('activation_session_invalid_id');
        if(strlen($sessionHash)!==32)throw new ActivationSessionException('activation_session_invalid_hash');
        if($purpose!==self::PURPOSE)throw new ActivationSessionException('activation_session_invalid_purpose');
        if(!in_array($state,[self::ACTIVE,self::CONSUMED,self::INVALIDATED],true))throw new ActivationSessionException('activation_session_invalid_state');
        foreach([$expiresAt,$createdAt,$updatedAt] as $t)self::timestamp($t);foreach([$consumedAt,$invalidatedAt,$lastAttemptAt] as $t)if($t!==null)self::timestamp($t);
        if($failedAttempts<0||$failedAttempts>5||($failedAttempts===0)!==($lastAttemptAt===null))throw new ActivationSessionException('activation_session_invalid_attempts');
        if(self::plus($createdAt,900)!==$expiresAt||$updatedAt<$createdAt||$lastAttemptAt!==null&&($lastAttemptAt<$createdAt||$lastAttemptAt>$updatedAt))throw new ActivationSessionException('activation_session_invalid_timestamps');
        if($state===self::ACTIVE&&($consumedAt!==null||$invalidatedAt!==null)||$state===self::CONSUMED&&($consumedAt===null||$invalidatedAt!==null)||$state===self::INVALIDATED&&($invalidatedAt===null||$consumedAt!==null))throw new ActivationSessionException('activation_session_incoherent_state');
        $terminal=$state===self::CONSUMED?$consumedAt:($state===self::INVALIDATED?$invalidatedAt:null);if($terminal!==null&&($terminal<$createdAt||$terminal>$updatedAt))throw new ActivationSessionException('activation_session_invalid_timestamps');
    }
    public static function fromRow(array $row):self{$keys=array_keys($row);sort($keys);$expected=self::KEYS;sort($expected);if($keys!==$expected)throw new ActivationSessionException('activation_session_invalid_shape');return new self(self::positive($row['id']),self::binary($row['session_hash']),self::positive($row['application_id']),self::positive($row['verification_id']),self::positive($row['generation']),self::string($row['purpose']),self::string($row['state']),self::string($row['expires_at']),self::nullable($row['consumed_at']),self::nullable($row['invalidated_at']),self::counter($row['failed_attempts']),self::nullable($row['last_attempt_at']),self::string($row['created_at']),self::string($row['updated_at']));}
    public function isExpired(string $now):bool{self::timestamp($now);return $now>=$this->expiresAt;}
    public static function timestamp(string $v):void{if(strlen($v)!==19||trim($v)!==$v)throw new ActivationSessionException('activation_session_invalid_timestamp');$d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$v,new DateTimeZone('UTC'));$e=DateTimeImmutable::getLastErrors();if(!$d||$e!==false&&($e['warning_count']||$e['error_count'])||$d->format('Y-m-d H:i:s')!==$v)throw new ActivationSessionException('activation_session_invalid_timestamp');}
    public static function plus(string $v,int $seconds):string{self::timestamp($v);$modifier=($seconds>=0?'+':'').$seconds.' seconds';$next=(new DateTimeImmutable($v,new DateTimeZone('UTC')))->modify($modifier);if(!$next)throw new ActivationSessionException('activation_session_invalid_timestamp');return$next->format('Y-m-d H:i:s');}
    private static function unsigned(mixed $v):int{if(is_int($v)){if($v<0)return -1;return $v;}if(!is_string($v)||preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$v)!==1)return -1;$max=(string)PHP_INT_MAX;if(strlen($v)>strlen($max)||strlen($v)===strlen($max)&&strcmp($v,$max)>0)return -1;return(int)$v;}
    private static function positive(mixed $v):int{$n=self::unsigned($v);if($n<1)throw new ActivationSessionException('activation_session_invalid_row');return$n;}
    private static function counter(mixed $v):int{$n=self::unsigned($v);if($n<0||$n>5)throw new ActivationSessionException('activation_session_invalid_row');return$n;}
    private static function string(mixed $v):string{if(!is_string($v)||$v==='')throw new ActivationSessionException('activation_session_invalid_row');return$v;}
    private static function nullable(mixed $v):?string{if($v!==null&&!is_string($v))throw new ActivationSessionException('activation_session_invalid_row');return$v;}
    private static function binary(mixed $v):string{if(!is_string($v)||strlen($v)!==32)throw new ActivationSessionException('activation_session_invalid_row');return$v;}
}
