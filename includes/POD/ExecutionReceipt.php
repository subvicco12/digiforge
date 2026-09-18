<?php
declare(strict_types=1);
namespace DigiForge\POD;
use WP_Error;

/** Builds immutable post-adapter execution evidence without performing the action. */
final class ExecutionReceipt
{
 /** @return array<string,mixed>|WP_Error */
 public static function record(array $authorization,string $action,string $externalReference,int $executedBy,int $executedAt):array|WP_Error
 {
  $required=strtoupper(trim($action));$evidence=(string)($authorization['authorization']['evidence_hash']??'');
  $verified=ExecutionAuthorizationVerifier::verify($authorization,$required,$evidence,$executedAt,static fn(string $nonce):bool=>true);
  if(is_wp_error($verified))return $verified;
  if(!preg_match('/^[A-Za-z0-9._:\/-]{3,160}$/',$externalReference))
   return new WP_Error('digiforge_execution_reference','A valid provider reference is required.',['status'=>400]);
  if($executedBy<1)return new WP_Error('digiforge_execution_actor','A valid execution actor is required.',['status'=>403]);
  $nonce=(string)$authorization['authorization']['nonce'];
  $payload=['action'=>$required,'evidence_hash'=>$evidence,'authorization_hash'=>(string)$authorization['authorization_hash'],'authorization_nonce_hash'=>hash('sha256',$nonce),'external_reference'=>$externalReference,'executed_by'=>$executedBy,'executed_at'=>$executedAt];
  $canonical=$payload;ksort($canonical);
  return ['state'=>'EXECUTION_RECORDED','receipt'=>$payload,'receipt_hash'=>hash('sha256',(string)wp_json_encode($canonical)),'nonce_must_be_consumed'=>true];
 }
}
