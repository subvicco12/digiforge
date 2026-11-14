<?php
declare(strict_types=1);
namespace DigiForge\Queue;

/** Conservative local classification; UNKNOWN or unclassified errors never imply retry permission. */
final class QueueFailurePolicy
{
 public static function evaluate(string $errorCode,array $errorData,int $attempts,int $maxAttempts,int $delaySeconds):array
 {
  $base=['failure_class'=>$errorCode,'retry_eligible'=>false,'automatic_retry_authorized'=>false,'external_execution_authorized'=>false];
  if(($errorData['retry_permitted']??null)!==true||($errorData['outcome']??null)==='UNKNOWN'||($errorData['provider_outcome']??null)==='UNKNOWN')return $base+['state'=>'HUMAN_REVIEW','reason'=>'Retry permission and a known outcome require review.'];
  foreach(['outcome','provider_outcome']as $field)if(array_key_exists($field,$errorData)&&!in_array($errorData[$field],['NOT_SENT','CONFIRMED_FAILURE'],true))return $base+['state'=>'HUMAN_REVIEW','reason'=>'External outcome is not a confirmed safe retry boundary.'];
  if(($errorData['provider_execution_performed']??null)===true&&!array_key_exists('outcome',$errorData)&&!array_key_exists('provider_outcome',$errorData))return $base+['state'=>'HUMAN_REVIEW','reason'=>'Performed provider execution requires outcome reconciliation.'];
  // This existing class is explicit rate-limit evidence; generic transport,
  // provider, quota or output failures do not establish safe retry semantics.
  if($errorCode!=='digiforge_launch_ai_rate_limited')return $base+['state'=>'HUMAN_REVIEW','reason'=>'No reviewed retry policy exists for this failure class.'];
  if($attempts<0||$maxAttempts<1||$delaySeconds<1||$delaySeconds>86400)return $base+['state'=>'HUMAN_REVIEW','reason'=>'Finite attempts and an explicit bounded backoff are required.'];
  if($attempts>=$maxAttempts-1)return $base+['state'=>'DEAD_LETTER','reason'=>'The configured attempt ceiling is reached.','next_attempts'=>$maxAttempts];
  return array_replace($base,['retry_eligible'=>true,'state'=>'RETRY','reason'=>'Explicit retry permission and known rate-limit class permit a bounded local retry plan.','next_attempts'=>$attempts+1,'delay_seconds'=>$delaySeconds]);
 }
}
