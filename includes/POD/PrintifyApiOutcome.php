<?php
declare(strict_types=1);
namespace DigiForge\POD;
/** Sanitized outcome policy. Never returns provider bodies or transport messages. */
final class PrintifyApiOutcome
{
 public static function retryAfter(array $headers,int $now=0):int {
  $now=$now?:time();$value=null;foreach($headers as $name=>$candidate)if(strtolower((string)$name)==='retry-after'){$value=$candidate;break;}
  if(!is_scalar($value))return 60;$value=trim((string)$value);
  if(preg_match('/^\d+$/D',$value))return max(1,min(3600,(int)$value));
  $date=strtotime($value);return $date===false?60:max(1,min(3600,$date-$now));
 }
 /** attempted counts the just-completed attempt; callers must not exceed three. */
 public static function classify(mixed $status,array $headers=[],int $attempted=1,string $method='GET',int $now=0):array {
  $code=is_int($status)?$status:0;$read=strtoupper($method)==='GET';$transient=$code===0||$code===408||$code>=500;
  $category=$code>=200&&$code<300?'SUCCESS':($code===429?'RATE_LIMITED':($transient?($read?'TRANSIENT':'UNKNOWN'):($code>=400&&$code<500?'DEFINITIVE_ERROR':($read?'DEFINITIVE_ERROR':'UNKNOWN'))));
  return ['reason_code'=>match(true){$code===0=>'TRANSPORT',$code===408=>'TIMEOUT',$code===401=>'AUTHENTICATION',$code===403=>'AUTHORIZATION',$code===404=>'NOT_FOUND',$code===409=>'CONFLICT',$code===422=>'VALIDATION',$code===429=>'RATE_LIMIT',$code>=500=>'PROVIDER_UNAVAILABLE',$code>=300&&$code<400=>'REDIRECT_REJECTED',$code>=200&&$code<300=>'NONE',default=>'UNEXPECTED_STATUS'},'category'=>$category,'bounded_retry_after'=>($category==='RATE_LIMITED'||$category==='TRANSIENT'||$category==='UNKNOWN')?self::retryAfter($headers,$now):0,'retry_permitted'=>$read&&$attempted>=1&&$attempted<3&&in_array($category,['RATE_LIMITED','TRANSIENT'],true)];
 }
}
