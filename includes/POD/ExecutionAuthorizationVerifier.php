<?php
declare(strict_types=1);
namespace DigiForge\POD;
use WP_Error;

/** Pure verifier for short-lived POD execution authorization evidence. */
final class ExecutionAuthorizationVerifier
{
 /** @return true|WP_Error */
 public static function verify(array $record,string $requiredAction,string $expectedEvidenceHash,int $now,callable $nonceUnused):true|WP_Error
 {
  if(($record['state']??'')!=='EXECUTION_AUTHORIZED'||($record['executed']??null)!==false)
   return new WP_Error('digiforge_execution_state','Execution authorization must be unused and authorized.',['status'=>409]);
  $a=$record['authorization']??null;if(!is_array($a))return new WP_Error('digiforge_execution_payload','Authorization payload is required.',['status'=>400]);
  $requiredAction=strtoupper(trim($requiredAction));
  if(!in_array($requiredAction,['ETSY_DRAFT_CREATE','PROVIDER_ORDER_SUBMIT'],true)||($a['action']??'')!==$requiredAction)
   return new WP_Error('digiforge_execution_scope','Authorization action does not match the requested operation.',['status'=>403]);
  $expectedEvidenceHash=strtolower(trim($expectedEvidenceHash));
  if(!preg_match('/^[a-f0-9]{64}$/',$expectedEvidenceHash)||!hash_equals($expectedEvidenceHash,(string)($a['evidence_hash']??'')))
   return new WP_Error('digiforge_execution_evidence','Authorization evidence does not match current approved evidence.',['status'=>409]);
  $issued=(int)($a['issued_at']??0);$expires=(int)($a['expires_at']??0);
  if($issued<1||$expires<=$issued||$now<$issued||$now>$expires||($expires-$issued)>900)
   return new WP_Error('digiforge_execution_expired','Execution authorization is expired or invalid.',['status'=>403]);
  $nonce=(string)($a['nonce']??'');if(!preg_match('/^[A-Za-z0-9_-]{24,128}$/',$nonce)||$nonceUnused($nonce)!==true)
   return new WP_Error('digiforge_execution_replay','Execution authorization nonce is invalid or already consumed.',['status'=>409]);
  $payload=$a;ksort($payload);$calculated=hash('sha256',(string)wp_json_encode($payload));
  if(!hash_equals($calculated,(string)($record['authorization_hash']??'')))
   return new WP_Error('digiforge_execution_tampered','Execution authorization integrity check failed.',['status'=>409]);
  return true;
 }
}
