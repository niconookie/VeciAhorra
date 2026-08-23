<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Minimarket\Onboarding\RateLimit;
use DateTimeImmutable;use DateTimeZone;
final readonly class DurableRateLimitBucket
{
    private const KEYS=['id','bucket_hash','domain','window_started_at','window_seconds','hit_count','expires_at','created_at','updated_at'];
    public function __construct(public int$id,public string$bucketHash,public string$domain,public string$windowStartedAt,public int$windowSeconds,public int$hitCount,public string$expiresAt,public string$createdAt,public string$updatedAt)
    {if($id<1||strlen($bucketHash)!==32||!isset(DurableRateLimitSpecification::POLICIES[$domain]))throw new DurableRateLimitException('rate_limit_bucket_invalid');[$limit,$window]=DurableRateLimitSpecification::POLICIES[$domain];if($windowSeconds!==$window||$hitCount<1||$hitCount>$limit)throw new DurableRateLimitException('rate_limit_bucket_corrupt');foreach([$windowStartedAt,$expiresAt,$createdAt,$updatedAt]as$t)self::timestamp($t);if(self::plus($windowStartedAt,$windowSeconds)!==$expiresAt||$createdAt>$updatedAt||$updatedAt<$windowStartedAt)throw new DurableRateLimitException('rate_limit_bucket_corrupt');}
    public static function fromRow(array$r):self{$keys=array_keys($r);sort($keys);$e=self::KEYS;sort($e);if($keys!==$e)throw new DurableRateLimitException('rate_limit_bucket_corrupt');return new self(self::positive($r['id']),self::binary($r['bucket_hash']),self::string($r['domain']),self::string($r['window_started_at']),self::positive($r['window_seconds']),self::positive($r['hit_count']),self::string($r['expires_at']),self::string($r['created_at']),self::string($r['updated_at']));}
    public static function timestamp(string$v):void{if(strlen($v)!==19||trim($v)!==$v)throw new DurableRateLimitException('rate_limit_timestamp_invalid');$d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$v,new DateTimeZone('UTC'));$e=DateTimeImmutable::getLastErrors();if(!$d||$e!==false&&($e['warning_count']||$e['error_count'])||$d->format('Y-m-d H:i:s')!==$v)throw new DurableRateLimitException('rate_limit_timestamp_invalid');}
    public static function plus(string$v,int$s):string{self::timestamp($v);$d=(new DateTimeImmutable($v,new DateTimeZone('UTC')))->modify(($s>=0?'+':'').$s.' seconds');if(!$d)throw new DurableRateLimitException('rate_limit_timestamp_invalid');return$d->format('Y-m-d H:i:s');}
    private static function uint(mixed$v):int{if(is_int($v))return$v>=0?$v:-1;if(!is_string($v)||preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$v)!==1)return-1;$m=(string)PHP_INT_MAX;if(strlen($v)>strlen($m)||strlen($v)===strlen($m)&&strcmp($v,$m)>0)return-1;return(int)$v;}
    private static function positive(mixed$v):int{$n=self::uint($v);if($n<1)throw new DurableRateLimitException('rate_limit_bucket_corrupt');return$n;}
    private static function binary(mixed$v):string{if(!is_string($v)||strlen($v)!==32)throw new DurableRateLimitException('rate_limit_bucket_corrupt');return$v;}
    private static function string(mixed$v):string{if(!is_string($v)||$v==='')throw new DurableRateLimitException('rate_limit_bucket_corrupt');return$v;}
}
